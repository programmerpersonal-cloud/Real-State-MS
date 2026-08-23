<?php
/**
 * Sales analytics — not built yet.
 *
 * The tab is real, the route is real and it is authorized like every other
 * report. What it does not have is data, and the workspace would rather show
 * an honest description of what is coming than a chart of zeroes that looks
 * like an answer.
 *
 * The toolbar above still works: the period, the comparison toggle and the
 * filters all apply to this report the moment it has figures to narrow.
 */
$pending = [
    'icon'  => 'bi-tag',
    'title' => 'Sales analytics',
    'desc'  => 'Completed sales, gross contract value, how much of it has actually been '
                 . 'collected, and what remains outstanding. Contract value and collected '
                 . 'revenue are kept apart: a signed sale is not money in the bank and will '
                 . 'never be labelled as though it were.',
];
require dirname(__DIR__) . '/_pending.php';
