<?php
/**
 * "How it works" — the two paths through the platform.
 *
 * The homepage could show a visitor what exists (listings, agents, prices)
 * and why to trust it (verified, named agent, reviews), but nothing on it
 * said what actually *happens* after the click. That gap is the reason the
 * section exists: a visitor deciding whether to create an account is asking
 * a process question, and every other section answers an inventory one.
 *
 * Two tracks rather than one, because this platform has two publicly
 * self-serving audiences and they want opposite things. Registration offers
 * exactly these two roles — AuthController::SELF_SERVICE_ROLES is
 * [customer, owner] — so the split here is the same split the sign-up form
 * makes, not a marketing invention.
 *
 * ── Every claim below is checked against includes/permissions.php ──
 *
 * The buyer's track is the customer role's own permission set: favorites.*
 * is the shortlist, inquiries.create and reservations.create are the
 * enquiry and the viewing request, and my-lease / my-payments /
 * payments.receipt / maintenance.create are what the account is worth after
 * moving in.
 *
 * The owner's track is deliberately *not* "list your property in minutes".
 * An owner holds my-properties.view and my-income.view — they read their
 * portfolio; they do not hold properties.create, so they cannot publish a
 * listing themselves. The agency does it for them and an administrator
 * approves it before the public site will show it. Writing the self-service
 * version would have been the better sentence and the false one.
 *
 * Every link goes to a route that exists in index.php: listings, register,
 * contact.
 */
$isIn = isLoggedIn();

/* Both tracks end somewhere useful for a signed-in visitor too, rather than
   inviting them to create the account they are already holding. */
$tracks = [
    [
        'eyebrow' => 'Looking for a property',
        'icon'    => 'bi-search',
        'title'   => 'Find it, view it, move in',
        'steps'   => [
            ['Search the listings',
             'Filter by location, property type, price and bedrooms. Every result is a live, approved listing — nothing is held back behind a signup.'],
            ['Shortlist what fits',
             'Save the ones worth a second look to your account, so comparing them later does not mean starting the search again.'],
            ['Talk to the named agent',
             'Send an enquiry or request a viewing. It reaches the agent who actually handles that property, not a shared inbox.'],
            ['Move in and stay on top of it',
             'Your lease, payments, receipts and maintenance requests all sit in one dashboard once you are in.'],
        ],
        // The same for everyone: the listings are open to anyone, which is
        // the point the first step makes.
        'cta'      => ['Browse properties', APP_URL . '/index.php?page=listings'],
        'ctaAlt'   => $isIn ? null : ['Create a free account', APP_URL . '/index.php?page=register'],
    ],
    [
        'eyebrow' => 'Own a property',
        'icon'    => 'bi-house-check',
        'title'   => 'Hand it over, watch it work',
        'steps'   => [
            ['Register as an owner',
             'Choose "Owner" when you create your account. Staff accounts are issued by an administrator — this one you can open yourself.'],
            ['We take the details',
             'An agent captures the property, the photographs and the paperwork with you, so the listing is right before anyone sees it.'],
            ['It is reviewed, then it goes live',
             'Every listing is checked and approved before it reaches the public site, and it carries the name of the agent responsible for it.'],
            ['Follow the income and the issues',
             'Your dashboard shows the properties in your portfolio, the income against them, the enquiries they attract and the maintenance raised on them.'],
        ],
        'cta'      => $isIn
            ? ['Go to your dashboard', APP_URL . '/index.php?page=dashboard']
            : ['Create an owner account', APP_URL . '/index.php?page=register'],
        'ctaAlt'   => ['Talk to our team', APP_URL . '/index.php?page=contact'],
    ],
];
?>
<div class="howto">
    <?php foreach ($tracks as $i => $track): ?>
        <article class="howto__track" data-reveal data-reveal-delay="<?= $i * 80 ?>">

            <header class="howto__head">
                <span class="howto__icon" aria-hidden="true"><i class="bi <?= $track['icon'] ?>"></i></span>
                <div>
                    <span class="eyebrow"><?= sanitize($track['eyebrow']) ?></span>
                    <h3 class="howto__title"><?= sanitize($track['title']) ?></h3>
                </div>
            </header>

            <?php /* An ordered list, because the order is the content. The
                     numbers are drawn by CSS from the list's own counter
                     rather than typed into the markup, so the sequence a
                     screen reader announces and the sequence on screen can
                     never disagree. */ ?>
            <ol class="howto__steps">
                <?php foreach ($track['steps'] as [$stepTitle, $stepBody]): ?>
                    <li class="howto__step">
                        <h4 class="howto__step-title"><?= sanitize($stepTitle) ?></h4>
                        <p class="howto__step-body"><?= sanitize($stepBody) ?></p>
                    </li>
                <?php endforeach ?>
            </ol>

            <div class="howto__actions">
                <a href="<?= sanitize($track['cta'][1]) ?>" class="btn btn--primary">
                    <?= sanitize($track['cta'][0]) ?>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
                <?php if ($track['ctaAlt']): ?>
                    <a href="<?= sanitize($track['ctaAlt'][1]) ?>" class="btn btn--ghost">
                        <?= sanitize($track['ctaAlt'][0]) ?>
                    </a>
                <?php endif ?>
            </div>
        </article>
    <?php endforeach ?>
</div>
