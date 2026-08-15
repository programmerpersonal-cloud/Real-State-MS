<?php
/**
 * Enquiry Confirmation (Public)
 *
 * Landed on via redirect after a successful enquiry POST, so a browser
 * refresh cannot resubmit. Its job is to (a) confirm receipt, (b) state the
 * response-time promise explicitly, and (c) give the visitor somewhere to go
 * next instead of a dead end.
 */
$subject = $_SESSION['inquiry_subject'] ?? null;
unset($_SESSION['inquiry_subject']);   // one-shot: a reload shows the generic copy
?>

<section class="page-hero page-hero--tight">
    <div class="site-container">
        <span class="confirm-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></span>

        <span class="eyebrow">Enquiry received</span>
        <h1 class="page-hero__title">Thank you — we've got your message</h1>
        <p class="page-hero__lede">
            <?php if ($subject): ?>
                Your enquiry about <strong><?= sanitize($subject) ?></strong> has been logged and assigned
                to the right person.
            <?php else: ?>
                Your enquiry has been logged and assigned to the right person.
            <?php endif; ?>
            You'll get a reply within <?= BIZ_RESPONSE_HOURS ?> hours on business days.
        </p>

        <div class="page-hero__actions">
            <a href="<?= APP_URL ?>/index.php?page=listings" class="btn btn--primary btn--lg">
                <i class="bi bi-search" aria-hidden="true"></i> Keep browsing properties
            </a>
            <a href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>" class="btn btn--outline btn--lg">
                <i class="bi bi-telephone" aria-hidden="true"></i> Call us now
            </a>
        </div>
    </div>
</section>

<!-- ══ WHAT HAPPENS NEXT ═══════════════════════════════════ -->
<section class="section section--flush-top">
    <div class="site-container site-container--narrow">
        <div class="panel">
            <h2 class="panel__title">What happens next</h2>
            <ol class="step-list step-list--tight">
                <?php
                $next = [
                    ['We read it properly', 'Your enquiry goes to the agent who represents that property, not a shared inbox.'],
                    ['You get a real reply', 'Within ' . BIZ_RESPONSE_HOURS . ' hours on business days — with an answer, not an acknowledgement.'],
                    ['We arrange the viewing', 'If you want to see the property, we group viewings by area so one trip covers several.'],
                ];
                foreach ($next as $i => [$t, $d]): ?>
                    <li class="step">
                        <span class="step__num" aria-hidden="true"><?= $i + 1 ?></span>
                        <div>
                            <h3 class="step__title"><?= $t ?></h3>
                            <p class="step__desc"><?= $d ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>

        <div class="panel" style="margin-top:var(--space-6)">
            <h2 class="panel__title">Need us sooner?</h2>
            <div class="info-grid">
                <div class="info-card">
                    <span class="info-card__icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
                    <span class="info-card__label">Call</span>
                    <a class="info-card__value" href="tel:<?= sanitize(preg_replace('/\s+/', '', BIZ_PHONE)) ?>">
                        <?= sanitize(BIZ_PHONE) ?>
                    </a>
                    <span class="info-card__note">Mon–Thu &amp; Sat, 08:00–17:00</span>
                </div>
                <div class="info-card">
                    <span class="info-card__icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                    <span class="info-card__label">Email</span>
                    <a class="info-card__value" href="mailto:<?= sanitize(rawurlencode(BIZ_EMAIL)) ?>"><?= sanitize(BIZ_EMAIL) ?></a>
                    <span class="info-card__note">Replies within <?= BIZ_RESPONSE_HOURS ?> hours</span>
                </div>
                <div class="info-card">
                    <span class="info-card__icon" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
                    <span class="info-card__label">Visit</span>
                    <span class="info-card__value"><?= BIZ_STREET ?></span>
                    <span class="info-card__note"><?= sanitize(BIZ_CITY) ?>, <?= BIZ_REGION ?></span>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-top:var(--space-6)">
            <h2 class="panel__title">While you wait</h2>
            <p class="panel__lede">A few places worth a look.</p>
            <div class="svc-mini-grid" style="margin-top:var(--space-5)">
                <a class="svc-mini" href="<?= APP_URL ?>/index.php?page=listings&amp;property_type=rent">
                    <span class="value-card__icon" aria-hidden="true"><i class="bi bi-key"></i></span>
                    <span>
                        <strong>Properties to rent</strong>
                        <small>Everything currently available to let.</small>
                    </span>
                </a>
                <a class="svc-mini" href="<?= APP_URL ?>/index.php?page=listings&amp;property_type=sale">
                    <span class="value-card__icon value-card__icon--green" aria-hidden="true"><i class="bi bi-house-heart"></i></span>
                    <span>
                        <strong>Properties for sale</strong>
                        <small>Homes and commercial space on the market now.</small>
                    </span>
                </a>
                <a class="svc-mini" href="<?= APP_URL ?>/index.php?page=agents">
                    <span class="value-card__icon value-card__icon--purple" aria-hidden="true"><i class="bi bi-people"></i></span>
                    <span>
                        <strong>Meet the agents</strong>
                        <small>See who represents what, and contact them directly.</small>
                    </span>
                </a>
            </div>
        </div>
    </div>
</section>
