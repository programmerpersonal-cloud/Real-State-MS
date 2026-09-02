<?php
/**
 * Property — detail.
 *
 * A record header stating what this property is and what can be done to it,
 * then the rest behind tabs. Tabs rather than one long column because the
 * page answers several unrelated questions — what is it, who rents it, what
 * has been paid, what is broken — and stacking them meant scrolling past
 * four sections to reach the fifth.
 *
 * `properties.show` is held by every signed-in role: an owner opens their own
 * listing from the portfolio, a tenant from a saved search, a technician from
 * the job they are on. Only staff may change it, so the editing affordances
 * below are drawn from the permission rather than assumed — and every tab is
 * drawn only when the controller actually loaded its data, which it does only
 * behind the matching permission.
 *
 * Vars from PropertyController::show().
 */
$p       = $property;
/* Both halves of the answer: the role must own the action, and the listing
   must be one this user maintains. edit(), approve() and archive() enforce the
   second half themselves, so this only keeps the page from offering a control
   that would refuse. */
$canEdit = can('properties.edit') && canManageProperty($p);
$canRunListing = canManageProperty($p);
$isArchived = (int) ($p['is_archived'] ?? 0) === 1;
$approval   = (string) ($p['approval_status'] ?? '');
$coords  = propertyCoords($p);
$price   = propertyPrice($p);

$pageTitle = sanitize($p['title']);

// The trail back points at whichever list this viewer actually came from:
// staff to the agency register, an owner to their own portfolio. A viewer
// with neither (a tenant arriving from a public listing) gets no crumb rather
// than a link that would refuse them.
$parentCrumb = null;
if (can('properties.view')) {
    $parentCrumb = ['label' => 'Properties', 'url' => APP_URL . '/index.php?page=properties'];
} elseif (can('my-properties.view') && ownsProperty($p)) {
    $parentCrumb = ['label' => 'My Properties', 'url' => APP_URL . '/index.php?page=my-properties'];
}
$breadcrumbs = array_values(array_filter([$parentCrumb, ['label' => $p['property_code']]]));

$base    = APP_URL . '/index.php?page=properties';
$editUrl = $base . '&action=edit&id=' . (int) $p['id'];

// The page header carries no buttons: the record header below owns the
// actions, and two rows of controls saying the same thing is one row too many.
$actionButton = null;

$mapVariant  = 'admin';
$mapEditUrl  = $canEdit ? $editUrl : '';
$fullAddress = trim(implode(', ', array_filter([$p['address'] ?? '', $p['location'] ?? ''])));

// Tenancy is the owner's income and the agency's business, not a browsing
// customer's — and a technician is on the matrix for the address, not the rent.
$canSeeTenancy = can('leases.view') || ownsProperty($p);

$cover = $images[0]['file_path'] ?? null;

/* Tabs are declared as data so the strip and the panels cannot disagree about
   which of them exist. A tab whose data the controller did not load — because
   the permission was not held — is simply absent. */
$tabs = array_values(array_filter([
    ['key' => 'overview',     'label' => 'Overview',     'icon' => 'bi-info-circle',      'count' => null],
    ['key' => 'documents',    'label' => 'Documents',    'icon' => 'bi-folder2-open',     'count' => count($documents ?? [])],
    ['key' => 'maintenance',  'label' => 'Maintenance',  'icon' => 'bi-wrench-adjustable','count' => count($maintenance ?? []),
     'when' => can('maintenance.view')],
    ['key' => 'reservations', 'label' => 'Reservations', 'icon' => 'bi-calendar-check',   'count' => count($reservations ?? []),
     'when' => can('reservations.view')],
    ['key' => 'payments',     'label' => 'Payments',     'icon' => 'bi-credit-card',      'count' => count($payments ?? []),
     'when' => can('payments.view') || ownsProperty($p)],
    ['key' => 'activity',     'label' => 'Activity',     'icon' => 'bi-clock-history',    'count' => count($history ?? [])],
], static fn(array $t): bool => $t['when'] ?? true));
?>

