<?php
/**
 * Privacy Policy (Public)
 *
 * Boilerplate written to match how the application actually behaves — the
 * data it stores, the roles that can read it and the retention it applies.
 * Have a lawyer review before relying on it commercially.
 */
$updated = '12 August 2026';
?>

<section class="page-hero page-hero--tight">
    <div class="site-container">
        <nav aria-label="Breadcrumb">
            <ol class="crumbs crumbs--center">
                <li><a href="<?= APP_URL ?>/index.php?page=home">Home</a></li>
                <li><span aria-current="page">Privacy</span></li>
            </ol>
        </nav>
        <span class="eyebrow">Legal</span>
        <h1 class="page-hero__title">Privacy policy</h1>
        <p class="page-hero__lede">
            What we collect, why we collect it, and what you can ask us to do with it.
        </p>
    </div>
</section>

<section class="section section--flush-top">
    <div class="site-container site-container--narrow">
        <div class="legal">
            <p class="legal__meta">Last updated <?= $updated ?></p>

            <h2>1. Who we are</h2>
            <p>
                <?= sanitize(companyName()) ?> operates this website and the property management platform
                behind it. In this policy, "we" and "us" mean <?= sanitize(companyName()) ?>, and "you" means
                anyone who visits the site or holds an account.
            </p>

            <h2>2. What we collect</h2>
            <p>We hold three broad categories of information.</p>
            <ul>
                <li>
                    <strong>Account data.</strong> Your name, email address, phone number, role and
                    the branch you belong to. Passwords are stored only as a one-way hash — we cannot
                    read them, and neither can anyone who obtains a copy of the database.
                </li>
                <li>
                    <strong>Transaction data.</strong> Properties, leases, payments, reservations,
                    maintenance requests and enquiries that you create or that relate to you.
                </li>
                <li>
                    <strong>Technical data.</strong> A session cookie that keeps you signed in, and
                    server logs recording the pages requested and the time of the request.
                </li>
            </ul>

            <h2>3. Why we use it</h2>
            <p>
                To operate your account, publish and manage listings, record leases and payments,
                route maintenance requests, and answer the enquiries you send us. We also use
                aggregate figures — how many properties are listed, how quickly issues close — to
                improve the service. We do not sell your data, and we do not use it for advertising.
            </p>

            <h2>4. Who can see it</h2>
            <p>
                Access is controlled by role. An agent sees the properties and enquiries assigned to
                them. An owner sees their own units and income. A tenant sees their own lease,
                payments and maintenance history. Administrators can see records across their
                organisation and every change is written to an audit log with the user and timestamp
                attached.
            </p>
            <p>
                We share data outside the platform only where it is necessary to deliver the service
                — for example passing a maintenance request to the contractor attending it — or where
                we are legally required to.
            </p>

            <h2>5. How long we keep it</h2>
            <p>
                Account data is retained while your account is open. Transaction records — leases,
                payments, receipts — are kept for as long as we are required to retain financial
                records, typically seven years, after which they are deleted. Archived properties are
                hidden from the public site immediately but retained internally for reporting.
            </p>

            <h2>6. Your rights</h2>
            <p>You can ask us to:</p>
            <ul>
                <li>give you a copy of the personal data we hold about you;</li>
                <li>correct anything that is wrong — most of it you can edit yourself in your profile;</li>
                <li>delete your account and the personal data attached to it, subject to the retention periods above;</li>
                <li>stop sending you non-essential email.</li>
            </ul>
            <p>
                Write to <a href="mailto:<?= sanitize(BIZ_EMAIL) ?>"><?= sanitize(BIZ_EMAIL) ?></a> and we will
                respond within 30 days.
            </p>

            <h2>7. Cookies</h2>
            <p>
                We set one essential cookie, which holds your session so you stay signed in between
                pages. It is deleted when you sign out or when the session expires. We do not use
                advertising or third-party tracking cookies.
            </p>

            <h2>8. Security</h2>
            <p>
                Passwords are hashed, access is role-checked on every request, and changes to records
                are logged. No system is perfectly secure, but if a breach affects your personal data
                we will tell you and the relevant authority without undue delay.
            </p>

            <h2>9. Changes to this policy</h2>
            <p>
                If we change this policy we will update the date at the top of this page. Material
                changes will also be notified to account holders by email.
            </p>

            <h2>10. Contact</h2>
            <p>
                Questions about this policy go to
                <a href="mailto:<?= sanitize(BIZ_EMAIL) ?>"><?= sanitize(BIZ_EMAIL) ?></a>, or write to us at
                <?= sanitize(BIZ_STREET . ', ' . BIZ_CITY . ', ' . BIZ_REGION) ?>.
            </p>
        </div>

        <div class="legal__footer">
            <a href="<?= APP_URL ?>/index.php?page=terms" class="btn btn--outline">
                Read the terms of service <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>
