<?php
/**
 * Application Navigation
 *
 * One answer to "where may this user go", shared by the sidebar rail and the
 * header's global search. Holding it in a single place is what stops the two
 * from drifting: a module added to the menu becomes searchable the same day,
 * and a module a role may not open is absent from both rather than hidden in
 * one and still reachable from the other.
 *
 * Each item is [page slug, label, icon]. The permission checked is
 * "{slug}.view" — the same string canAccessPage() and the controllers use, so
 * the menu stays a projection of the permission matrix rather than a second
 * copy of it kept in step by hand.
 */

/**
 * Every section the signed-in user may see, in menu order, with unreachable
 * items already removed. Sections left with nothing under them are dropped,
 * so no role is shown an empty heading.
 *
 * Labels are per-role where one module means a different thing to different
 * people: an agent's Inquiries is the agency's lead inbox, an owner's is
 * interest in their properties, a tenant's is their own correspondence. Same
 * page, same permission, honest name.
 *
 * @return array<int, array{0: string, 1: array<int, array{0: string, 1: string, 2: string}>}>
 */
function appNavSections(): array
{
    // Resolved once per request: the rail and the search index both ask for
    // this, and canAccessPage() is not free across ~25 slugs.
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $role = getCurrentUser()['role'] ?? '';

    $inquiryLabel = match ($role) {
        ROLE_OWNER    => 'Property Inquiries',
        ROLE_CUSTOMER => 'My Inquiries',
        default       => 'Inquiries',
    };
    $maintenanceLabel = match ($role) {
        ROLE_CUSTOMER    => 'My Requests',
        ROLE_MAINTENANCE => 'My Jobs',
        default          => 'Maintenance',
    };

    $sections = [
        ['Main Menu', [
            ['dashboard', 'Dashboard', 'bi-grid-1x2'],
        ]],

        ['Property', [
            ['properties',   'Properties',   'bi-buildings'],
            ['reservations', 'Reservations', 'bi-calendar-check'],
            ['documents',    'Documents',    'bi-folder2-open'],
        ]],

        ['People', [
            ['customers', 'Customers', 'bi-people'],
            ['owners',    'Owners',    'bi-person-badge'],
        ]],

        ['Transactions', [
            ['leases',   'Leases',   'bi-file-earmark-text'],
            ['payments', 'Payments', 'bi-credit-card'],
            ['sales',    'Sales',    'bi-cart-check'],
        ]],

        ['My Account', [
            ['my-lease',    'My Lease',    'bi-file-earmark-text'],
            ['my-payments', 'My Payments', 'bi-credit-card'],
            ['favorites',   'Favorites',   'bi-heart'],
        ]],

        ['My Portfolio', [
            ['my-properties', 'Properties', 'bi-buildings'],
            ['my-income',     'Income',     'bi-graph-up'],
        ]],

        ['Operations', [
            ['maintenance',   $maintenanceLabel, 'bi-wrench-adjustable'],
            ['inquiries',     $inquiryLabel,     'bi-chat-left-text'],
            ['notifications', 'Notifications',   'bi-bell'],
        ]],

        ['Administration', [
            ['users',               'Users & Roles',       'bi-shield-lock'],
            ['testimonials',        'Testimonials',        'bi-chat-quote'],
            ['branches',            'Branches',            'bi-diagram-3'],
            ['reports',             'Reports',             'bi-bar-chart-line'],
            ['document-categories', 'Document Categories', 'bi-tags'],
            ['legal',               'Terms & Legal',       'bi-file-earmark-check'],
            ['audit-logs',          'Audit Logs',          'bi-journal-text'],
            ['settings',            'Settings',            'bi-gear'],
        ]],
    ];

    $visible = [];
    foreach ($sections as [$label, $items]) {
        $allowed = array_values(array_filter(
            $items,
            fn(array $item): bool => canAccessPage($item[0])
        ));
        if ($allowed) {
            $visible[] = [$label, $allowed];
        }
    }

    return $cache = $visible;
}

/**
 * The same navigation flattened into one searchable list, for the header's
 * global search. Section is carried along so a result can say where it lives
 * and so "admin" can find Users & Roles without the word being in its label.
 *
 * @return array<int, array{slug: string, label: string, section: string, icon: string, url: string}>
 */
function appNavSearchIndex(): array
{
    $index = [];
    foreach (appNavSections() as [$sectionLabel, $items]) {
        foreach ($items as [$slug, $label, $icon]) {
            $index[] = [
                'slug'    => $slug,
                'label'   => $label,
                'section' => $sectionLabel,
                'icon'    => $icon,
                'url'     => APP_URL . '/index.php?page=' . $slug,
            ];
        }
    }
    return $index;
}
