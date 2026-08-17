<?php
/**
 * Terms of Service (Public)
 *
 * Written against how the platform actually works — roles, listings, payments
 * and the audit trail. Have a lawyer review before relying on it commercially.
 */
$updated = '12 August 2026';

/* Administrators can publish these terms from Settings → Legal & Terms, in which
   case the published version replaces the copy below. The static text stays as
   the fallback so the page is never blank on an install that has not written
   its own terms yet.

   ?doc=<slug> renders another published type (rental, sale, viewing, booking). */
$termsSlug    = trim((string) ($_GET['doc'] ?? '')) ?: (setting('terms_public_slug') ?: 'general');
$termsVersion = activeTerms($termsSlug);
?>

<section class="page-hero page-hero--tight">
    <div class="site-container">
        <nav aria-label="Breadcrumb">
            <ol class="crumbs crumbs--center">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><span aria-current="page">Terms</span></li>
            </ol>
        </nav>
        <span class="eyebrow">Legal</span>
        <h1 class="page-hero__title">Terms of service</h1>
        <p class="page-hero__lede">
            The agreement between you and <?= sanitize(companyName()) ?> when you use this site or hold an account.
        </p>
    </div>
</section>

<section class="section section--flush-top">
    <div class="site-container site-container--narrow">
        <?php if ($termsVersion): ?>
        <div class="legal">
            <p class="legal__meta">
                <?= sanitize($termsVersion['version_code']) ?>
                <?php if (!empty($termsVersion['effective_from'])): ?>
                    · in effect since <?= formatDate($termsVersion['effective_from']) ?>
                <?php endif ?>
            </p>
            <?= renderLegalText((string) $termsVersion['body']) ?>
        </div>
        <?php else: ?>
        <div class="legal">
            <p class="legal__meta">Last updated <?= $updated ?></p>

            <h2>1. Agreement</h2>
            <p>
                By using this website or signing in to <?= sanitize(companyName()) ?>, you agree to these terms. If
                you are accepting on behalf of an agency or company, you confirm you are authorised
                to bind it. If you do not agree, do not use the service.
            </p>

            <h2>2. Accounts</h2>
            <p>
                You must give accurate registration details and keep them current. You are
                responsible for everything done under your account, so keep your password to
                yourself and tell us promptly if you think it has been compromised. Accounts are
                personal — do not share one across several people, because the audit trail attributes
                every action to the account that performed it.
            </p>
            <p>
                We may suspend or close an account that breaches these terms, is used to publish
                fraudulent listings, or has been inactive for an extended period.
            </p>

            <h2>3. Listings</h2>
            <p>
                If you publish a property you confirm that you own it or are authorised to market
                it, that the details and photographs are accurate and current, and that the price
                shown is one you are genuinely prepared to transact at.
            </p>
            <p>
                Listings are reviewed before they appear on the public site. We may decline,
                edit or remove any listing — in particular one that is duplicated, misleading,
                already sold or let, or that we have reason to believe is not genuine.
            </p>

            <h2>4. Acceptable use</h2>
            <p>You agree not to:</p>
            <ul>
                <li>publish false, misleading or unlawful content;</li>
                <li>scrape, bulk-download or resell listing data;</li>
                <li>attempt to access accounts, records or areas you are not authorised to see;</li>
                <li>probe, load-test or interfere with the service or its infrastructure;</li>
                <li>use the platform to harass anyone or to discriminate unlawfully against prospective tenants or buyers.</li>
            </ul>

            <h2>5. Our role in transactions</h2>
            <p>
                <?= sanitize(companyName()) ?> provides software and agency services. Except where we are expressly
                engaged as your agent under a separate written agreement, we are not a party to any
                sale or tenancy arranged through the platform. Verify the property, the title and the
                counterparty before you commit money.
            </p>

            <h2>6. Fees and payments</h2>
            <p>
                Browsing the public site and holding a customer account are free. Agency,
                management and advisory services are charged as set out in the written engagement
                we agree with you. Payments recorded in the platform are a record of what has been
                received; they are not themselves a payment mechanism.
            </p>

            <h2>7. Your content</h2>
            <p>
                You keep ownership of the property details, photographs and documents you upload. You
                grant us a licence to host, display and distribute that content for the purpose of
                operating and marketing the service. You are responsible for having the right to
                upload it — including the rights to any photographs you did not take yourself.
            </p>

            <h2>8. Availability</h2>
            <p>
                We aim to keep the service available continuously, but we do not guarantee it. We may
                take it down for maintenance, and we may change or discontinue features. Where a
                change materially reduces what an account can do, we will give reasonable notice.
            </p>

            <h2>9. Liability</h2>
            <p>
                The service is provided as it stands. To the extent the law allows, we exclude
                liability for indirect or consequential loss, lost profit and lost opportunity, and
                our total liability is limited to the fees you paid us in the twelve months before
                the claim. Nothing here excludes liability that cannot lawfully be excluded.
            </p>

            <h2>10. Termination</h2>
            <p>
                You may close your account at any time from your profile. We may suspend or terminate
                access for a material breach of these terms. On termination your right to use the
                service stops immediately; records we are required to retain are kept as described in
                the <a href="<?= APP_URL ?>/index.php?page=privacy">privacy policy</a>.
            </p>

            <h2>11. Changes to these terms</h2>
            <p>
                We may update these terms. The date at the top of this page shows the current
                version, and we will notify account holders by email about material changes.
                Continuing to use the service after a change means you accept it.
            </p>

            <h2>12. Contact</h2>
            <p>
                Questions about these terms go to
                <a href="mailto:<?= sanitize(BIZ_EMAIL) ?>"><?= sanitize(BIZ_EMAIL) ?></a>, or write to us at
                <?= sanitize(BIZ_STREET . ', ' . BIZ_CITY . ', ' . BIZ_REGION) ?>.
            </p>
        </div>
        <?php endif ?>

        <div class="legal__footer">
            <a href="<?= APP_URL ?>/index.php?page=privacy" class="btn btn--outline">
                Read the privacy policy <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>
