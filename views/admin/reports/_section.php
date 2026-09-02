<?php
/**
 * A section heading.
 *
 * Eight reports were each a stack of cards in a plausible order, and a stack
 * of cards in a plausible order is still a stack of cards: nothing on the
 * page said where the headline figures stopped and the trend analysis began,
 * so a reader had to infer the structure from the content of every panel.
 * This is the one line that states it.
 *
 * It is a heading, not a divider. The rule under it is hairline and the type
 * is small — the section label should organise the page from the corner of
 * the eye, not compete with the figures it introduces. Sections are <h3>,
 * which puts the cards inside them at <h4> and keeps the document outline
 * honest: page <h1>, report <h2>, section <h3>, card <h4>, with no level
 * skipped anywhere in the workspace.
 *
 * Expects $section:
 *   title string
 *   desc  string  optional — one line, only where it earns its place
 *   meta  string  optional pre-escaped HTML, set to the right of the rule
 *   id    string  optional anchor, for a region that wants aria-labelledby
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$rsC = $section ?? [];
if (empty($rsC['title'])) {
    return;
}
?>
<div class="rsection">
    <div class="rsection__lead">
        <h3 class="rsection__title"<?= !empty($rsC['id']) ? ' id="' . sanitize((string) $rsC['id']) . '"' : '' ?>>
            <?= sanitize((string) $rsC['title']) ?>
        </h3>
        <?php if (!empty($rsC['desc'])): ?>
            <p class="rsection__desc"><?= sanitize((string) $rsC['desc']) ?></p>
        <?php endif ?>
    </div>
    <?php if (!empty($rsC['meta'])): ?>
        <?php /* Pre-escaped by the caller, the same contract page_header.php
                 uses for $pageMeta — it carries counts and small pills. */ ?>
        <div class="rsection__meta"><?= $rsC['meta'] ?></div>
    <?php endif ?>
</div>
