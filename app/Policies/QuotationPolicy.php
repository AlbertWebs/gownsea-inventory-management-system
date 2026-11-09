<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'management', 'sales_crm'], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function approve(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission('quotations.approve')
            && ($user->mayViewAllLocations() || $user->locations()->whereKey($quotation->location_id)->exists());
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $this->viewAny($user) && ($user->mayViewAllLocations() || $user->locations()->whereKey($quotation->location_id)->exists());
    }
}
