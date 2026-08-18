<?php
/**
 * Lease — the tenancy record.
 *
 * Rebuilt onto the shared detail layout in step 4. Two things were worth
 * fixing beyond the styling:
 *
 *   · Terminate opened a hand-rolled overlay — a div with inline
 *     position:fixed toggled by onclick="…style.display='flex'". It had no
 *     focus trap, no scroll lock, no Escape, and nothing returned focus to
 *     the button afterwards. It is now the shared .modal, which has all four.
 *   · The arrears figure coloured itself with an inline style computed in
 *     the markup. It is a status, so it wears the status treatment.
 *
 * Expects: $lease, $schedule, $arrears
 */
$l   = $lease;
$id  = (int) $l['id'];
$due = array_values(array_filter(
    $schedule,
    static fn(array $s): bool => in_array($s['status'], ['pending', 'overdue', 'partial'], true)
));

$pageHeaderVariant = 'record';
$pageEyebrow       = 'Lease ' . $l['lease_code'];
?>
<div class="detail-header">
    <div class="detail-header__body">
        <div class="detail-header__eyebrow"><?= sanitize($l['lease_code']) ?></div>
        <h2 class="detail-header__title"><?= sanitize($l['property_title']) ?></h2>
        <p class="detail-header__lede">
            <i class="bi bi-person" aria-hidden="true"></i>
            <a href="<?= APP_URL ?>/index.php?page=customers&amp;action=show&amp;id=<?= (int) $l['customer_id'] ?>">
                <?= sanitize($l['customer_name']) ?>
            </a>
            <?php if (!empty($l['property_address'])): ?>
                &nbsp;·&nbsp; <?= sanitize($l['property_address']) ?>
            <?php endif ?>
        </p>

        <div class="detail-stats">
            <div class="detail-stat">
                <div class="detail-stat__label">Rent</div>
                <div class="detail-stat__value"><?= formatCurrency((float) $l['rent_amount']) ?></div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat__label">Deposit held</div>
                <div class="detail-stat__value"><?= formatCurrency((float) $l['deposit_amount']) ?></div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat__label">Arrears</div>
                <div class="detail-stat__value<?= $arrears > 0 ? ' text-danger' : '' ?>">
                    <?= formatCurrency((float) $arrears) ?>
                </div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat__label">Term</div>
                <div class="detail-stat__value"><?= formatDate($l['end_date']) ?></div>
            </div>
        </div>
    </div>

    <div class="detail-header__actions no-print">
        <?= uiStatus($l['status']) ?>
        <?php if ($l['status'] === 'active' && can('payments.create')): ?>
            <a class="btn btn--primary btn--sm"
               href="<?= APP_URL ?>/index.php?page=payments&amp;action=create&amp;customer_id=<?= (int) $l['customer_id'] ?>&amp;property_id=<?= (int) $l['property_id'] ?>&amp;lease_id=<?= $id ?>">
                <i class="bi bi-cash" aria-hidden="true"></i> Record payment
            </a>
        <?php endif ?>
        <?= uiRowActions(array_merge(
            $l['contract_file'] ? [[
                'label' => 'Open the contract', 'icon' => 'bi-file-earmark-pdf',
                'url' => APP_URL . '/' . $l['contract_file'],
                'attrs' => ['target' => '_blank', 'rel' => 'noopener'],
            ]] : [],
            [
                ['label' => 'View property', 'icon' => 'bi-buildings', 'can' => 'properties.show',
                 'url' => APP_URL . '/index.php?page=properties&action=show&id=' . (int) $l['property_id']],
                ['label' => 'View tenant', 'icon' => 'bi-person', 'can' => 'customers.show',
                 'url' => APP_URL . '/index.php?page=customers&action=show&id=' . (int) $l['customer_id']],
            ],
            $l['status'] === 'active' ? [
                ['label' => 'Renew lease', 'icon' => 'bi-arrow-clockwise', 'can' => 'leases.renew',
                 'url' => APP_URL . '/index.php?page=leases&action=renew&id=' . $id],
                ['label' => 'Terminate lease', 'icon' => 'bi-x-circle', 'can' => 'leases.terminate',
                 'danger' => true, 'url' => '#',
                 'attrs' => ['data-modal-open' => 'terminateModal']],
            ] : []
        ), 'Lease actions') ?>
    </div>
</div>

