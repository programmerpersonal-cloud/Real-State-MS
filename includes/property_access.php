<?php
/**
 * Property access scope
 *
 * Answers one question, in one place: which properties may the signed-in user
 * act on? The options a form offers, the rows a list query returns and the row
 * an INSERT is permitted to write all read their predicate from here, so a
 * hidden <option> and a rejected write can never disagree.
 *
 * The relationships are the ones the schema already models — nothing new is
 * invented, and nothing is inferred from the request:
 *
 *   admin        every property
 *   agent        properties assigned to them        properties.agent_id
 *   owner        properties they own                properties.owner_id → owners.user_id
 *   customer     properties they currently lease    leases.customer_id, status = 'active'
 *   maintenance  properties they hold an open job on
 *                                                   maintenance_requests.assigned_to
 *
 * Every function fails closed. An account with no linked owner/customer
 * profile, an unrecognised role and a signed-out visitor all resolve to the
 * predicate `0 = 1`, so a caller that forgets to check still gets nothing
 * rather than everything.
 */

// ─── Profile lookups ───────────────────────────────────────────────────
// A user account and the owner/customer profile it represents are separate
// rows. Both lookups are cached per request because the scope is rebuilt
// several times on a single page (form options, list query, row count).

/**
 * owners.id backing the signed-in user, or null when the account is not
 * linked to an owner profile.
 */
function currentOwnerId(): ?int
{
    static $cache = [];
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return null;
    }
    if (!array_key_exists($userId, $cache)) {
        $stmt = getDBConnection()->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $cache[$userId] = ((int) $stmt->fetchColumn()) ?: null;
    }
    return $cache[$userId];
}

/**
 * customers.id backing the signed-in user, or null when the account is not
 * linked to a customer profile.
 */
function currentCustomerId(): ?int
{
    static $cache = [];
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return null;
    }
    if (!array_key_exists($userId, $cache)) {
        $stmt = getDBConnection()->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $cache[$userId] = ((int) $stmt->fetchColumn()) ?: null;
    }
    return $cache[$userId];
}

// ─── Seeing a property ─────────────────────────────────────────────────

/**
 * Is this property owned by the signed-in user?
 *
 * The comparison is owners.id against properties.owner_id — never user id
 * against owner id, which are different sequences that would silently match
 * the wrong people.
 */
function ownsProperty(?array $property): bool
{
    if (!$property || getUserRole() !== ROLE_OWNER) {
        return false;
    }
    $ownerId = currentOwnerId();

    return $ownerId !== null
        && isset($property['owner_id'])
        && (int) $property['owner_id'] === $ownerId;
}

