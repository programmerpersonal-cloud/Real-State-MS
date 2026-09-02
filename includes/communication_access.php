<?php
/**
 * Communication access scope
 *
 * Answers one question, in one place: who may this user talk to, and which
 * conversations may they touch? The recipient list a form offers, the rows the
 * inbox query returns and the row a send is permitted to write all read their
 * predicate from here, so a hidden <option> and a rejected POST can never
 * disagree.
 *
 * Arranged to match includes/property_access.php and includes/record_access.php
 * deliberately: the three files are read together, scope functions return
 * [predicate, params], and everything fails closed. The parameter prefix here
 * is `:ca_` so a communication scope can be merged into a filter query without
 * colliding with the `:scope_` and `:ra_` names the other two use.
 *
 * ─── THE THREE RULES THAT MATTER ────────────────────────────────────────
 *
 * 1. A REAL AGENT RELATIONSHIP ALWAYS WINS.
 *    Counterparts are resolved from the relationships the schema actually
 *    models — owner → property → agent, tenant → active lease → property →
 *    agent, buyer → sale → agent, technician → assigned request → property →
 *    agent. Nothing is inferred from the request and no relationship is
 *    invented.
 *
 * 2. ADMINISTRATORS ARE A FALLBACK, NOT A SHORTCUT.
 *    When — and only when — step 1 yields nobody, the office becomes the
 *    counterpart. Administrators are never added *alongside* a resolved agent.
 *    The `*` wildcard in permissionMatrix() governs administrative
 *    authorization, which modules and actions an administrator may reach. It
 *    is NOT a communication relationship and must never be read as one: an
 *    administrator who could message every account in the database by virtue
 *    of holding `*` would make the whole of this file decorative.
 *
 * 3. AUTHORIZATION IS ALWAYS LIVE.
 *    Every answer is re-derived from the CURRENT role, the CURRENT account
 *    state and the CURRENT business rows. Nothing here reads
 *    conversation_participants.role_at_join, which is a historical snapshot
 *    kept for the audit trail and for rendering an old thread honestly. A
 *    tenant whose lease ends, an agent who is deactivated and a technician
 *    whose job is reassigned all lose access, while their participant row
 *    survives.
 *
 * Note that rule 3 is why communicationActor() exists rather than this file
 * calling getUserRole(). That helper reads $_SESSION, which was written at
 * login and is therefore a snapshot of who this person was then — an account
 * deactivated an hour ago still carries a valid-looking session. For the rest
 * of the application that is an acceptable trade; for deciding who may read
 * correspondence about someone's tenancy it is not.
 */

// ─── Who is asking ─────────────────────────────────────────────────────

/**
 * The signed-in user as the database describes them *now*: id, role name and
 * active flag, or null when there is no usable actor.
 *
 * Returns null — which every caller below treats as "refuse" — when the
 * session names an account that has since been deactivated or deleted. One
 * cached query per request; the scope is rebuilt several times on a single
 * page (recipient list, inbox query, unread count) and each rebuild asks.
 *
 * @return array{id:int, role:string, full_name:string}|null
 */
function communicationActor(): ?array
{
    static $cache = [];

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return null;
    }

    if (!array_key_exists($userId, $cache)) {
        $stmt = getDBConnection()->prepare("
            SELECT u.id, u.full_name, r.name AS role
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = :uid AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();

        $cache[$userId] = $row
            ? ['id' => (int) $row['id'], 'role' => (string) $row['role'], 'full_name' => (string) $row['full_name']]
            : null;
    }

    return $cache[$userId];
}

/** The signed-in user's live role name, or '' when there is no usable actor. */
function communicationActorRole(): string
{
    return communicationActor()['role'] ?? '';
}

/**
 * May this user use the communication module at all?
 *
 * Both halves: the account must be live (rule 3) and the role must hold the
 * permission (permissions.php). Held apart from the scope functions because
 * "your account is disabled" and "you have nobody to talk to" are different
 * answers and the UI says different things about them.
 */
function canUseCommunication(): bool
{
    return communicationActor() !== null && can('messages.view');
}

// ─── The relationship edges, written once ──────────────────────────────
// Each builder below returns a SQL fragment meaning "this subject reaches an
// agent by this route". They take the subject and the agent as *expressions*
// rather than values so the same rule can be asked in both directions:
//
//   subject = ':ca_me', agent = 'u.id'   → "which agents do I reach?"
//   subject = 'u.id',   agent = live-set → "does this user reach any agent?"
//
// The second form is what lets the administrator scope find the accounts that
// have nobody — without keeping a second, drifting copy of the relationship
// rules. Both expressions are literals written in this file; neither is ever
// built from request input.

/**
 * The set of agent user ids that are still agents and still active.
 *
 * Used as the right-hand side when the question is "any agent at all". Role is
 * matched by name through the roles table rather than by a hard-coded id, the
 * same way notifyAdmins() does it, so renumbering the seed data cannot quietly
 * change who counts as an agent.
 */
function communicationLiveAgentIds(): string
{
    return "(SELECT ca_ag.id FROM users ca_ag
                JOIN roles ca_agr ON ca_ag.role_id = ca_agr.id
               WHERE ca_ag.is_active = 1 AND ca_agr.name = '" . ROLE_AGENT . "')";
}

/** Owner → property they own → that property's agent. */
function communicationOwnerAgentEdge(string $subject, string $agentMatch): string
{
    return "EXISTS (SELECT 1
                      FROM owners ca_o
                      JOIN properties ca_op ON ca_op.owner_id = ca_o.id
                     WHERE ca_o.user_id = {$subject}
                       AND ca_op.agent_id {$agentMatch})";
}

/**
 * Customer → agent, by any of the three routes a customer can have one.
 *
 * Each of the three routes is filtered by what makes it a live relationship,
 * and the three answers are different:
 *
 *   tenancy      `status = 'active'` — moving out ends the conversation.
 *   sale         no filter. A completed purchase is permanent: the agent who
 *                sold you the flat stays reachable about it years later, and
 *                a cancelled sale is exactly when a buyer most needs to ask
 *                why.
 *   reservation  `status IN ('active','confirmed')` — a hold that has expired
 *                or been cancelled is over. Without this a seven-day
 *                reservation taken once would keep a channel open forever,
 *                which is the same mistake as never checking whether a lease
 *                is still running.
 *
 * The sale arm reads sales.agent_id first and falls back to the property's
 * agent, because 1 of the 3 sales in this database has no agent_id of its own.
 */
