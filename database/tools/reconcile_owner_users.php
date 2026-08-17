<?php
/**
 * Owner ⇄ User reconciliation.
 *
 * Reports how the existing owners and user accounts line up, and — only when
 * asked, and only on evidence strong enough to be certain — writes the link
 * into owners.user_id.
 *
 * WHAT COUNTS AS THE SAME PERSON
 *   Exact match on normalised email (lowercased, trimmed) between an owner
 *   row and a user account. Nothing else is auto-linked: similar names, similar
 *   phones and "looks like the same person" are reported for a human to decide,
 *   because a wrong link hands one owner's income and property list to another.
 *
 * USAGE
 *   php database/tools/reconcile_owner_users.php                     # dry run, changes nothing
 *   php database/tools/reconcile_owner_users.php --apply             # writes the safe links
 *   php database/tools/reconcile_owner_users.php --create-profiles   # + build the missing
 *                                                                   #   owner profiles behind
 *                                                                   #   owner-role accounts
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

$owners = $db->query("SELECT id, full_name, phone, email, user_id FROM owners ORDER BY id")->fetchAll();
$users  = $db->query("
    SELECT u.id, u.full_name, u.email, u.username, u.phone, u.is_active, r.name AS role
    FROM users u JOIN roles r ON u.role_id = r.id
    ORDER BY u.id
")->fetchAll();

/** Owner rows already pointing at a user, so nothing is touched twice. */
$linkedUserIds = [];
foreach ($owners as $o) {
    if ($o['user_id']) $linkedUserIds[(int) $o['user_id']] = (int) $o['id'];
}

$usersByEmail = [];
foreach ($users as $u) {
    if ($norm($u['email']) !== '') $usersByEmail[$norm($u['email'])][] = $u;
}

$autoLink = [];      // certain: exact email match, both sides free
$review   = [];      // needs a human
$ownersNoAccount = [];

foreach ($owners as $o) {
    if ($o['user_id']) continue;

    $email      = $norm($o['email']);
    $candidates = $email !== '' ? ($usersByEmail[$email] ?? []) : [];

    if (!$candidates) {
        // No email match. Report anything that merely *resembles* a match.
        $loose = array_filter($users, fn($u) =>
            $norm($u['full_name']) === $norm($o['full_name']) ||
            ($norm($u['phone']) !== '' && $norm($u['phone']) === $norm($o['phone']))
        );
        if ($loose) {
            $review[] = [
                'owner'  => $o,
                'reason' => 'Name or phone resembles an account, but the emails do not match',
                'users'  => array_values($loose),
            ];
        } else {
            $ownersNoAccount[] = $o;
        }
        continue;
    }

    if (count($candidates) > 1) {
        $review[] = ['owner' => $o, 'reason' => 'Several accounts share this email', 'users' => $candidates];
        continue;
    }

    $user = $candidates[0];
    if (isset($linkedUserIds[(int) $user['id']])) {
        $review[] = [
            'owner'  => $o,
            'reason' => 'That account is already linked to owner #' . $linkedUserIds[(int) $user['id']],
            'users'  => [$user],
        ];
        continue;
    }
    if ($user['role'] !== 'owner') {
        $review[] = [
            'owner'  => $o,
            'reason' => "Account exists but its role is '{$user['role']}', not 'owner' — changing a role is a decision, not a migration",
            'users'  => [$user],
        ];
        continue;
    }

    $autoLink[] = ['owner' => $o, 'user' => $user];
    $linkedUserIds[(int) $user['id']] = (int) $o['id'];
}

/** Owner-role accounts with no owner profile behind them. */
$ownerRoleOrphans = array_values(array_filter(
    $users,
    fn($u) => $u['role'] === 'owner' && !isset($linkedUserIds[(int) $u['id']])
));

// ─── Report ──────────────────────────────────────────────────────────────
$line = str_repeat('─', 72);
echo "\n{$line}\nOWNER ⇄ USER RECONCILIATION" . ($apply ? '  (APPLYING)' : '  (dry run)') . "\n{$line}\n";
printf("Owners: %d    Users: %d    Already linked: %d\n\n", count($owners), count($users),
    count(array_filter($owners, fn($o) => $o['user_id'])));

