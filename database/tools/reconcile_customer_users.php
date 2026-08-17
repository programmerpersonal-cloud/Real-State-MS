<?php
/**
 * Customer ⇄ User reconciliation.
 *
 * The tenant half of database/tools/reconcile_owner_users.php, and it exists
 * for the same reason: a tenant's permissions are read through
 * customers.user_id. A customer row with no account behind it is fine — most
 * tenants never log in — but a tenant *account* with no customer row behind it
 * can do nothing. It cannot see its lease, its payments, and since the
 * maintenance scope landed it cannot report a fault either, because there is
 * no link saying which property is theirs.
 *
 * WHAT COUNTS AS THE SAME PERSON
 *   Exact match on normalised email (lowercased, trimmed) between a customer
 *   row and a user account. Nothing else is auto-linked. A wrong link here
 *   hands one tenant the right to file against another tenant's home, so
 *   similar names and matching phone numbers are reported for a human to
 *   decide rather than guessed at.
 *
 * USAGE
 *   php database/tools/reconcile_customer_users.php                   # dry run, changes nothing
 *   php database/tools/reconcile_customer_users.php --apply           # writes the safe links
 *   php database/tools/reconcile_customer_users.php --create-profiles # + build the missing
 *                                                                     #   customer profiles behind
 *                                                                     #   customer-role accounts
 *
 * Safe to run repeatedly: already-linked rows are skipped.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

$createProfiles = in_array('--create-profiles', $argv, true);
$apply          = in_array('--apply', $argv, true) || $createProfiles;
$db             = getDBConnection();

$norm = static fn(?string $v): string => strtolower(trim((string) $v));

$customers = $db->query("SELECT id, full_name, phone, email, user_id, customer_type FROM customers ORDER BY id")->fetchAll();
$users     = $db->query("
    SELECT u.id, u.full_name, u.email, u.username, u.phone, u.is_active, r.name AS role
    FROM users u JOIN roles r ON u.role_id = r.id
    ORDER BY u.id
")->fetchAll();

/** Customer rows already pointing at a user, so nothing is touched twice. */
$linkedUserIds = [];
foreach ($customers as $c) {
    if ($c['user_id']) $linkedUserIds[(int) $c['user_id']] = (int) $c['id'];
}

$usersByEmail = [];
foreach ($users as $u) {
    if ($norm($u['email']) !== '') $usersByEmail[$norm($u['email'])][] = $u;
}

$autoLink = [];         // certain: exact email match, both sides free
$review   = [];         // needs a human
$customersNoAccount = [];

foreach ($customers as $c) {
    if ($c['user_id']) continue;

    $email      = $norm($c['email']);
    $candidates = $email !== '' ? ($usersByEmail[$email] ?? []) : [];

    if (!$candidates) {
        // No email match. Report anything that merely *resembles* a match.
        $loose = array_filter($users, fn($u) =>
            $norm($u['full_name']) === $norm($c['full_name']) ||
            ($norm($u['phone']) !== '' && $norm($u['phone']) === $norm($c['phone']))
        );
        if ($loose) {
            $review[] = [
                'customer' => $c,
                'reason'   => 'Name or phone resembles an account, but the emails do not match',
                'users'    => array_values($loose),
            ];
        } else {
            $customersNoAccount[] = $c;
        }
        continue;
    }

    if (count($candidates) > 1) {
        $review[] = ['customer' => $c, 'reason' => 'Several accounts share this email', 'users' => $candidates];
        continue;
    }

    $user = $candidates[0];
    if (isset($linkedUserIds[(int) $user['id']])) {
        $review[] = [
            'customer' => $c,
            'reason'   => 'That account is already linked to customer #' . $linkedUserIds[(int) $user['id']],
            'users'    => [$user],
        ];
        continue;
    }
    if ($user['role'] !== 'customer') {
        $review[] = [
            'customer' => $c,
            'reason'   => "Account exists but its role is '{$user['role']}', not 'customer' — changing a role is a decision, not a migration",
            'users'    => [$user],
        ];
        continue;
    }

    $autoLink[] = ['customer' => $c, 'user' => $user];
    $linkedUserIds[(int) $user['id']] = (int) $c['id'];
}

/** Customer-role accounts with no customer profile behind them. */
$customerRoleOrphans = array_values(array_filter(
    $users,
    fn($u) => $u['role'] === 'customer' && !isset($linkedUserIds[(int) $u['id']])
));

