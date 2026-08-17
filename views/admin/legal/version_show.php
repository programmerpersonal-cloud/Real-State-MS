<?php
/**
 * Terms version — read-only view.
 *
 * The wording is the record here, so it gets the wide column and everything
 * about the record sits beside it. The hash is shown deliberately: it is what
 * makes an acceptance provable years later, and hiding it would make the
 * guarantee invisible.
 *
 * Expects: $version, $editable
 */
$v      = $version;
$vid    = (int) $v['id'];
$isLive = $v['status'] === 'active';
$accept = (int) ($v['acceptance_count'] ?? 0);

$legalUrl = APP_URL . '/index.php?page=legal';
?>
<div class="detail-cols">
    <div class="detail-cols__main">
        <?php if (!$editable && $v['status'] !== 'draft'): ?>
            <div class="alert alert--info">
                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                <div>
                    This wording is locked. <?= $accept > 0
                        ? 'It has been accepted ' . number_format($accept) . ' time' . ($accept === 1 ? '' : 's') . ', and'
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
                        <?= sanitize($v['name']) ?> · <?= sanitize($v['version_code']) ?>
                    </p>
                </div>
                <?= uiStatus($v['status']) ?>
            </div>
            <div class="card__body">
                <div class="prose">
                    <?= renderLegalText((string) $v['body']) ?>
                </div>
            </div>
        </div>
    </div>

    <aside class="detail-cols__side">
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">This version</h3></div>
            <div class="card__body">
                <dl class="datalist">
                    <div class="datalist__row"><dt>Status</dt><dd><?= uiStatus($v['status']) ?></dd></div>
                    <div class="datalist__row"><dt>Effective from</dt>
                        <dd class="num"><?= formatDate($v['effective_from']) ?></dd>
                    </div>
                    <div class="datalist__row"><dt>Effective to</dt>
                        <dd class="num">
                            <?= $v['effective_to'] ? formatDate($v['effective_to']) : '<span class="text-subtle">Still current</span>' ?>
                        </dd>
                    </div>
                    <div class="datalist__row"><dt>Written by</dt><dd><?= sanitize($v['created_by_name'] ?? '—') ?></dd></div>
                    <div class="datalist__row"><dt>Created</dt>
                        <dd class="num"><?= formatDateTime($v['created_at']) ?></dd>
                    </div>
                    <?php if (!empty($v['activated_at'])): ?>
                        <div class="datalist__row"><dt>Published</dt>
                            <dd class="num">
                                <?= formatDateTime($v['activated_at']) ?>
                                <div class="person__meta">by <?= sanitize($v['activated_by_name'] ?? '—') ?></div>
                            </dd>
                        </div>
                    <?php endif ?>
                    <?php if (!empty($v['summary'])): ?>
                        <div class="datalist__row"><dt>What changed</dt><dd><?= sanitize($v['summary']) ?></dd></div>
                    <?php endif ?>
                </dl>

                <div class="form-hint">
                    <i class="bi bi-fingerprint" aria-hidden="true"></i>
                    SHA-256 <code class="hash"><?= sanitize($v['content_hash']) ?></code>
                    — copied into every acceptance record, so the exact wording can always be proven.
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">Acceptances</h3></div>
            <div class="card__body">
                <div class="bignum"><?= number_format($accept) ?></div>
                <p class="form-hint">
                    <?= $accept === 0
                        ? 'Nobody has accepted this version yet.'
                        : 'These records are permanent and are never altered by later revisions.' ?>
                </p>
                <?php if ($accept > 0): ?>
                    <a class="btn btn--outline btn--sm" href="<?= $legalUrl ?>&amp;action=acceptances&amp;id=<?= $vid ?>">
                        <i class="bi bi-journal-check" aria-hidden="true"></i> View the log
                    </a>
                <?php endif ?>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h3 class="card__title">Manage</h3></div>
            <div class="card__body stack">
                <?php /* Publishing and withdrawing both change what customers are
                         asked to agree to, so both hand over to the shared dialog,
                         which can name the version. A browser prompt cannot. */ ?>
                <?php if ($editable): ?>
                    <a class="btn btn--primary btn--sm btn--block" href="<?= $legalUrl ?>&amp;action=edit&amp;id=<?= $vid ?>">
                        <i class="bi bi-pencil" aria-hidden="true"></i> Edit draft
                    </a>
                    <form method="post" action="<?= $legalUrl ?>&amp;action=publish">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $vid ?>">
                        <button class="btn btn--success btn--sm btn--block"
                                data-confirm="This becomes the wording presented for acceptance from its effective date. Any version currently live is superseded and kept on record — nothing already accepted changes."
                                data-confirm-title="Publish these terms?"
                                data-confirm-action="Publish"
                                data-confirm-record="<?= sanitize($v['version_code'] . ' · ' . $v['title']) ?>"
                                data-confirm-tone="primary">
                            <i class="bi bi-send" aria-hidden="true"></i> Publish
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= $legalUrl ?>&amp;action=revise">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $vid ?>">
                        <button class="btn btn--primary btn--sm btn--block">
                            <i class="bi bi-files" aria-hidden="true"></i> Revise into a new draft
                        </button>
                    </form>
                <?php endif ?>

                <?php if ($isLive): ?>
                    <form method="post" action="<?= $legalUrl ?>&amp;action=withdraw">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $vid ?>">
                        <button class="btn btn--outline btn--sm btn--block"
                                data-confirm="Nothing is published for this type until another version is. Bookings that require acceptance will have no terms to present."
                                data-confirm-title="Withdraw these terms?"
                                data-confirm-action="Withdraw"
                                data-confirm-record="<?= sanitize($v['version_code']) ?>"
                                data-confirm-tone="danger">
                            <i class="bi bi-x-circle" aria-hidden="true"></i> Withdraw
                        </button>
                    </form>
                <?php endif ?>

                <a class="btn btn--ghost btn--sm btn--block" href="<?= $legalUrl ?>">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> All terms
                </a>
            </div>
        </div>
    </aside>
</div>
