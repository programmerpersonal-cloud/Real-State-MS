<?php
/**
 * Sticky mobile call-to-action bar.
 *
 * Shown only below the tablet breakpoint, where the desktop header actions
 * have scrolled away and the primary conversion path would otherwise be
 * several screens up. Two actions maximum — a third turns a decision into a
 * menu, and on a phone the bar would start competing with the OS gesture area.
 *
 * The pair is chosen per page so the bar always offers the next step that
 * makes sense where the visitor actually is, rather than one generic button
 * repeated site-wide.
 *
 * Suppressed on the thank-you page (the conversion already happened) and on
 * the legal pages (nothing to convert).
 */
$view = $publicView ?? 'home';

if (in_array($view, ['thanks', 'privacy', 'terms', '404'], true)) {
    return;
}

$tel = 'tel:' . preg_replace('/\s+/', '', BIZ_PHONE);

// [icon, label, href, is_primary]
$actions = match ($view) {
    // On a listing, the visitor is looking at one specific property: enquire
    // about that one, or call about it.
    'listing' => [
        ['bi-calendar-check', 'Book viewing', '#inquiry', true],
        ['bi-telephone',      'Call',         $tel,       false],
    ],
    'agent' => [
        ['bi-chat-dots', 'Message', '#agent-enquiry', true],
        ['bi-telephone', 'Call',    $tel,             false],
    ],
    'service' => [
        ['bi-send',      'Enquire', '#enquiry', true],
        ['bi-telephone', 'Call',    $tel,       false],
    ],
    // Discovery pages: the next step is to search or to talk to someone.
    'listings', 'agents', 'services' => [
        ['bi-envelope',  'Contact us', APP_URL . '/index.php?page=contact', true],
        ['bi-telephone', 'Call',       $tel,                                false],
    ],
    default => [
        ['bi-search',    'Browse properties', APP_URL . '/index.php?page=listings', true],
        ['bi-telephone', 'Call',              $tel,                                 false],
    ],
};
?>
<div class="sticky-cta" id="stickyCta" role="region" aria-label="Quick actions">
    <?php foreach ($actions as [$icon, $label, $href, $primary]): ?>
        <a class="sticky-cta__btn <?= $primary ? 'sticky-cta__btn--primary' : '' ?>"
           href="<?= sanitize($href) ?>">
            <i class="<?= $icon ?>" aria-hidden="true"></i>
            <span><?= $label ?></span>
        </a>
    <?php endforeach; ?>
</div>
