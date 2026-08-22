<?php
/**
 * Record access scope — the commercial tables
 *
 * property_access.php answers level 3 for properties, maintenance requests,
 * enquiries and owner profiles. This file answers the same question for the
 * rest of the business: leases, payments, sales, reservations and customer
 * records.
 *
 * The arrangement is deliberately identical, because the two files are read
 * together and a second style would be a second set of mistakes:
 *
 *   xxxViewScope()   a SQL predicate plus its bound parameters, pushed into
 *                    the WHERE of the list query so the database — not the
 *                    controller's memory — decides which rows come back;
 *   canViewXxx()     the same rule applied to one row already fetched by id,
 *                    for the detail page and the update action;
 *   canManageXxx()   narrower still: reading a record never implies changing
 *                    it.
 *
 * The relationships are the ones the schema already models. Nothing is
 * inferred from the request, and every function fails closed — an account with
 * no linked owner/customer profile, an unrecognised role and a signed-out
 * visitor all resolve to `0 = 1` or false.
 *
 *   admin        everything
 *   agent        the properties assigned to them, plus the records they
 *                created or handled themselves
 *   owner        the properties registered to them, and the money on them
 *   customer     their own tenancies, payments, purchases and holds
 *   maintenance  nothing commercial at all
 *
 * Parameter names are prefixed `:ra_` so a scope can be merged into a filter
 * set without colliding with the module's own placeholders.
 */

// ─── Who is asking ─────────────────────────────────────────────────────

/**
 * The signed-in user id, or 0. Every scope below starts here, and 0 is what
 * turns the whole predicate into `0 = 1`.
 */
function recordScopeUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

// ─── Properties ────────────────────────────────────────────────────────

/**
 * SQL predicate restricting a `properties` query (aliased $p) to the rows the
 * signed-in user may work with in the management screens.
 *
 * Deliberately not applied inside Property::getAll() by default: that model
 * also serves the public site, where the visitor has no session and every
 * approved listing is meant to be visible. The staff list opts in by passing
 * the `scoped` filter, so a public page cannot accidentally inherit a
 * predicate that would empty it.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function propertyRecordScope(string $p = 'p'): array
{
    $userId = recordScopeUserId();
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        // Assignment, not branch membership: sharing an office is not the
        // same as being responsible for the listing.
        case ROLE_AGENT:
            return ["{$p}.agent_id = :ra_user", [':ra_user' => $userId]];

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["{$p}.owner_id = :ra_owner", [':ra_owner' => $ownerId]]
                : ['0 = 1', []];

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId
                ? ["EXISTS (SELECT 1 FROM leases ra_l
                             WHERE ra_l.property_id = {$p}.id
                               AND ra_l.customer_id = :ra_customer
                               AND ra_l.status = 'active')",
                   [':ra_customer' => $customerId]]
                : ['0 = 1', []];

        case ROLE_MAINTENANCE:
            return ["EXISTS (SELECT 1 FROM maintenance_requests ra_m
                             WHERE ra_m.property_id = {$p}.id
                               AND ra_m.assigned_to = :ra_user)",
                    [':ra_user' => $userId]];
    }

    return ['0 = 1', []];
}

/**
 * May the signed-in user edit, archive or otherwise change this property?
 *
 * canViewProperty() is wider — a tenant reads the home they rent, a
 * technician the address of a job — but nobody outside the office writes to
 * the register, and an agent writes only to their own listings.
 */
function canManageProperty(?array $property): bool
{
    if (!$property || !isLoggedIn()) {
        return false;
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;
        case ROLE_AGENT:
            return (int) ($property['agent_id'] ?? 0) === recordScopeUserId();
    }

    return false;
}

/**
 * Whether one property id sits inside the signed-in user's scope.
 *
 * For validating a submitted property_id before any other work is done — the
 * counterpart of canFileMaintenanceFor() for the commercial forms.
 */
