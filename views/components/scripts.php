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
 */
$jsVersion = static function (string $script): string {
    $path = ASSETS_PATH . '/js/' . $script . '.js';
    $mtime = is_file($path) ? filemtime($path) : false;
    return $mtime === false ? '' : '?v=' . $mtime;
};
?>
<?php foreach (['main', 'components'] as $script): ?>
<script src="<?= JS_URL ?>/<?= $script ?>.js<?= $jsVersion($script) ?>"></script>
<?php endforeach ?>
