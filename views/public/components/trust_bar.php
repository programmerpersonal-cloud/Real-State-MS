<?php
/**
 * Above-the-fold trust strip.
 *
 * Three claims the business can actually stand behind, placed directly under
 * the hero CTA where they answer the objection a visitor has at the moment
 * they decide whether to click. Every item is verifiable:
 *
 *   - the response window is the published BIZ_RESPONSE_HOURS commitment
 *   - listings really are reviewed before they reach the public site
 *   - every listing really does name the agent responsible for it
 *
 * Nothing here is a number we cannot evidence — no invented "500+ happy
 * customers", which is the fastest way for a site to read as generated.
 */
?>
<ul class="trust-bar">
    <li>
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <span>Replies within <strong><?= BIZ_RESPONSE_HOURS ?> hours</strong></span>
    </li>
    <li>
        <i class="bi bi-patch-check" aria-hidden="true"></i>
        <span><strong>Verified</strong> listings only</span>
    </li>
    <li>
        <i class="bi bi-person-badge" aria-hidden="true"></i>
        <span>A <strong>named agent</strong> on every property</span>
    </li>
</ul>
