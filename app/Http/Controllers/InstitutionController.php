<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveInstitutionRequest;
use App\Models\AuditLog;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InstitutionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->mayViewAllLocations(), 403);

        return Inertia::render('Institutions/Index', [
            'institutions' => Institution::query()->withCount(['customers', 'events'])->orderBy('name')->get(),
        ]);
    }

    public function store(SaveInstitutionRequest $request): RedirectResponse
    {
        $institution = Institution::query()->create($request->validated());
        $this->audit($request, $institution, 'institution.created', null, $institution->only(['name', 'type', 'email', 'phone', 'address', 'county', 'is_active']));

        return back()->with('success', "Institution {$institution->name} created.");
    }

    public function update(SaveInstitutionRequest $request, Institution $institution): RedirectResponse
    {
        $before = $institution->only(['name', 'type', 'email', 'phone', 'address', 'county', 'is_active']);
        $institution->fill($request->validated())->save();
        $this->audit($request, $institution, 'institution.updated', $before, $institution->only(['name', 'type', 'email', 'phone', 'address', 'county', 'is_active']));

        return back()->with('success', "Institution {$institution->name} updated.");
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(SaveInstitutionRequest $request, Institution $institution, string $action, ?array $before, array $after): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Institution::class,
            'subject_id' => $institution->id,
            'before_state' => $before,
            'after_state' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reason' => 'Institution profile maintained.',
            'occurred_at' => now(),
        ]);
    }
}
