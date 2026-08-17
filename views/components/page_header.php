<?php
/**
 * Page Header Component
 * Expects: $pageTitle, $breadcrumbs (passed to header), optional $pageSubtitle, $actionButton, $actionButtons
 *
 * An action may carry an 'attrs' map (e.g. ['data-modal-open' => 'id']) when
 * it drives something on the page rather than navigating away.
 */
$renderActionAttrs = static function (array $btn): string {
    $out = '';
    foreach ($btn['attrs'] ?? [] as $name => $value) {
        $out .= ' ' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8')
              . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
    }
    return $out;
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= sanitize($pageTitle ?? 'Page') ?></h1>
        <?php if (!empty($pageSubtitle)): ?>
            <p class="page-header__subtitle"><?= sanitize($pageSubtitle) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($actionButtons) && is_array($actionButtons)): ?>
        <div class="page-header__actions">
            <?php foreach ($actionButtons as $btn): ?>
                <a href="<?= $btn['url'] ?? '#' ?>" class="btn <?= $btn['class'] ?? 'btn--outline' ?>"<?= $renderActionAttrs($btn) ?>>
                    <?php if (!empty($btn['icon'])): ?><i class="bi <?= $btn['icon'] ?>"></i><?php endif; ?>
                    <?= sanitize($btn['label'] ?? 'Action') ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php elseif (!empty($actionButton)): ?>
        <div class="page-header__actions">
            <a href="<?= $actionButton['url'] ?? '#' ?>" class="btn <?= $actionButton['class'] ?? 'btn--primary' ?>"<?= $renderActionAttrs($actionButton) ?>>
                <i class="bi <?= $actionButton['icon'] ?? 'bi-plus-lg' ?>"></i>
                <?= sanitize($actionButton['label'] ?? 'Action') ?>
            </a>
        </div>
    <?php endif; ?>
</div>
