<?php
/**
 * List toolbar — search, filters, view switch and page actions.
 *
 * One bar for every register in the system. Rebuilt in step 2.1: the previous
 * version laid every control out in one wide row, so a module with three
 * filters spent a full band of the screen on a row of 130px-wide <select>s
 * that mostly said "Any type". That reads as a form someone has to fill in
 * before the page will behave.
 *
 * Now the bar is three groups on one 40px line:
 *
 *   search        takes the slack, because it is the control people reach for
 *   filters       one button that opens a popover; the count of applied
 *                 filters rides on the button, so the bar states what is
 *                 narrowing the list without spending width on it
 *   actions       view switch and page actions, pinned right
 *
 * A module with a single filter keeps it inline — a popover holding one
 * <select> is a click for nothing. The threshold is two.
 *
 * It is still a plain GET form. Filtering works with scripting off: without
 * JS the popover renders as a normal expanded fieldset, so every control is
 * still reachable and Apply still submits. The URL is still real, still
 * bookmarkable, and the back button still behaves.
 *
 * Expects $toolbar:
 *   page     string   the ?page= slug, preserved as a hidden field
 *   search   array    ['name'=>'search', 'value'=>'', 'placeholder'=>'…']
 *   filters  array    [['name','label','value','options'=>[value=>label],'all'=>'All'], …]
 *   keep     array    extra query params to carry through, e.g. ['state'=>'expired']
 *   views    array    ['key'=>'properties', 'options'=>[['view','icon','label'], …]]
 *   actions  array    [['label','icon','url','class','can','attrs'], …]
 *
 * Every filter's options are supplied by the caller from the same array the
 * controller validates against, so a value can never be offered here that the
 * query would refuse.
 */
$t        = $toolbar ?? [];
$search   = $t['search']  ?? null;
$filters  = $t['filters'] ?? [];
$keep     = $t['keep']    ?? [];
$views    = $t['views']   ?? null;
$actions  = $t['actions'] ?? [];

// Sort order survives a filter change: someone who sorted by price and then
// narrows to one city expects to still be looking at it by price.
foreach (['sort', 'dir'] as $k) {
    if (!isset($keep[$k]) && !empty($_GET[$k])) {
        $keep[$k] = (string) $_GET[$k];
    }
}

/* How many filters are actually narrowing the list. Drives the count on the
   button and whether the reset link is offered at all — a Clear control on a
   list nobody has filtered is a control that never does anything. */
$appliedCount = 0;
foreach ($filters as $f) {
    if (($f['value'] ?? '') !== '') {
        $appliedCount++;
    }
}