<!-- ── Record header ────────────────────────────────────────────── -->
<div class="detail-header">
    <div class="detail-header__media">
        <img src="<?= sanitize(propertyImage($p, $cover ? ['path' => $cover] : null)) ?>"
             alt="<?= sanitize($p['title']) ?>" width="264" height="192">
    </div>

    <div class="detail-header__body">
        <div class="detail-header__eyebrow"><?= sanitize($p['property_code']) ?></div>
        <h2 class="detail-header__title"><?= sanitize($p['title']) ?></h2>

        <div class="detail-header__meta">
            <?php if ($isArchived): ?>
                <span><?= uiStatus('archived', 'Archived') ?></span>
            <?php else: ?>
                <span><?= uiStatus($p['status']) ?></span>
            <?php endif ?>
            <?php if ($approval !== 'approved'): ?>
                <span><?= uiStatus($approval,
                    $approval === 'pending' ? 'Awaiting approval' : 'Returned for changes') ?></span>
            <?php endif ?>
            <span><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= sanitize($p['location'] ?: 'Location not set') ?></span>
            <span><i class="bi <?= categoryIcon($p['category']) ?>" aria-hidden="true"></i> <?= sanitize(uiLabel($p['category'])) ?></span>
            <span><i class="bi bi-tag" aria-hidden="true"></i> <?= sanitize($price['label']) ?></span>
        </div>

        <div class="detail-stats">
            <div class="detail-stat">
                <div class="detail-stat__label"><?= $price['isRental'] ? 'Monthly rent' : 'Sale price' ?></div>
                <div class="detail-stat__value">
                    <?= $price['amount'] > 0 ? formatCurrency($price['amount']) : '—' ?>
                </div>
            </div>
            <?php if (!empty($p['deposit_amount'])): ?>
                <div class="detail-stat">
                    <div class="detail-stat__label">Deposit</div>
                    <div class="detail-stat__value"><?= formatCurrency((float) $p['deposit_amount']) ?></div>
                </div>
            <?php endif ?>
            <?php if (!empty($p['size_sqm'])): ?>
                <div class="detail-stat">
                    <div class="detail-stat__label">Size</div>
                    <div class="detail-stat__value"><?= (int) $p['size_sqm'] ?> m²</div>
                </div>
            <?php endif ?>
            <div class="detail-stat">
                <div class="detail-stat__label">Rooms</div>
                <div class="detail-stat__value"><?= (int) $p['num_rooms'] ?> / <?= (int) $p['num_bathrooms'] ?></div>
            </div>
        </div>
    </div>

    <div class="detail-header__actions">
        <?php if ($canEdit): ?>
            <a href="<?= $editUrl ?>" class="btn btn--primary btn--sm">
                <i class="bi bi-pencil" aria-hidden="true"></i> Edit
            </a>
        <?php endif ?>

        <?php /* Contextual messaging. The partial draws nothing unless this
                 user may attach a conversation to this property *and* has
                 someone reachable — an owner sees "Message your agent", or
                 "Contact managing office" when no agent is assigned, and a
                 browsing customer with no relationship to the listing sees
                 nothing at all. */ ?>
        <?php
        $messageContext = ['property_id' => (int) $p['id']];
        require VIEWS_PATH . '/components/ui/message_action.php';
        ?>
        <?php
        /* Every state change below is a signed POST, not a link. Approving
           publishes a listing to the public site and archiving takes one off
           it; a verb reachable by GET is a verb a prefetcher, a crawler or a
           shared bookmark can perform on somebody's behalf.

           Which verbs appear depends on where the property actually is: an
           archived property offers Restore and nothing else that would act on
           a record the register cannot see, and an already-approved listing
           is not offered approval again. */
        $record = $p['property_code'] . ' · ' . $p['title'];
        ?>
        <?php if ($isArchived && $canRunListing && canAny('properties.restore', 'properties.archive')): ?>
            <?php /* The only thing to do with an archived property, so it is a
                     button rather than a menu item three clicks deep. */ ?>
            <form method="POST" action="<?= $base ?>&amp;action=restore" class="inline-form">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn btn--primary btn--sm"
                        data-confirm="It returns to the active Properties register as <?= sanitize(strtolower(uiLabel($p['status_before_archive'] ?: 'available'))) ?>, with its photographs, owner, agent, documents and history exactly as they are."
                        data-confirm-title="Restore this property?"
                        data-confirm-action="Restore property"
                        data-confirm-tone="primary"
                        data-confirm-record="<?= sanitize($record) ?>">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore
                </button>
            </form>
        <?php endif ?>

        <?= uiRowActions(array_values(array_filter([
            ($canRunListing && !$isArchived && $approval !== 'approved')
                ? ['label' => 'Approve listing', 'icon' => 'bi-check2-circle', 'can' => 'properties.approve',
                   'method' => 'post', 'url' => $base . '&action=approve',
                   'fields' => ['id' => (int) $p['id']],
                   'confirm' => [
                       'title' => 'Approve this listing?', 'tone' => 'info', 'action' => 'Approve',
                       'body'  => 'It becomes visible on the public site and can be reserved, let or sold. The agent who submitted it is notified.',
                       'record' => $record,
                   ]] : null,
            ($canRunListing && !$isArchived && $approval !== 'rejected' && can('properties.approve'))
                ? ['label' => 'Return with a note', 'icon' => 'bi-arrow-counterclockwise',
                   'url' => '#',
                   'attrs' => [
                       'data-modal-open'  => 'propertyRejectModal',
                       'data-fill-id'     => (string) (int) $p['id'],
                       'data-fill-record' => $record,
                   ]] : null,
            ['label' => 'View public listing', 'icon' => 'bi-box-arrow-up-right',
             'url' => APP_URL . '/index.php?page=listing&id=' . (int) $p['id']],
            ($canRunListing && !$isArchived)
                ? ['label' => 'Archive', 'icon' => 'bi-archive', 'can' => 'properties.archive', 'danger' => true,
                   'method' => 'post', 'url' => $base . '&action=archive',
                   'fields' => ['id' => (int) $p['id']],
                   'confirm' => [
                       'title'  => 'Archive this property?',
                       'body'   => 'It moves to Archived Properties and comes off the public site. Nothing is deleted — photos, owner, agent, leases, payments and documents are kept, and you can restore it at any time.',
                       'action' => 'Archive property',
                       'record' => $record,
                   ]] : null,
        ])), 'More actions') ?>
    </div>
