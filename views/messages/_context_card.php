<?php
/**
 * The business context a conversation is about.
 *
 * Compact by design. The thread is the content; this is the label on the
 * folder it lives in, and a property card the size of a listing page would
 * push the first message below the fold on a laptop.
 *
 * Everything rendered here is read live from the current records — see
 * CommunicationController::conversationContext(). Nothing is snapshotted, so a
 * property sold last week reads "Sold" in a thread that started while it was
 * available, which is the honest answer.
 *
 * Shared by the thread header and the compose screen so a record cannot be
 * described one way before the conversation exists and another way after.
 *
 * Expects: $contextBlocks  array of blocks from conversationContext()
 * Optional: $contextEyebrow  overrides the leading word ("Regarding")
 */
if (empty($contextBlocks)) {
    return;
}
?>
<div class="msg__ctx">
    <?php if (!empty($contextEyebrow)): ?>
        <p class="msg__ctx-lead"><?= sanitize($contextEyebrow) ?></p>
    <?php endif; ?>

    <?php foreach ($contextBlocks as $b): ?>
        <?php
        $isLink  = !empty($b['url']);
        $tag     = $isLink ? 'a' : 'div';
        $href    = $isLink ? ' href="' . sanitize((string) $b['url']) . '"' : '';
        $classes = 'msg__ctx-card' . ($isLink ? ' msg__ctx-card--link' : '');
        ?>
        <<?= $tag ?> class="<?= $classes ?>"<?= $href ?>>

            <?php /* The property photograph, when there is one. Everything
                     else gets the glyph for its kind — a maintenance request
                     has no picture and inventing one would be noise. */ ?>
            <?php if (!empty($b['image']) && ($url = uploadUrl($b['image']))): ?>
                <img class="msg__ctx-thumb" src="<?= sanitize($url) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
                <span class="msg__ctx-thumb msg__ctx-thumb--glyph" aria-hidden="true">
                    <i class="bi <?= sanitize((string) $b['icon']) ?>"></i>
                </span>
            <?php endif; ?>

            <span class="msg__ctx-text">
                <span class="msg__ctx-eyebrow"><?= sanitize((string) $b['eyebrow']) ?></span>
                <span class="msg__ctx-title"><?= sanitize((string) $b['title']) ?></span>
                <?php if (($b['reference'] ?? '') !== ''): ?>
                    <span class="msg__ctx-ref"><?= sanitize((string) $b['reference']) ?></span>
                <?php endif; ?>
            </span>

            <span class="msg__ctx-meta">
                <?php if (!empty($b['gone'])): ?>
                    <?php /* The record behind the conversation is gone — the FK
                             set the column NULL. The correspondence outlives it,
                             so the thread stays readable and says why. */ ?>
                    <span class="status status--muted">
                        <i class="bi bi-dash-circle" aria-hidden="true"></i>Record removed
                    </span>
                <?php else: ?>
                    <?php if (($b['priority'] ?? '') !== ''): ?>
                        <?= uiPriority((string) $b['priority']) ?>
                    <?php endif; ?>
                    <?php if (($b['status'] ?? '') !== ''): ?>
                        <?= uiStatus((string) $b['status']) ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($isLink): ?>
                    <i class="bi bi-chevron-right msg__ctx-go" aria-hidden="true"></i>
                    <span class="sr-only">Open this record</span>
                <?php endif; ?>
            </span>
        </<?= $tag ?>>
    <?php endforeach; ?>
</div>
