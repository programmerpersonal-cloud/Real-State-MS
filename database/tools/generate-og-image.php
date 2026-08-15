<?php
/**
 * Generates the 1200x630 social share card.
 *
 * Composition: a real property photograph, a brand-blue → ink gradient scrim
 * so text stays legible over any image, then the wordmark, a one-line value
 * proposition and the NAP strip. 1200x630 is the size Facebook, LinkedIn,
 * X (summary_large_image) and WhatsApp all crop cleanly from.
 */

$SRC   = 'c:/xampp/htdocs/Real-State-MS/Real-State-MS/assets/img/property/property-exterior-9.webp';
$FONT  = __DIR__ . '/fonts/raleway-bold.ttf';
$OUTD  = 'c:/xampp/htdocs/Real-State-MS/Real-State-MS/assets/img/social';
$W = 1200; $H = 630;

if (!is_dir($OUTD)) mkdir($OUTD, 0775, true);

$canvas = imagecreatetruecolor($W, $H);
imagesavealpha($canvas, false);

// ── Photo, cover-fitted (crop to fill, never letterbox) ────────────────
$src = imagecreatefromwebp($SRC);
$sw = imagesx($src); $sh = imagesy($src);
$scale = max($W / $sw, $H / $sh);
$nw = (int) ceil($sw * $scale); $nh = (int) ceil($sh * $scale);
$dx = (int) (($W - $nw) / 2); $dy = (int) (($H - $nh) / 2);
imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
imagedestroy($src);

// ── Scrim: brand ink from the left, darkening down ─────────────────────
// Drawn per-column/row rather than as a flat overlay so the photo still
// reads on the right while the text side stays at AA contrast.
for ($x = 0; $x < $W; $x++) {
    // 0.92 alpha at the left edge easing to 0.20 at the right.
    $t = $x / $W;
    $a = 0.92 - (0.72 * pow($t, 0.85));
    for ($y = 0; $y < $H; $y++) {
        // Extra darkening toward the bottom for the NAP strip.
        $ay = min(1.0, $a + 0.28 * pow($y / $H, 2.2));
        $rgb = imagecolorat($canvas, $x, $y);
        $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
        // Blend toward #0d2230 (the site's --ink), tinted with brand blue.
        $r = (int) ($r * (1 - $ay) + 13  * $ay);
        $g = (int) ($g * (1 - $ay) + 34  * $ay);
        $b = (int) ($b * (1 - $ay) + 48  * $ay);
        imagesetpixel($canvas, $x, $y, imagecolorallocate($canvas, $r, $g, $b));
    }
}

$white  = imagecolorallocate($canvas, 255, 255, 255);
$blue   = imagecolorallocate($canvas, 77, 173, 232);   // lightened --primary for contrast on ink
$muted  = imagecolorallocate($canvas, 183, 199, 210);

// ── Accent rule ────────────────────────────────────────────────────────
imagefilledrectangle($canvas, 72, 150, 72 + 64, 150 + 6, $blue);

// ── Wordmark ───────────────────────────────────────────────────────────
imagettftext($canvas, 68, 0, 70, 260, $white, $FONT, 'Saxane');
imagettftext($canvas, 25, 0, 74, 315, $blue,  $FONT, 'REAL ESTATE');

// ── Value proposition ──────────────────────────────────────────────────
imagettftext($canvas, 30, 0, 70, 400, $white, $FONT, 'Find your next home in Borama');
imagettftext($canvas, 21, 0, 70, 444, $muted, $FONT, 'Verified listings · Named agents · Replies within 24 hours');

// ── NAP strip ──────────────────────────────────────────────────────────
imagefilledrectangle($canvas, 70, 520, 70 + 3, 520 + 46, $blue);
imagettftext($canvas, 18, 0, 90, 543, $white, $FONT, 'Borama, Awdal');
imagettftext($canvas, 18, 0, 90, 573, $muted, $FONT, '+252 63 331 1945  ·  saxane.com');

imagejpeg($canvas, $OUTD . '/og-default.jpg', 86);
imagedestroy($canvas);

printf("wrote %s (%d bytes)\n", $OUTD . '/og-default.jpg', filesize($OUTD . '/og-default.jpg'));
