<?php
/**
 * Terms version — draft editor.
 *
 * Only ever reached for a draft: LegalController::edit() bounces anything
 * published before it gets here.
 *
 * Expects: $type, $version (null when new), $formData, $nextCode
 */
$fd    = $formData ?? [];
$isNew = empty($version);
$backUrl = $isNew
    ? APP_URL . '/index.php?page=legal&action=create&doc=' . (int) $type['id']
    : APP_URL . '/index.php?page=legal&action=edit&id=' . (int) $version['id'];
$formAction = $isNew
    ? APP_URL . '/index.php?page=legal&action=create&doc=' . (int) $type['id']
    : APP_URL . '/index.php?page=legal&action=edit&id=' . (int) $version['id'];
?>
<form method="POST" data-validate action="<?= $formAction ?>">
    <?= csrfField() ?>
    <input type="hidden" name="terms_document_id" value="<?= (int) $type['id'] ?>">
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $version['id'] ?>"><?php endif ?>

    <div class="card mb-3">
        <div class="card__header">
            <div>
                <h3 class="card__title"><?= sanitize($type['name']) ?> · <code><?= sanitize($nextCode) ?></code></h3>
                <p class="card__subtitle">
                    A draft is private until you publish it. Publishing supersedes the current live version.
                </p>
            </div>
            <span class="badge badge--muted">Draft</span>
        </div>

        <div class="card__body">
            <div class="form-group">
                <label class="form-label" for="tv-title">Title *</label>
                <input type="text" id="tv-title" name="title" class="form-control" required maxlength="200"
                       value="<?= sanitize($fd['title'] ?? ($type['name'] . ' ' . $nextCode)) ?>">
            </div>

            <div class="form-grid--2">
                <div class="form-group">
                    <label class="form-label" for="tv-summary">What changed</label>
                    <input type="text" id="tv-summary" name="summary" class="form-control" maxlength="255"
                           value="<?= sanitize($fd['summary'] ?? '') ?>"
                           placeholder="e.g. Deposit refund window extended to 21 days">
                    <div class="form-hint">Shown in the version history so the reason for a revision stays on record.</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="tv-from">Effective from</label>
                    <input type="date" id="tv-from" name="effective_from" class="form-control"
                           value="<?= sanitize(substr((string) ($fd['effective_from'] ?? ''), 0, 10)) ?>">
                    <div class="form-hint">Defaults to the day you publish.</div>
                </div>
            </div>

            <h4 class="form-section">Terms text</h4>

            <div class="form-group">
                <label class="form-label" for="tv-body">Body *</label>
                <textarea id="tv-body" name="body" class="form-control" rows="22" required
                          style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem;line-height:1.6"
                          placeholder="## 1. Deposit&#10;&#10;The **deposit** is refundable within 14 days provided that:&#10;&#10;- the property is undamaged&#10;- all rent is settled"><?= sanitize($fd['body'] ?? '') ?></textarea>
                <div class="form-hint">
                    Plain text with a few conventions:
                    <code>## Heading</code>, <code>### Sub-heading</code>, <code>- bullet</code>,
                    <code>1. numbered</code>, <code>**bold**</code>, <code>*italic*</code>,
                    <code>[label](https://…)</code>. A blank line starts a new paragraph.
                    HTML is escaped rather than rendered, so nothing typed here can run in a browser.
                </div>
            </div>
        </div>

        <div class="card__footer" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button type="submit" class="btn btn--primary"><i class="bi bi-save"></i> Save draft</button>
            <button type="submit" name="publish" value="1" class="btn btn--success"
                    onclick="return confirm('Save and publish? Any live version is superseded and kept on record.')">
                <i class="bi bi-send"></i> Save &amp; publish
            </button>
            <button type="submit" class="btn btn--outline" formaction="<?= APP_URL ?>/index.php?page=legal&amp;action=preview"
                    formtarget="_blank" formnovalidate>
                <i class="bi bi-eye"></i> Preview
            </button>
            <a class="btn btn--outline" href="<?= APP_URL ?>/index.php?page=legal">Cancel</a>
            <span class="text-muted" style="font-size:.78rem;margin-left:auto">
                <i class="bi bi-info-circle"></i> Preview renders on the server, so it matches exactly what gets published.
            </span>
        </div>
    </div>

    <input type="hidden" name="back_url" value="<?= sanitize($backUrl) ?>">
</form>

<?php if (!$isNew && (int) ($version['acceptance_count'] ?? 0) === 0): ?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Discard</h3></div>
    <div class="card__body">
        <form method="post" action="<?= APP_URL ?>/index.php?page=legal&amp;action=delete-draft">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $version['id'] ?>">
            <button class="btn btn--danger btn--sm" onclick="return confirm('Delete this draft?')">
                <i class="bi bi-trash"></i> Delete draft
            </button>
            <div class="form-hint">Only unpublished drafts can be deleted.</div>
        </form>
    </div>
</div>
<?php endif ?>