echo "SAFE TO LINK — exact email match, account free, role already 'owner' (" . count($autoLink) . ")\n";
foreach ($autoLink as $pair) {
    printf("  owner #%d %-28s ⇄ user #%d %-28s %s\n",
        $pair['owner']['id'], $pair['owner']['full_name'],
        $pair['user']['id'], $pair['user']['full_name'], $pair['user']['email']);
}
if (!$autoLink) echo "  (none)\n";

echo "\nNEEDS MANUAL REVIEW (" . count($review) . ")\n";
foreach ($review as $item) {
    printf("  owner #%d %s <%s> phone %s\n    → %s\n",
        $item['owner']['id'], $item['owner']['full_name'],
        $item['owner']['email'] ?: 'no email', $item['owner']['phone'], $item['reason']);
    foreach ($item['users'] as $u) {
        printf("      candidate user #%d %s <%s> role=%s\n", $u['id'], $u['full_name'], $u['email'], $u['role']);
    }
}
if (!$review) echo "  (none)\n";

echo "\nOWNERS WITH NO ACCOUNT — expected for owners who never log in (" . count($ownersNoAccount) . ")\n";
foreach ($ownersNoAccount as $o) {
    printf("  owner #%d %s <%s>\n", $o['id'], $o['full_name'], $o['email'] ?: 'no email');
}
if (!$ownersNoAccount) echo "  (none)\n";

echo "\nOWNER-ROLE ACCOUNTS WITH NO OWNER PROFILE (" . count($ownerRoleOrphans) . ")\n";
foreach ($ownerRoleOrphans as $u) {
    printf("  user #%d %-24s <%s>%s\n", $u['id'], $u['full_name'], $u['email'],
        $createProfiles ? ' → profile will be created' : ' — run with --create-profiles, or change the role');
}
if (!$ownerRoleOrphans) echo "  (none)\n";

// ─── Apply ───────────────────────────────────────────────────────────────
if (!$apply) {
    echo "\n{$line}\nDry run — nothing was written."
       . "\n  --apply            link owners to the accounts they already match"
       . "\n  --create-profiles  also build the missing profiles behind owner-role accounts\n{$line}\n\n";
    exit(0);
}

$toCreate = $createProfiles ? $ownerRoleOrphans : [];
if (!$autoLink && !$toCreate) {
    echo "\n{$line}\nNothing to do — both sides already agree.\n{$line}\n\n";
    exit(0);
}

// One transaction for the whole reconciliation: it either lands as a
// consistent picture or not at all.
$db->beginTransaction();
try {
    $link = $db->prepare("UPDATE owners SET user_id = :uid WHERE id = :oid AND user_id IS NULL");
    foreach ($autoLink as $pair) {
        $link->execute([':uid' => (int) $pair['user']['id'], ':oid' => (int) $pair['owner']['id']]);
    }

    // The profile is built from the account's own details — no invented data,
    // and linked immediately so it can never become a second owner identity.
    $insert = $db->prepare("
        INSERT INTO owners (user_id, full_name, phone, email, commission_rate)
        VALUES (:uid, :name, :phone, :email, :rate)
    ");
    foreach ($toCreate as $u) {
        $insert->execute([
            ':uid'   => (int) $u['id'],
            ':name'  => $u['full_name'] ?: $u['username'],
            ':phone' => $u['phone'] ?: '',
            ':email' => $u['email'],
            ':rate'  => DEFAULT_COMMISSION_RATE,
        ]);
    }

    $db->commit();
    echo "\n{$line}\nLinked " . count($autoLink) . " owner(s); created " . count($toCreate) . " owner profile(s).\n";
    if ($toCreate) echo "Fill in phone and payout details for the new profiles when you have them.\n";
    echo "{$line}\n\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "\nFAILED — rolled back, nothing changed: " . $e->getMessage() . "\n\n");
    exit(1);
}