/** Does the signed-in user hold a live lease on this property? */
function leasesProperty(?array $property): bool
{
    $customerId = currentCustomerId();
    if (!$property || $customerId === null || empty($property['id'])) {
        return false;
    }
    $stmt = getDBConnection()->prepare("
        SELECT 1 FROM leases
        WHERE property_id = :pid AND customer_id = :cid AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([':pid' => (int) $property['id'], ':cid' => $customerId]);
    return (bool) $stmt->fetchColumn();
}

/** Is the signed-in technician working a job at this property? */
function worksOnProperty(?array $property): bool
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$property || !$userId || empty($property['id'])) {
        return false;
    }
    $stmt = getDBConnection()->prepare("
        SELECT 1 FROM maintenance_requests
        WHERE property_id = :pid AND assigned_to = :uid
        LIMIT 1
    ");
    $stmt->execute([':pid' => (int) $property['id'], ':uid' => $userId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * May the signed-in user open this property's detail page?
 *
 * `properties.show` is granted to every signed-in role, which is what makes
 * this function necessary: holding the permission means "this page is part of
 * your job", not "every property is". Staff work the whole register; everyone
 * else reaches a property through a relationship to it, or through the public
 * listing that is already visible to the world.
 */
function canViewProperty(?array $property): bool
{
    if (!$property) {
        return false;
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
        case ROLE_AGENT:
            return true;

        case ROLE_OWNER:
            return ownsProperty($property);

        // A buyer browses. Anything on the public site is already readable
        // without signing in, so refusing it here would be theatre — but an
        // unapproved or archived listing is not public, and a tenant still
        // reaches the home they rent whatever its listing state.
        case ROLE_CUSTOMER:
            return propertyIsPubliclyVisible($property) || leasesProperty($property);

        case ROLE_MAINTENANCE:
            return worksOnProperty($property);
    }

    return false;
}

// ─── Filing a maintenance request ──────────────────────────────────────

/**
 * SQL predicate restricting the `properties` table (aliased $alias) to the
 * rows the signed-in user may file a maintenance request against.
 *
 * Returned as a fragment rather than a list of ids so it can be pushed into
 * the INSERT itself: the database, not the caller, decides whether the row
 * is allowed to exist.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function maintenancePropertyScope(string $alias = 'p'): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        // An agent manages what has been assigned to them. Branch membership
        // deliberately does not widen this: being in the same office is not
        // the same as being responsible for the property.
        case ROLE_AGENT:
            return ["{$alias}.agent_id = :scope_user", [':scope_user' => $userId]];

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["{$alias}.owner_id = :scope_owner", [':scope_owner' => $ownerId]]
                : ['0 = 1', []];

        // A tenant's property is the one they hold a live lease on. Expired
        // and terminated leases drop out, so moving out ends the ability to
        // file against the address.
        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $customerId
                ? ["EXISTS (SELECT 1 FROM leases scope_l
                             WHERE scope_l.property_id = {$alias}.id
                               AND scope_l.customer_id = :scope_customer
                               AND scope_l.status = 'active')",
                   [':scope_customer' => $customerId]]
                : ['0 = 1', []];

        // A technician on site may report a second fault at the address they
        // are already working on, and nowhere else.
        case ROLE_MAINTENANCE:
            return ["EXISTS (SELECT 1 FROM maintenance_requests scope_m
                             WHERE scope_m.property_id = {$alias}.id
                               AND scope_m.assigned_to = :scope_user
                               AND scope_m.status NOT IN ('completed','rejected','cancelled'))",
                    [':scope_user' => $userId]];
    }

    return ['0 = 1', []];
}

/**
 * The properties the signed-in user may file against, for populating a form.
 *
 * This is a convenience for the UI only. It is not the security boundary —
 * MaintenanceRequest::create() re-applies the same scope when it writes.
 */
function maintenanceSelectableProperties(): array
{
    [$scope, $params] = maintenancePropertyScope('p');
    $stmt = getDBConnection()->prepare("
        SELECT p.id, p.title, p.property_code
        FROM properties p
        WHERE p.is_archived = 0 AND ({$scope})
        ORDER BY p.title
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Whether the signed-in user may file a request against one specific property.
 *
 * Used to reject a submitted property id with a clear message before any file
 * upload is processed.
 */
function canFileMaintenanceFor(int $propertyId): bool
{
    if ($propertyId <= 0) {
        return false;
    }
    [$scope, $params] = maintenancePropertyScope('p');
    $stmt = getDBConnection()->prepare("
        SELECT 1 FROM properties p
        WHERE p.id = :scope_pid AND p.is_archived = 0 AND ({$scope})
        LIMIT 1
    ");
    $stmt->execute($params + [':scope_pid' => $propertyId]);
    return (bool) $stmt->fetchColumn();
}

// ─── Reading and working on requests ───────────────────────────────────

/**
 * SQL predicate restricting a maintenance_requests query to the rows the
 * signed-in user may read. Expects the request table aliased $m and its
 * properties join aliased $p.
 *
 * Wider than the filing scope on purpose: a request stays readable to the
 * person who filed it and to the technician holding it, even after the
 * underlying relationship has moved on.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function maintenanceViewScope(string $m = 'm', string $p = 'p'): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        case ROLE_AGENT:
            return ["({$p}.agent_id = :scope_user
                     OR {$m}.reported_by = :scope_user
                     OR {$m}.assigned_to = :scope_user)", [':scope_user' => $userId]];

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["{$p}.owner_id = :scope_owner", [':scope_owner' => $ownerId]]
                : ['0 = 1', []];

        // A tenant sees their own reports, not everything filed against the
        // building they live in — an owner's request can carry costs and
        // notes that are none of the tenant's business.
        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            $params = [':scope_user' => $userId];
            if (!$customerId) {
                return ["{$m}.reported_by = :scope_user", $params];
            }
            $params[':scope_customer'] = $customerId;
            return ["({$m}.customer_id = :scope_customer OR {$m}.reported_by = :scope_user)", $params];

        case ROLE_MAINTENANCE:
            return ["({$m}.assigned_to = :scope_user OR {$m}.reported_by = :scope_user)",
                    [':scope_user' => $userId]];
    }

    return ['0 = 1', []];
}

/**
 * The row-level equivalent of maintenanceViewScope(), for a request already
 * fetched by id.
 *
 * Expects the row shape MaintenanceRequest::findById() returns, which carries
 * the owning property's owner_id and agent_id alongside the request columns.
 */
function canViewMaintenanceRequest(array $request): bool
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return false;
    }

    $reportedBy = (int) ($request['reported_by'] ?? 0);
    $assignedTo = (int) ($request['assigned_to'] ?? 0);

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;

        case ROLE_AGENT:
            return (int) ($request['property_agent_id'] ?? 0) === $userId
                || $reportedBy === $userId
                || $assignedTo === $userId;

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId !== null
                && (int) ($request['property_owner_id'] ?? 0) === $ownerId;

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            return $reportedBy === $userId
                || ($customerId !== null && (int) ($request['customer_id'] ?? 0) === $customerId);

        case ROLE_MAINTENANCE:
            return $assignedTo === $userId || $reportedBy === $userId;
    }

    return false;
}

/**
 * Whether the signed-in user may change a request — status, costs, notes,
 * technician assignment.
 *
 * Reading a request never implies editing it: an owner and a tenant can watch
 * a job progress, but only the people running it can move it.
 */
function canManageMaintenanceRequest(array $request): bool
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return false;
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;

        case ROLE_AGENT:
            return (int) ($request['property_agent_id'] ?? 0) === $userId;

        case ROLE_MAINTENANCE:
            return (int) ($request['assigned_to'] ?? 0) === $userId;
    }

    return false;
}

/**
 * Whether the signed-in user may assign a technician to a request. Narrower
 * than canManageMaintenanceRequest(): a technician updates the job they hold
 * but does not hand it to someone else.
 */
function canAssignMaintenanceRequest(array $request): bool
{
    return hasRole(ROLE_ADMIN, ROLE_AGENT) && canManageMaintenanceRequest($request);
}

// ─── Owner profiles ────────────────────────────────────────────────────

/**
 * Whether the signed-in user may read one owner's profile page.
 *
 * The page carries the owner's portfolio and their total income to date, so
 * holding `owners.show` cannot be the whole answer: staff may read any of
 * them, an owner may read exactly one — their own. Without this, an owner
 * could walk `?id=` through the table and read every landlord's earnings.
 */
function canViewOwnerProfile(array $owner): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    if (hasRole(ROLE_ADMIN, ROLE_AGENT)) {
        return true;
    }

    if (getUserRole() === ROLE_OWNER) {
        $ownerId = currentOwnerId();
        return $ownerId !== null && (int) ($owner['id'] ?? 0) === $ownerId;
    }

    return false;
}