// One filter stays inline; two or more earn a popover.
$usePopover = count($filters) > 1;
$popoverId  = 'toolbar-filters';
?>
<form method="GET" class="toolbar" role="search">
    <input type="hidden" name="page" value="<?= sanitize($t['page'] ?? ($_GET['page'] ?? '')) ?>">
    <?php foreach ($keep as $k => $v): ?>
        <input type="hidden" name="<?= sanitize((string) $k) ?>" value="<?= sanitize((string) $v) ?>">
    <?php endforeach ?>

    <?php if ($search): ?>
        <div class="toolbar__search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label class="sr-only" for="toolbar-search"><?= sanitize($search['label'] ?? 'Search') ?></label>
            <input type="search"
                   id="toolbar-search"
                   name="<?= sanitize($search['name'] ?? 'search') ?>"
                   class="form-control"
                   placeholder="<?= sanitize($search['placeholder'] ?? 'Search…') ?>"
                   value="<?= sanitize((string) ($search['value'] ?? '')) ?>">
        </div>
    <?php endif ?>

    <?php if ($filters): ?>
        <?php if ($usePopover): ?>
            <?php /* data-filter-popover is progressive: the JS gives it a
                     button and a panel. Without JS the <details> is simply an
                     expandable block, which is a working filter set rather
                     than a broken one. */ ?>
            <details class="toolbar__filters" data-filter-popover<?= $appliedCount ? ' open' : '' ?>>
                <summary class="toolbar__filter-trigger" aria-controls="<?= $popoverId ?>">
                    <i class="bi bi-sliders" aria-hidden="true"></i>
                    <span>Filters</span>
                    <?php if ($appliedCount): ?>
                        <span class="toolbar__filter-count"><?= $appliedCount ?></span>
                    <?php endif ?>
                    <i class="bi bi-chevron-down toolbar__filter-chev" aria-hidden="true"></i>
                </summary>

                <div class="toolbar__popover" id="<?= $popoverId ?>">
                    <div class="toolbar__popover-grid">
                        <?php foreach ($filters as $f): ?>
                            <?php $id = 'filter-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $f['name']); ?>
                            <div class="toolbar__field">
                                <label class="toolbar__field-label" for="<?= $id ?>">
                                    <?= sanitize($f['label'] ?? $f['name']) ?>
                                </label>
                                <select name="<?= sanitize((string) $f['name']) ?>" id="<?= $id ?>" class="form-control">
                                    <option value=""><?= sanitize($f['all'] ?? ('All ' . strtolower($f['label'] ?? 'items'))) ?></option>
                                    <?php foreach (($f['options'] ?? []) as $value => $label): ?>
                                        <option value="<?= sanitize((string) $value) ?>"
                                            <?= (string) ($f['value'] ?? '') === (string) $value ? 'selected' : '' ?>>
                                            <?= sanitize((string) $label) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        <?php endforeach ?>
                    </div>

                    <div class="toolbar__popover-foot">
                        <?php /* Offered only when something is actually applied.
                                 It is a link rather than a submit so it clears
                                 by navigating to the bare list — which also
                                 drops the page number, as narrowing should. */ ?>
                        <?php if ($appliedCount): ?>
                            <a class="btn btn--ghost btn--sm"
                               href="<?= sanitize(APP_URL . '/index.php?page=' . ($t['page'] ?? '')) ?>">
                                Reset
                            </a>
                        <?php endif ?>
                        <button type="submit" class="btn btn--primary btn--sm">Apply filters</button>
                    </div>
                </div>
            </details>
        <?php else: ?>
            <?php foreach ($filters as $f): ?>
                <?php $id = 'filter-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $f['name']); ?>
                <label class="sr-only" for="<?= $id ?>"><?= sanitize($f['label'] ?? $f['name']) ?></label>
                <select name="<?= sanitize((string) $f['name']) ?>" id="<?= $id ?>"
                        class="form-control toolbar__inline-filter">
                    <option value=""><?= sanitize($f['all'] ?? ('All ' . strtolower($f['label'] ?? 'items'))) ?></option>
                    <?php foreach (($f['options'] ?? []) as $value => $label): ?>
                        <option value="<?= sanitize((string) $value) ?>"
                            <?= (string) ($f['value'] ?? '') === (string) $value ? 'selected' : '' ?>>
                            <?= sanitize((string) $label) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            <?php endforeach ?>
            <button type="submit" class="btn btn--outline btn--sm toolbar__apply">
                <i class="bi bi-funnel" aria-hidden="true"></i>
                <span class="sr-only">Apply filters</span>
            </button>
        <?php endif ?>
    <?php endif ?>

    <div class="toolbar__actions">
        <?php if ($views && !empty($views['options'])): ?>
            <div class="segmented" data-view-switch="<?= sanitize($views['key'] ?? 'list') ?>"
                 role="group" aria-label="Change how the list is displayed">
                <?php foreach ($views['options'] as $v): ?>
                    <button type="button" class="segmented__btn" data-view="<?= sanitize($v['view']) ?>"
                            aria-pressed="false" title="<?= sanitize($v['label']) ?> view">
                        <i class="bi <?= sanitize($v['icon']) ?>" aria-hidden="true"></i>
                        <span class="sr-only"><?= sanitize($v['label']) ?></span>
                    </button>
                <?php endforeach ?>
            </div>
        <?php endif ?>

        <?php foreach ($actions as $a): ?>
            <?php if (!empty($a['can']) && !can((string) $a['can'])) continue; ?>
            <a href="<?= sanitize((string) ($a['url'] ?? '#')) ?>"
               class="btn <?= sanitize((string) ($a['class'] ?? 'btn--outline')) ?> btn--sm"
               <?php foreach (($a['attrs'] ?? []) as $k => $v): ?>
                   <?= sanitize((string) $k) ?>="<?= sanitize((string) $v) ?>"
               <?php endforeach ?>>
                <?php if (!empty($a['icon'])): ?>
                    <i class="bi <?= sanitize((string) $a['icon']) ?>" aria-hidden="true"></i>
                <?php endif ?>
                <?= sanitize((string) ($a['label'] ?? 'Action')) ?>
            </a>
        <?php endforeach ?>
    </div>
</form>
