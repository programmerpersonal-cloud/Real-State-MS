<?php
/**
 * Authorization
 *
 * Three questions, asked in that order, each answered in exactly one place:
 *
 *   1. May this role open the module?      canAccessPage('inquiries')
 *   2. May this user perform the action?   can('inquiries.reply')
 *   3. Which rows may they touch?          inquiryViewScope()  — property_access.php
 *
 * Levels 1 and 2 live here because they depend only on the role: they are the
 * same answer for every agent in the company, so they can be written down as a
 * table. Level 3 depends on the relationship between this user and this row —
 * which property, whose lease — so it stays in property_access.php next to the
 * SQL that enforces it.
 *
 * Permission names are `page.action`, where `page` is the router slug from
 * index.php. That is deliberate: canAccessPage() is then literally
 * can("{$page}.view") with no page→module lookup table in between, so a menu
 * entry and the controller behind it cannot drift apart the way the sidebar
 * and InquiryController had.
 *
 * Everything fails closed. An unknown role, an unknown permission and a
 * signed-out visitor all resolve to false, so a page that nobody remembered to
 * add to the matrix is unreachable rather than public.
 */

/**
 * The permission matrix: what each role may do, before record scoping.
 *
 * `*` grants everything. `page.*` grants every action on one page. Anything
 * not listed is denied — there is no implicit inheritance between roles,
 * because "an agent is an admin with less" stops being true the moment one
 * role gains something another lacks.
 *
 * Read a row as the answer to "what does this job involve?", not "what tables
 * exist?". The tenant row is short because a tenant's work is short.
 *
 * @return array<string, string[]>
 */
