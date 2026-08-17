<?php
/**
 * Error summary for a form the server rejected.
 *
 * The client-side twin of this lives in components.js and is built when a
 * submit is stopped in the browser. This one covers the other half: the
 * request that reached PHP, failed a rule the browser cannot check — a
 * duplicate email, a lease that overlaps another, a coordinate out of range
 * — and came back through the redirect with $_SESSION['form_errors'] intact.
 *
 * Both render the same markup so the experience is identical whichever side
 * caught the problem.
 *
 * Rendered only for two or more problems. A single one is already stated
 * against its own field, and repeating it at the top of the form is noise
 * that pushes the field itself further down the screen.
 *
 * Expects: $formErrors  array<string field, string message>
 * Optional: $errorSummaryLabels  array<string field, string label> when a
 *           field name is not what the label above it says.
 */
$errs = $formErrors ?? [];
if (count($errs) < 2) {
    return;
}
$labels = $errorSummaryLabels ?? [];
?>
<div class="error-summary" role="alert" tabindex="-1" data-server-summary>
    <div class="error-summary__title">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        There are <?= count($errs) ?> problems with this form
    </div>
    <ul class="error-summary__list">
        <?php foreach ($errs as $field => $message): ?>
            <li>
                <?php /* Links to the field's error text rather than the input:
                         the error carries a stable id, and jumping to it puts
                         both the message and the control it belongs to on
                         screen together. */ ?>
                <a href="#<?= sanitize(uiErrorId((string) $field)) ?>">
                    <?= sanitize((string) $message) ?>
                </a>
            </li>
        <?php endforeach ?>
    </ul>
</div>
