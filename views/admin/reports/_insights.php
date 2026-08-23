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
 * Expects: $insights — array from ReportController::insights()
 *
 * Every local in this file is prefixed, and that is not house style — it is a
 * bug fix. A partial pulled in with require shares the including view's
 * variable scope, so a plain $series or $meta here silently overwrites the
 * one the report was using. It cost exactly that: the KPI tiles clobbered the
 * overview's $spark and the revenue chart rendered "nothing to chart" over a
 * period with revenue in it, while the tab strip clobbered $meta and titled
 * the Overview "Performance". Prefixes are what make these safe to require
 * more than once, and in any order.
 */
$insItems = $insights ?? [];
?>
<section class="card rcard" aria-labelledby="insights-title">
    <div class="card__header">
        <div class="rcard__titles">
            <h3 class="card__title" id="insights-title">Key insights</h3>
            <p class="card__subtitle">Drawn from the figures on this page</p>
        </div>
    </div>

    <div class="card__body card__body--flush">
        <?php if (!$insItems): ?>
            <div class="rinsights__none">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <p>Nothing stands out in this period. No arrears, no vacancy and no
                   movement large enough to be worth flagging.</p>
            </div>
        <?php else: ?>
            <ul class="rinsights">
                <?php foreach ($insItems as $insItem): ?>
                    <li class="rinsight rinsight--<?= sanitize((string) ($insItem['tone'] ?? 'info')) ?>">
                        <span class="rinsight__icon" aria-hidden="true">
                            <i class="bi <?= sanitize((string) ($insItem['icon'] ?? 'bi-info-circle')) ?>"></i>
                        </span>
                        <div class="rinsight__body">
                            <div class="rinsight__label"><?= sanitize((string) ($insItem['label'] ?? '')) ?></div>
                            <p class="rinsight__text"><?= sanitize((string) ($insItem['text'] ?? '')) ?></p>
                            <?php if (!empty($insItem['url'])): ?>
                                <a class="rinsight__link" href="<?= sanitize((string) $insItem['url']) ?>">
                                    Open the report
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
