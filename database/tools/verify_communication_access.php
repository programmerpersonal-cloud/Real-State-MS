<?php
/**
 * Communication access — verification report
 *
 * Proves, against the real database, that includes/communication_access.php
 * does what it claims. Run it after the communication migration, after any
 * change to the access layer, and whenever the relationship rules move.
 *
 *     php database/tools/verify_communication_access.php
 *
 * WHAT IT ASSERTS
 *   · a real agent relationship always wins, and the administrator fallback
 *     fires only when no agent resolves
 *   · the fallback population is exactly the active administrators
 *   · unrelated users cannot reach each other — cross-owner, cross-tenant,
 *     cross-property
 *   · a conversation cannot be opened by someone who is not party to it,
 *     however the id arrives
 *   · a deactivated counterpart disappears from the scope
 *   · ending the business relationship revokes access to the conversation,
 *     while the participant rows, their role_at_join and the messages
 *     themselves all survive
 *   · reading a conversation never implies being able to send into it
 *   · archiving is per participant
 *
 * WHAT IT WRITES
 *   Nothing that survives it. Two kinds of temporary state are used and both
 *   are undone:
 *
 *   1. A handful of fixture conversations, created through the model and
 *      deleted in the shutdown handler — so an interrupted run still cleans
 *      up. Only rows this script created are touched, by id.
 *   2. Three revocation scenarios — a deactivated account, an agent
 *      unassigned from a property, and an ended tenancy — which run in CHILD
 *      PROCESSES inside a transaction that is always rolled back. They are
 *      children rather than inline because the access layer caches the actor
 *      and their agent edge per request — correctly, since a role does not
 *      change mid-request — and a cache filled before the mutation would hide
 *      the very thing being tested. A fresh process is the honest way to ask
 *      the question twice.
 *
 * WHAT IT CANNOT PROVE HERE
 *   Both tenants in this database also hold a sale, and a sale is a permanent
 *   relationship by design, so ending their tenancy correctly does *not*
 *   revoke the channel. Conversation-level revocation is therefore proved
 *   through the owner route instead, which has exactly one link in it. Seed a
 *   tenant with no sale and the third scenario will cover it directly.
 *
 * Exit code is 0 when everything passed, 1 otherwise, so it can be wired into
 * a check later.
 */

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';

require_once dirname(__DIR__, 2) . '/includes/init.php';

// The application has no autoloader — index.php requires a model when it
// dispatches to the controller that needs it — so this tool names its own.
require_once BASE_PATH . '/models/Conversation.php';
require_once BASE_PATH . '/models/ConversationMessage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// ─── Harness ───────────────────────────────────────────────────────────

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['fixtureConversations'] = [];

function heading(string $text): void
{
    echo "\n" . $text . "\n" . str_repeat('─', max(60, mb_strlen($text))) . "\n";
}

function check(bool $condition, string $description, string $detail = ''): bool
{
    if ($condition) {
        $GLOBALS['pass']++;
        echo "  PASS  {$description}\n";
    } else {
        $GLOBALS['fail']++;
        echo "  FAIL  {$description}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
    return $condition;
}

function note(string $text): void
{
    echo "        {$text}\n";
}

/** Impersonate a user the way a signed-in request would look. */
function actAs(array $user): void
{
    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];
}

/** Remove anything this run created, even if it died half way through. */
register_shutdown_function(function (): void {
    if (empty($GLOBALS['fixtureConversations'])) {
        return;
    }
    $ids = implode(',', array_map('intval', $GLOBALS['fixtureConversations']));
    try {
        // Participants and messages go with it: both cascade.
        getDBConnection()->exec("DELETE FROM conversations WHERE id IN ({$ids})");
        echo "\nCleaned up fixture conversations: {$ids}\n";
    } catch (PDOException $e) {
        echo "\nWARNING: could not clean up fixture conversations {$ids} — " . $e->getMessage() . "\n";
    }
});

$db = getDBConnection();

