<?php
/**
 * Terms version — read-only view.
 *
 * Expects: $version, $editable
 */
$v      = $version;
$isLive = $v['status'] === 'active';
$accept = (int) ($v['acceptance_count'] ?? 0);
?>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px">
    <div>
        <?php if (!$editable && $v['status'] !== 'draft'): ?>
        <div class="alert alert--info" style="margin-bottom:16px">
            <i class="bi bi-lock"></i>
            <div>
                This wording is locked. <?= $accept > 0
                    ? 'It has been accepted ' . $accept . ' time' . ($accept === 1 ? '' : 's') . ', and'
                    : 'Once published, a version' ?>
                editing it would change what those records refer to.
                Use <strong>Revise</strong> to start a new draft from this text.
            </div>
        </div>
        <?php endif ?>

        <div class="card">
            <div class="card__header">
                <div>
                    <h3 class="card__title"><?= sanitize($v['title']) ?></h3>
                    <p class="card__subtitle">
                        <?= sanitize($v['name']) ?> · <code><?= sanitize($v['version_code']) ?></code>
                    </p>
                </div>
                <span class="badge <?= getStatusBadgeClass($v['status']) ?>"><?= ucfirst($v['status']) ?></span>
            </div>
            <div class="card__body">
                <div class="legal" style="background:transparent;border:0;padding:0">
                    <?= renderLegalText((string) $v['body']) ?>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Version</h3></div>
            <div class="card__body">
                <table style="width:100%;font-size:.875rem">
                    <tr><td class="text-muted" style="padding:8px 0;width:45%">Status</td>
                        <td style="padding:8px 0"><span class="badge <?= getStatusBadgeClass($v['status']) ?>"><?= ucfirst($v['status']) ?></span></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Effective from</td>
                        <td style="padding:8px 0"><?= formatDate($v['effective_from']) ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Effective to</td>
                        <td style="padding:8px 0"><?= $v['effective_to'] ? formatDate($v['effective_to']) : '<span class="text-subtle">Current</span>' ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Written by</td>
                        <td style="padding:8px 0"><?= sanitize($v['created_by_name'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted" style="padding:8px 0">Created</td>
                        <td style="padding:8px 0"><?= formatDateTime($v['created_at']) ?></td></tr>
                    <?php if (!empty($v['activated_at'])): ?>
                    <tr><td class="text-muted" style="padding:8px 0">Published</td>
                        <td style="padding:8px 0"><?= formatDateTime($v['activated_at']) ?><br>
                            <span class="text-muted" style="font-size:.75rem">by <?= sanitize($v['activated_by_name'] ?? '—') ?></span></td></tr>
                    <?php endif ?>
                    <?php if (!empty($v['summary'])): ?>
                    <tr><td class="text-muted" style="padding:8px 0">What changed</td>
                        <td style="padding:8px 0"><?= sanitize($v['summary']) ?></td></tr>
                    <?php endif ?>
                </table>

                <div class="form-hint" style="margin-top:12px">
                    <i class="bi bi-fingerprint"></i>
                    SHA-256 <code style="font-size:.68rem;word-break:break-all"><?= sanitize($v['content_hash']) ?></code>
                    — copied into every acceptance record so the exact wording can always be proven.
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Acceptances</h3></div>
            <div class="card__body">
                <div style="font-size:1.6rem;font-weight:700"><?= $accept ?></div>
                <p class="text-muted" style="font-size:.82rem;margin:4px 0 12px">
                    <?= $accept === 0
                        ? 'Nobody has accepted this version yet.'
                        : 'These records are permanent and are never altered by later revisions.' ?>
                </p>
                <?php if ($accept > 0): ?>
                    <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=legal&amp;action=acceptances&amp;id=<?= (int) $v['id'] ?>">
                        <i class="bi bi-journal-check"></i> View the log
                    </a>
                <?php endif ?>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h3 class="card__title">Manage</h3></div>
            <div class="card__body" style="display:flex;flex-direction:column;gap:10px">
                <?php if ($editable): ?>
                    <a class="btn btn--primary btn--sm" href="<?= APP_URL ?>/index.php?page=legal&amp;action=edit&amp;id=<?= (int) $v['id'] ?>">
                        <i class="bi bi-pencil"></i> Edit draft
                    </a>
                    <form method="post" action="<?= APP_URL ?>/index.php?page=legal&amp;action=publish">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                        <button class="btn btn--success btn--sm" style="width:100%"
                                onclick="return confirm('Publish this version? Any live version is superseded and kept on record.')">
                            <i class="bi bi-send"></i> Publish
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= APP_URL ?>/index.php?page=legal&amp;action=revise">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                        <button class="btn btn--primary btn--sm" style="width:100%">
                            <i class="bi bi-files"></i> Revise into a new draft
                        </button>
                    </form>
                <?php endif ?>

                <?php if ($isLive): ?>
                    <form method="post" action="<?= APP_URL ?>/index.php?page=legal&amp;action=withdraw">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                        <button class="btn btn--outline btn--sm" style="width:100%"
                                onclick="return confirm('Withdraw these terms? Nothing will be published for this type until you publish another version.')">
                            <i class="bi bi-x-circle"></i> Withdraw
                        </button>
                    </form>
                <?php endif ?>

                <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=legal">
                    <i class="bi bi-arrow-left"></i> All terms
                </a>
            </div>
        </div>
    </div>
</div>