function communicationCustomerAgentEdge(string $subject, string $agentMatch): string
{
    return "(EXISTS (SELECT 1
                       FROM customers ca_c
                       JOIN leases ca_l ON ca_l.customer_id = ca_c.id
                       JOIN properties ca_lp ON ca_l.property_id = ca_lp.id
                      WHERE ca_c.user_id = {$subject}
                        AND ca_l.status = 'active'
                        AND ca_lp.agent_id {$agentMatch})
          OR EXISTS (SELECT 1
                       FROM customers ca_c2
                       JOIN sales ca_s ON ca_s.customer_id = ca_c2.id
                       LEFT JOIN properties ca_sp ON ca_s.property_id = ca_sp.id
                      WHERE ca_c2.user_id = {$subject}
                        AND (ca_s.agent_id {$agentMatch} OR ca_sp.agent_id {$agentMatch}))
          OR EXISTS (SELECT 1
                       FROM customers ca_c3
                       JOIN reservations ca_rv ON ca_rv.customer_id = ca_c3.id
                       JOIN properties ca_rp ON ca_rv.property_id = ca_rp.id
                      WHERE ca_c3.user_id = {$subject}
                        AND ca_rv.status IN ('active','confirmed')
                        AND ca_rp.agent_id {$agentMatch}))";
}

/**
 * Technician → assigned request → that property's agent.
 *
 * Every assigned request counts, not only open ones: a job closed last week is
 * exactly what a technician and an agent still need to discuss.
 */
function communicationMaintenanceAgentEdge(string $subject, string $agentMatch): string
{
    return "EXISTS (SELECT 1
                      FROM maintenance_requests ca_m
                      JOIN properties ca_mp ON ca_m.property_id = ca_mp.id
                     WHERE ca_m.assigned_to = {$subject}
                       AND ca_mp.agent_id {$agentMatch})";
}

/**
 * The one edge belonging to a given portal role, or null for a role that has
 * none (staff reach people through the mirror image, not through an edge of
 * their own).
 *
 * Every caller that needs "does this role reach an agent" goes through here,
 * so the mapping from role to relationship exists once. Three callers rely on
 * it — the signed-in user's own scope, the administrator scope's search for
 * accounts with nobody, and the pairwise check used when deciding who to
 * notify — and before this they would have been three copies free to drift.
 */
function communicationAgentEdgeForRole(string $role, string $subject, string $agentMatch): ?string
{
    switch ($role) {
        case ROLE_OWNER:
            return communicationOwnerAgentEdge($subject, $agentMatch);
        case ROLE_CUSTOMER:
            return communicationCustomerAgentEdge($subject, $agentMatch);
        case ROLE_MAINTENANCE:
            return communicationMaintenanceAgentEdge($subject, $agentMatch);
    }

    return null;
}

/**
 * "This user reaches at least one live agent" — the edges above, chosen by the
 * user's own current role.
 *
 * Written from the perspective of a `users` row aliased $u joined to `roles`
 * aliased $r, so it can be dropped into the administrator scope to find the
 * accounts that have nobody. This is the *negation* of it that produces the
 * fallback population, and deriving it from the same builders is the whole
 * point: a relationship added later widens the agent scope and narrows the
 * admin fallback in one edit rather than two.
 */
function communicationHasAgentEdge(string $u = 'u', string $r = 'r'): string
{
    $any   = 'IN ' . communicationLiveAgentIds();
    $arms  = [];

    foreach (communicationFallbackRoles() as $role) {
        $edge = communicationAgentEdgeForRole($role, "{$u}.id", $any);
        if ($edge !== null) {
            $arms[] = "({$r}.name = '" . $role . "' AND " . $edge . ")";
        }
    }

    return '(' . implode("\n          OR ", $arms) . ')';
}

// ─── Step 1 of the resolution: the real agent relationship ─────────────

/**
 * The agent-relationship half of the contact scope, before any fallback.
 *
 * Returned separately from messageContactScope() because the difference
 * between "has an agent" and "has nobody" is precisely what decides whether
 * the fallback fires — and because the verification tool needs to be able to
 * assert that the fallback did *not* fire when an agent exists.
 *
 * Expects a `users` query aliased $u joined to `roles` aliased $r.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function messageAgentScope(string $u = 'u', string $r = 'r'): array
{
    $actor = communicationActor();
    if ($actor === null) {
        return ['0 = 1', []];
    }

    $me     = ':ca_me';
    $params = [':ca_me' => $actor['id']];
    $agent  = "= {$u}.id";

    switch ($actor['role']) {

        // A portal user reaches the agents on the other end of their own
        // edge. The counterpart's role is checked live in the outer query, so
        // an agent account that has since been demoted stops matching.
        case ROLE_OWNER:
        case ROLE_CUSTOMER:
        case ROLE_MAINTENANCE:
            return [
                "{$r}.name = '" . ROLE_AGENT . "' AND "
                    . communicationAgentEdgeForRole($actor['role'], $me, $agent),
                $params,
            ];

        // The mirror image: the people on the other end of the same edges,
        // seen from the agent's side. Administrators are included here as a
        // genuine relationship rather than a fallback — an agent and the
        // office they work for are colleagues, and the brief names Admins as
        // an agent counterpart outright.
        case ROLE_AGENT:
            $mirror = ["{$r}.name = '" . ROLE_ADMIN . "'"];
            foreach (communicationFallbackRoles() as $role) {
                $edge = communicationAgentEdgeForRole($role, "{$u}.id", "= {$me}");
                if ($edge !== null) {
                    $mirror[] = "({$r}.name = '" . $role . "' AND " . $edge . ")";
                }
            }
            return ['(' . implode("\n                  OR ", $mirror) . ')', $params];

        // An administrator reaches the staff, plus exactly the people who have
        // nobody else — the population the fallback in step 3 sends to them.
        // NOT the whole user table: a tenant who has an agent is their agent's
        // correspondent, and an administrator wanting to reach them goes
        // through the record, not around it.
        case ROLE_ADMIN:
            return [
                "({$r}.name IN ('" . ROLE_AGENT . "','" . ROLE_ADMIN . "')
                  OR NOT " . communicationHasAgentEdge($u, $r) . ")",
                $params,
            ];
    }

    return ['0 = 1', []];
}

/**
 * The roles the administrator fallback exists for.
 *
 * Staff are absent by design. An agent already reaches the office through the
 * ROLE_ADMIN arm of their own scope, so there is nothing for them to fall back
 * *to*; and an administrator with no counterparts has genuinely run out of
 * people rather than lost a relationship. Falling back would, for both, mean
 * inventing an edge — which is exactly what rule 2 forbids.
 *
 * @return string[]
 */