function canActOnProperty(int $propertyId): bool
{
    if ($propertyId <= 0) {
        return false;
    }
    [$scope, $params] = propertyRecordScope('p');
    $stmt = getDBConnection()->prepare("
        SELECT 1 FROM properties p
        WHERE p.id = :ra_pid AND p.is_archived = 0 AND ({$scope})
        LIMIT 1
    ");
    $stmt->execute($params + [':ra_pid' => $propertyId]);
    return (bool) $stmt->fetchColumn();
}

// ─── Leases ────────────────────────────────────────────────────────────

/**
 * SQL predicate restricting a leases query to the rows the signed-in user may
 * read. Expects the leases table aliased $l and its properties join $p.
 *
 * An agent keeps the tenancies they wrote even if the property is later
 * reassigned, because the paperwork stays theirs to answer for.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function leaseViewScope(string $l = 'l', string $p = 'p'): array
{
    $userId = recordScopeUserId();
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        case ROLE_AGENT:
            return ["({$p}.agent_id = :ra_user OR {$l}.created_by = :ra_user)",
                    [':ra_user' => $userId]];

        // Either link is enough: leases.owner_id is stamped at creation and
        // properties.owner_id is the live relationship, and a transfer of one
        // must not hide the tenancy from the landlord holding the other.
        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["({$l}.owner_id = :ra_owner OR {$p}.owner_id = :ra_owner)",
                   [':ra_owner' => $ownerId]]
                : ['0 = 1', []];

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId
                ? ["{$l}.customer_id = :ra_customer", [':ra_customer' => $customerId]]
                : ['0 = 1', []];
    }

    return ['0 = 1', []];
}

/**
 * The row-level equivalent of leaseViewScope().
 *
 * Expects the row shape Lease::findById() returns, which carries the owning
 * property's owner_id and agent_id alongside the lease columns.
 */
function canViewLease(?array $lease): bool
{
    if (!$lease || !isLoggedIn()) {
        return false;
    }

    $userId = recordScopeUserId();

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;

        case ROLE_AGENT:
            return (int) ($lease['property_agent_id'] ?? 0) === $userId
                || (int) ($lease['created_by'] ?? 0) === $userId;

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId !== null
                && ((int) ($lease['owner_id'] ?? 0) === $ownerId
                    || (int) ($lease['property_owner_id'] ?? 0) === $ownerId);

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId !== null && (int) ($lease['customer_id'] ?? 0) === $customerId;
    }

    return false;
}

/**
 * Whether the signed-in user may renew, terminate or otherwise change a
 * tenancy. Owners and tenants watch a lease run; the agency moves it.
 */
function canManageLease(?array $lease): bool
{
    if (!$lease || !isLoggedIn()) {
        return false;
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;
        case ROLE_AGENT:
            $userId = recordScopeUserId();
            return (int) ($lease['property_agent_id'] ?? 0) === $userId
                || (int) ($lease['created_by'] ?? 0) === $userId;
    }

    return false;
}

// ─── Payments ──────────────────────────────────────────────────────────

/**
 * SQL predicate restricting a payments query to the rows the signed-in user
 * may read. Expects the payments table aliased $py and its properties join
 * $p (LEFT JOIN — a payment need not name a property).
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function paymentViewScope(string $py = 'py', string $p = 'p'): array
{
    $userId = recordScopeUserId();
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        // The money on their listings, plus anything they took in themselves —
        // a one-off receipt that names no property would otherwise vanish from
        // the desk that wrote it.
        case ROLE_AGENT:
            return ["({$p}.agent_id = :ra_user OR {$py}.received_by = :ra_user)",
                    [':ra_user' => $userId]];

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["{$p}.owner_id = :ra_owner", [':ra_owner' => $ownerId]]
                : ['0 = 1', []];

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId
                ? ["{$py}.customer_id = :ra_customer", [':ra_customer' => $customerId]]
                : ['0 = 1', []];
    }

    return ['0 = 1', []];
}

/**
 * The row-level equivalent of paymentViewScope(), for a receipt reached by id.
 *
 * This is the check that stops `?page=payments&action=receipt&id=` being
 * walked through the ledger: `payments.receipt` is held by every tenant so
 * they can print their own, which without this would print anyone's.
 */
function canViewPayment(?array $payment): bool
{
    if (!$payment || !isLoggedIn()) {
        return false;
    }

    $userId = recordScopeUserId();

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;

        case ROLE_AGENT:
            return (int) ($payment['property_agent_id'] ?? 0) === $userId
                || (int) ($payment['received_by'] ?? 0) === $userId;

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId !== null
                && (int) ($payment['property_owner_id'] ?? 0) === $ownerId;

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId !== null
                && (int) ($payment['customer_id'] ?? 0) === $customerId;
    }

    return false;
}

