<?php
/**
 * New message: choose a recipient, optionally write the first line, send.
 *
 * The list is exactly what messageContacts() returned and nothing else. There
 * is no user directory here and no way to reach one — a recipient the access
 * layer did not resolve is refused on POST by canMessageUser(), so a
 * hand-edited value in the radio group buys nothing.
 *
 * The relationship line under each name is deliberately literal. An
 * administrator reached through the fallback is labelled as the office, never
 * as "your agent": telling an owner that the head office is their property
 * agent would be a lie the interface tells on the system's behalf.
 *
 * Expects: $contacts $contactSource $scopeHint $composeContext $base $listSuffix
 */
$source = $contactSource ?? 'none';
$ctx    = $composeContext ?? [];
?>
<header class="msg__head">
    <a class="msg__back" href="<?= sanitize($base . $listSuffix) ?>">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        <span>Conversations</span>
    </a>

    <div class="msg__head-who">
        <div class="msg__head-text">
            <h2 class="msg__head-name">New message</h2>
            <p class="msg__head-role"><?= sanitize($scopeHint ?? '') ?></p>
        </div>
    </div>
</header>

<?php if (empty($contacts)): ?>
    <?php /* No form at all rather than an empty one. A recipient picker with
             nothing in it invites the reader to look for the missing list. */ ?>
    <div class="msg__blank">
        <?= uiEmptyState([
            'icon'  => 'bi-person-x',
            'title' => 'No contacts available',
            'desc'  => $emptyMessage ?? 'You have no communication contacts at the moment.',
        ]) ?>
    </div>

<?php else: ?>
    <form class="msg__compose" method="post"
          action="<?= APP_URL ?>/index.php?page=messages&amp;action=start">
        <?= csrfField() ?>

        <?php if (!empty($ctx)): ?>
            <?php /* The ids travel as hidden fields, but they are a hint and
                     nothing more: start() puts every one of them back through
                     canCreateContextConversation() before a row exists. What
                     is rendered here has already been validated once, which is
                     why an unauthorised id never reaches this markup. */ ?>
            <input type="hidden" name="property_id" value="<?= (int) $ctx['property_id'] ?>">
            <input type="hidden" name="lease_id" value="<?= (int) $ctx['lease_id'] ?>">
            <input type="hidden" name="maintenance_request_id" value="<?= (int) $ctx['maintenance_request_id'] ?>">

            <?php
            $contextBlocks  = $ctx['blocks'] ?? [];
            $contextEyebrow = 'Regarding';
            require __DIR__ . '/_context_card.php';
            ?>
        <?php endif; ?>

        <div class="msg__compose-scroll">
            <fieldset class="msg__recipients">
                <legend class="msg__compose-label">Recipient</legend>

                <?php /* Revealed by assets/js/messages.js. Hidden by default so
                         a reader without JavaScript is never shown a filter box
                         that does nothing — the list is short by construction. */ ?>
                <div class="msg__filter-box" data-msg-filter-box hidden>
                    <label class="sr-only" for="msgContactFilter">Filter contacts by name</label>
                    <div class="input-icon">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input class="form-control" type="search" id="msgContactFilter"
                               placeholder="Filter contacts" data-msg-filter autocomplete="off">
                    </div>
                </div>

                <ul class="msg__contacts" data-msg-contacts>
                    <?php foreach ($contacts as $i => $c): ?>
                        <?php
                        $role = (string) ($c['role'] ?? '');

                        /* What this person is to the reader, told honestly. The
                           only thing known for certain is the resolution source
                           and the two roles, so nothing beyond that is claimed.
                           The two staff cases come from the access layer, so
                           the wording for "why this administrator" lives beside
                           the rule that produced them. */
                        if ($role === ROLE_ADMIN && $source === 'admin') {
                            $relation = communicationCounterpartReason($role);
                        } elseif ($role === ROLE_ADMIN) {
                            $relation = 'Administration';
                        } elseif ($role === ROLE_AGENT) {
                            $relation = 'Handles your property, tenancy or purchase';
                        } elseif ($role === ROLE_OWNER) {
                            $relation = 'Owner of a property assigned to you';
                        } elseif ($role === ROLE_CUSTOMER) {
                            $relation = 'Tenant or buyer on a property assigned to you';
                        } elseif ($role === ROLE_MAINTENANCE) {
                            $relation = 'Technician working on a property assigned to you';
                        } else {
                            $relation = (string) ($c['role_label'] ?? '');
                        }
                        $id = 'contact' . (int) $c['id'];
                        ?>
                        <li class="msg__contact" data-name="<?= sanitize(mb_strtolower((string) $c['full_name'])) ?>">
                            <input class="msg__contact-input" type="radio" name="recipient_id"
                                   id="<?= $id ?>" value="<?= (int) $c['id'] ?>"
                                   <?= $i === 0 ? 'checked' : '' ?> required>
                            <label class="msg__contact-label" for="<?= $id ?>">
                                <?= uiAvatar((string) $c['full_name'], $c['avatar'] ?? null, 'md') ?>
                                <span class="msg__contact-text">
                                    <span class="msg__contact-name"><?= sanitize((string) $c['full_name']) ?></span>
                                    <span class="msg__contact-role"><?= sanitize((string) ($c['role_label'] ?? uiLabel($role))) ?></span>
                                    <span class="msg__contact-rel"><?= sanitize($relation) ?></span>
                                </span>
                                <i class="bi bi-check-circle-fill msg__contact-tick" aria-hidden="true"></i>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <p class="msg__contacts-none" data-msg-no-match hidden>No contact matches that name.</p>
            </fieldset>

            <div class="msg__compose-field">
                <label class="msg__compose-label" for="msgFirst">Message <span class="msg__optional">(optional)</span></label>
                <textarea class="form-control" id="msgFirst" name="body" rows="4"
                          maxlength="<?= ConversationMessage::MAX_LENGTH ?>"
                          placeholder="Write your first message…"></textarea>
                <p class="msg__hint">
                    You can also start the conversation now and write afterwards.
                </p>
            </div>
        </div>

        <div class="msg__compose-actions">
            <a class="btn btn--outline" href="<?= sanitize($base . $listSuffix) ?>">Cancel</a>
            <button class="btn btn--primary" type="submit">
                <i class="bi bi-send" aria-hidden="true"></i> Start conversation
            </button>
        </div>
    </form>
<?php endif; ?>