</div>

<?php
/* Where this property stands, in a sentence.
 *
 * The pills above name the state; they do not explain what it means for the
 * property or what happens next, and those are the two things somebody
 * opening a listing that is not live actually needs. Only rendered when
 * there is something to say — an approved, unarchived property is the normal
 * case and gets no banner at all.
 */
$banner = null;
if ($isArchived) {
    $banner = ['muted', 'bi-archive', 'This property is archived',
        'It is hidden from the register and from the public site. Nothing has been '
        . 'deleted — its photographs, owner, agent, documents, leases and payments are '
        . 'all kept, and restoring returns it as '
        . strtolower(uiLabel($p['status_before_archive'] ?: 'available')) . '.'];
} elseif ($approval === 'pending') {
    $banner = ['', 'bi-hourglass-split', 'Awaiting approval',
        can('properties.approve')
            ? 'This listing was submitted for review and is not on the public site yet. '
              . 'Approve it to publish it, or return it to the agent with a note.'
            : 'This listing has been submitted for review. An administrator has to approve '
              . 'it before it appears on the public site; you will be notified either way.'];
} elseif ($approval === 'rejected') {
    $banner = ['muted', 'bi-arrow-counterclockwise', 'Returned for changes',
        ($p['approval_note'] ?? '') !== ''
            ? 'Reason given: ' . $p['approval_note']
            : 'This listing was not approved and is not on the public site.'];
}
?>
<?php if ($banner): ?>
    <?php [$tone, $icon, $title, $body] = $banner; ?>
    <div class="notice<?= $tone !== '' ? ' notice--' . $tone : '' ?>">
        <div class="notice__icon"><i class="bi <?= $icon ?>" aria-hidden="true"></i></div>
        <div class="notice__body">
            <div class="notice__title"><?= sanitize($title) ?></div>
            <p class="notice__item"><?= sanitize($body) ?></p>
        </div>
    </div>
<?php endif ?>