// ─── Sales ─────────────────────────────────────────────────────────────

/**
 * SQL predicate restricting a sales query to the rows the signed-in user may
 * read. Expects the sales table aliased $s and its properties join $p.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function saleViewScope(string $s = 's', string $p = 'p'): array
{
    $userId = recordScopeUserId();
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        // The deals they closed, and the deals on their listings — the
        // commission ledger is built from the first, the pipeline from both.
        case ROLE_AGENT:
            return ["({$s}.agent_id = :ra_user OR {$p}.agent_id = :ra_user)",
                    [':ra_user' => $userId]];

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["{$p}.owner_id = :ra_owner", [':ra_owner' => $ownerId]]
                : ['0 = 1', []];

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId
                ? ["{$s}.customer_id = :ra_customer", [':ra_customer' => $customerId]]
                : ['0 = 1', []];
    }

    return ['0 = 1', []];
}

/** The row-level equivalent of saleViewScope(). */
function canViewSale(?array $sale): bool
{
    if (!$sale || !isLoggedIn()) {
        return false;
    }

    $userId = recordScopeUserId();

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;

        case ROLE_AGENT:
            return (int) ($sale['agent_id'] ?? 0) === $userId
                || (int) ($sale['property_agent_id'] ?? 0) === $userId;

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId !== null
                && (int) ($sale['property_owner_id'] ?? 0) === $ownerId;

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId !== null
                && (int) ($sale['customer_id'] ?? 0) === $customerId;
    }

    return false;
}

// ─── Reservations ──────────────────────────────────────────────────────

/**
 * SQL predicate restricting a reservations query to the rows the signed-in
 * user may read. Expects the reservations table aliased $r and its properties
 * join $p.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function reservationViewScope(string $r = 'r', string $p = 'p'): array
{
    $userId = recordScopeUserId();
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        case ROLE_AGENT:
            return ["({$p}.agent_id = :ra_user OR {$r}.created_by = :ra_user)",
                    [':ra_user' => $userId]];

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["{$p}.owner_id = :ra_owner", [':ra_owner' => $ownerId]]
                : ['0 = 1', []];

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId
                ? ["{$r}.customer_id = :ra_customer", [':ra_customer' => $customerId]]
                : ['0 = 1', []];
    }

    return ['0 = 1', []];
}

/** The row-level equivalent of reservationViewScope(). */
function canViewReservation(?array $reservation): bool
{
    if (!$reservation || !isLoggedIn()) {
        return false;
    }

    $userId = recordScopeUserId();

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;

        case ROLE_AGENT:
            return (int) ($reservation['property_agent_id'] ?? 0) === $userId
                || (int) ($reservation['created_by'] ?? 0) === $userId;

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId !== null
                && (int) ($reservation['property_owner_id'] ?? 0) === $ownerId;

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId !== null
                && (int) ($reservation['customer_id'] ?? 0) === $customerId;
    }

    return false;
}

/**
 * Whether the signed-in user may cancel or confirm a hold. A customer sees
 * their own reservation; releasing or converting it is the agency's call.
 */
function canManageReservation(?array $reservation): bool
{
    if (!$reservation || !isLoggedIn()) {
        return false;
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;
        case ROLE_AGENT:
            $userId = recordScopeUserId();
            return (int) ($reservation['property_agent_id'] ?? 0) === $userId
                || (int) ($reservation['created_by'] ?? 0) === $userId;
    }

    return false;
}

// ─── Owner records ─────────────────────────────────────────────────────