<div class="detail-cols">
    <div class="detail-cols__main">
        <div class="table-card">
            <div class="table-head">
                <div class="table-head__title">Payment schedule</div>
                <span class="table-head__note">
                    <?= count($due) ?> of <?= count($schedule) ?> still outstanding
                </span>
            </div>

            <?php if (empty($schedule)): ?>
                <?= uiEmptyState([
                    'icon'  => 'bi-calendar3',
                    'title' => 'No schedule recorded',
                    'desc'  => 'A schedule is written when a lease is created. This one has none.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="cell-date">Due</th>
                                <th class="cell-num">Amount</th>
                                <th>Status</th>
                                <th class="cell-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedule as $s): ?>
                                <tr>
                                    <td class="cell-date"><?= formatDate($s['due_date']) ?></td>
                                    <td class="cell-num">
                                        <?= formatCurrency((float) $s['amount']) ?>
                                        <?php if ((float) $s['penalty'] > 0): ?>
                                            <div class="person__meta text-danger">
                                                +<?= formatCurrency((float) $s['penalty']) ?> penalty
                                            </div>
                                        <?php endif ?>
                                    </td>
                                    <td>
                                        <?= uiStatus($s['status']) ?>
                                        <?php if (!empty($s['paid_date'])): ?>
                                            <div class="person__meta">paid <?= formatDate($s['paid_date']) ?></div>
                                        <?php endif ?>
                                    </td>
                                    <td class="cell-actions">
                                        <?php if ($s['status'] !== 'paid' && can('payments.create')): ?>
                                            <a class="btn btn--outline btn--sm"
                                               href="<?= APP_URL ?>/index.php?page=payments&amp;action=create&amp;schedule=<?= (int) $s['id'] ?>">
                                                Record
                                            </a>
                                        <?php endif ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>
    </div>

    <aside class="detail-cols__side">
        <div class="card mb-3">
            <div class="card__header"><h3 class="card__title">The tenancy</h3></div>
            <div class="card__body">
                <dl class="datalist">
                    <div class="datalist__row"><dt>From</dt><dd class="num"><?= formatDate($l['start_date']) ?></dd></div>
                    <div class="datalist__row"><dt>Until</dt><dd class="num"><?= formatDate($l['end_date']) ?></dd></div>
                    <div class="datalist__row"><dt>Moved in</dt><dd class="num"><?= formatDate($l['move_in_date']) ?></dd></div>
                    <?php if ($l['move_out_date']): ?>
                        <div class="datalist__row"><dt>Moved out</dt><dd class="num"><?= formatDate($l['move_out_date']) ?></dd></div>
                    <?php endif ?>
                    <div class="datalist__row"><dt>Rent falls due</dt>
                        <dd><?= sanitize(uiLabel((string) $l['payment_schedule'])) ?></dd>
                    </div>
                    <div class="datalist__row"><dt>Late fee</dt>
                        <dd class="num"><?= number_format((float) $l['late_fee_rate'], 2) ?>%</dd>
                    </div>
                    <?php if ($l['owner_name']): ?>
                        <div class="datalist__row"><dt>Owner</dt><dd><?= sanitize($l['owner_name']) ?></dd></div>
                    <?php endif ?>
                    <?php if (!empty($l['created_by_name'])): ?>
                        <div class="datalist__row"><dt>Created by</dt><dd><?= sanitize($l['created_by_name']) ?></dd></div>
                    <?php endif ?>
                </dl>
            </div>
        </div>

        <?php if ($l['terms']): ?>
            <div class="card mb-3">
                <div class="card__header"><h3 class="card__title">Terms</h3></div>
                <div class="card__body">
                    <div class="prose"><?= nl2br(sanitize($l['terms'])) ?></div>
                </div>
            </div>
        <?php endif ?>

        <?php if ($l['termination_reason']): ?>
            <div class="card">
                <div class="card__header"><h3 class="card__title">Why it ended</h3></div>
                <div class="card__body">
                    <div class="prose text-danger"><?= nl2br(sanitize($l['termination_reason'])) ?></div>
                </div>
            </div>
        <?php endif ?>
    </aside>
</div>

<?php if ($l['status'] === 'active' && can('leases.terminate')): ?>
    <?php /* The shared modal, not the hand-rolled overlay this page used to
             carry. That one was a div toggled by an inline style, so it had
             no focus trap, no scroll lock, no Escape and no focus return —
             all of which the component below already does. */ ?>
    <div class="modal" id="terminateModal" data-modal hidden>
        <div class="modal__backdrop" data-modal-close></div>
        <div class="modal__dialog" role="dialog" aria-modal="true"
             aria-labelledby="terminateTitle" tabindex="-1">
            <header class="modal__header">
                <div>
                    <h3 class="modal__title" id="terminateTitle">
                        <i class="bi bi-x-circle" aria-hidden="true"></i> Terminate this lease
                    </h3>
                    <p class="modal__subtitle">
                        The property returns to available and the tenancy is closed on the date below.
                        The record and its payment history are kept.
                    </p>
                </div>
                <button type="button" class="modal__close" data-modal-close aria-label="Close">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>

            <form class="modal__form" method="post" data-validate
                  action="<?= APP_URL ?>/index.php?page=leases&amp;action=terminate&amp;id=<?= $id ?>">
                <?= csrfField() ?>
                <div class="modal__body">
                    <div class="form-group">
                        <label class="form-label" for="term-date">Move-out date</label>
                        <input type="date" class="form-control" id="term-date" name="move_out_date"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="term-reason">
                            Reason <span class="req" aria-hidden="true">*</span>
                        </label>
                        <textarea class="form-control" id="term-reason" name="reason" rows="3" required
                                  placeholder="Why the tenancy is ending — this is kept on the record."></textarea>
                    </div>
                </div>
                <footer class="modal__footer">
                    <button type="submit" class="btn btn--danger">
                        <i class="bi bi-x-circle" aria-hidden="true"></i> Terminate lease
                    </button>
                    <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
                </footer>
            </form>
        </div>
    </div>
<?php endif ?>
