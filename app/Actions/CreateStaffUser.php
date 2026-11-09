<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateStaffUser
{
    /** @param array{name:string,email:string,role:string,location_ids:list<int|string>,primary_location_id?:int|string|null} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): User
    {
        if ($actor->role !== 'super_admin' && in_array($data['role'], ['super_admin', 'management'], true)) {
            abort(403);
        }
        if (! in_array($data['role'], ['super_admin', 'management'], true) && count($data['location_ids']) === 0) {
            throw ValidationException::withMessages(['location_ids' => 'Assign at least one active branch to operational staff.']);
        }

        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): User {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => Hash::make(Str::random(64)),
                'role' => $data['role'],
                'is_active' => true,
            ]);

            $locationIds = collect($data['location_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
            $primary = isset($data['primary_location_id']) ? (int) $data['primary_location_id'] : $locationIds->first();
            $user->locations()->sync($locationIds->mapWithKeys(fn (int $id): array => [$id => ['is_primary' => $id === $primary]])->all());

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'staff.account_created',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'after_state' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'location_ids' => $locationIds->all(), 'primary_location_id' => $primary],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $user;
        }, attempts: 3);
    }
}