function permissionMatrix(): array
{
    static $matrix = null;
    if ($matrix !== null) {
        return $matrix;
    }

    $matrix = [

        // ─── Administrator ─────────────────────────────────────────────
        // Runs the company. The only role with a wildcard, and the only one
        // that may change who else can do what.
        //
        // The wildcard is an *authorization* statement — which modules and
        // actions are open — and it is not a relationship. It grants
        // `messages.create`, and `messages.create` means "starting a
        // conversation is part of your job". It does not mean "with every
        // account in the database": who an administrator may actually write
        // to is decided by messageContactScope() in
        // includes/communication_access.php, which resolves the agency team
        // plus the clients who have no agent to fall back on. Reading `*` as
        // a licence to message anyone would make that file decorative.
        ROLE_ADMIN => ['*'],

        // ─── Agent ─────────────────────────────────────────────────────
        // Runs the day-to-day business: listings, people, deals. Everything
        // destructive or structural (approving a listing, blacklisting a
        // customer, deleting a document, touching users/branches/settings) is
        // withheld — an agent creates and edits, an admin removes.
        //
        // The property workflow is the sharpest case of that line, and it is
        // drawn by omission here rather than by a check in a controller:
        //
        //   properties.approve   sign a listing off        admin only
        //   properties.reject    send it back              admin only
        //   properties.approvals open the approval queue   admin only
        //   properties.archive   file a property away      admin only
        //   properties.restore   bring one back            admin only
        //
        // An agent creates a listing and it enters the queue as 'pending';
        // they cannot approve it, their own least of all. Because can() has no
        // entry to match and this role holds no wildcard, every one of those
        // strings is false for an agent in the UI *and* at authorize(), which
        // is what makes a hand-typed ?action=approve a 403 rather than a
        // hidden button someone found.
        ROLE_AGENT => [
            'dashboard.view',

            'properties.view', 'properties.show', 'properties.create',
            'properties.edit', 'properties.delete-image',

            'customers.view', 'customers.show', 'customers.create', 'customers.edit',
            'owners.view',    'owners.show',    'owners.create',    'owners.edit',

            'leases.view',       'leases.show',   'leases.create', 'leases.edit',
            'leases.renew',      'leases.terminate',
            'payments.view',     'payments.show',  'payments.create', 'payments.receipt',
            'sales.view',        'sales.show',     'sales.create',
            'reservations.view', 'reservations.create',
            'reservations.cancel', 'reservations.confirm',

            'maintenance.view', 'maintenance.show', 'maintenance.create',
            'maintenance.update', 'maintenance.assign',

            'inquiries.view', 'inquiries.show', 'inquiries.create', 'inquiries.reply',

            // The agency's side of the communication module. An agent is the
            // hub — owners, tenants, buyers and technicians all resolve to
            // them — so they hold every action. Who each of those actions may
            // be aimed at is a different question, answered by
            // messageContactScope() in communication_access.php: holding
            // `messages.create` means "starting conversations is part of your
            // job", never "with anyone".
            'messages.view', 'messages.show', 'messages.create',
            'messages.send', 'messages.archive',

            'documents.view', 'documents.show', 'documents.create',
            'documents.edit', 'documents.archive', 'documents.restore',

            'reports.view',
            'notifications.view',
            'profile.view', 'profile.edit',
        ],

        // ─── Property owner ────────────────────────────────────────────
        // Watches their own portfolio. Reads a great deal and writes almost
        // nothing: an owner reports a fault and reads the enquiries their
        // properties attract, but does not answer enquiries on the agency's
        // behalf or manage the tenancy.
        //
        // Note `owners.show` without `owners.view`: an owner reaches their own
        // profile page but never the directory of every owner. That gap is the
        // whole point of separating module access from record access.
        ROLE_OWNER => [
            'dashboard.view',

            'my-properties.view',
            'my-income.view',
            'owners.show',

            // The property detail page, not the agency's property register.
            // What an owner may read on it is cut further by the document
            // visibility scope, which is why the page itself can stay open.
            'properties.show',
            'documents.show',

            'maintenance.view', 'maintenance.show', 'maintenance.create',

            // Scoped to their own properties by inquiryViewScope(). Read-only:
            // replying is the agency's job.
            'inquiries.view', 'inquiries.show',

            // Correspondence with the agents managing their portfolio — or,
            // where no agent has been assigned, with the office. The same five
            // actions every non-admin role holds: the difference between an
            // owner and an agent here is not what they may do, it is who
            // messageContactScope() resolves for them.
            'messages.view', 'messages.show', 'messages.create',
            'messages.send', 'messages.archive',

            'notifications.view',
            'profile.view', 'profile.edit',
        ],

        // ─── Customer / tenant ─────────────────────────────────────────
        // Lives in one of the properties, or is shopping for one. Sees their
        // own tenancy and their own correspondence, nothing about the
        // building's other occupants or the owner's finances.
        ROLE_CUSTOMER => [
            'dashboard.view',

            'my-lease.view',
            'my-payments.view',
            'payments.receipt',

            // Shortlisting is a buyer's tool, so it belongs to the buyer's
            // role and to no one else. The heart on a public listing card is
            // drawn from can('favorites.add') for the same reason the sidebar
            // is: staff browsing the site are not offered a wishlist they
            // would only be refused.
            'favorites.view', 'favorites.add', 'favorites.remove',

            // Browsing and shortlisting: the listing detail page a favourite
            // links back to.
            'properties.show',
            'documents.show',

            'maintenance.view', 'maintenance.show', 'maintenance.create',

            // A tenant raises enquiries and follows their own. Scoped by
            // inquiryViewScope() to rows they filed.
            'inquiries.view', 'inquiries.show', 'inquiries.create',

            'reservations.create',

            // Their agent, about their tenancy or their purchase. Resolved
            // through the live lease, sale or reservation — which is why
            // moving out ends the conversation rather than merely hiding it.
            'messages.view', 'messages.show', 'messages.create',
            'messages.send', 'messages.archive',

            'notifications.view',
            'profile.view', 'profile.edit',
        ],

        // ─── Maintenance technician ────────────────────────────────────
        // Works jobs. Sees the work queue and nothing commercial — no prices,
        // no tenancies, no enquiries.
        ROLE_MAINTENANCE => [
            'dashboard.view',

            'maintenance.view', 'maintenance.show',
            'maintenance.create', 'maintenance.update',

            // The address and access notes for the job in hand.
            'properties.show',
            'documents.show',

            // Operational coordination with the agent responsible for the
            // property they are working on. Nothing commercial travels this
            // way that does not already travel through the job itself.
            'messages.view', 'messages.show', 'messages.create',
            'messages.send', 'messages.archive',

            'notifications.view',
            'profile.view', 'profile.edit',
        ],
    ];

    return $matrix;
}

/**
 * Pages the `*` wildcard deliberately does not reach.
 *
 * These are not capabilities, they are personal records: "my lease", "my
 * income", the properties *I* own. Seniority does not produce a tenancy, so an
 * administrator holding every permission in the system still has no lease to
 * look at, and My Lease in their sidebar would open a page that can only be
 * empty.
 *
 * A role that genuinely has one of these holds it explicitly in the matrix,
 * and an explicit grant always beats this list.
 *
 * @return string[]
 */
function personalPages(): array
{
    return ['my-lease', 'my-payments', 'my-properties', 'my-income', 'favorites'];
}

// ─── Asking the questions ──────────────────────────────────────────────

/**
 * Whether the signed-in user holds a permission.
 *
 * This is the UI's question — "should I draw this button?" — and it is only
 * ever advisory. Every call site that matters is backed by authorize() on the
 * server; hiding a control is courtesy, refusing the request is security.
 */
function can(string $permission): bool
{
    if (!isLoggedIn() || $permission === '') {
        return false;
    }

    $granted = permissionMatrix()[getUserRole()] ?? [];

    // An explicit grant is the strongest statement in the matrix and wins
    // over every rule below, including the personal-pages exception.
    if (in_array($permission, $granted, true)) {
        return true;
    }

    $page = strstr($permission, '.', true);

    // `page.*` — every action on one page.
    if ($page !== false && in_array($page . '.*', $granted, true)) {
        return true;
    }

    if (in_array('*', $granted, true)) {
        return $page === false || !in_array($page, personalPages(), true);
    }

    return false;
}

