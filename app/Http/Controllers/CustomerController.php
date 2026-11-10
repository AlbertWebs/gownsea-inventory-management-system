<?php

namespace App\Http\Controllers;

use App\Actions\UpdateCustomerSmsConsent;
use App\Http\Requests\UpdateCustomerSmsConsentRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Location;
use App\Support\KenyanPhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->pluck('locations.id')->all();
        $search = trim($request->string('q')->toString());

        return Inertia::render('Customers/Index', [
            'customers' => Customer::query()->with('institution:id,name')
                ->when($locationIds !== null, fn ($query) => $query->where(fn ($customers) => $customers
                    ->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds))
                    ->orWhereHas('hires', fn ($hires) => $hires->whereIn('location_id', $locationIds))))
                ->when($search !== '', fn ($query) => $query->where(fn ($customers) => $customers
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('customer_number', 'like', "%{$search}%")
                    ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$search}%"))))
                ->withCount(['hires' => fn ($hires) => $locationIds !== null ? $hires->whereIn('location_id', $locationIds) : $hires])
                ->orderBy('name')->paginate(25)->through(fn (Customer $customer): array => [
                    'id' => $customer->id, 'customer_number' => $customer->customer_number, 'type' => $customer->type,
                    'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email,
                    'institution' => $customer->institution, 'hires_count' => $customer->hires_count,
                    'sms_consent' => ! $customer->sms_opt_out && $customer->sms_consent_at !== null,
                    'sms_preferences' => $customer->sms_preferences ?? ['transactional' => true, 'reminders' => true, 'payments' => true, 'orders' => true],
                    'created_at' => $customer->created_at,
                ])->withQueryString(),
            'filters' => ['q' => $search],
            'canManageSmsConsent' => in_array($user->role, ['super_admin', 'management', 'sales_crm'], true),
        ]);
    }

    public function updateSmsConsent(UpdateCustomerSmsConsentRequest $request, Customer $customer, UpdateCustomerSmsConsent $update): RedirectResponse
    {
        $customer = $update->handle($customer, (bool) $request->validated('sms_consent'), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', $customer->sms_opt_out ? 'SMS consent withdrawn. Future messages are suppressed.' : 'SMS consent recorded for this customer.');
    }

    public function updateSmsPreferences(Request $request, Customer $customer): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->mayViewAllLocations() || $customer->locations()->whereIn('locations.id', $user->locations()->pluck('locations.id'))->exists(), 403);
        $data = $request->validate([
            'preferences' => ['required', 'array:transactional,reminders,payments,orders'],
            'preferences.transactional' => ['required', 'boolean'],
            'preferences.reminders' => ['required', 'boolean'],
            'preferences.payments' => ['required', 'boolean'],
            'preferences.orders' => ['required', 'boolean'],
        ]);
        $before = $customer->sms_preferences;
        $customer->update(['sms_preferences' => $data['preferences']]);
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => 'customer.sms_preferences_updated',
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'before_state' => ['sms_preferences' => $before],
            'after_state' => ['sms_preferences' => $customer->sms_preferences],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);

        return back()->with('success', 'Customer SMS preferences updated.');
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'management', 'sales_crm', 'storekeeper'], true), 403);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();

        return Inertia::render('Customers/Create', [
            'locations' => Location::query()->where('is_active', true)
                ->when($locationIds !== null, fn ($query) => $query->whereIn('id', $locationIds))
                ->orderBy('name')->get(['id', 'name']),
            'institutions' => Institution::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name', 'type']),
            'defaultLocationId' => $user->locations()->wherePivot('is_primary', true)->value('locations.id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'sales_crm', 'storekeeper'], true), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:individual,institution'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+254|254|0)?[17][0-9]{8}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'source' => ['nullable', 'in:referral,website,social,tender,direct,other'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'return_to' => ['nullable', 'in:hiring.issue'],
        ]);
        $returnTo = $data['return_to'] ?? null;
        unset($data['return_to']);

        $location = Location::query()->findOrFail($data['location_id']);
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($location->id)->exists()) {
            abort(403);
        }

        if (! empty($data['phone'])) {
            try {
                $data['phone'] = KenyanPhoneNumber::normalize($data['phone']);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
            }
        }

        $customer = DB::transaction(function () use ($data, $location, $actor): Customer {
            $customer = Customer::query()->create([
                ...$data,
                'customer_number' => 'CUS-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'source' => $data['source'] ?? 'direct',
            ]);
            $customer->locations()->attach($location->id, ['assigned_by' => $actor->id]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $location->id,
                'action' => 'customer.created',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'after_state' => ['customer_number' => $customer->customer_number, 'type' => $customer->type, 'location_id' => $location->id],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'occurred_at' => now(),
            ]);

            return $customer;
        });

        $createdCustomer = [
            'id' => $customer->id,
            'name' => $customer->name,
            'customer_number' => $customer->customer_number,
            'phone' => $customer->phone,
            'institution_id' => $customer->institution_id,
        ];

        return redirect()->route($returnTo ?? 'customers.index')
            ->with('success', "Customer {$customer->customer_number} was created.")
            ->with('createdCustomer', $returnTo ? $createdCustomer : null);
    }
}