/**
 * SQL predicate restricting an owners query to the landlords the signed-in
 * user deals with. Expects the owners table aliased $o.
 *
 * The owner profile page is not a contact card: it carries the bank name and
 * account number, the commission rate, the portfolio and the total income
 * received to date. So an agent's directory holds the landlords whose
 * properties they actually manage, and nobody else's.
 *
 * The option list a property form is built from (Owner::getAllSimple) stays
 * unscoped on purpose — it is id and name only, and an agent registering a
 * property has to be able to attach it to any landlord already on file.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function ownerViewScope(string $o = 'o'): array
{
    $userId = recordScopeUserId();
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        case ROLE_AGENT:
            return ["EXISTS (SELECT 1 FROM properties ra_op
                             WHERE ra_op.owner_id = {$o}.id
                               AND ra_op.agent_id = :ra_user)",
                    [':ra_user' => $userId]];

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["{$o}.id = :ra_owner", [':ra_owner' => $ownerId]]
                : ['0 = 1', []];
    }

    return ['0 = 1', []];
}

// ─── Customer records ──────────────────────────────────────────────────

/**
 * SQL predicate restricting a customers query to the people the signed-in
 * user deals with. Expects the customers table aliased $c.
 *
 * An agent's clients are the ones they entered plus the ones they have
 * business with — a tenancy, a sale or a hold touching a property assigned to
 * them. The three EXISTS clauses are what make "assigned to that specific
 * agent" true of the client list as well as the deal list; each is a keyed
 * lookup, so the cost does not grow with the size of the directory.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function customerViewScope(string $c = 'c'): array
{
    $userId = recordScopeUserId();
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        case ROLE_AGENT:
            return ["({$c}.created_by = :ra_user
                     OR EXISTS (SELECT 1 FROM leases ra_l
                                  JOIN properties ra_lp ON ra_l.property_id = ra_lp.id
                                 WHERE ra_l.customer_id = {$c}.id
                                   AND (ra_lp.agent_id = :ra_user OR ra_l.created_by = :ra_user))
                     OR EXISTS (SELECT 1 FROM sales ra_s
                                  JOIN properties ra_sp ON ra_s.property_id = ra_sp.id
                                 WHERE ra_s.customer_id = {$c}.id
                                   AND (ra_s.agent_id = :ra_user OR ra_sp.agent_id = :ra_user))
                     OR EXISTS (SELECT 1 FROM reservations ra_r
                                  JOIN properties ra_rp ON ra_r.property_id = ra_rp.id
                                 WHERE ra_r.customer_id = {$c}.id
                                   AND (ra_rp.agent_id = :ra_user OR ra_r.created_by = :ra_user)))",
                    [':ra_user' => $userId]];

        // Held for completeness. A customer does not carry `customers.view`,
        // so this arm is only reachable if the matrix ever grants it — and
        // when it does, it must mean their own record and no one else's.
        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId
                ? ["{$c}.id = :ra_customer", [':ra_customer' => $customerId]]
                : ['0 = 1', []];
    }

    return ['0 = 1', []];
}

/**
 * The row-level equivalent of customerViewScope().
 *
 * An agent's answer needs the same three relationship tests the list uses, so
 * it asks the database with the same predicate rather than keeping a second,
 * drifting copy of the rule in PHP. One indexed lookup.
 */
function canViewCustomer(?array $customer): bool
{
    if (!$customer || !isLoggedIn() || empty($customer['id'])) {
        return false;
    }

    if (hasRole(ROLE_ADMIN)) {
        return true;
    }

    if (getUserRole() === ROLE_CUSTOMER) {
        $customerId = currentCustomerId();
        return $customerId !== null && (int) $customer['id'] === $customerId;
    }

    if (getUserRole() !== ROLE_AGENT) {
        return false;
    }

    [$scope, $params] = customerViewScope('c');
    $stmt = getDBConnection()->prepare(
        "SELECT 1 FROM customers c WHERE c.id = :ra_cid AND ({$scope}) LIMIT 1"
    );
    $stmt->execute($params + [':ra_cid' => (int) $customer['id']]);
    return (bool) $stmt->fetchColumn();
}

/** Whether one customer id sits inside the scope, for validating a submission. */
function canActOnCustomer(int $customerId): bool
{
    return $customerId > 0 && canViewCustomer(['id' => $customerId]);
}

// ─── Explaining the scope ──────────────────────────────────────────────
// As in property_access.php, the wording sits beside the rule so the two are
// changed together. A user shown four rows out of four hundred should be able
// to read why, rather than assume the page is broken.

/** One line under a scoped commercial list saying whose records are shown. */
function recordScopeHint(string $noun): string
{
    switch (getUserRole()) {
        case ROLE_ADMIN:
            return "Every {$noun} on the agency's books.";
        case ROLE_AGENT:
            return ucfirst($noun) . 's on your properties, and those you handled yourself.';
        case ROLE_OWNER:
            return ucfirst($noun) . 's on the properties registered to you.';
        case ROLE_CUSTOMER:
            return 'Your own ' . $noun . 's.';
    }
    return ucfirst($noun) . 's linked to your account.';
}
