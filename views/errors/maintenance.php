<?php
/**
 * 503 — Maintenance mode
 *
 * Shown to everyone while a restore is running, and to nobody otherwise.
 * Deliberately standalone: it does not go through layout.php, because the
 * layout draws a sidebar and a notification bell from the database, and this
 * page exists precisely for the minutes when the database is being written
 * over. Nothing here queries anything.
 *
 * Styling is inline for the same reason — a stylesheet is a second request
 * that has to survive whatever is happening to the server right now.
 *
 * Expects: $maintenanceInfo  from backupMaintenanceInfo()
 */
$reason  = (string) ($maintenanceInfo['reason'] ?? 'The system is briefly unavailable.');
$started = $maintenanceInfo['started_at'] ?? null;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Maintenance in progress</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            padding: 24px; background: #f6fafc; color: #1d3340;
            font: 400 0.95rem/1.65 'Inter', -apple-system, 'Segoe UI', sans-serif;
        }
        .panel {
            max-width: 460px; width: 100%; background: #fff; border: 1px solid #e4eaee;
            border-radius: 12px; padding: 40px 32px; text-align: center;
            box-shadow: 0 2px 4px -2px rgba(29,51,64,.06), 0 8px 20px -6px rgba(29,51,64,.11);
        }
        .icon {
            width: 56px; height: 56px; margin: 0 auto 20px; border-radius: 14px;
            display: grid; place-items: center; background: #e6f2fa; color: #005f9e;
            font-size: 26px; line-height: 1;
        }
        h1 { margin: 0 0 10px; font-size: 1.3rem; letter-spacing: -.01em; }
        p  { margin: 0 0 8px; color: #4c566a; }
        .meta { margin-top: 20px; font-size: .78rem; color: #6b7688; }
        @media (prefers-reduced-motion: no-preference) {
            .icon { animation: pulse 2.4s ease-in-out infinite; }
            @keyframes pulse { 0%,100% { opacity: 1 } 50% { opacity: .55 } }
        }
    </style>
</head>
<body>
    <main class="panel">
        <div class="icon" aria-hidden="true">&#9881;</div>
        <h1>Maintenance in progress</h1>
        <p><?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?></p>
        <p>This page refreshes on its own. No action is needed.</p>
        <?php if ($started): ?>
            <div class="meta">Started <?= htmlspecialchars($started, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif ?>
    </main>
    <?php /* Matches the Retry-After header the router sends, so the browser and
             any uptime monitor agree on when to look again. */ ?>
    <script>setTimeout(function () { location.reload(); }, 120000);</script>
</body>
</html>
