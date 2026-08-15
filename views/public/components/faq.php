<?php
/**
 * FAQ accordion.
 *
 * Renders siteFaqs() (or an $faqs override). Built on <details>/<summary> so
 * it expands with no JavaScript, is keyboard operable for free, and its
 * content stays in the DOM for crawlers — a div-and-JS accordion hides the
 * answers from anything that doesn't run scripts.
 *
 * The caller sets $pageFaqs before including the layout to emit matching
 * FAQPage structured data; both read the same array, so the markup and the
 * schema can never describe different questions.
 */
$faqItems = $faqs ?? siteFaqs();
?>
<div class="faq-list">
    <?php $i = 0; foreach ($faqItems as $question => $answer): ?>
        <details class="faq-item" data-reveal data-reveal-delay="<?= $i * 50 ?>"
                 <?= $i === 0 ? 'open' : '' ?>>
            <summary class="faq-item__q">
                <span><?= sanitize($question) ?></span>
                <i class="bi bi-plus-lg faq-item__icon" aria-hidden="true"></i>
            </summary>
            <div class="faq-item__a"><p><?= sanitize($answer) ?></p></div>
        </details>
    <?php $i++; endforeach; ?>
</div>