// ─── Report ──────────────────────────────────────────────────────────────
$line = str_repeat('─', 72);
echo "\n{$line}\nCUSTOMER ⇄ USER RECONCILIATION" . ($apply ? '  (APPLYING)' : '  (dry run)') . "\n{$line}\n";
printf("Customers: %d    Users: %d    Already linked: %d\n\n", count($customers), count($users),
    count(array_filter($customers, fn($c) => $c['user_id'])));

echo "SAFE TO LINK — exact email match, account free, role already 'customer' (" . count($autoLink) . ")\n";
foreach ($autoLink as $pair) {
    printf("  customer #%d %-25s ⇄ user #%d %-25s %s\n",
        $pair['customer']['id'], $pair['customer']['full_name'],
        $pair['user']['id'], $pair['user']['full_name'], $pair['user']['email']);
}
if (!$autoLink) echo "  (none)\n";

echo "\nNEEDS MANUAL REVIEW (" . count($review) . ")\n";
foreach ($review as $item) {
    printf("  customer #%d %s <%s> phone %s\n    → %s\n",
        $item['customer']['id'], $item['customer']['full_name'],
        $item['customer']['email'] ?: 'no email', $item['customer']['phone'], $item['reason']);
    foreach ($item['users'] as $u) {
        printf("      candidate user #%d %s <%s> role=%s\n", $u['id'], $u['full_name'], $u['email'], $u['role']);
    }
}
if (!$review) echo "  (none)\n";

echo "\nCUSTOMERS WITH NO ACCOUNT — expected for tenants who never log in (" . count($customersNoAccount) . ")\n";
foreach ($customersNoAccount as $c) {
    printf("  customer #%d %s <%s>\n", $c['id'], $c['full_name'], $c['email'] ?: 'no email');
}
if (!$customersNoAccount) echo "  (none)\n";

echo "\nCUSTOMER-ROLE ACCOUNTS WITH NO CUSTOMER PROFILE (" . count($customerRoleOrphans) . ")\n";
echo "  These accounts cannot see a lease or report a maintenance issue until\n";
echo "  they are linked to the customer row that holds their tenancy.\n";
foreach ($customerRoleOrphans as $u) {
    printf("  user #%d %-24s <%s>%s\n", $u['id'], $u['full_name'], $u['email'],
        $createProfiles ? ' → profile will be created' : ' — run with --create-profiles, or link it by hand');
}
if (!$customerRoleOrphans) echo "  (none)\n";

// ─── Apply ───────────────────────────────────────────────────────────────
if (!$apply) {
    echo "\n{$line}\nDry run — nothing was written."
       . "\n  --apply            link customers to the accounts they already match"
       . "\n  --create-profiles  also build the missing profiles behind customer-role accounts\n{$line}\n\n";
    exit(0);
}

$toCreate = $createProfiles ? $customerRoleOrphans : [];
if (!$autoLink && !$toCreate) {
    echo "\n{$line}\nNothing to do — both sides already agree.\n{$line}\n\n";
    exit(0);
}

// One transaction for the whole reconciliation: it either lands as a
// consistent picture or not at all.
$db->beginTransaction();
try {
    $link = $db->prepare("UPDATE customers SET user_id = :uid WHERE id = :cid AND user_id IS NULL");
    foreach ($autoLink as $pair) {
        $link->execute([':uid' => (int) $pair['user']['id'], ':cid' => (int) $pair['customer']['id']]);
    }

    // The profile is built from the account's own details — no invented data,
    // and linked immediately so it can never become a second tenant identity.
    // A fresh profile holds no lease, so it grants no property access until
    // someone records the tenancy; creating it only unblocks the portal.
    $insert = $db->prepare("
        INSERT INTO customers (user_id, full_name, email, phone, customer_type)
        VALUES (:uid, :name, :email, :phone, 'tenant')
    ");
    foreach ($toCreate as $u) {
        $insert->execute([
            ':uid'   => (int) $u['id'],
            ':name'  => $u['full_name'] ?: $u['username'],
            ':email' => $u['email'],
            ':phone' => $u['phone'] ?: '',
        ]);
    }

    $db->commit();
    echo "\n{$line}\nLinked " . count($autoLink) . " customer(s); created " . count($toCreate) . " customer profile(s).\n";
    if ($toCreate) echo "A new profile has no lease attached, so it still cannot report a fault —\n"
                      . "record the tenancy under Leases to complete the link.\n";
    echo "{$line}\n\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "\nFAILED — rolled back, nothing changed: " . $e->getMessage() . "\n\n");
    exit(1);
}
