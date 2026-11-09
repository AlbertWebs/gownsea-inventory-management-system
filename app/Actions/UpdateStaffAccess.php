<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UpdateStaffAccess
{
    /** @param array{role:string,is_active:bool|int|string,location_ids:list<int|string>,primary_location_id?:int|string|null,permissions?:list<string>} $data */
    public function handle(User $staff, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): User
    {
        return DB::transaction(function () use ($staff, $data, $actor, $ipAddress, $userAgent): User {
            $staff = User::query()->lockForUpdate()->findOrFail($staff->id);
            if ($staff->id === $actor->id) {
                throw ValidationException::withMessages(['staff' => 'Use another authorized administrator to change your own access.']);
            }
            if ($actor->role !== 'super_admin' && (in_array($staff->role, ['super_admin', 'management'], true) || in_array($data['role'], ['super_admin', 'management'], true))) {
                abort(403);
            }

            $active = (bool) $data['is_active'];
            $role = $data['role'];
            if ($staff->role === 'super_admin' && (! $active || $role !== 'super_admin')) {
                $otherActiveAdmins = User::query()->where('role', 'super_admin')->where('is_active', true)->where('id', '!=', $staff->id)->count();
                if ($otherActiveAdmins === 0) {
                    throw ValidationException::withMessages(['role' => 'At least one active super administrator must remain.']);
                }
            }
            if ($role === 'super_admin' && ! $active && User::query()->where('role', 'super_admin')->where('is_active', true)->where('id', '!=', $staff->id)->count() === 0) {
                throw ValidationException::withMessages(['is_active' => 'At least one active super administrator must remain.']);
            }

            $locationIds = collect($data['location_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
            $permissionOverride = $role === 'super_admin' ? null : ($data['permissions'] ?? null);
            $before = [
                'role' => $staff->role,
                'is_active' => $staff->is_active,
                'permissions' => $staff->permissions ?? $staff->effectivePermissions(),
                'location_ids' => $staff->locations()->pluck('locations.id')->sort()->values()->all(),
            ];
            $staff->update(['role' => $role, 'is_active' => $active, 'permissions' => $permissionOverride]);
            $primary = isset($data['primary_location_id']) ? (int) $data['primary_location_id'] : $locationIds->first();
            $staff->locations()->sync($locationIds->mapWithKeys(fn (int $id): array => [$id => ['is_primary' => $id === $primary]])->all());
            if (! $active && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $staff->id)->delete();
            }
            $after = [
                'role' => $staff->role,
                'is_active' => $staff->is_active,
                'permissions' => $staff->permissions ?? $staff->effectivePermissions(),
                'location_ids' => $locationIds->all(),
                'primary_location_id' => $primary,
            ];
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'staff.access_updated',
                'subject_type' => User::class,
                'subject_id' => $staff->id,
                'before_state' => $before,
                'after_state' => $after,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $staff->refresh()->load('locations:id,name');
        }, attempts: 3);
    }
}
