<?php
/**
 * Saxane Real Estate Management System
 * Seed Demo Users
 *
 * Run this script once to create demo accounts for each role.
 * Usage: Open in browser — http://localhost/Real-State-MS/Real-State-MS/database/seed_demo_users.php
 * Or run via CLI: php seed_demo_users.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();

// ─── Ensure roles exist ────────────────────────────────────────────────
$roles = [
    ['name' => 'admin',       'display_name' => 'Administrator'],
    ['name' => 'agent',       'display_name' => 'Agent'],
    ['name' => 'customer',    'display_name' => 'Customer'],
    ['name' => 'owner',       'display_name' => 'Owner'],
    ['name' => 'maintenance', 'display_name' => 'Maintenance'],
];

foreach ($roles as $role) {
    $check = $db->prepare("SELECT id FROM roles WHERE name = :name");
    $check->execute([':name' => $role['name']]);
    if (!$check->fetchColumn()) {
        $db->prepare("INSERT INTO roles (name, display_name) VALUES (:name, :display_name)")
           ->execute($role);
    }
}

// ─── Fetch role IDs ────────────────────────────────────────────────────
$roleIds = [];
$stmt = $db->query("SELECT id, name FROM roles");
while ($row = $stmt->fetch()) {
    $roleIds[$row['name']] = $row['id'];
}

// ─── Demo users ────────────────────────────────────────────────────────
$demoUsers = [
    [
        'full_name' => 'Demo Admin',
        'email'     => 'admin@saxane.com',
        'phone'     => '+1-555-0001',
        'username'  => 'admin',
        'password'  => 'admin123',
        'role'      => 'admin',
    ],
    [
        'full_name' => 'Demo Agent',
        'email'     => 'agent@saxane.com',
        'phone'     => '+1-555-0002',
        'username'  => 'agent',
        'password'  => 'agent123',
        'role'      => 'agent',
    ],
    [
        'full_name' => 'Demo Customer',
        'email'     => 'customer@saxane.com',
        'phone'     => '+1-555-0003',
        'username'  => 'customer',
        'password'  => 'customer123',
        'role'      => 'customer',
    ],
    [
        'full_name' => 'Demo Owner',
        'email'     => 'owner@saxane.com',
        'phone'     => '+1-555-0004',
        'username'  => 'owner',
        'password'  => 'owner123',
        'role'      => 'owner',
    ],
    [
        'full_name' => 'Demo Maintenance',
        'email'     => 'maintenance@saxane.com',
        'phone'     => '+1-555-0005',
        'username'  => 'maintenance',
        'password'  => 'maintenance123',
        'role'      => 'maintenance',
    ],
];

$created = 0;
$skipped = 0;

foreach ($demoUsers as $user) {
    // Skip if username already exists
    $check = $db->prepare("SELECT id FROM users WHERE username = :username");
    $check->execute([':username' => $user['username']]);

    if ($check->fetchColumn()) {
        // Update password to match demo credentials
        $db->prepare("UPDATE users SET password = :password, is_active = 1 WHERE username = :username")
           ->execute([
               ':password' => password_hash($user['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
               ':username' => $user['username'],
           ]);
        $skipped++;
        continue;
    }

    $db->prepare("
        INSERT INTO users (full_name, email, phone, username, password, role_id, is_active)
        VALUES (:full_name, :email, :phone, :username, :password, :role_id, 1)
    ")->execute([
        ':full_name' => $user['full_name'],
        ':email'     => $user['email'],
        ':phone'     => $user['phone'],
        ':username'  => $user['username'],
        ':password'  => password_hash($user['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
        ':role_id'   => $roleIds[$user['role']],
    ]);
    $created++;
}

// ─── Output ────────────────────────────────────────────────────────────
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    echo "=== Saxane Demo Users Seeder ===\n";
    echo "Created: {$created} | Updated: {$skipped}\n\n";
    foreach ($demoUsers as $u) {
        echo "  {$u['role']}  →  username: {$u['username']}  |  password: {$u['password']}\n";
    }
    echo "\nDone!\n";
} else {
    echo "<!DOCTYPE html><html><head><title>Demo Users Seeded</title>";
    echo "<style>body{font-family:system-ui;max-width:600px;margin:60px auto;padding:20px;color:#1e293b}";
    echo "h1{font-size:1.4rem;margin-bottom:8px}.badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:.78rem;font-weight:600}";
    echo ".badge--green{background:#ecfdf5;color:#16a34a}.badge--blue{background:#eff6ff;color:#2563eb}";
    echo "table{width:100%;border-collapse:collapse;margin-top:16px}th,td{padding:10px 14px;text-align:left;border-bottom:1px solid #e5e7eb}";
    echo "th{background:#f9fafb;font-size:.78rem;text-transform:uppercase;letter-spacing:.5px;color:#6b7280}";
    echo "code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:.85rem}</style></head><body>";
    echo "<h1>✅ Demo Users Seeded</h1>";
    echo "<p>Created: <span class='badge badge--green'>{$created}</span> &nbsp; Updated: <span class='badge badge--blue'>{$skipped}</span></p>";
    echo "<table><tr><th>Role</th><th>Username</th><th>Password</th></tr>";
    foreach ($demoUsers as $u) {
        echo "<tr><td>{$u['role']}</td><td><code>{$u['username']}</code></td><td><code>{$u['password']}</code></td></tr>";
    }
    echo "</table><p style='margin-top:20px'><a href='" . APP_URL . "/index.php?page=login'>← Back to Login</a></p></body></html>";
}
