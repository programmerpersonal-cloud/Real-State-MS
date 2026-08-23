<?php
/**
 * The company's social accounts, as a row of icon links.
 *
 * One definition rather than a copy per page. Each network's URL comes from
 * the settings table when an administrator has set one (`social_facebook`,
 * `social_instagram`, …) and falls back to the BIZ_SOCIAL defaults in
 * config/app.php otherwise. A network with neither is left out entirely
 * rather than rendered as a link to "#", which looks like a control and
 * behaves like nothing.
 *
 * WhatsApp is built from the company phone number — the channel a
 * real-estate office is actually reached on — with everything but the digits
 * stripped, which is the shape wa.me expects.
 *
 * Expects (optional):
 *   $socialClass  extra class on the wrapper, e.g. 'auth-social'
 *   $socialLabel  accessible name for the group
 */
$socialClass = $socialClass ?? '';
$socialLabel = $socialLabel ?? 'Follow ' . companyName();

$socialAccounts = [];
foreach (BIZ_SOCIAL as $key => [$name, $icon, $fallback]) {
    $url = trim((string) setting('social_' . $key, $fallback));
    if ($url !== '') {
        $socialAccounts[] = ['name' => $name, 'icon' => $icon, 'url' => $url];
    }
}

$waDigits = preg_replace('/\D+/', '', companyPhone());
if ($waDigits !== '') {
    $socialAccounts[] = ['name' => 'WhatsApp', 'icon' => 'bi-whatsapp', 'url' => 'https://wa.me/' . $waDigits];
}

if (!$socialAccounts) return;
?>
<ul class="social-row<?= $socialClass !== '' ? ' ' . sanitize($socialClass) : '' ?>"
    aria-label="<?= sanitize($socialLabel) ?>">
    <?php foreach ($socialAccounts as $account): ?>
        <li>
            <?php /* The glyph is decorative; the link's accessible name is the
                     text beside it, kept for screen readers only, so the control
                     announces as "Marko Real Estate on Facebook, link" rather
                     than as an unnamed icon. title= gives the same words to a
                     pointer as a tooltip. */ ?>
            <a class="social-row__link" href="<?= sanitize($account['url']) ?>"
               target="_blank" rel="noopener noreferrer"
               title="<?= sanitize($account['name']) ?>">
                <i class="bi <?= $account['icon'] ?>" aria-hidden="true"></i>
                <span class="sr-only"><?= sanitize(companyName() . ' on ' . $account['name']) ?></span>
            </a>
        </li>
    <?php endforeach ?>
</ul>