// ─── Enquiries ─────────────────────────────────────────────────────────
// An enquiry is the one record every role has a legitimate but different
// interest in: the agency works the lead, the owner wants to know their
// property is attracting interest, the person who sent it wants an answer.
// So the module is open to all three and the rows are cut differently for
// each, rather than the module being locked to staff.

/**
 * SQL predicate restricting an inquiries query to the rows the signed-in user
 * may read. Expects the inquiries table aliased $i and its properties join
 * aliased $p (LEFT JOIN — an enquiry need not name a property).
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function inquiryViewScope(string $i = 'i', string $p = 'p'): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return ['0 = 1', []];
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return ['1 = 1', []];

        // An agent works their own properties, whatever has been handed to
        // them by name, and the unclaimed pool. That last clause is what keeps
        // lead capture working: a walk-in enquiry that names no property and
        // has been assigned to nobody must stay visible to the whole desk,
        // otherwise new business falls through the floor unseen.
        case ROLE_AGENT:
            return ["({$p}.agent_id = :scope_user
                     OR {$i}.assigned_to = :scope_user
                     OR ({$i}.assigned_to IS NULL AND {$i}.property_id IS NULL))",
                    [':scope_user' => $userId]];

        // Enquiries about the owner's own properties. A general enquiry that
        // names no property is agency business, not theirs, so the NULL
        // property_id rows drop out here.
        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId
                ? ["{$p}.owner_id = :scope_owner", [':scope_owner' => $ownerId]]
                : ['0 = 1', []];

        // The sender's own correspondence. Matched on the linked customer
        // profile and on the address it was sent from, because an enquiry
        // submitted from the public site carries an email but no customer_id
        // — the visitor may not have had an account yet when they wrote in.
        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            $email      = trim((string) ($_SESSION['user_email'] ?? ''));
            $clauses = [];
            $params  = [];

            if ($customerId) {
                $clauses[] = "{$i}.customer_id = :scope_customer";
                $params[':scope_customer'] = $customerId;
            }
            if ($email !== '') {
                $clauses[] = "{$i}.email = :scope_email";
                $params[':scope_email'] = $email;
            }

            return $clauses ? ['(' . implode(' OR ', $clauses) . ')', $params] : ['0 = 1', []];
    }

    // Technicians have no business in the enquiry list, and neither has
    // anything unrecognised.
    return ['0 = 1', []];
}

/**
 * The row-level equivalent of inquiryViewScope(), for an enquiry already
 * fetched by id.
 *
 * Expects the row shape Inquiry::findById() returns, which carries the
 * property's owner_id and agent_id alongside the enquiry columns.
 */