<!-- ── Tabs ─────────────────────────────────────────────────────── -->
<div class="tabs" data-tabs role="tablist">
    <?php foreach ($tabs as $i => $t): ?>
        <button type="button" class="tabs__item<?= $i === 0 ? ' is-active' : '' ?>"
                data-tab="<?= $t['key'] ?>" role="tab"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
            <i class="bi <?= $t['icon'] ?>" aria-hidden="true"></i>
            <?= sanitize($t['label']) ?>
            <?php if ($t['count'] !== null): ?>
                <span class="tabs__count"><?= (int) $t['count'] ?></span>
            <?php endif ?>
        </button>
    <?php endforeach ?>
</div>

<!-- ── Overview ─────────────────────────────────────────────────── -->
<div class="tab-panel is-active" data-panel="overview">
    <div class="detail-cols">
        <div class="detail-cols__main">
            <?php if (count($images) > 1): ?>
                <?php
                /* The gallery, as a viewer rather than a contact sheet.

                   A grid of eight equal thumbnails is the right shape for the
                   editor — there the photographs are objects being managed, and
                   the cover badge and the remove button need somewhere to sit.
                   It is the wrong shape here: on the page where somebody is
                   deciding whether this is the right building, the picture is
                   the content, and 140px of it is a stamp.

                   So this is one large stage with the rest as a rail beneath it.
                   Every thumbnail is a real link to the full-size file, which is
                   exactly what the grid offered before — script only intercepts
                   the click and swaps the stage instead of opening a tab. With
                   scripting off the whole thing degrades to the links it is
                   built from, and nothing is unreachable. */
                $viewerCount = count($images);
                ?>
                <div class="card mb-3">
                    <div class="card__header"><h2 class="card__title">Gallery</h2>
                        <span class="text-subtle"><?= $viewerCount ?> photos</span>
                    </div>
                    <div class="card__body">
                        <div class="viewer" data-viewer>
                            <div class="viewer__stage">
                                <?php /* The stage starts on the first photograph and is
                                         swapped in place, so the position in the set is
                                         announced rather than left to the picture. */ ?>
                                <a class="viewer__frame"
                                   href="<?= APP_URL . '/' . sanitize($images[0]['file_path']) ?>"
                                   target="_blank" rel="noopener"
                                   data-viewer-frame
                                   aria-label="Open this photo at full size in a new tab">
                                    <img src="<?= APP_URL . '/' . sanitize($images[0]['file_path']) ?>"
                                         alt="Photograph of <?= sanitize($p['title']) ?>"
                                         width="900" height="600" data-viewer-image>
                                </a>

                                <?php /* Shown only once script has taken the rail over —
                                         a previous/next control that cannot move anything
                                         is worse than no control. */ ?>
                                <button type="button" class="viewer__nav viewer__nav--prev"
                                        data-viewer-step="-1" aria-label="Previous photo" hidden>
                                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="viewer__nav viewer__nav--next"
                                        data-viewer-step="1" aria-label="Next photo" hidden>
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </button>

                                <p class="viewer__counter" data-viewer-counter hidden>
                                    <span data-viewer-position>1</span> / <?= $viewerCount ?>
                                </p>
                            </div>

                            <ul class="viewer__rail" data-viewer-rail>
                                <?php foreach ($images as $i => $img): ?>
                                    <li>
                                        <a class="viewer__thumb<?= $i === 0 ? ' is-current' : '' ?>"
                                           href="<?= APP_URL . '/' . sanitize($img['file_path']) ?>"
                                           target="_blank" rel="noopener"
                                           data-viewer-thumb="<?= $i ?>"
                                           <?= $i === 0 ? 'aria-current="true"' : '' ?>>
                                            <img src="<?= APP_URL . '/' . sanitize($img['file_path']) ?>"
                                                 alt="Photo <?= $i + 1 ?> of <?= $viewerCount ?>"
                                                 loading="lazy" width="120" height="90">
                                        </a>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif ?>

            <div class="card mb-3">
                <div class="card__header"><h2 class="card__title">Description</h2></div>
                <div class="card__body">
                    <?php if (trim((string) $p['description']) !== ''): ?>
                        <p class="prose"><?= nl2br(sanitize($p['description'])) ?></p>
                    <?php else: ?>
                        <p class="text-subtle">No description has been written for this property yet.</p>
                    <?php endif ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card__header">
                    <h2 class="card__title">Location</h2>
                    <?php if ($fullAddress !== ''): ?>
                        <span class="text-subtle"><?= sanitize($fullAddress) ?></span>
                    <?php endif ?>
                </div>
                <div class="card__body">
                    <?php require VIEWS_PATH . '/components/property_map.php'; ?>
                </div>
            </div>
        </div>

        <aside class="detail-cols__side">
            <div class="card mb-3">
                <div class="card__header"><h2 class="card__title">Specification</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Code</dt><dd><strong><?= sanitize($p['property_code']) ?></strong></dd></div>
                        <div class="datalist__row"><dt>Status</dt><dd><?= uiStatus($p['status']) ?></dd></div>
                        <div class="datalist__row"><dt>Listing</dt><dd><?= sanitize(uiLabel($p['property_type'])) ?></dd></div>
                        <div class="datalist__row"><dt>Type</dt><dd><?= sanitize(uiLabel($p['category'])) ?></dd></div>
                        <div class="datalist__row"><dt>Location</dt><dd><?= sanitize($p['location'] ?: '—') ?></dd></div>
                        <div class="datalist__row"><dt>Coordinates</dt>
                            <dd><?= $coords ? '<code>' . sanitize($coords['text']) . '</code>' : '<span class="text-subtle">Not pinned</span>' ?></dd>
                        </div>
                        <?php if (!empty($p['size_sqm'])): ?>
                            <div class="datalist__row"><dt>Size</dt><dd class="num"><?= (int) $p['size_sqm'] ?> m²</dd></div>
                        <?php endif ?>
                        <div class="datalist__row"><dt>Rooms</dt>
                            <dd class="num"><?= (int) $p['num_rooms'] ?> bed · <?= (int) $p['num_bathrooms'] ?> bath · <?= (int) $p['num_floors'] ?> floor<?= (int) $p['num_floors'] === 1 ? '' : 's' ?></dd>
                        </div>
                        <div class="datalist__row"><dt>Features</dt>
                            <dd>
                                <?php
                                $features = array_filter([
                                    $p['is_furnished'] ? 'Furnished' : null,
                                    $p['has_parking']  ? 'Parking'   : null,
                                    $p['has_security'] ? 'Security'  : null,
                                ]);
                                ?>
                                <?= $features ? sanitize(implode(' · ', $features)) : '<span class="text-subtle">None recorded</span>' ?>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card__header"><h2 class="card__title">Pricing</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <?php if (!empty($p['price'])): ?>
                            <div class="datalist__row"><dt>Sale price</dt><dd class="num"><strong><?= formatCurrency((float) $p['price']) ?></strong></dd></div>
                        <?php endif ?>
                        <?php if (!empty($p['rent_amount'])): ?>
                            <div class="datalist__row"><dt>Monthly rent</dt><dd class="num"><strong><?= formatCurrency((float) $p['rent_amount']) ?></strong></dd></div>
                        <?php endif ?>
                        <?php if (!empty($p['deposit_amount'])): ?>
                            <div class="datalist__row"><dt>Deposit</dt><dd class="num"><?= formatCurrency((float) $p['deposit_amount']) ?></dd></div>
                        <?php endif ?>
                        <?php if (empty($p['price']) && empty($p['rent_amount'])): ?>
                            <div class="datalist__row"><dt>Price</dt><dd class="text-subtle">Not set</dd></div>
                        <?php endif ?>
                    </dl>
                </div>
            </div>

            <?php if ($canSeeTenancy): ?>
                <div class="card mb-3">
                    <div class="card__header">
                        <h2 class="card__title">Current tenancy</h2>
                        <?php if (!empty($activeLease)): ?><?= uiStatus('active') ?><?php endif ?>
                    </div>
                    <div class="card__body">
                        <?php if (!empty($activeLease)): $l = $activeLease; ?>
                            <dl class="datalist">
                                <div class="datalist__row"><dt>Tenant</dt><dd><strong><?= sanitize($l['customer_name']) ?></strong></dd></div>
                                <div class="datalist__row"><dt>Lease</dt><dd><code><?= sanitize($l['lease_code']) ?></code></dd></div>
                                <div class="datalist__row"><dt>Term</dt><dd class="num"><?= formatDate($l['start_date']) ?> — <?= formatDate($l['end_date']) ?></dd></div>
                                <div class="datalist__row"><dt>Rent</dt><dd class="num"><strong><?= formatCurrency((float) $l['rent_amount']) ?></strong> <span class="text-muted"><?= sanitize($l['payment_schedule'] ?? '') ?></span></dd></div>
                                <?php if (!empty($l['deposit_amount'])): ?>
                                    <div class="datalist__row"><dt>Deposit</dt><dd class="num"><?= formatCurrency((float) $l['deposit_amount']) ?></dd></div>
                                <?php endif ?>
                            </dl>
                        <?php else: ?>
                            <p class="text-subtle">No active lease on this property.</p>
                        <?php endif ?>
                    </div>
                    <?php if (!empty($activeLease) && can('leases.show')): ?>
                        <div class="card__footer">
                            <a class="btn btn--outline btn--sm" href="<?= APP_URL ?>/index.php?page=leases&amp;action=show&amp;id=<?= (int) $activeLease['id'] ?>">
                                Open lease <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    <?php endif ?>
                </div>
            <?php endif ?>

            <div class="card">
                <div class="card__header"><h2 class="card__title">Assigned</h2></div>
                <div class="card__body">
                    <dl class="datalist">
                        <div class="datalist__row"><dt>Owner</dt><dd><?= sanitize($p['owner_name'] ?? '—') ?></dd></div>
                        <div class="datalist__row"><dt>Agent</dt><dd><?= sanitize($p['agent_name'] ?? '—') ?></dd></div>
                        <div class="datalist__row"><dt>Branch</dt><dd><?= sanitize($p['branch_name'] ?? '—') ?></dd></div>
                        <div class="datalist__row"><dt>Created</dt><dd class="num"><?= formatDate($p['created_at']) ?></dd></div>
                        <div class="datalist__row"><dt>Updated</dt><dd class="num"><?= formatDate($p['updated_at'] ?? $p['created_at']) ?></dd></div>
                    </dl>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- ── Documents ────────────────────────────────────────────────── -->