/** @return array<int, array<string,mixed>> */
function usersByRole(string $role): array
{
    return getDBConnection()
        ->query("SELECT u.id, u.full_name, r.name AS role
                 FROM users u JOIN roles r ON u.role_id = r.id
                 WHERE u.is_active = 1 AND r.name = '{$role}'
                 ORDER BY u.id")
        ->fetchAll();
}

$admins      = usersByRole(ROLE_ADMIN);
$agents      = usersByRole(ROLE_AGENT);
$owners      = usersByRole(ROLE_OWNER);
$customers   = usersByRole(ROLE_CUSTOMER);
$technicians = usersByRole(ROLE_MAINTENANCE);
$adminIds    = array_map('intval', array_column($admins, 'id'));

// ─── Child-process scenarios ───────────────────────────────────────────
// Reached only when this script re-invokes itself. Each mutates the
// database inside a transaction, asks the access layer the question, and
// rolls back — so the process exits having changed nothing. They cannot run
// inline: the access layer caches the actor and their agent edge per
// request, so a cache filled before the mutation would answer for the world
// as it was.

$scenario = $argv[1] ?? '';

if ($scenario === 'scenario:inactive') {
    // The subject is found with plain SQL rather than by asking the access
    // layer. Asking it here would fill communicationHasAgentContact()'s
    // per-request cache with the answer for the world *before* the mutation,
    // and the assertions below would then be reading a stale yes. The cache
    // is correct — a role does not change mid-request — which is exactly why
    // the question has to be asked for the first time after the change.
    $pair = $db->query("
        SELECT o.user_id AS subject_id, p.agent_id
        FROM owners o
        JOIN properties p ON p.owner_id = o.id
        JOIN users au ON p.agent_id = au.id
        JOIN roles ar ON au.role_id = ar.id
        WHERE o.user_id IS NOT NULL AND au.is_active = 1 AND ar.name = '" . ROLE_AGENT . "'
        GROUP BY o.user_id
        HAVING COUNT(DISTINCT p.agent_id) = 1
        LIMIT 1
    ")->fetch();

    if (!$pair) {
        note('No owner resolves to exactly one agent — deactivation scenario skipped.');
        exit(0);
    }

    $subject = $db->query("SELECT u.id, u.full_name, r.name AS role FROM users u
                           JOIN roles r ON u.role_id = r.id
                           WHERE u.id = " . (int) $pair['subject_id'])->fetch();
    $agentId = (int) $pair['agent_id'];
    $agent   = $db->query("SELECT u.id, u.full_name, r.name AS role FROM users u
                           JOIN roles r ON u.role_id = r.id WHERE u.id = {$agentId}")->fetch();

    $db->beginTransaction();
    try {
        $db->exec("UPDATE users SET is_active = 0 WHERE id = {$agentId}");

        // A fresh process, so nothing was cached before the change.
        actAs($subject);
        $after = array_map('intval', array_column(messageContacts(), 'id'));

        check(!in_array($agentId, $after, true),
            "a deactivated agent disappears from {$subject['full_name']}'s contacts");
        check(!canMessageUser($agentId),
            'and can no longer be messaged');
        check(communicationContactSource() === 'admin',
            'the administrator fallback takes over, because the agent edge is now empty');

        actAs($agent);
        check(communicationActor() === null,
            'the deactivated account itself has no usable actor, despite a valid-looking session');
        check(messageContacts() === [],
            'and resolves no contacts at all');
    } finally {
        $db->rollBack();
    }

    printf("        (rolled back — user #%d is active again: %s)\n",
        $agentId,
        $db->query("SELECT is_active FROM users WHERE id = {$agentId}")->fetchColumn() ? 'yes' : 'NO — INVESTIGATE');
    exit(0);
}

if ($scenario === 'scenario:agent-unassigned') {
    // The owner route, which has exactly one link in it —
    // properties.agent_id — and so can be ended cleanly. This is the scenario
    // that proves revocation reaches the *conversation*, not merely the
    // contact list: the tenant equivalent cannot reach that assertion in this
    // database, because both tenants also hold a sale, and a sale is a
    // permanent relationship by design.
    $row = $db->query("
        SELECT o.user_id AS subject_id, p.id AS property_id, p.agent_id
        FROM owners o
        JOIN properties p ON p.owner_id = o.id
        JOIN users au ON p.agent_id = au.id
        JOIN roles ar ON au.role_id = ar.id
        WHERE o.user_id IS NOT NULL AND au.is_active = 1 AND ar.name = '" . ROLE_AGENT . "'
        GROUP BY o.user_id
        HAVING COUNT(DISTINCT p.agent_id) = 1
        LIMIT 1
    ")->fetch();

    if (!$row) {
        note('No owner resolves to exactly one agent — unassignment scenario skipped.');
        exit(0);
    }

    $ownerUserId = (int) $row['subject_id'];
    $agentId     = (int) $row['agent_id'];
    $subject     = $db->query("SELECT u.id, u.full_name, r.name AS role FROM users u
                               JOIN roles r ON u.role_id = r.id WHERE u.id = {$ownerUserId}")->fetch();

    actAs($subject);
    check(canMessageUser($agentId), "owner {$subject['full_name']} reaches agent #{$agentId} through a managed property");

    $db->beginTransaction();
    try {
        $db->exec("INSERT INTO conversations (conversation_type, created_by, status)
                   VALUES ('direct', {$ownerUserId}, 'active')");
        $convId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO conversation_participants (conversation_id, user_id, role_at_join)
                   VALUES ({$convId}, {$ownerUserId}, 'owner'), ({$convId}, {$agentId}, 'agent')");
        $db->exec("INSERT INTO conversation_messages (conversation_id, sender_id, body)
                   VALUES ({$convId}, {$ownerUserId}, 'Before the reassignment.')");

        check(canAccessConversation($convId), 'and may open a conversation with them');

        // The agency reassigns every one of this owner's properties away.
        $ownerId = (int) $db->query("SELECT id FROM owners WHERE user_id = {$ownerUserId}")->fetchColumn();
        $db->exec("UPDATE properties SET agent_id = NULL WHERE owner_id = {$ownerId}");

        check(!canMessageUser($agentId),
            'once the properties are unassigned, the agent is no longer a valid contact');
        check(!canAccessConversation($convId),
            'and the existing conversation is no longer readable');

        // The fallback taking over is deliberately NOT asserted here. The
        // "before" check above has already asked communicationHasAgentContact()
        // in this process, and its per-request cache correctly still says yes —
        // so contactSource() would answer for the world as it was. That claim
        // is proved with a cold cache in scenario:inactive instead. The two
        // assertions above are unaffected: both re-query, and both get the
        // right answer for the right reason.

        $parts = (int) $db->query("SELECT COUNT(*) FROM conversation_participants
                                   WHERE conversation_id = {$convId}")->fetchColumn();
        $snapshots = $db->query("SELECT role_at_join FROM conversation_participants
                                 WHERE conversation_id = {$convId}")->fetchAll(PDO::FETCH_COLUMN);
        check($parts === 2, 'while both participant rows survive');
        check(in_array('owner', $snapshots, true) && in_array('agent', $snapshots, true),
            'and role_at_join still records who they were — audit history, never permission');

        $msgs = (int) $db->query("SELECT COUNT(*) FROM conversation_messages
                                  WHERE conversation_id = {$convId}")->fetchColumn();
        check($msgs === 1, 'the correspondence itself is preserved, merely unreadable by them');
    } finally {
        $db->rollBack();
    }

    printf("        (rolled back — %d properties still have an agent assigned)\n",
        (int) $db->query("SELECT COUNT(*) FROM properties WHERE agent_id IS NOT NULL")->fetchColumn());
    exit(0);
}

if ($scenario === 'scenario:relationship-ended') {
    // A tenant reaches their agent by up to three routes at once — in this
    // database Ahmed reaches agent #7 through both a tenancy and a
    // reservation — so ending only the tenancy correctly leaves the channel
    // open. That is the rule working, not failing. To test revocation the
    // scenario has to end every live route, which is what "the relationship
    // ended" actually means.
    //
    // Found with SQL, for the cache reason explained in the scenario above.
    $row = $db->query("
        SELECT c.id AS customer_id, c.user_id, p.agent_id
        FROM customers c
        JOIN leases l ON l.customer_id = c.id AND l.status = 'active'
        JOIN properties p ON l.property_id = p.id
        JOIN users au ON p.agent_id = au.id
        JOIN roles ar ON au.role_id = ar.id
        WHERE c.user_id IS NOT NULL AND au.is_active = 1 AND ar.name = '" . ROLE_AGENT . "'
        LIMIT 1
    ")->fetch();

    if (!$row) {
        note('No tenant holds a live tenancy on an agent-managed property — scenario skipped.');
        exit(0);
    }

    $customerId = (int) $row['customer_id'];
    $agentId    = (int) $row['agent_id'];
    $subject    = $db->query("SELECT u.id, u.full_name, r.name AS role FROM users u
                              JOIN roles r ON u.role_id = r.id
                              WHERE u.id = " . (int) $row['user_id'])->fetch();

    actAs($subject);
    check(canMessageUser($agentId),
        "tenant {$subject['full_name']} reaches agent #{$agentId} through a live relationship");

    $db->beginTransaction();
    try {
        // Built with plain SQL rather than the model: Conversation::create()
        // opens its own transaction and MySQL does not nest them.
        $db->exec("INSERT INTO conversations (conversation_type, created_by, status)
                   VALUES ('direct', " . (int) $subject['id'] . ", 'active')");
        $convId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO conversation_participants (conversation_id, user_id, role_at_join)
                   VALUES ({$convId}, " . (int) $subject['id'] . ", 'customer'),
                          ({$convId}, {$agentId}, 'agent')");

        check(canAccessConversation($convId), 'and may open a conversation with them');

        // End every live route: the tenancy terminates, the holds expire.
        // Sales are deliberately untouched — a completed purchase is a
        // permanent relationship, so if one existed the channel *should*
        // survive, and the assertions below would correctly fail.
        $db->exec("UPDATE leases SET status = 'terminated'
                    WHERE customer_id = {$customerId} AND status = 'active'");
        $db->exec("UPDATE reservations SET status = 'expired'
                    WHERE customer_id = {$customerId} AND status IN ('active','confirmed')");

        $hasSale = (int) $db->query("SELECT COUNT(*) FROM sales WHERE customer_id = {$customerId}")->fetchColumn();
        if ($hasSale > 0) {
            note("Tenant also holds {$hasSale} sale(s) — a permanent relationship. Revocation not expected; scenario stops here.");
        } else {
            check(!canMessageUser($agentId),
                'once every live relationship ends, the agent is no longer a valid contact');
            check(!canAccessConversation($convId),
                'and the existing conversation is no longer readable');
            check(communicationContactSource() === 'admin',
                'the administrator fallback takes over');

            $stillThere = (int) $db->query("SELECT COUNT(*) FROM conversation_participants
                                            WHERE conversation_id = {$convId}")->fetchColumn();
            check($stillThere === 2,
                'while both participant rows survive — role_at_join is audit history, not permission');
        }
    } finally {
        $db->rollBack();
    }

    printf("        (rolled back — %d active leases, %d live reservations restored)\n",
        (int) $db->query("SELECT COUNT(*) FROM leases WHERE status = 'active'")->fetchColumn(),
        (int) $db->query("SELECT COUNT(*) FROM reservations WHERE status IN ('active','confirmed')")->fetchColumn());
    exit(0);
}

if (($argv[1] ?? '') === '') {
    echo "Communication access verification\n";
    echo "Database: " . DB_NAME . "  ·  " . date('Y-m-d H:i') . "\n";
    echo sprintf(
        "Active accounts: %d admin, %d agent, %d owner, %d customer, %d maintenance\n",
        count($admins), count($agents), count($owners), count($customers), count($technicians)
    );
}

// ─── A. Contact resolution, every account ──────────────────────────────

heading('A. Contact resolution — agent relationship preferred, fallback only when empty');

$resolved = [];
foreach (array_merge($admins, $agents, $owners, $customers, $technicians) as $user) {
    actAs($user);

    $source   = communicationContactSource();
    $contacts = messageContacts();
    $roles    = array_count_values(array_column($contacts, 'role'));

    $resolved[(int) $user['id']] = ['source' => $source, 'contacts' => $contacts];

    $label = sprintf('%-11s %-24s → %-6s (%d)', $user['role'], mb_substr($user['full_name'], 0, 23), $source, count($contacts));

    if (in_array($user['role'], [ROLE_OWNER, ROLE_CUSTOMER, ROLE_MAINTENANCE], true)) {

        if ($source === 'agent') {
            // Rule 2: administrators are never added alongside a resolved agent.
            check(
                empty($roles[ROLE_ADMIN]),
                $label . ' — agent resolved, no admin alongside',
                'admins present: ' . ($roles[ROLE_ADMIN] ?? 0)
            );
            check(
                ($roles[ROLE_AGENT] ?? 0) === count($contacts),
                $label . ' — every contact is an agent'
            );
        } else {
            // Rule 3: the fallback population is exactly the active admins.
            $ids = array_map('intval', array_column($contacts, 'id'));
            sort($ids);
            $expected = $adminIds;
            sort($expected);
            check(
                $ids === $expected,
                $label . ' — fallback is exactly the active administrators',
                'got [' . implode(',', $ids) . '] expected [' . implode(',', $expected) . ']'
            );
        }
    } else {
        check($source === 'staff', $label . ' — staff scope, fallback not applicable');
        check(count($contacts) > 0, $label . ' — staff reach at least the other staff');
    }
}

// ─── B. The wildcard is not a relationship ─────────────────────────────

heading('B. The admin `*` wildcard grants no communication edge of its own');

$tenantWithAgent = null;
foreach ($customers as $c) {
    if (($resolved[(int) $c['id']]['source'] ?? '') === 'agent') { $tenantWithAgent = $c; break; }
}

if ($tenantWithAgent === null) {
    note('No tenant in this database resolves to an agent — check skipped.');
} else {
    foreach ($admins as $admin) {
        actAs($admin);
        check(
            can('messages.create'),
            "admin {$admin['full_name']} holds messages.create through the wildcard"
        );
        check(
            !canMessageUser((int) $tenantWithAgent['id']),
            "admin {$admin['full_name']} still cannot message {$tenantWithAgent['full_name']} (has their own agent)",
            'the wildcard leaked a communication edge'
        );
    }

    // …and the same administrator *can* reach a client who has nobody.
    $orphan = null;
    foreach (array_merge($owners, $customers, $technicians) as $u) {
        if (($resolved[(int) $u['id']]['source'] ?? '') === 'admin') { $orphan = $u; break; }
    }
    if ($orphan) {
        actAs($admins[0]);
        check(
            canMessageUser((int) $orphan['id']),
            "admin reaches {$orphan['full_name']}, who has no agent (the fallback, both ways)"
        );
    }
}

// ─── C. Unrelated users cannot reach each other ────────────────────────

heading('C. Cross-owner, cross-tenant and cross-property refusals');

$pairs = [];
if (count($owners) >= 2)    { $pairs[] = [$owners[0], $owners[1], 'owner → another owner']; }
if (count($customers) >= 2) { $pairs[] = [$customers[0], $customers[1], 'tenant → another tenant']; }
if ($technicians && $owners) { $pairs[] = [$technicians[0], $owners[0], 'technician → an owner']; }

foreach ($pairs as [$from, $to, $label]) {
    actAs($from);
    check(!canMessageUser((int) $to['id']), "{$label} is refused");
}

// An owner must not reach an agent who manages somebody else's property.
foreach ($owners as $owner) {
    if (($resolved[(int) $owner['id']]['source'] ?? '') !== 'agent') { continue; }

    $mine = array_map('intval', array_column($resolved[(int) $owner['id']]['contacts'], 'id'));
    foreach ($agents as $agent) {
        if (in_array((int) $agent['id'], $mine, true)) { continue; }
        actAs($owner);
        check(
            !canMessageUser((int) $agent['id']),
            "owner {$owner['full_name']} cannot reach unrelated agent {$agent['full_name']}"
        );
        break;   // one counter-example per owner is enough
    }
}

// Nobody may message themselves.
actAs($owners[0] ?? $admins[0]);
check(!canMessageUser((int) ($owners[0]['id'] ?? $admins[0]['id'])), 'a user cannot message themselves');
check(!canMessageUser(0) && !canMessageUser(-1) && !canMessageUser(999999), 'absent and nonsense user ids are refused');

// ─── D. Conversations: membership, IDOR, sending, archiving ────────────

heading('D. Conversation access — fixtures created, then removed');

$speaker = null;
foreach (array_merge($owners, $customers) as $u) {
    if (($resolved[(int) $u['id']]['source'] ?? '') === 'agent') { $speaker = $u; break; }
}

if ($speaker === null) {
    note('No portal user resolves to an agent — conversation checks skipped.');
} else {
    actAs($speaker);
    $counterpart = $resolved[(int) $speaker['id']]['contacts'][0];

    $conversations = new Conversation();
    $messages      = new ConversationMessage();

    $convId = $conversations->create('direct', [], [(int) $counterpart['id']]);
    check($convId > 0, "a direct conversation is created between {$speaker['full_name']} and {$counterpart['full_name']}");

    if ($convId > 0) {
        $GLOBALS['fixtureConversations'][] = $convId;

        // Duplicate prevention.
        $again = $conversations->create('direct', [], [(int) $counterpart['id']]);
        check($again === $convId, 'creating the same conversation again reuses it rather than duplicating', "got {$again}");

        // Participants, and the role snapshot.
        $parts = $conversations->participants($convId);
        check(count($parts) === 2, 'exactly two participants were enrolled', 'got ' . count($parts));
        check(
            !empty(array_filter($parts, fn($p) => $p['role_at_join'] !== null && $p['role_at_join'] !== '')),
            'role_at_join was stamped from the live role'
        );

        // Access, from both ends.
        check($conversations->findById($convId) !== null, 'the conversation can be fetched');
        check(canAccessConversation($convId), 'the creator may open it');
        check(canSendToConversation($convId), 'the creator may send into it');

        actAs($counterpart);
        check(canAccessConversation($convId), 'the counterpart may open it');

        // Sending, and unread accounting.
        actAs($speaker);
        $msgId = $messages->create($convId, 'Verification fixture message.');
        check($msgId > 0, 'a message is stored');

        $row = $db->query("SELECT last_message_id FROM conversations WHERE id = {$convId}")->fetchColumn();
        check((int) $row === $msgId, 'the conversation now points at its newest message');
        check($messages->totalUnreadFor() === 0, 'the sender has no unread count for their own message');

        actAs($counterpart);
        check($messages->totalUnreadFor() >= 1, 'the recipient has an unread message');
        $messages->markReadUpTo($convId);
        check($messages->totalUnreadFor() === 0, 'opening the thread clears it');

        // Archiving is one-sided.
        $conversations->setArchived($convId, true);
        check(count($conversations->forUser(['archived' => true])) >= 1, 'the archiver sees it under Archived');
        actAs($speaker);
        $stillThere = array_filter($conversations->forUser(), fn($c) => (int) $c['id'] === $convId);
        check(!empty($stillThere), 'the other participant still sees it in their inbox');

        // IDOR: every account that is NOT a participant must be refused.
        $participantIds = [(int) $speaker['id'], (int) $counterpart['id']];
        $refused = 0;
        $leaked  = [];
        foreach (array_merge($admins, $agents, $owners, $customers, $technicians) as $u) {
            if (in_array((int) $u['id'], $participantIds, true)) { continue; }
            actAs($u);
            if (canAccessConversation($convId)) {
                $leaked[] = "{$u['role']} {$u['full_name']}";
            } else {
                $refused++;
            }
        }
        check(
            empty($leaked),
            "every non-participant is refused the conversation id ({$refused} accounts tested)",
            'leaked to: ' . implode(', ', $leaked)
        );

        // A non-participant must not be able to read the messages either.
        $outsider = null;
        foreach ($owners as $u) {
            if (!in_array((int) $u['id'], $participantIds, true)) { $outsider = $u; break; }
        }
        if ($outsider) {
            actAs($outsider);
            check($messages->forConversation($convId) === [], 'a non-participant reads no messages from it');
            check(!canSendToConversation($convId), 'a non-participant cannot send into it');
            check($messages->create($convId, 'Should never be stored.') === 0, 'a non-participant write is refused');
        }

        // Nonsense ids.
        actAs($speaker);
        check(!canAccessConversation(0) && !canAccessConversation(999999), 'absent conversation ids are refused');

        // Closed conversations stay readable and stop accepting messages.
        $db->exec("UPDATE conversations SET status = 'closed' WHERE id = {$convId}");
        check(canAccessConversation($convId), 'a closed conversation is still readable');
        check(!canSendToConversation($convId), 'a closed conversation refuses new messages');
        $db->exec("UPDATE conversations SET status = 'active' WHERE id = {$convId}");
    }

    // Context conversations must not become a side door to a record.
    heading('D2. Business context is checked, not trusted');

    $foreignProperty = $db->query("
        SELECT p.id FROM properties p
        WHERE p.owner_id IS NOT NULL
          AND p.owner_id NOT IN (SELECT id FROM owners WHERE user_id = " . (int) $speaker['id'] . ")
        LIMIT 1")->fetchColumn();

    if ($foreignProperty) {
        actAs($speaker);
        check(
            !canCreateContextConversation((int) $foreignProperty),
            "a property outside the user's scope (#{$foreignProperty}) is refused as context"
        );
        $bad = $conversations->create('property', ['property_id' => (int) $foreignProperty], [(int) $counterpart['id']]);
        check($bad === 0, 'no row is written for a conversation about an unauthorised property');
        if ($bad > 0) { $GLOBALS['fixtureConversations'][] = $bad; }
    } else {
        note('No property outside this user\'s scope to test with — check skipped.');
    }
}

// ─── E. Revocation, in child processes ─────────────────────────────────

heading('E. Live revocation — deactivated account, ended tenancy');

/**
 * Run one scenario in a fresh process so the per-request caches start empty,
 * and fold its tally into this one.
 */
function runScenario(string $name): void
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($name);
    $out = (string) shell_exec($cmd . ' 2>&1');

    if (trim($out) === '') {
        $GLOBALS['fail']++;
        echo "  FAIL  scenario {$name} produced no output\n";
        return;
    }

    echo $out;
    $GLOBALS['pass'] += substr_count($out, '  PASS  ');
    $GLOBALS['fail'] += substr_count($out, '  FAIL  ');
}

runScenario('scenario:inactive');
runScenario('scenario:agent-unassigned');
runScenario('scenario:relationship-ended');

// ─── F. Nothing else was disturbed ─────────────────────────────────────

heading('F. Regression — the inquiry reply system is untouched');

$legacyCols = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'")->fetchColumn();
check($legacyCols === 8, "the legacy `messages` table still has its 8 columns", "found {$legacyCols}");

$inquiryId = $db->query("SELECT inquiry_id FROM messages WHERE inquiry_id IS NOT NULL LIMIT 1")->fetchColumn();
if ($inquiryId) {
    require_once BASE_PATH . '/models/Inquiry.php';
    $thread = (new Inquiry())->getMessages((int) $inquiryId);
    check(is_array($thread) && count($thread) > 0, "Inquiry::getMessages() still returns the reply thread for inquiry #{$inquiryId}");
} else {
    note('No inquiry replies in this database — thread check skipped.');
}

// ─── Result ────────────────────────────────────────────────────────────

echo "\n" . str_repeat('═', 70) . "\n";
printf("%d passed, %d failed\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo str_repeat('═', 70) . "\n";

exit($GLOBALS['fail'] === 0 ? 0 : 1);
