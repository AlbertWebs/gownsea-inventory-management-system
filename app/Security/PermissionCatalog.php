<?php

namespace App\Security;

final class PermissionCatalog
{
    /** @var array<string, string> */
    public const LABELS = [
        'dashboard.view' => 'View dashboard',
        'search.use' => 'Use global search',
        'inventory.view' => 'View inventory',
        'inventory.catalog.manage' => 'Create and edit products',
        'inventory.stock.receive' => 'Receive and inspect stock',
        'inventory.stock.adjust' => 'Adjust stock and approve counts',
        'hiring.view' => 'View hires and reservations',
        'hiring.issue' => 'Issue or reserve gowns',
        'hiring.return' => 'Process returns and inspections',
        'hiring.penalty.manage' => 'Collect, waive or reconcile penalties',
        'customers.view' => 'View customers',
        'customers.manage' => 'Create customers and manage consent',
        'crm.manage' => 'Manage leads and follow-ups',
        'quotations.manage' => 'Manage and send quotations',
        'quotations.approve' => 'Approve or reject quotations',
        'orders.view' => 'View custom orders',
        'orders.manage' => 'Manage production and delivery',
        'sales.view' => 'View sales and payments',
        'sales.record' => 'Record sales and receipts',
        'sales.refund' => 'Refund sales and payments',
        'finance.view' => 'View finance and reconciliation',
        'finance.manage' => 'Manage expenses and ledger',
        'reports.view' => 'View operational reports',
        'reports.finance' => 'View financial reports',
        'reports.crm' => 'View CRM reports',
        'events.manage' => 'Manage graduation events',
        'transfers.manage' => 'Create, dispatch and receive transfers',
        'staff.manage' => 'Provision and manage staff access',
        'settings.manage' => 'Manage business settings',
    ];

    /** @var array<string, list<string>> */
    private const ROLE_DEFAULTS = [
        'super_admin' => ['*'],
        'management' => ['*'],
        'storekeeper' => [
            'dashboard.view', 'search.use', 'inventory.view', 'inventory.catalog.manage', 'inventory.stock.receive',
            'hiring.view', 'hiring.issue', 'hiring.return', 'customers.view', 'customers.manage', 'sales.view', 'sales.record',
            'reports.view', 'transfers.manage',
        ],
        'sales_crm' => [
            'dashboard.view', 'search.use', 'inventory.view', 'hiring.view', 'hiring.issue', 'hiring.return', 'customers.view', 'customers.manage',
            'crm.manage', 'quotations.manage', 'orders.view', 'orders.manage', 'sales.view', 'sales.record', 'reports.view',
            'reports.crm', 'events.manage',
        ],
        'finance' => [
            'dashboard.view', 'search.use', 'inventory.view', 'hiring.view', 'customers.view', 'orders.view',
            'hiring.penalty.manage', 'sales.view', 'sales.record', 'sales.refund', 'finance.view', 'finance.manage', 'reports.view', 'reports.finance',
        ],
        'production' => ['dashboard.view', 'search.use', 'orders.view', 'orders.manage', 'reports.view'],
    ];

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        if (in_array($role, ['super_admin', 'management'], true)) {
            return array_keys(self::LABELS);
        }

        $permissions = self::ROLE_DEFAULTS[$role] ?? [];

        return in_array('*', $permissions, true) ? array_keys(self::LABELS) : $permissions;
    }

    /** @return array<string, string> */
    public static function labelsForRole(string $role): array
    {
        return array_intersect_key(self::LABELS, array_flip(self::forRole($role)));
    }

    /** @return list<string> */
    public static function normalizeForRole(string $role, ?array $permissions): array
    {
        $defaults = self::forRole($role);
        if ($permissions === null) {
            return $defaults;
        }

        return array_values(array_unique(array_intersect($defaults, $permissions)));
    }
}
