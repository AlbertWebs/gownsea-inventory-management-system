<?php

namespace App\Http\Controllers;

use App\Actions\ConvertLeadToCustomer;
use App\Actions\RecordLeadActivity;
use App\Actions\SaveLead;
use App\Actions\SaveLeadTask;
use App\Http\Requests\StoreLeadActivityRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\StoreLeadTaskRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadTask;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Lead::class), 403);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();
        $baseQuery = Lead::query()->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds));
        $leads = (clone $baseQuery)->with([
            'location:id,name', 'owner:id,name', 'customer:id,name', 'institution:id,name',
            'activities' => fn ($query) => $query->with('actor:id,name')->latest('happened_at')->limit(8),
            'tasks' => fn ($query) => $query->with('assignee:id,name')->orderByRaw('completed_at IS NOT NULL')->orderBy('due_at')->limit(20),
        ])
            ->orderByRaw('CASE WHEN follow_up_at IS NULL THEN 1 ELSE 0 END')->orderBy('follow_up_at')->latest('updated_at')->paginate(50)->withQueryString();

        return Inertia::render('Customers/CRM', [
            'leads' => $leads,
            'locations' => Location::query()->where('is_active', true)->when($locationIds !== null, fn ($query) => $query->whereIn('id', $locationIds))->orderBy('name')->get(['id', 'name']),
            'institutions' => Institution::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name']),
            'customers' => Customer::query()->when($locationIds !== null, fn ($query) => $query->where(fn ($q) => $q->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds))->orWhereHas('hires', fn ($hires) => $hires->whereIn('location_id', $locationIds))))->orderBy('name')->limit(500)->get(['id', 'name']),
            'stages' => (clone $baseQuery)->selectRaw('stage, COUNT(*) as count, COALESCE(SUM(estimated_value_minor), 0) as value_minor')->groupBy('stage')->get()->keyBy('stage'),
            'staff' => User::query()->where('is_active', true)->whereHas('locations', fn ($query) => $query->whereIn('locations.id', $locationIds ?? Location::query()->where('is_active', true)->select('id')))->with('locations:id')->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->can('create', Lead::class),
        ]);
    }

    public function store(StoreLeadRequest $request, SaveLead $save): RedirectResponse
    {
        $lead = $save->create($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Lead {$lead->reference} created.");
    }

    public function update(UpdateLeadRequest $request, Lead $lead, SaveLead $save): RedirectResponse
    {
        $lead = $save->update($lead, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Lead {$lead->reference} updated.");
    }

    public function moveStage(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless($request->user()->can('update', $lead), 403);
        $data = $request->validate([
            'stage' => ['required', 'string', 'in:new,contacted,quotation_sent,negotiation,won,lost'],
            'lost_reason' => ['nullable', 'required_if:stage,lost', 'string', 'min:5', 'max:180'],
        ]);

        DB::transaction(function () use ($request, $lead, $data): void {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $before = $lead->only(['reference', 'location_id', 'customer_id', 'institution_id', 'stage', 'estimated_value_minor', 'probability', 'next_action', 'follow_up_at', 'lost_reason']);
            $lead->update(['stage' => $data['stage'], 'lost_reason' => $data['stage'] === 'lost' ? $data['lost_reason'] : null]);
            $after = $lead->fresh()->only(array_keys($before));
            LeadActivity::query()->create(['lead_id' => $lead->id, 'actor_id' => $request->user()->id, 'activity_type' => 'lead.stage_changed', 'before_state' => $before, 'after_state' => $after, 'happened_at' => now()]);
            AuditLog::query()->create(['actor_id' => $request->user()->id, 'location_id' => $lead->location_id, 'action' => 'lead.stage_changed', 'subject_type' => Lead::class, 'subject_id' => $lead->id, 'before_state' => $before, 'after_state' => $after, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'occurred_at' => now()]);
        }, attempts: 3);

        return back()->with('success', "Lead {$lead->reference} moved to ".str_replace('_', ' ', $data['stage']).'.');
    }

    public function convert(Request $request, Lead $lead, ConvertLeadToCustomer $convert): RedirectResponse
    {
        abort_unless($request->user()->can('update', $lead), 403);
        $customer = $convert->handle($lead, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Lead linked to customer {$customer->customer_number}.");
    }

    public function activity(StoreLeadActivityRequest $request, Lead $lead, RecordLeadActivity $record): RedirectResponse
    {
        $record->handle($lead, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Conversation activity recorded.');
    }

    public function storeTask(StoreLeadTaskRequest $request, Lead $lead, SaveLeadTask $save): RedirectResponse
    {
        $task = $save->create($lead, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Follow-up task '{$task->title}' scheduled.");
    }

    public function completeTask(Request $request, Lead $lead, LeadTask $task, SaveLeadTask $save): RedirectResponse
    {
        abort_unless($request->user()->can('update', $lead), 403);
        $completed = $save->complete($lead, $task, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Follow-up task '{$completed->title}' completed.");
    }
}
