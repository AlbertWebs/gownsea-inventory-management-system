<?php

namespace App\Policies;

use App\Models\CustomOrder;
use App\Models\User;

class CustomOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'management', 'sales_crm', 'production'], true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'management', 'sales_crm'], true);
    }

    public function update(User $user, CustomOrder $customOrder): bool
    {
        return $this->viewAny($user) && ($user->mayViewAllLocations() || $user->locations()->whereKey($customOrder->location_id)->exists());
    }
}
