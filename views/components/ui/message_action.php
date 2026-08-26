<?php
/**
 * The "Message…" button on a business record.
 *
 * One partial for the property, tenancy and maintenance pages, so the three
 * cannot end up offering differently-worded versions of the same action — and
 * so the rule that decides whether it appears at all is asked in one place.
 *
 * It renders nothing unless communicationEntryPoint() resolves someone. That
 * is deliberate: a button that opens a compose screen with an empty recipient
 * list is a dead end, and the commonest way to build one is to draw the
 * control from a permission alone. The permission is only the first of three
 * questions — see includes/communication_access.php.
 *
 * The label comes from the resolved relationship, never from the page. An
 * owner whose property has no agent gets "Contact managing office", because
 * calling head office their agent would be untrue.
 *
 * Expects: $messageContext  ['property_id'|'lease_id'|'maintenance_request_id' => int]
 * Optional: $messageActionClass  button classes (default outline, small)
 *           $messageActionShort  true to use the compact label
 */
$__entry = communicationEntryPoint($messageContext ?? []);

if ($__entry):
    $__class = $messageActionClass ?? 'btn btn--outline btn--sm';
    $__label = !empty($messageActionShort) ? $__entry['short'] : $__entry['label'];
?>
<a class="<?= sanitize($__class) ?>" href="<?= sanitize($__entry['url']) ?>">
    <i class="bi <?= sanitize($__entry['icon']) ?>" aria-hidden="true"></i>
    <?= sanitize($__label) ?>
</a>
<?php
endif;

// Not left in scope for whatever renders next: these partials are required
// inline, several times on some pages, and a stale $messageContext would send
// the second button to the first button's record.
unset($__entry, $__class, $__label, $messageContext, $messageActionClass, $messageActionShort);