/**
 * Whether the user holds at least one of several permissions.
 *
 * For controls that open a screen offering a choice of actions: the button is
 * worth drawing if any of them will work.
 */
function canAny(string ...$permissions): bool
{
    foreach ($permissions as $permission) {
        if (can($permission)) {
            return true;
        }
    }
    return false;
}

/**
 * Level 1: whether a router page should appear in navigation and open at all.
 *
 * Derived from the same matrix as everything else rather than a second list of
 * pages, so adding a permission is the single edit that makes a menu entry
 * appear.
 */
function canAccessPage(string $page): bool
{
    return $page !== '' && can($page . '.view');
}

/**
 * Server-side enforcement. Call at the top of every controller action.
 *
 * Grants when the user holds ANY of the listed permissions, so an action
 * reachable two ways ("edit, or the admin-only override") states both.
 *
 * Refuses by rendering 403 rather than bouncing to the dashboard. A silent
 * redirect tells a user who followed a stale link that the application is
 * broken; a page that explains itself tells them the truth.
 */
function authorize(string ...$permissions): void
{
    requireLogin();

    if (!canAny(...$permissions)) {
        denyAccess($permissions[0] ?? '');
    }
}

/**
 * Level 3 refusal: the user may open the module, but not this row.
 *
 * Separate from authorize() because the two are genuinely different events —
 * one means "wrong job", the other means "not your record" — and the audit
 * trail is worth more when it distinguishes them. Pass the record so the log
 * says what was reached for.
 */
function authorizeRecord(bool $allowed, string $entityType = '', int $entityId = 0): void
{
    requireLogin();

    if (!$allowed) {
        denyAccess(
            $entityType !== '' ? $entityType . '.record' : '',
            $entityType,
            $entityId
        );
    }
}

// ─── Refusing ──────────────────────────────────────────────────────────

/**
 * Render the 403 page and stop.
 *
 * Every refusal is written to the audit log. Once navigation stops offering
 * links the user cannot follow, reaching this function means someone typed a
 * URL, replayed a bookmark, or followed a link that outlived their access —
 * all three are worth a line in the log, which they were not while accidental
 * clicks drowned them out.
 */
function denyAccess(string $permission = '', string $entityType = '', int $entityId = 0): void
{
    logAudit(
        'access_denied',
        $entityType !== '' ? $entityType : 'permission',
        $entityId,
        '',
        $permission !== '' ? $permission : ($_GET['page'] ?? '')
    );

    http_response_code(403);

    renderPage(VIEWS_PATH . '/errors/403.php', [
        'deniedPermission' => $permission,
        'pageTitle'        => 'Access restricted',
    ]);
}

// ─── Explaining the refusal ────────────────────────────────────────────
// The wording lives beside the rules so the two are changed together, the
// same arrangement property_access.php uses for its scope hints.

/**
 * Why this user cannot see this, phrased for the role reading it.
 *
 * Says which account is being refused, because the commonest innocent cause of
 * a 403 is being signed in as the wrong one.
 */
function accessDeniedReason(): string
{
    switch (getUserRole()) {
        case ROLE_AGENT:
            return 'This section is limited to administrators. Your agent account covers listings, clients and transactions.';
        case ROLE_OWNER:
            return 'Your owner account covers the properties registered to you — their performance, enquiries and maintenance. Agency-wide records are held by the office.';
        case ROLE_CUSTOMER:
            return 'Your account covers your own tenancy — your lease, payments, requests and enquiries. Everything else belongs to the managing office.';
        case ROLE_MAINTENANCE:
            return 'Your account covers the maintenance jobs assigned to you. Commercial and tenancy records are handled by the office.';
    }
    return 'Your account does not include access to this section.';
}

/**
 * Where to send them instead: the nearest page that does the same job for
 * their role. A refusal that offers no way forward is a dead end, and a dead
 * end is what makes people email support.
 *
 * @return array{label:string, url:string, hint:string}
 */
function accessDeniedSuggestion(): array
{
    $base = APP_URL . '/index.php?page=';

    switch (getUserRole()) {
        case ROLE_OWNER:
            return [
                'label' => 'Go to my properties',
                'url'   => $base . 'my-properties',
                'hint'  => 'Your portfolio, its income and the enquiries it has attracted.',
            ];
        case ROLE_CUSTOMER:
            return [
                'label' => 'Go to my lease',
                'url'   => $base . 'my-lease',
                'hint'  => 'Your tenancy, payment history and open requests.',
            ];
        case ROLE_MAINTENANCE:
            return [
                'label' => 'Go to my jobs',
                'url'   => $base . 'maintenance',
                'hint'  => 'The maintenance requests currently assigned to you.',
            ];
    }

    return [
        'label' => 'Back to dashboard',
        'url'   => $base . 'dashboard',
        'hint'  => 'Everything your account covers, in one place.',
    ];
}