function communicationFallbackRoles(): array
{
    return [ROLE_OWNER, ROLE_CUSTOMER, ROLE_MAINTENANCE];
}

/**
 * Does the signed-in user reach at least one agent?
 *
 * The question step 3 turns on, and it is only ever asked of the roles in
 * communicationFallbackRoles() — for staff the answer would be meaningless,
 * because their scope mixes colleagues and clients and no part of it is a
 * fallback. One indexed EXISTS, cached per request, because both
 * messageContactScope() and communicationContactSource() ask it.
 */
function communicationHasAgentContact(): bool
{
    static $cache = [];

    $actor = communicationActor();
    if ($actor === null) {
        return false;
    }
    if (array_key_exists($actor['id'], $cache)) {
        return $cache[$actor['id']];
    }

    [$scope, $params] = messageAgentScope('u', 'r');

    $stmt = getDBConnection()->prepare("
        SELECT 1
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.is_active = 1 AND u.id <> :ca_me AND ({$scope})
        LIMIT 1
    ");
    $stmt->execute($params + [':ca_me' => $actor['id']]);

    return $cache[$actor['id']] = (bool) $stmt->fetchColumn();
}

// ─── The full resolution, steps 1 through 5 ────────────────────────────

/**
 * SQL predicate restricting a `users` query (aliased $u, joined to `roles`
 * aliased $r) to the people the signed-in user may open a conversation with.
 *
 * The ordered resolution, in one place:
 *
 *   1. No live actor, or no `messages.create`      → nobody.
 *   2. Agents resolved from a real relationship    → those, and only those.
 *   3. Nobody resolved                             → active administrators.
 *   4. Which administrator, when there are several → the caller's choice; see
 *      messageContacts(), which returns them all in a deterministic order.
 *   5. The `*` wildcard grants no edge of its own  → see the ROLE_ADMIN arm of
 *      messageAgentScope().
 *
 * The caller must still apply `u.is_active = 1` and exclude the actor's own
 * id; messageContacts() below does both, and is the function to use unless you
 * are merging this into a larger query.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function messageContactScope(string $u = 'u', string $r = 'r'): array
{
    $actor = communicationActor();
    if ($actor === null || !can('messages.create')) {
        return ['0 = 1', []];
    }

    // Staff have no fallback to reach, so their scope is settled in one step
    // and the extra EXISTS below is never paid for.
    if (!in_array($actor['role'], communicationFallbackRoles(), true)) {
        return messageAgentScope($u, $r);
    }

    // Step 2 first, always. The fallback is only ever reached by exhausting
    // the real relationships, never by preferring the easier query.
    if (communicationHasAgentContact()) {
        return messageAgentScope($u, $r);
    }

    // Step 3.
    return ["{$r}.name = '" . ROLE_ADMIN . "'", []];
}

/**
 * The people the signed-in user may write to, resolved.
 *
 * A convenience for building a recipient list — it is NOT the security
 * boundary. canMessageUser() re-asks the database with the same predicate
 * before anything is written, so a hand-edited recipient id matches nothing.
 *
 * Ordered deterministically (name, then id) so a fallback list of several
 * administrators is stable between requests: the same office is offered in the
 * same order every time, and a caller that has to pick one without asking gets
 * a repeatable answer rather than whichever row the engine returned first.
 *
 * @return array<int, array<string, mixed>>
 */
function messageContacts(): array
{
    $actor = communicationActor();
    if ($actor === null) {
        return [];
    }

    [$scope, $params] = messageContactScope('u', 'r');

    $stmt = getDBConnection()->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.avatar, r.name AS role, r.display_name AS role_label
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.is_active = 1 AND u.id <> :ca_me AND ({$scope})
        ORDER BY u.full_name ASC, u.id ASC
    ");
    $stmt->execute($params + [':ca_me' => $actor['id']]);

    return $stmt->fetchAll();
}

/**
 * Where the current user's contacts came from.
 *
 *   'staff'  the actor is an agent or an administrator, whose scope is
 *            colleagues and clients together and to which no fallback applies
 *   'agent'  a real agent relationship resolved — step 2
 *   'admin'  nothing resolved, so the office answers — step 3
 *   'none'   no live actor, or no permission to start a conversation
 *
 * Recorded honestly rather than guessed, so the UI can say "your managing
 * agent" or "the office" truthfully, and so the verification tool can assert
 * that the fallback fired only when it should have. 'staff' is a distinct
 * answer rather than a flattering 'agent': an administrator has not resolved
 * an agent relationship, and saying so would make the assertion meaningless.
 */
function communicationContactSource(): string
{
    $actor = communicationActor();
    if ($actor === null || !can('messages.create')) {
        return 'none';
    }
    if (!in_array($actor['role'], communicationFallbackRoles(), true)) {
        return 'staff';
    }

    return communicationHasAgentContact() ? 'agent' : 'admin';
}

/**
 * May the signed-in user open a conversation with this specific user?
 *
 * Asks the database with the same predicate the list is built from rather than
 * keeping a second copy of the rule in PHP, so the recipient picker and the
 * write path cannot drift. One indexed lookup.
 *
 * This is the function that makes a hand-typed `recipient_id` harmless.
 */
function canMessageUser(int $userId): bool
{
    $actor = communicationActor();
    if ($actor === null || $userId <= 0 || $userId === $actor['id']) {
        return false;
    }

    [$scope, $params] = messageContactScope('u', 'r');

    $stmt = getDBConnection()->prepare("
        SELECT 1
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = :ca_target AND u.is_active = 1 AND u.id <> :ca_me AND ({$scope})
        LIMIT 1
    ");
    $stmt->execute($params + [':ca_me' => $actor['id'], ':ca_target' => $userId]);

    return (bool) $stmt->fetchColumn();
}

// ─── Reaching a conversation ───────────────────────────────────────────

/**
 * SQL predicate restricting a conversations query to the rows the signed-in
 * user may read. Expects the conversations table aliased $c.
 *
 * Membership is the first half — an active participant row — and it is
 * deliberately not the whole answer: the row-level check below re-derives the
 * relationship as well. This predicate is what the inbox list is built from,
 * and it is kept to the membership test alone because a list is allowed to be
 * slightly generous where opening the row is not. Nothing sensitive is
 * rendered from the list that canAccessConversation() will not confirm.
 *
 * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
 */
function conversationViewScope(string $c = 'c'): array
{
    $actor = communicationActor();
    if ($actor === null) {
        return ['0 = 1', []];
    }

    return [
        "EXISTS (SELECT 1 FROM conversation_participants ca_cp
                  WHERE ca_cp.conversation_id = {$c}.id
                    AND ca_cp.user_id = :ca_me
                    AND ca_cp.is_active = 1)",
        [':ca_me' => $actor['id']],
    ];
}

/**
 * Is the signed-in user still an active participant of this conversation?
 *
 * The membership half, on its own, because canAccessConversation() and
 * canSendToConversation() both need it and the second needs to be able to
 * refuse for a different reason than the first.
 */
function isConversationParticipant(int $conversationId): bool
{
    $actor = communicationActor();
    if ($actor === null || $conversationId <= 0) {
        return false;
    }

    $stmt = getDBConnection()->prepare("
        SELECT 1 FROM conversation_participants
        WHERE conversation_id = :ca_conv AND user_id = :ca_me AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':ca_conv' => $conversationId, ':ca_me' => $actor['id']]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Is at least one other active participant still someone this user may
 * communicate with?
 *
 * This is rule 3 doing its work. Membership alone would mean a conversation,
 * once joined, is readable forever — so a tenant who moved out last year would
 * keep reading the thread about the flat they left. Re-asking the contact
 * scope on every access means the relationship ending ends the access, while
 * the participant row and its role_at_join survive for the audit trail.
 */
function conversationCounterpartStillReachable(int $conversationId): bool
{
    $actor = communicationActor();
    if ($actor === null) {
        return false;
    }

    [$scope, $params] = messageContactScope('u', 'r');

    $stmt = getDBConnection()->prepare("
        SELECT 1
        FROM conversation_participants ca_other
        JOIN users u ON ca_other.user_id = u.id
        JOIN roles r ON u.role_id = r.id
        WHERE ca_other.conversation_id = :ca_conv
          AND ca_other.is_active = 1
          AND u.is_active = 1
          AND u.id <> :ca_me
          AND ({$scope})
        LIMIT 1
    ");
    $stmt->execute($params + [':ca_me' => $actor['id'], ':ca_conv' => $conversationId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Is the record this conversation is *about* still within the user's scope?
 *
 * Reuses canViewProperty(), canViewLease() and canViewMaintenanceRequest()
 * rather than restating what those already decide — the conversation inherits
 * the visibility of the thing it discusses, which is the property of a
 * business communication system that a chat application does not have.
 *
 * A direct conversation has no context and passes trivially.
 *
 * $mustExist is the difference between the two callers, and it matters:
 *
 *   false (reading)  A conversation whose context row has since been deleted
 *                    is treated as contextless rather than refused. The
 *                    correspondence outlives the record — the FK sets the
 *                    column NULL and the thread stays readable.
 *
 *   true (creating)  A record that does not exist cannot be discussed. Without
 *                    this an id typed into the query string sails through
 *                    every check here, gets rendered into the compose form as
 *                    a hidden field, and fails only at the foreign key — where
 *                    the user is told the conversation "could not be started"
 *                    with no idea why.
 *
 * @param array $conversation A row from the conversations table, or the ids
 *                            a conversation is about to be created with.
 */
function canAccessConversationContext(array $conversation, bool $mustExist = false): bool
{
    $db = getDBConnection();

    $propertyId    = (int) ($conversation['property_id'] ?? 0);
    $leaseId       = (int) ($conversation['lease_id'] ?? 0);
    $maintenanceId = (int) ($conversation['maintenance_request_id'] ?? 0);

    if ($maintenanceId > 0) {
        $stmt = $db->prepare("
            SELECT m.*, p.owner_id AS property_owner_id, p.agent_id AS property_agent_id
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            WHERE m.id = ?
        ");
        $stmt->execute([$maintenanceId]);
        $row = $stmt->fetch();
        if (!$row && $mustExist) {
            return false;
        }
        if ($row && !canViewMaintenanceRequest($row)) {
            return false;
        }
    }

    if ($leaseId > 0) {
        $stmt = $db->prepare("
            SELECT l.*, p.owner_id AS property_owner_id, p.agent_id AS property_agent_id
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE l.id = ?
        ");
        $stmt->execute([$leaseId]);
        $row = $stmt->fetch();
        if (!$row && $mustExist) {
            return false;
        }
        if ($row && !canViewLease($row)) {
            return false;
        }
    }

    if ($propertyId > 0) {
        $stmt = $db->prepare("SELECT * FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        $row = $stmt->fetch();
        if (!$row && $mustExist) {
            return false;
        }
        if ($row && !canViewProperty($row)) {
            return false;
        }
    }

    return true;
}

/**
 * May the signed-in user open this conversation?
 *
 * The row-level check, and the one that makes a hand-typed
 * `?page=messages&id=` a refusal rather than a leak. All four halves must
 * hold — a live account holding the permission, an active participant row, a
 * counterpart still in scope, and a context still visible.
 */
function canAccessConversation(int $conversationId): bool
{
    if ($conversationId <= 0 || !canUseCommunication()) {
        return false;
    }
    if (!isConversationParticipant($conversationId)) {
        return false;
    }
    if (!conversationCounterpartStillReachable($conversationId)) {
        return false;
    }

    $stmt = getDBConnection()->prepare("
        SELECT property_id, lease_id, maintenance_request_id
        FROM conversations WHERE id = ?
    ");
    $stmt->execute([$conversationId]);
    $conversation = $stmt->fetch();

    return $conversation ? canAccessConversationContext($conversation) : false;
}

/**
 * May the signed-in user send into this conversation?
 *
 * Strictly narrower than reading it: everything canAccessConversation()
 * requires, plus the `messages.send` permission and a conversation that is
 * still open. Reading correspondence you are party to never implies being able
 * to add to it — a closed thread stays legible and stays closed.
 */
function canSendToConversation(int $conversationId): bool
{
    if (!can('messages.send') || !canAccessConversation($conversationId)) {
        return false;
    }

    $stmt = getDBConnection()->prepare("SELECT status FROM conversations WHERE id = ?");
    $stmt->execute([$conversationId]);

    return $stmt->fetchColumn() === 'active';
}

/**
 * May the signed-in user open a conversation carrying this business context?
 *
 * Called before an insert, so a hand-edited property_id, lease_id or
 * maintenance_request_id is refused before a row exists rather than after. The
 * ids are checked through the existing record scopes, which is why a
 * conversation cannot be used as a side door to a property the user could not
 * open directly.
 */
function canCreateContextConversation(int $propertyId = 0, int $leaseId = 0, int $maintenanceId = 0): bool
{
    if (!canUseCommunication() || !can('messages.create')) {
        return false;
    }

    // $mustExist: a record that is not there cannot be discussed, and saying
    // so here is what turns a mistyped id into "that record is not available
    // to you" instead of a foreign-key failure two steps later.
    return canAccessConversationContext([
        'property_id'            => $propertyId ?: null,
        'lease_id'               => $leaseId ?: null,
        'maintenance_request_id' => $maintenanceId ?: null,
    ], true);
}

// ─── Presence ──────────────────────────────────────────────────────────

/**
 * Record that the signed-in user was here.
 *
 * Called once per request from init.php. Rate-limited to one write a minute
 * per session, so a burst of page loads costs one UPDATE rather than twenty —
 * `users` is read on every request in this application and is not a table to
 * write to casually.
 *
 * Failure is silent by design: presence is decoration on a page that has real
 * work to do, and a locked row must never turn a dashboard into an error.
 */
function touchPresence(): void
{
    if (!isLoggedIn()) {
        return;
    }

    $now  = time();
    $last = (int) ($_SESSION['presence_written_at'] ?? 0);
    if ($now - $last < PRESENCE_WRITE_INTERVAL) {
        return;
    }
    $_SESSION['presence_written_at'] = $now;

    try {
        getDBConnection()
            ->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ?")
            ->execute([(int) $_SESSION['user_id']]);
    } catch (PDOException $e) {
        error_log('Presence write failed: ' . $e->getMessage());
    }
}

/**
 * How to describe someone's presence, honestly.
 *
 * The one rule this function exists to enforce: **never claim more than the
 * server knows.** `last_seen_at` is stamped on a request, so all it can
 * support is "they were here N ago". Within a two-minute window that rounds
 * fairly to "Online"; beyond it, the interface says how long ago and stops
 * pretending.
 *
 * There is no socket, no heartbeat and no polling behind this. Someone
 * reading the same page for ten minutes is reported as last seen ten minutes
 * ago, because that is the last moment the server heard from them. A green
 * dot that survives an hour of silence is a lie, and a lying dot is worse
 * than no dot.
 *
 *   under 2 minutes   Online          (dot shown)
 *   under 1 hour      Last seen 12m ago
 *   under 24 hours    Last seen 3h ago
 *   under 7 days      Last seen 2d ago
 *   older / never     no claim at all
 *
 * @return array{online:bool, label:string, title:string}
 */
function communicationPresence(?string $lastSeenAt): array
{
    $none = ['online' => false, 'label' => '', 'title' => ''];

    if (!$lastSeenAt) {
        return $none;                      // never seen since the column existed
    }

    $then = strtotime($lastSeenAt);
    if ($then === false) {
        return $none;
    }

    // Compared against the database's own clock. PHP and MySQL do not
    // necessarily share a timezone — on this machine they differ by an hour —
    // and measuring the gap between two clocks instead of the age of a
    // timestamp is exactly the bug that inverted the message edit window.
    static $dbNow = null;
    if ($dbNow === null) {
        try {
            $dbNow = strtotime((string) getDBConnection()->query("SELECT NOW()")->fetchColumn());
        } catch (PDOException $e) {
            $dbNow = time();
        }
    }

    $ago   = $dbNow - $then;
    $title = 'Last seen ' . formatDateTime($lastSeenAt);

    if ($ago < 0) {
        return $none;                      // stamped in the future: say nothing
    }
    if ($ago < PRESENCE_ONLINE_WINDOW) {
        return ['online' => true, 'label' => 'Online', 'title' => 'Active in the last couple of minutes'];
    }
    if ($ago < 3600) {
        return ['online' => false, 'label' => 'Last seen ' . max(1, (int) ($ago / 60)) . 'm ago', 'title' => $title];
    }
    if ($ago < 86400) {
        return ['online' => false, 'label' => 'Last seen ' . (int) ($ago / 3600) . 'h ago', 'title' => $title];
    }
    if ($ago < 604800) {
        return ['online' => false, 'label' => 'Last seen ' . (int) ($ago / 86400) . 'd ago', 'title' => $title];
    }

    return $none;
}

// ─── Reactions ─────────────────────────────────────────────────────────

/**
 * May the signed-in user react to this message?
 *
 * Reacting is a lighter act than sending — it adds no words to the record —
 * but it still writes a row that names them in the conversation, so it takes
 * the same live access check the thread does. Deliberately *not* the send
 * check: a closed conversation stays readable, and acknowledging a message in
 * a closed thread is harmless.
 *
 * A withdrawn message cannot be reacted to; there is nothing left to react to.
 */
function canReactToMessage(int $messageId): bool
{
    $actor = communicationActor();
    if ($actor === null || !can('messages.show')) {
        return false;
    }

    $message = messageForAuthorship($messageId);
    if (!$message || !empty($message['deleted_at'])) {
        return false;
    }

    return canAccessConversation((int) $message['conversation_id']);
}

/** Is this one of the emoji the server is willing to store? */
function isAllowedReaction(string $emoji): bool
{
    return in_array($emoji, MESSAGE_REACTIONS, true);
}

// ─── Changing a message after it was sent ──────────────────────────────

/**
 * How long a message stays editable, in seconds.
 *
 * Fifteen minutes: long enough to catch the typo you noticed as the page
 * reloaded, short enough that nobody can quietly rewrite what they agreed to
 * last week. This is business correspondence about tenancies and money, and an
 * indefinitely editable record is not a record.
 *
 * Deletion has no such window — see canDeleteMessage() for why the two differ.
 */
function messageEditWindow(): int
{
    return 15 * 60;
}

/**
 * One message with the columns every check below needs, or null.
 *
 * Fetched through the conversation so a single query answers both "does this
 * exist" and "which conversation is it in" — the second is what the access
 * layer actually cares about.
 *
 * `age_seconds` is computed by the DATABASE, deliberately, and this is not a
 * stylistic choice. created_at is written by MySQL's NOW(); comparing it
 * against PHP's time() silently measures the gap between two clocks as well as
 * the age of the message. On this very machine PHP runs in Europe/Berlin and
 * MySQL an hour ahead, which made a message sent one second ago read as an
 * hour in the future and one sent an hour ago read as brand new — inverting
 * the edit window exactly. TIMESTAMPDIFF asks the clock that wrote the value,
 * so the answer is right whatever either timezone is set to.
 */
function messageForAuthorship(int $messageId): ?array
{
    if ($messageId <= 0) {
        return null;
    }

    $stmt = getDBConnection()->prepare("
        SELECT m.id, m.conversation_id, m.sender_id, m.body, m.message_type,
               m.created_at, m.edited_at, m.deleted_at,
               TIMESTAMPDIFF(SECOND, m.created_at, NOW()) AS age_seconds,
               (SELECT COUNT(*) FROM message_attachments a WHERE a.message_id = m.id) AS attachment_count
        FROM conversation_messages m
        WHERE m.id = ?
    ");
    $stmt->execute([$messageId]);

    return $stmt->fetch() ?: null;
}

/**
 * May the signed-in user edit this message?
 *
 * Five conditions, and all of them are required:
 *
 *   · they wrote it — authorship is not transferable, and an administrator
 *     editing someone else's words would make the whole thread untrustworthy;
 *   · it has not been deleted;
 *   · it is not a system line, which nobody authored;
 *   · they can still send into the conversation, which carries the live
 *     relationship check, the active-account check and the "conversation is
 *     still open" check with it;
 *   · it is inside the edit window.
 *
 * Note the fourth: revoking someone's access to a conversation also revokes
 * their ability to go back and change what they said in it.
 */
function canEditMessage(int $messageId): bool
{
    $actor = communicationActor();
    if ($actor === null || !can('messages.send')) {
        return false;
    }

    $message = messageForAuthorship($messageId);
    if (!$message) {
        return false;
    }

    if ((int) $message['sender_id'] !== $actor['id']) { return false; }
    if (!empty($message['deleted_at']))               { return false; }
    if (($message['message_type'] ?? 'text') !== 'text') { return false; }

    if (!canSendToConversation((int) $message['conversation_id'])) {
        return false;
    }

    // Measured by the database — see messageForAuthorship(). A negative age
    // would mean the row is stamped in the future, which is a clock problem
    // rather than an editable message, so it fails closed.
    $age = (int) ($message['age_seconds'] ?? PHP_INT_MAX);

    return $age >= 0 && $age <= messageEditWindow();
}

/**
 * May the signed-in user delete this message?
 *
 * The same rules as editing minus the time window, and the asymmetry is
 * deliberate. Editing rewrites history — it changes what the record says was
 * agreed — so it is fenced to the minutes just after sending. Deleting only
 * withdraws a message: `deleted_at` is set, the row and its body stay in the
 * table, the thread keeps its shape, and the reader is told plainly that
 * something was withdrawn. Nothing is destroyed, so nothing needs the fence.
 */
function canDeleteMessage(int $messageId): bool
{
    $actor = communicationActor();
    if ($actor === null || !can('messages.send')) {
        return false;
    }

    $message = messageForAuthorship($messageId);
    if (!$message) {
        return false;
    }

    if ((int) $message['sender_id'] !== $actor['id']) { return false; }
    if (!empty($message['deleted_at']))               { return false; }
    if (($message['message_type'] ?? 'text') !== 'text') { return false; }

    return canSendToConversation((int) $message['conversation_id']);
}

// ─── Asking about two people who are not the signed-in user ────────────

/**
 * May these two accounts communicate right now?
 *
 * Everything above answers for the *signed-in* user, because that is what a
 * request needs. Deciding who to notify is the one question that is not about
 * the signed-in user: the sender is asking, but the answer is about each
 * recipient. This is that answer, and it is deliberately built from the same
 * edge builders rather than a second copy of the rules — see
 * communicationAgentEdgeForRole().
 *
 * The order mirrors §3a exactly:
 *
 *   1. both accounts must be live, and neither may be the other;
 *   2. staff reach staff — an agent and the office are colleagues;
 *   3. a portal user reaches an agent through their real edge;
 *   4. a portal user reaches an administrator only when they have no agent —
 *      the fallback, and only then;
 *   5. two portal users never reach each other.
 */
function canUsersCommunicate(int $subjectId, int $otherId): bool
{
    if ($subjectId <= 0 || $otherId <= 0 || $subjectId === $otherId) {
        return false;
    }

    $db = getDBConnection();

    $stmt = $db->prepare("
        SELECT u.id, r.name AS role
        FROM users u JOIN roles r ON u.role_id = r.id
        WHERE u.id IN (:a, :b) AND u.is_active = 1
    ");
    $stmt->execute([':a' => $subjectId, ':b' => $otherId]);

    $roles = array_column($stmt->fetchAll(), 'role', 'id');
    if (count($roles) !== 2) {
        return false;                       // one of them is gone or disabled
    }

    $subjectRole = $roles[$subjectId];
    $otherRole   = $roles[$otherId];
    $staff       = [ROLE_ADMIN, ROLE_AGENT];

    // 2. Colleagues.
    if (in_array($subjectRole, $staff, true) && in_array($otherRole, $staff, true)) {
        return true;
    }

    // Work out which side is the portal user. Two portal users never reach
    // each other, so a pair with no staff member in it is refused outright.
    if (in_array($subjectRole, $staff, true)) {
        $portalId = $otherId;    $portalRole = $otherRole;
        $staffId  = $subjectId;  $staffRole  = $subjectRole;
    } elseif (in_array($otherRole, $staff, true)) {
        $portalId = $subjectId;  $portalRole = $subjectRole;
        $staffId  = $otherId;    $staffRole  = $otherRole;
    } else {
        return false;                       // 5.
    }

    // 3. The real agent edge, asked of this exact pair.
    if ($staffRole === ROLE_AGENT) {
        $edge = communicationAgentEdgeForRole($portalRole, ':ca_portal', '= :ca_staff');
        if ($edge === null) {
            return false;
        }
        $stmt = $db->prepare("SELECT 1 WHERE " . $edge . " LIMIT 1");
        $stmt->execute([':ca_portal' => $portalId, ':ca_staff' => $staffId]);

        return (bool) $stmt->fetchColumn();
    }

    // 4. The administrator fallback — available only to a portal user who
    //    resolves no agent at all. An administrator does not become a valid
    //    correspondent for someone who already has one.
    $edge = communicationAgentEdgeForRole($portalRole, ':ca_portal', 'IN ' . communicationLiveAgentIds());
    if ($edge === null) {
        return false;
    }
    $stmt = $db->prepare("SELECT 1 WHERE " . $edge . " LIMIT 1");
    $stmt->execute([':ca_portal' => $portalId]);

    return !$stmt->fetchColumn();
}

/**
 * Who should actually be told about a new message in this conversation.
 *
 * Historical participation is not delivery. A participant row survives a
 * revoked relationship on purpose — it is what lets an old thread name its
 * authors honestly — so it cannot be the thing that decides who gets post.
 * Four conditions, all live:
 *
 *   · not the sender          nobody is notified of their own message
 *   · still an active participant
 *   · account still active
 *   · still able to communicate with the sender  (canUsersCommunicate)
 *
 * The last one is what stops a technician whose job was reassigned, or an
 * owner whose agent was unassigned, from continuing to receive mail about a
 * relationship that has ended.
 *
 * @return array<int, int> user ids
 */
function conversationDeliverableRecipients(int $conversationId, int $senderId): array
{
    if ($conversationId <= 0 || $senderId <= 0) {
        return [];
    }

    $stmt = getDBConnection()->prepare("
        SELECT cp.user_id
        FROM conversation_participants cp
        JOIN users u ON cp.user_id = u.id
        WHERE cp.conversation_id = :conv
          AND cp.user_id <> :sender
          AND cp.is_active = 1
          AND u.is_active = 1
    ");
    $stmt->execute([':conv' => $conversationId, ':sender' => $senderId]);

    $recipients = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        if (canUsersCommunicate((int) $userId, $senderId)) {
            $recipients[] = (int) $userId;
        }
    }

    return $recipients;
}

// ─── Counting unread, for the navigation badge ─────────────────────────

/**
 * How many conversations hold something this user has not read.
 *
 * Conversations, not messages — deliberately, and it is worth being precise
 * about why there are two counts rather than two sources of truth:
 *
 *   ConversationMessage::totalUnreadFor()   counts unread MESSAGE ROWS. The
 *                                           inbox header uses it, because
 *                                           "17 unread messages" is what a
 *                                           person wants to know before
 *                                           opening the module.
 *   this function                           counts unread CONVERSATIONS. The
 *                                           navigation badge uses it, because
 *                                           the inbox is a list of
 *                                           conversations and a badge should
 *                                           count the rows it will land on.
 *
 * Both read the same watermark — conversation_participants.last_read_message_id
 * — so they cannot disagree about what "unread" means. There is one unread
 * model and two ways of totting it up.
 *
 * Archived conversations are excluded, matching the inbox: filing a thread
 * away and then being counted for it is what makes people stop trusting a
 * badge.
 */
function messagesUnreadConversationCount(): int
{
    $actor = communicationActor();
    if ($actor === null || !can('messages.view')) {
        return 0;
    }

    try {
        $stmt = getDBConnection()->prepare("
            SELECT COUNT(DISTINCT cm.conversation_id)
            FROM conversation_messages cm
            JOIN conversation_participants cp
              ON cp.conversation_id = cm.conversation_id
             AND cp.user_id = :ca_me AND cp.is_active = 1 AND cp.archived_at IS NULL
            WHERE cm.deleted_at IS NULL
              AND cm.sender_id <> :ca_me
              AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([':ca_me' => $actor['id']]);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // A badge that fails is a badge that shows nothing, not a page that
        // dies — the same bargain notificationBell() makes.
        error_log('Messages unread count error: ' . $e->getMessage());
        return 0;
    }
}

// ─── Reaching the module from a business record ────────────────────────

/**
 * The "Message…" action for a property, lease or maintenance page — or null
 * when there is nobody to offer.
 *
 * Three things have to be true before a button is worth drawing, and this
 * asks all three rather than the first one:
 *
 *   1. the role holds `messages.create`;
 *   2. this user may attach a conversation to *this* record, which is
 *      canCreateContextConversation() and therefore the same
 *      canViewProperty()/canViewLease()/canViewMaintenanceRequest() the rest
 *      of the application uses;
 *   3. somebody is actually reachable — an entry point that opens a compose
 *      screen with an empty recipient list is a dead end wearing a button.
 *
 * The label is resolved from the real relationship, never hard-coded. An
 * owner whose property has no agent is offered the managing office, and is
 * told so: calling head office "your agent" would be the interface telling a
 * lie on the system's behalf.
 *
 * This is a display decision. The URL it produces is a request hint like any
 * other — CommunicationController::start() re-validates the context and the
 * recipient before a row is written.
 *
 * @param array{property_id?:int, lease_id?:int, maintenance_request_id?:int} $context
 * @return array{label:string, short:string, url:string, icon:string, source:string}|null
 */
function communicationEntryPoint(array $context = []): ?array
{
    if (!canUseCommunication() || !can('messages.create')) {
        return null;
    }

    $propertyId    = (int) ($context['property_id'] ?? 0);
    $leaseId       = (int) ($context['lease_id'] ?? 0);
    $maintenanceId = (int) ($context['maintenance_request_id'] ?? 0);

    if (!canCreateContextConversation($propertyId, $leaseId, $maintenanceId)) {
        return null;
    }

    // The expensive half, asked last: is there anyone on the other end?
    if (messageContacts() === []) {
        return null;
    }

    $source = communicationContactSource();

    // Staff reach a mixed list — an owner, a tenant, a technician, a
    // colleague — so the button cannot name one of them. Portal users reach
    // exactly one kind of counterpart, so theirs can and should.
    switch ($source) {
        case 'admin':
            $label = 'Contact managing office';
            $short = 'Contact office';
            $icon  = 'bi-building-gear';
            break;
        case 'agent':
            $label = 'Message your agent';
            $short = 'Message agent';
            $icon  = 'bi-chat-dots';
            break;
        default:
            $label = 'Message';
            $short = 'Message';
            $icon  = 'bi-chat-dots';
    }

    $query = array_filter([
        'page'                   => 'messages',
        'compose'                => '1',
        'property_id'            => $propertyId ?: null,
        'lease_id'               => $leaseId ?: null,
        'maintenance_request_id' => $maintenanceId ?: null,
    ]);

    return [
        'label'  => $label,
        'short'  => $short,
        'icon'   => $icon,
        'source' => $source,
        'url'    => APP_URL . '/index.php?' . http_build_query($query),
    ];
}

/**
 * Why this counterpart, in one line, for the compose screen.
 *
 * Says the quiet part out loud where it matters: an administrator offered
 * through the fallback is offered *because* no agent is assigned, and the
 * person writing to them should know that rather than wonder why head office
 * is answering their question about a leaking tap.
 */
function communicationCounterpartReason(string $role): string
{
    $source = communicationContactSource();

    if ($role === ROLE_ADMIN && $source === 'admin') {
        return 'No agent is assigned to this record yet, so your message goes to the managing office.';
    }
    if ($role === ROLE_AGENT) {
        return 'The agent responsible for this record.';
    }
    if ($role === ROLE_ADMIN) {
        return 'Agency administration.';
    }

    return '';
}

// ─── Explaining an empty scope ─────────────────────────────────────────
// The wording lives beside the rules so the two are changed together — the
// arrangement property_access.php uses for its own scope hints.

/**
 * Who this user is talking to, phrased for the role reading it.
 *
 * Says which relationship produced the contact list, because "why can I only
 * see one person here?" is the first question the module will be asked.
 */
function communicationScopeHint(): string
{
    if (communicationContactSource() === 'admin') {
        return 'Your property has not been assigned an agent yet, so your messages go to the managing office.';
    }

    switch (communicationActorRole()) {
        case ROLE_ADMIN:
            return 'You can message the agency team, and any client who has not yet been assigned an agent.';
        case ROLE_AGENT:
            return 'You can message the owners, tenants, buyers and technicians attached to the properties assigned to you.';
        case ROLE_OWNER:
            return 'You can message the agents managing the properties registered to you.';
        case ROLE_CUSTOMER:
            return 'You can message the agent handling your tenancy or purchase.';
        case ROLE_MAINTENANCE:
            return 'You can message the agents responsible for the properties you have been assigned work on.';
    }

    return '';
}

/**
 * What to say when there is genuinely nobody, in two parts: the state, and
 * why it is that way. Distinguishes the two reasons — an account with no
 * business relationships yet, and a role that has run out of counterparts —
 * because the reader can act on the first and cannot act on the second.
 *
 * Split because the two callers need different halves. Somewhere the state is
 * already a heading above the message, and repeating it in the sentence
 * underneath — "No conversations yet" over "No conversations yet. Messages
 * from clients…" — spends the one line that had something to say on saying
 * the title again. Somewhere else the sentence stands alone and needs both.
 *
 * @return array{0:string,1:string} [state, explanation]
 */
function communicationEmptyScopeParts(): array
{
    switch (communicationActorRole()) {
        case ROLE_ADMIN:
            return ['No conversations yet',
                    'Messages from clients without an assigned agent will arrive here.'];
        case ROLE_AGENT:
            return ['No contacts yet',
                    'Owners, tenants and technicians appear here once a property is assigned to you.'];
        case ROLE_OWNER:
            return ['No contacts yet',
                    'Your managing agent appears here once a property has been registered to you.'];
        case ROLE_CUSTOMER:
            return ['No contacts yet',
                    'Your agent appears here once you hold a tenancy, a purchase or a reservation.'];
        case ROLE_MAINTENANCE:
            return ['No contacts yet',
                    'The responsible agent appears here once a job has been assigned to you.'];
    }

    return ['', 'Your authorized conversations will appear here.'];
}

/**
 * The same thing as one sentence, for the places that carry no heading of
 * their own. Unchanged in wording and unchanged for every existing caller.
 */
function communicationEmptyScopeMessage(): string
{
    [$state, $detail] = communicationEmptyScopeParts();

    return $state === '' ? $detail : $state . '. ' . $detail;
}