function canViewInquiry(array $inquiry): bool
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return false;
    }

    switch (getUserRole()) {
        case ROLE_ADMIN:
            return true;

        case ROLE_AGENT:
            return (int) ($inquiry['property_agent_id'] ?? 0) === $userId
                || (int) ($inquiry['assigned_to'] ?? 0) === $userId
                || (empty($inquiry['assigned_to']) && empty($inquiry['property_id']));

        case ROLE_OWNER:
            $ownerId = currentOwnerId();
            return $ownerId !== null
                && (int) ($inquiry['property_owner_id'] ?? 0) === $ownerId;

        case ROLE_CUSTOMER:
            $customerId = currentCustomerId();
            $email      = trim((string) ($_SESSION['user_email'] ?? ''));
            return ($customerId !== null && (int) ($inquiry['customer_id'] ?? 0) === $customerId)
                || ($email !== '' && strcasecmp(trim((string) ($inquiry['email'] ?? '')), $email) === 0);
    }

    return false;
}

/** One line under the enquiry list saying which enquiries are shown. */
function inquiryScopeHint(): string
{
    switch (getUserRole()) {
        case ROLE_ADMIN:    return 'Every enquiry received by the agency.';
        case ROLE_AGENT:    return 'Enquiries on your properties, assigned to you, or not yet claimed.';
        case ROLE_OWNER:    return 'Enquiries about the properties registered to you.';
        case ROLE_CUSTOMER: return 'Enquiries you have sent us.';
    }
    return 'Enquiries linked to your account.';
}

/** Why the enquiry list came back empty, phrased for the role reading it. */
function inquiryEmptyScopeMessage(): string
{
    switch (getUserRole()) {
        case ROLE_ADMIN:
            return 'No enquiries have come in yet. They arrive here from the contact form and the property listings.';
        case ROLE_AGENT:
            return 'Nothing in your queue. Enquiries appear here when they concern a property assigned to you, are assigned to you by name, or arrive unclaimed.';
        case ROLE_OWNER:
            return 'No enquiries have been received about your properties yet.';
        case ROLE_CUSTOMER:
            return 'You have not sent us any enquiries yet. Ask about a property from its listing page and the conversation will appear here.';
    }
    return 'No enquiries are linked to your account.';
}

// ─── Explaining the scope ──────────────────────────────────────────────
// The wording lives next to the rules it describes so the two are changed
// together. A user who is shown three properties out of two hundred should
// be able to read why, and a user shown none should not be left guessing
// whether the page is broken.

/** One line under the property field saying which properties are listed. */
function maintenanceScopeHint(): string
{
    switch (getUserRole()) {
        case ROLE_ADMIN:       return 'Any property in the portfolio.';
        case ROLE_AGENT:       return 'Properties assigned to you.';
        case ROLE_OWNER:       return 'Properties registered to you as owner.';
        case ROLE_CUSTOMER:    return 'The property you currently lease.';
        case ROLE_MAINTENANCE: return 'Properties you have an open job on.';
    }
    return 'Properties linked to your account.';
}

/** Why the property list came back empty, phrased for the role reading it. */
function maintenanceEmptyScopeMessage(): string
{
    switch (getUserRole()) {
        case ROLE_ADMIN:
            return 'No properties have been added yet. Add one before reporting an issue against it.';
        case ROLE_AGENT:
            return 'No properties are assigned to you yet. An administrator assigns properties to agents; ask the office to add yours.';
        case ROLE_OWNER:
            return 'No properties are registered to your owner profile. Contact the office if you believe this is wrong.';
        case ROLE_CUSTOMER:
            return 'You have no active lease. Issues can only be reported for a property you currently rent.';
        case ROLE_MAINTENANCE:
            return 'You have no open jobs. Issues can only be reported for a property you are currently working on.';
    }
    return 'Your account is not linked to a property.';
}
