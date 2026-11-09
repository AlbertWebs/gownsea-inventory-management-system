<?php

namespace App\Policies;

use App\Models\GraduationEvent;
use App\Models\User;

class GraduationEventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'management', 'sales_crm'], true);
    }

    public function update(User $user, GraduationEvent $graduationEvent): bool
    {
        return $this->create($user) && ($user->mayViewAllLocations() || $user->locations()->whereKey($graduationEvent->location_id)->exists());
    }
}
