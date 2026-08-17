<?php
/**
 * Stylesheet links — the one place the CSS load order is decided.
 *
 * Order is load-bearing, not cosmetic:
 *   design-system → layout → components → pages/* → responsive
 *
 * design-system first because every other file reads its custom properties,
 * and responsive last because its narrow-width rules override component and
 * layout defaults at equal specificity — at equal specificity the later rule
 * wins, so moving it earlier silently un-does the mobile layout.
 *
 * Expects: $bundle  'app' (default) | 'public' | 'auth'
 *
 * A page loads only the bundle it renders in: the public site has no use for
 * the sidebar rail, and the app has none for the marketing pages. Everything
 * shared lives in design-system + components, which all three bundles carry.
 */
$bundle = $bundle ?? 'app';

$sheets = match ($bundle) {
    'public' => ['design-system', 'components', 'public'],
    'auth'   => ['design-system', 'components', 'pages/auth'],
    default  => ['design-system', 'layout', 'components', 'pages/dashboard', 'pages/settings'],
};
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
