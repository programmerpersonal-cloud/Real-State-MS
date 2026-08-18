<?php
/**
 * Page Header — three declensions of one component.
 *
 * Every page in the application opens with one of three shapes, and before
 * Phase 6 they all used the same one:
 *
 *   list    a register. Title, what it holds, and the primary action.
 *   record  one thing. Title with an eyebrow naming what kind of thing it is.
 *   form    creating or editing. Title, a way back, and nothing competing
 *           with the form's own Save.
 *
 * Set $pageHeaderVariant to pick one; 'list' is the default because most
 * pages are registers. The variant changes emphasis and what is allowed in the
 * bar, not the markup contract — every page keeps passing $pageTitle,
 * $pageSubtitle and $actionButton(s) exactly as before.
 *
 * The breadcrumb is deliberately absent: it lives in the topbar, and rendering
 * it twice on one screen is the commonest way an admin panel wastes its first
 * two inches.
 *
 * Expects:  $pageTitle
 * Optional: $pageSubtitle, $actionButton, $actionButtons, $pageHeaderVariant,
 *           $pageEyebrow, $backLink ['url'=>…, 'label'=>…], $pageMeta (html)
 *
 * An action may carry 'can' => 'module.action', and is then rendered only for
 * someone who holds it — the same contract list_toolbar.php uses, so the
 * primary action reads identically wherever it is placed. This mirrors the
 * permission; it never stands in for it. authorize() in the controller is
 * still what decides, and a hand-typed URL still gets a 403.
 */
$variant = $pageHeaderVariant ?? 'list';
if (!in_array($variant, ['list', 'record', 'form'], true)) {
    $variant = 'list';
}

$renderActionAttrs = static function (array $btn): string {
    $out = '';
    foreach ($btn['attrs'] ?? [] as $name => $value) {
        $out .= ' ' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8')
              . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
    }
    return $out;
};

/* One list either way, so the two branches below cannot drift. The single
   $actionButton keeps its historic default of btn--primary; a set of them
   defaults to outline, because a bar with three primaries has none. */
$actions = [];
if (!empty($actionButtons) && is_array($actionButtons)) {
    foreach ($actionButtons as $b) {
        $b['class'] = $b['class'] ?? 'btn--outline';
        $actions[] = $b;
    }
} elseif (!empty($actionButton)) {
    $b = $actionButton;
    $b['class'] = $b['class'] ?? 'btn--primary';
    $b['icon']  = $b['icon'] ?? 'bi-plus-lg';
    $actions[]  = $b;
}
?>
<div class="page-header page-header--<?= $variant ?>" data-page-header>
    <div class="page-header__lead">
        <?php if (!empty($backLink)): ?>
            <a class="page-header__back" href="<?= sanitize((string) ($backLink['url'] ?? '#')) ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <?= sanitize((string) ($backLink['label'] ?? 'Back')) ?>
            </a>
        <?php endif ?>

        <?php if (!empty($pageEyebrow)): ?>
            <div class="page-header__eyebrow"><?= sanitize($pageEyebrow) ?></div>
        <?php endif ?>

        <h1 class="page-header__title"><?= sanitize($pageTitle ?? 'Page') ?></h1>

        <?php if (!empty($pageSubtitle)): ?>
            <p class="page-header__subtitle"><?= sanitize($pageSubtitle) ?></p>
        <?php endif ?>

        <?php /* Pre-escaped by the caller — used for status pills and counts
                 that belong beside the title rather than under it. */ ?>
        <?php if (!empty($pageMeta)): ?>
            <div class="page-header__meta"><?= $pageMeta ?></div>
        <?php endif ?>
    </div>

    <?php if ($actions): ?>
        <div class="page-header__actions">
            <?php foreach ($actions as $btn): ?>
                <?php if (!empty($btn['can']) && !can((string) $btn['can'])) continue; ?>
                <a href="<?= $btn['url'] ?? '#' ?>"
                   class="btn <?= sanitize((string) $btn['class']) ?>"<?= $renderActionAttrs($btn) ?>>
                    <?php if (!empty($btn['icon'])): ?>
                        <i class="bi <?= sanitize((string) $btn['icon']) ?>" aria-hidden="true"></i>
                    <?php endif ?>
                    <?= sanitize((string) ($btn['label'] ?? 'Action')) ?>
                </a>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>
