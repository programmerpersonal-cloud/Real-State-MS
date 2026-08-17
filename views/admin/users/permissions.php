<?php
/**
 * Roles & Permissions — the matrix, rendered from the matrix.
 *
 * This is not documentation. Every cell is read from permissionMatrix() at
 * request time, which is the same function can() and authorize() ask, so the
 * page cannot describe rules the application does not enforce. A capability
 * added to a role tomorrow shows up here without anyone editing this file.
 *
 * Read-only on purpose. The matrix is code, and code is where a change to who
 * may do what should be reviewed — a screen that edits it would put the
 * authority of the whole system behind one unaudited form.
 *
 * Vars from UserController::permissions().
 */
$roleNames = array_column($roles, 'name');

/** Does this role hold this permission? Same three rules as can(). */
$holds = static function (string $role, string $perm) use ($matrix): bool {
    $granted = $matrix[$role] ?? [];
    if (in_array('*', $granted, true)) {
        return true;
    }
    if (in_array($perm, $granted, true)) {
        return true;
    }
    [$module] = explode('.', $perm, 2);

    return in_array($module . '.*', $granted, true);
};

/** A role with the wildcard is described once rather than ticked 200 times. */
$isWildcard = static fn(string $role): bool => in_array('*', $matrix[$role] ?? [], true);

/* How many capabilities each role actually holds, for the summary row. */
$totals = [];
foreach ($roleNames as $role) {
    $n = 0;
    foreach ($groups as $actions) {
        foreach ($actions as $perm) {
            if ($holds($role, $perm)) {
                $n++;
            }
        }
    }
    $totals[$role] = $n;
}
$allPerms = array_sum(array_map('count', $groups));
?>

<div class="alert alert--info">
    <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
    <div>
        Every cell below is read from the permission matrix the application
        enforces, not from a description of it. Changing who may do what is a
        change to <code>includes/permissions.php</code>, so it goes through
        review — this page reports the result.
    </div>
</div>

<div class="detail-stats detail-stats--standalone">
    <?php foreach ($roles as $r): ?>
        <div class="detail-stat">
            <div class="detail-stat__label"><?= sanitize($r['display_name']) ?></div>
            <div class="detail-stat__value">
                <?php if ($isWildcard($r['name'])): ?>
                    All
                <?php else: ?>
                    <?= $totals[$r['name']] ?><span class="text-subtle">/<?= $allPerms ?></span>
                <?php endif ?>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="table-card">
    <div class="table-head">
        <div class="table-head__title">
            <?= count($groups) ?> modules · <?= $allPerms ?> capabilities
        </div>
        <span class="table-head__note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            A role with no tick is refused by the controller, not merely shown less
        </span>
    </div>

    <div class="table-wrap">
        <table class="table table--matrix">
            <thead>
                <tr>
                    <th scope="col">Capability</th>
                    <?php foreach ($roles as $r): ?>
                        <th scope="col" class="cell-center">
                            <?= sanitize($r['display_name']) ?>
                            <?php if ($isWildcard($r['name'])): ?>
                                <div class="person__meta">everything</div>
                            <?php endif ?>
                        </th>
                    <?php endforeach ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $module => $actions): ?>
                    <tr class="matrix__group">
                        <th scope="colgroup" colspan="<?= count($roles) + 1 ?>">
                            <?= sanitize(uiLabel((string) $module)) ?>
                        </th>
                    </tr>
                    <?php foreach ($actions as $action => $perm): ?>
                        <tr>
                            <th scope="row" class="matrix__row">
                                <?= sanitize(uiLabel((string) $action)) ?>
                                <div class="person__meta"><?= sanitize($perm) ?></div>
                            </th>
                            <?php foreach ($roleNames as $role): ?>
                                <?php $yes = $holds($role, $perm); ?>
                                <td class="cell-center">
                                    <?php if ($yes): ?>
                                        <i class="bi bi-check-circle-fill matrix__yes" aria-hidden="true"></i>
                                        <span class="sr-only">Allowed</span>
                                    <?php else: ?>
                                        <i class="bi bi-dash matrix__no" aria-hidden="true"></i>
                                        <span class="sr-only">Not allowed</span>
                                    <?php endif ?>
                                </td>
                            <?php endforeach ?>
                        </tr>
                    <?php endforeach ?>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
