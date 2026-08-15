<?php
/**
 * Settings — Index
 * Expects: $grouped (group => rows), $groupMeta, $schema, $lastUpdated
 */
$groups = array_keys($grouped);
$first  = $groups[0] ?? null;

/** Chrome for a group that has no entry in the controller's GROUPS map. */
$metaFor = function (string $g) use ($groupMeta): array {
    return $groupMeta[$g] ?? [
        'label' => ucwords(str_replace('_', ' ', $g)),
        'icon'  => 'bi-sliders',
        'desc'  => '',
    ];
};

// code => symbol, for the currency auto-fill in the browser.
$symbolMap = [];
foreach (SettingsController::CURRENCIES as $code => [$name, $symbol]) {
    $symbolMap[$code] = $symbol;
}
?>
<form method="post" class="settings" id="settingsForm" enctype="multipart/form-data" novalidate>
    <?= csrfField() ?>

    <div class="settings__layout">
        <!-- ── Section nav ───────────────────────────────── -->
        <aside class="settings__nav" data-settings-nav>
            <?php foreach ($groups as $g): $m = $metaFor($g); ?>
                <button type="button"
                        class="settings-nav__item<?= $g === $first ? ' is-active' : '' ?>"
                        data-section="<?= sanitize($g) ?>">
                    <i class="bi <?= sanitize($m['icon']) ?>"></i>
                    <span class="settings-nav__label"><?= sanitize($m['label']) ?></span>
                    <span class="settings-nav__count"><?= count($grouped[$g]) ?></span>
                </button>
            <?php endforeach ?>

            <?php if (!empty($lastUpdated)): ?>
                <div class="settings-nav__meta">
                    <i class="bi bi-clock-history"></i>
                    Last updated <?= formatDateTime($lastUpdated) ?>
                </div>
            <?php endif ?>
        </aside>

        <!-- ── Panels ────────────────────────────────────── -->
        <div class="settings__panels">
            <?php foreach ($grouped as $g => $items): $m = $metaFor($g); ?>
                <section class="settings-panel<?= $g === $first ? ' is-active' : '' ?>" data-section-panel="<?= sanitize($g) ?>">
                    <div class="card">
                        <div class="card__header settings-panel__header">
                            <div class="settings-panel__heading">
                                <span class="settings-panel__icon"><i class="bi <?= sanitize($m['icon']) ?>"></i></span>
                                <div>
                                    <h3 class="card__title"><?= sanitize($m['label']) ?></h3>
                                    <?php if (!empty($m['desc'])): ?>
                                        <p class="card__subtitle"><?= sanitize($m['desc']) ?></p>
                                    <?php endif ?>
                                </div>
                            </div>
                        </div>

                        <div class="card__body settings-panel__body">
                            <?php foreach ($items as $s):
                                $key   = $s['setting_key'];
                                $val   = (string)($s['setting_value'] ?? '');
                                $f     = $schema[$key] ?? [];
                                $type  = $f['type'] ?? 'text';
                                $label = $f['label'] ?? ucwords(str_replace('_', ' ', $key));
                                $name  = 'settings[' . $key . ']';
                                $id    = 'set_' . $key;
                            ?>
                            <div class="setting-row<?= !empty($f['full']) || $type === 'textarea' ? ' setting-row--stacked' : '' ?>">
                                <div class="setting-row__info">
                                    <label class="setting-row__label" for="<?= $id ?>">
                                        <?= sanitize($label) ?>
                                        <?php if (!empty($f['required'])): ?><span class="setting-row__req" title="Required">*</span><?php endif ?>
                                    </label>
                                    <?php if (!empty($f['hint'])): ?>
                                        <p class="setting-row__hint"><?= sanitize($f['hint']) ?></p>
                                    <?php endif ?>
                                </div>

                                <div class="setting-row__control">
                                    <?php if ($type === 'toggle'): ?>
                                        <input type="hidden" name="<?= $name ?>" value="0">
                                        <label class="switch">
                                            <input type="checkbox" id="<?= $id ?>" name="<?= $name ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>>
                                            <span class="switch__track"><span class="switch__thumb"></span></span>
                                            <span class="switch__text" data-on="Enabled" data-off="Disabled"><?= $val === '1' ? 'Enabled' : 'Disabled' ?></span>
                                        </label>

                                    <?php elseif ($type === 'textarea'): ?>
                                        <textarea class="form-control" id="<?= $id ?>" name="<?= $name ?>" rows="3"
                                                  maxlength="<?= (int)($f['max'] ?? 400) ?>"
                                                  placeholder="<?= sanitize($f['placeholder'] ?? '') ?>"><?= sanitize($val) ?></textarea>

                                    <?php elseif ($type === 'select'): ?>
                                        <?php $opts = $f['options'] ?? []; ?>
                                        <select class="form-control" id="<?= $id ?>" name="<?= $name ?>"
                                                <?= $key === 'currency' ? 'data-currency-select' : '' ?>>
                                            <?php if ($val !== '' && !array_key_exists($val, $opts)): ?>
                                                <option value="<?= sanitize($val) ?>" selected><?= sanitize($val) ?> (current)</option>
                                            <?php endif ?>
                                            <?php foreach ($opts as $ov => $ol): ?>
                                                <option value="<?= sanitize((string)$ov) ?>" <?= (string)$ov === $val ? 'selected' : '' ?>>
                                                    <?= sanitize((string)$ol) ?>
                                                </option>
                                            <?php endforeach ?>
                                        </select>

                                    <?php elseif ($type === 'logo'): ?>
                                        <?php $logoUrl = companyLogoUrl(); ?>
                                        <div class="logo-field" data-logo-field>
                                            <div class="logo-field__preview" data-logo-preview>
                                                <?php if ($logoUrl !== ''): ?>
                                                    <img src="<?= sanitize($logoUrl) ?>" alt="Current company logo">
                                                <?php else: ?>
                                                    <i class="bi bi-image"></i>
                                                <?php endif ?>
                                            </div>
                                            <div class="logo-field__body">
                                                <input type="file" id="<?= $id ?>" name="company_logo_file"
                                                       accept="image/png,image/jpeg,image/webp,image/gif"
                                                       class="logo-field__input" data-logo-input>
                                                <div class="logo-field__actions">
                                                    <label class="btn btn--outline btn--sm" for="<?= $id ?>">
                                                        <i class="bi bi-upload"></i>
                                                        <?= $logoUrl !== '' ? 'Replace logo' : 'Choose image' ?>
                                                    </label>
                                                    <span class="logo-field__file" data-logo-filename>
                                                        <?= $logoUrl !== '' ? 'Current logo in use' : 'No file chosen' ?>
                                                    </span>
                                                </div>
                                                <?php if ($val !== ''): ?>
                                                    <label class="logo-field__remove">
                                                        <input type="checkbox" name="remove_company_logo" value="1" data-logo-remove>
                                                        Remove the current logo
                                                    </label>
                                                <?php endif ?>
                                            </div>
                                        </div>

                                    <?php elseif ($type === 'number'): ?>
                                        <div class="input-affix<?= !empty($f['suffix']) ? ' input-affix--suffix' : '' ?>">
                                            <input class="form-control" id="<?= $id ?>" type="number" name="<?= $name ?>"
                                                   value="<?= sanitize($val) ?>"
                                                   min="<?= sanitize((string)($f['min'] ?? 0)) ?>"
                                                   max="<?= sanitize((string)($f['max'] ?? 100)) ?>"
                                                   step="<?= sanitize((string)($f['step'] ?? '1')) ?>">
                                            <?php if (!empty($f['suffix'])): ?>
                                                <span class="input-affix__tag"><?= sanitize($f['suffix']) ?></span>
                                            <?php endif ?>
                                        </div>

                                    <?php else: ?>
                                        <div class="input-affix<?= !empty($f['icon']) ? ' input-affix--icon' : '' ?>">
                                            <?php if (!empty($f['icon'])): ?>
                                                <i class="bi <?= sanitize($f['icon']) ?> input-affix__icon"></i>
                                            <?php endif ?>
                                            <input class="form-control" id="<?= $id ?>"
                                                   type="<?= in_array($type, ['email', 'tel', 'url'], true) ? $type : 'text' ?>"
                                                   name="<?= $name ?>" value="<?= sanitize($val) ?>"
                                                   maxlength="<?= (int)($f['max'] ?? 255) ?>"
                                                   placeholder="<?= sanitize($f['placeholder'] ?? '') ?>"
                                                   <?= $key === 'currency_symbol' ? 'data-currency-symbol' : '' ?>>
                                        </div>
                                    <?php endif ?>
                                </div>
                            </div>
                            <?php endforeach ?>
                        </div>
                    </div>
                </section>
            <?php endforeach ?>
        </div>
    </div>

    <!-- ── Sticky save bar ───────────────────────────────── -->
    <div class="settings-bar" data-settings-bar>
        <div class="settings-bar__status">
            <i class="bi bi-shield-lock"></i>
            <span data-settings-status>All changes saved</span>
        </div>
        <div class="settings-bar__actions">
            <button type="reset" class="btn btn--outline btn--sm" data-settings-reset>Discard</button>
            <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save Settings</button>
        </div>
    </div>
</form>

<script>
  window.__currencySymbols = <?= json_encode($symbolMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
