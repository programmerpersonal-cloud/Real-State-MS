<?php
/**
 * Key insights.
 *
 * Every line here was produced by a rule in ReportController::insights() that
 * either fired or did not, from figures already on this page. There is no
 * model behind it, no phrasebook of encouraging sentences, and no line that
 * appears because the panel would look empty without one.
 *
 * Which is why an empty panel is a real outcome and says so plainly: a
 * portfolio that is fully let, fully collected and flat against last period
 * has nothing worth flagging, and inventing something would teach the reader
 * to stop trusting this box.
 *
 * Phase 8 gave the list a hierarchy it did not have. The controller has
 * always ranked these — arrears above collections above occupancy above
 * revenue movement — and the view then drew all of them identically, so the
 * ranking existed in the array and nowhere on the screen. The first item now
 * reads as the lead: a larger figure, more room, and the rail beside it drawn
 * in full rather than at a whisper. Everything below it steps down.
 *
 * Emphasis is by *weight and space*, not by colour blocks. An insight that
 * needs acting on also carries the words "Needs attention", because a panel
 * that distinguished urgent from routine by hue alone would say nothing at
 * all on a greyscale print or to a reader who cannot separate the two.
 *
 * Expects: $insights — array from ReportController::insights()
 *
 * Every local in this file is prefixed. A partial pulled in with require
 * shares the including view's variable scope; see the note in _kpi.php.
 */
$insItems = $insights ?? [];
$insAlert = ['warning', 'danger'];
?>
<section class="card rcard" aria-labelledby="insights-title">
    <div class="card__header">
        <div class="rcard__titles">
            <h4 class="card__title" id="insights-title">Key insights</h4>
            <p class="card__subtitle">Derived from the figures on this page, most important first</p>
        </div>
    </div>

    <div class="card__body card__body--flush">
        <?php if (!$insItems): ?>
            <div class="rinsights__none">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <div>
                    <p class="rinsights__none-title">Nothing stands out in this period</p>
                    <p>No arrears, no vacancy and no movement large enough to be worth
                       flagging. This panel stays empty rather than filling itself.</p>
                </div>
            </div>
        <?php else: ?>
            <ul class="rinsights">
                <?php foreach ($insItems as $insI => $insItem): ?>
                    <?php
                    $insTone  = (string) ($insItem['tone'] ?? 'info');
                    $insNeeds = in_array($insTone, $insAlert, true);
                    ?>
                    <li class="rinsight rinsight--<?= sanitize($insTone) ?><?= $insI === 0 ? ' is-lead' : '' ?>">
                        <span class="rinsight__icon" aria-hidden="true">
                            <i class="bi <?= sanitize((string) ($insItem['icon'] ?? 'bi-info-circle')) ?>"></i>
                        </span>
                        <div class="rinsight__body">
                            <div class="rinsight__head">
                                <span class="rinsight__label"><?= sanitize((string) ($insItem['label'] ?? '')) ?></span>
                                <?php if ($insNeeds): ?>
                                    <span class="rinsight__flag">Needs attention</span>
                                <?php endif ?>
                            </div>
                            <p class="rinsight__text"><?= sanitize((string) ($insItem['text'] ?? '')) ?></p>
                            <?php if (!empty($insItem['url'])): ?>
                                <a class="rinsight__link" href="<?= sanitize((string) $insItem['url']) ?>">
                                    <span>Open the report</span>
                                    <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                                </a>
                            <?php endif ?>
                        </div>
                        <?php if (!empty($insItem['metric'])): ?>
                            <div class="rinsight__metric"><?= sanitize((string) $insItem['metric']) ?></div>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
</section>
