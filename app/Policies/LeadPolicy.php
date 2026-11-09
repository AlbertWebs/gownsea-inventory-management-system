<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'management', 'sales_crm'], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->viewAny($user) && ($user->mayViewAllLocations() || $user->locations()->whereKey($lead->location_id)->exists());
    }
}
