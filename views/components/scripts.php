<?php
/**
 * The application's script tags, in one place.
 *
 * components.js and main.js are a pair: main.js's DOMContentLoaded handler
 * calls into components.js, and components.js reads lockPageScroll() and
 * FOCUSABLE back out of main.js. Both are plain synchronous files, so every
 * declaration is in place before either handler runs, whichever order the
 * tags appear in.
 *
 * The reason this is a partial rather than two tags repeated in four layouts
 * is the cache-buster below. styles.php has versioned stylesheets since the
 * start of the redesign; the scripts did not, which meant a browser would
 * happily keep running a main.js from several edits ago against freshly
 * versioned CSS. That is worse than either being stale on its own — the page
 * looks redesigned and behaves like it used to — and it cost a real debugging
 * session before it was spotted.
 *
 * Expects (optional): $extraScripts  extra files from assets/js, loaded after
 * the pair above. A page that carries behaviour nothing else needs — the
 * authentication screen's panel switch, for one — names it here rather than
 * shipping it to every signed-in page, and it still gets the cache-buster.
 */
$jsVersion = static function (string $script): string {
    $path = ASSETS_PATH . '/js/' . $script . '.js';
    $mtime = is_file($path) ? filemtime($path) : false;
    return $mtime === false ? '' : '?v=' . $mtime;
};
?>
<?php /* The validation ruleset, handed to the browser as data rather than
         restated in JavaScript. There is one definition of what a valid
         Somali phone number is and it lives in includes/validation.php; this
         is a copy of it, not a second opinion. JSON, not inline JS, so the
         page carries no executable string. */ ?>
<script type="application/json" id="validationRules"><?=
    json_encode(validationClientRules(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
?></script>
<?php foreach (array_merge(['main', 'components'], $extraScripts ?? []) as $script): ?>
<script src="<?= JS_URL ?>/<?= $script ?>.js<?= $jsVersion($script) ?>"></script>
<?php endforeach ?>
