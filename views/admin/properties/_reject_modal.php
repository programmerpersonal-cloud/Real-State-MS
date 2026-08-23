<?php
/**
 * "Return this listing" dialog.
 *
 * Shared by the approval queue and the property detail page, because it is
 * the same decision made from two places and a second copy of the wording is
 * a second copy that drifts.
 *
 * One dialog serves any number of rows. Whichever control opened it carries
 * the property in `data-fill-id` / `data-fill-record`, and initModal() copies
 * those into the fields below on open — so a page of twenty submissions costs
 * one dialog rather than twenty hidden copies of the same form.
 *
 * Optional: $rejectFrom  'approvals' to send the decision back to the queue,
 *                        so an administrator working through a list is not
 *                        bounced to a record after every one.
 */
?>
<div class="modal" id="propertyRejectModal" data-modal hidden>
    <div class="modal__backdrop" data-modal-close></div>

    <div class="modal__dialog modal__dialog--sm" role="dialog" aria-modal="true"
         aria-labelledby="propertyRejectTitle" tabindex="-1">
        <header class="modal__header">
            <div>
                <h3 class="modal__title" id="propertyRejectTitle">
                    <i class="bi bi-arrow-counterclockwise"></i> Return this listing
                </h3>
                <p class="modal__subtitle" data-fill="record"></p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>

        <form class="modal__form" method="POST"
              action="<?= APP_URL ?>/index.php?page=properties&amp;action=reject">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="" data-fill="id">
            <input type="hidden" name="from" value="<?= sanitize($rejectFrom ?? '') ?>">

            <div class="modal__body">
                <div class="form-group">
                    <label class="form-label" for="approval-note">
                        Why is it being returned? <span class="req" aria-hidden="true">*</span>
                    </label>
                    <?php /* Required, and required for a reason: "rejected" with no
                             note leaves the agent to guess what to change, which is
                             how one listing gets submitted three times. */ ?>
                    <textarea class="form-control" id="approval-note" name="approval_note"
                              rows="4" required maxlength="500"
                              aria-describedby="approval-note-hint"
                              placeholder="e.g. The photographs are of a different unit, and the rent does not match the owner's agreement."></textarea>
                    <p class="form-hint" id="approval-note-hint">
                        This is sent to the agent as a notification and kept on the
                        property, so write what needs to change. The listing stays off
                        the public site until it is approved.
                    </p>
                </div>
            </div>

            <footer class="modal__footer">
                <button type="submit" class="btn btn--danger">
                    <i class="bi bi-arrow-counterclockwise"></i> Return to agent
                </button>
                <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
            </footer>
        </form>
    </div>
</div>
