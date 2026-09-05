<?php
/**
 * Stylesheet links — the one place the CSS load order is decided.
 *
 * Order is load-bearing, not cosmetic:
 *   design-system → tokens-app → layout → rail → components → pages/* → responsive
 *
 * rail.css sits directly after layout.css and only in the app bundle. It owns
 * the navigation rail outright — palette and shape both — because the rail is
 * themeable per installation and a value split across two sheets is a value
 * that drifts. It must load after layout.css so its shell rules win, and its
 * own :root palette must come after design-system.css for the same reason.
 *
 * design-system first because every other file reads its custom properties,
 * and responsive last because its narrow-width rules override component and
 * layout defaults at equal specificity — at equal specificity the later rule
 * wins, so moving it earlier silently un-does the mobile layout.
 *
 * tokens-app sits second and only in the two signed-in bundles. It redeclares
 * the type scale and density that design-system.css sets for everybody, so the
 * console can be read at a working size without reflowing the marketing site
 * that shares the same file. It must come after design-system (it overrides it)
 * and before everything that reads a type token.
 *
 * Expects: $bundle  'app' (default) | 'public' | 'auth'
 * Optional: $pageStyles  extra sheets from assets/css, appended after the
 *           bundle and before responsive.css — so a page's own rules can
 *           override the components they build on, and the narrow-width
 *           rules in responsive.css still win over both.
 *
 * The counterpart of $extraScripts in scripts.php, and it works for the same
 * reason: layout.php renders the view into a buffer *before* it renders
 * <head>, so a variable the view sets is already in scope by the time this
 * partial runs. A page that carries a stylesheet nothing else needs — the
 * reporting workspace, for one — names it rather than shipping it to every
 * signed-in screen.
 *
 * A page loads only the bundle it renders in: the public site has no use for
 * the sidebar rail, and the app has none for the marketing pages. Everything
 * shared lives in design-system + components, which all three bundles carry.
 */
$bundle = $bundle ?? 'app';

$sheets = match ($bundle) {
    'public' => ['design-system', 'components', 'public'],
    'auth'   => ['design-system', 'tokens-app', 'components', 'pages/auth'],
    default  => ['design-system', 'tokens-app', 'layout', 'rail', 'components', 'pages/dashboard', 'pages/settings'],
};
/* Page sheets sit between the bundle and responsive.css. Anything the page
   names is loaded once, even if it names it twice, and only if the file is
   really there — a typo should cost a missing style, not a 404 in the
   network panel of every reviewer who opens the page. */
foreach (array_unique($pageStyles ?? []) as $pageSheet) {
    if (is_file(ASSETS_PATH . '/css/' . $pageSheet . '.css')) {
        $sheets[] = $pageSheet;
    }
}

$sheets[] = 'responsive';

/**
 * Cache-buster from the file's own mtime. During the redesign the same URL
 * changes many times a day, and a stale sheet reads as a broken page rather
 * than an old one — this makes an edit visible on the next request without
 * anyone being told to hard-refresh.
 */
$cssVersion = static function (string $sheet): string {
    $path = ASSETS_PATH . '/css/' . $sheet . '.css';
    $mtime = is_file($path) ? filemtime($path) : false;
    return $mtime === false ? '' : '?v=' . $mtime;
};
?>
<?php foreach ($sheets as $sheet): ?>
<link rel="stylesheet" href="<?= CSS_URL ?>/<?= $sheet ?>.css<?= $cssVersion($sheet) ?>">
<?php endforeach ?>
