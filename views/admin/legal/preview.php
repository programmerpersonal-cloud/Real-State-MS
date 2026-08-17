<?php
/**
 * Terms preview — renders unsaved draft text through the same function the
 * published page uses, so what an author sees here is exactly what readers get.
 *
 * Expects: $title, $body, $backUrl
 */
?>
<div class="alert alert--info" style="margin-bottom:16px">
    <i class="bi bi-eye"></i>
    <div>
        Preview of unsaved text, rendered by the server with the same function the public page uses.
        Nothing has been saved — close this tab and continue editing.
    </div>
</div>

<div class="card">
    <div class="card__header">
        <h3 class="card__title"><?= sanitize($title) ?></h3>
        <a class="btn btn--outline btn--sm" href="<?= sanitize($backUrl) ?>">
            <i class="bi bi-arrow-left"></i> Back to the editor
        </a>
    </div>
    <div class="card__body">
        <?php $html = renderLegalText($body); ?>
        <?php if (trim($html) === ''): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-file-earmark"></i></div>
                <div class="empty-state__title">Nothing to preview</div>
                <div class="empty-state__desc">Write some terms text first.</div>
            </div>
        <?php else: ?>
            <div class="legal" style="background:transparent;border:0;padding:0"><?= $html ?></div>
        <?php endif ?>
    </div>
</div>
