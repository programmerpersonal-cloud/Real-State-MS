<?php
/**
 * List toolbar — search, filters, view switch and page actions.
 *
 * One bar for every list in the system, replacing both the old `.filter-bar`
 * and the bespoke inline-flex forms each module had grown. Those disagreed on
 * field order, control height and where the submit button went, so moving
 * between two lists meant re-learning the same three controls.
 *
 * It is a plain GET form. Filtering therefore works with scripting off, the
 * result is a real URL that can be bookmarked or shared, and the back button
 * behaves — none of which is true of a filter that posts or re-renders in JS.
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
?>
<form method="GET" class="list-toolbar" role="search">
    <input type="hidden" name="page" value="<?= sanitize($t['page'] ?? ($_GET['page'] ?? '')) ?>">
    <?php foreach ($keep as $k => $v): ?>
        <input type="hidden" name="<?= sanitize((string) $k) ?>" value="<?= sanitize((string) $v) ?>">
    <?php endforeach ?>

    <?php if ($search): ?>
        <div class="list-toolbar__search">
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
        <div class="list-toolbar__filters">
            <?php foreach ($filters as $f): ?>
                <?php $id = 'filter-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $f['name']); ?>
                <label class="sr-only" for="<?= $id ?>"><?= sanitize($f['label'] ?? $f['name']) ?></label>
                <select name="<?= sanitize((string) $f['name']) ?>" id="<?= $id ?>" class="form-control">
                    <option value=""><?= sanitize($f['all'] ?? ('All ' . strtolower($f['label'] ?? 'items'))) ?></option>
                    <?php foreach (($f['options'] ?? []) as $value => $label): ?>
                        <option value="<?= sanitize((string) $value) ?>"
                            <?= (string) ($f['value'] ?? '') === (string) $value ? 'selected' : '' ?>>
                            <?= sanitize((string) $label) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            <?php endforeach ?>
            <button type="submit" class="btn btn--outline btn--sm">
                <i class="bi bi-funnel" aria-hidden="true"></i> Apply
            </button>
        </div>
    <?php endif ?>

    <div class="list-toolbar__actions">
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