<div class="tab-panel" data-panel="documents">
    <?php require __DIR__ . '/_documents_card.php'; ?>
</div>

<!-- ── Maintenance ──────────────────────────────────────────────── -->
<?php if (can('maintenance.view')): ?>
<div class="tab-panel" data-panel="maintenance">
    <div class="table-card">
        <?php if (empty($maintenance)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-wrench-adjustable',
                'title' => 'No maintenance requests',
                'desc'  => 'Nothing has been reported against this property.',
                'actions' => [[
                    'label' => 'Report an issue', 'icon' => 'bi-plus-lg', 'can' => 'maintenance.create',
                    'url'   => APP_URL . '/index.php?page=maintenance&action=create',
                ]],
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>Code</th><th>Issue</th><th>Priority</th><th class="col-lo">Assigned</th>
                        <th>Status</th><th class="col-mid">Reported</th><th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($maintenance as $m): ?>
                            <tr>
                                <td><span class="table__id"><?= sanitize($m['request_code']) ?></span></td>
                                <td class="cell-clip"><?= sanitize(truncate((string) $m['description'], 60)) ?></td>
                                <td><?= uiStatus($m['priority']) ?></td>
                                <td class="col-lo"><?= sanitize($m['assigned_name'] ?: '—') ?></td>
                                <td><?= uiStatus($m['status']) ?></td>
                                <td class="cell-date col-mid"><?= formatDate($m['created_at']) ?></td>
                                <td class="cell-actions">
                                    <?= uiRowActions([[
                                        'label' => 'Open request', 'icon' => 'bi-eye', 'can' => 'maintenance.show',
                                        'url'   => APP_URL . '/index.php?page=maintenance&action=show&id=' . (int) $m['id'],
                                    ]]) ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </div>
</div>
<?php endif ?>

<!-- ── Reservations ─────────────────────────────────────────────── -->
<?php if (can('reservations.view')): ?>
<div class="tab-panel" data-panel="reservations">
    <div class="table-card">
        <?php if (empty($reservations)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-calendar-check',
                'title' => 'No reservations',
                'desc'  => 'This property has not been reserved.',
                'actions' => [[
                    'label' => 'New reservation', 'icon' => 'bi-plus-lg', 'can' => 'reservations.create',
                    'url'   => APP_URL . '/index.php?page=reservations&action=create',
                ]],
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>Code</th><th>Customer</th><th class="col-lo">Reserved</th><th>Expires</th>
                        <th class="cell-num col-mid">Deposit</th><th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($reservations as $r): ?>
                            <tr>
                                <td><span class="table__id"><?= sanitize($r['reservation_code']) ?></span></td>
                                <td><?= sanitize($r['customer_name']) ?></td>
                                <td class="cell-date col-lo"><?= formatDate($r['reservation_date']) ?></td>
                                <td class="cell-date"><?= formatDate($r['expiry_date'] ?? null) ?></td>
                                <td class="cell-num col-mid"><?= !empty($r['deposit_amount']) ? formatCurrency((float) $r['deposit_amount']) : '—' ?></td>
                                <td><?= uiStatus($r['status']) ?></td>
                                <td class="cell-actions">
                                    <?= uiRowActions([[
                                        'label' => 'Open reservations', 'icon' => 'bi-eye', 'can' => 'reservations.view',
                                        'url'   => APP_URL . '/index.php?page=reservations',
                                    ]]) ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </div>
</div>
<?php endif ?>

<!-- ── Payments ─────────────────────────────────────────────────── -->
<?php if (can('payments.view') || ownsProperty($p)): ?>
<div class="tab-panel" data-panel="payments">
    <div class="table-card">
        <?php if (empty($payments)): ?>
            <?= uiEmptyState([
                'icon'  => 'bi-credit-card',
                'title' => 'No payments recorded',
                'desc'  => 'Nothing has been paid against this property yet.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>Code</th><th>Customer</th><th class="col-lo">Type</th>
                        <th class="cell-num">Amount</th><th class="col-mid">Due</th><th>Status</th>
                        <th class="cell-actions"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><span class="table__id"><?= sanitize($pay['payment_code']) ?></span></td>
                                <td><?= sanitize($pay['customer_name']) ?></td>
                                <td class="col-lo"><?= sanitize(uiLabel((string) $pay['payment_type'])) ?></td>
                                <td class="cell-num"><strong><?= formatCurrency((float) $pay['amount']) ?></strong></td>
                                <td class="cell-date col-mid"><?= formatDate($pay['due_date'] ?? null) ?></td>
                                <td><?= uiStatus($pay['status']) ?></td>
                                <td class="cell-actions">
                                    <?= uiRowActions([[
                                        'label' => 'Open payments', 'icon' => 'bi-eye', 'can' => 'payments.view',
                                        'url'   => APP_URL . '/index.php?page=payments',
                                    ]]) ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </div>
</div>
<?php endif ?>

<!-- ── Activity ─────────────────────────────────────────────────── -->
<div class="tab-panel" data-panel="activity">
    <div class="card">
        <div class="card__header"><h2 class="card__title">Property history</h2></div>
        <div class="card__body">
            <?php if (empty($history)): ?>
                <?= uiEmptyState([
                    'icon'  => 'bi-clock-history',
                    'title' => 'No history yet',
                    'desc'  => 'Changes made to this property will be listed here.',
                ]) ?>
            <?php else: ?>
                <ul class="activity">
                    <?php foreach ($history as $h): ?>
                        <li class="activity__item">
                            <div class="activity__dot"></div>
                            <div>
                                <div class="activity__text">
                                    <strong><?= sanitize($h['changed_by_name'] ?? 'System') ?></strong>
                                    <?= sanitize($h['action']) ?>
                                    <?php if (!empty($h['field_changed'])): ?>
                                        <span class="activity__tag"><?= sanitize($h['field_changed']) ?></span>
                                    <?php endif ?>
                                </div>
                                <div class="activity__time"><?= formatDateTime($h['created_at']) ?></div>
                            </div>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    </div>
</div>

<?php
/* The approval decision that needs a reason. Rendered only for the people who
   can actually take it — a dialog nobody on this page may submit is markup
   for nothing, and its trigger is drawn under the same condition above. */
if ($canRunListing && !$isArchived && $approval !== 'rejected' && can('properties.approve')) {
    require __DIR__ . '/_reject_modal.php';
}
?>
