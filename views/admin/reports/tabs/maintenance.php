<?php
/**
 * Maintenance analytics — not built yet.
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
    'icon'  => 'bi-tools',
    'title' => 'Maintenance analytics',
    'desc'  => 'Open and completed work, average resolution time, cost against estimate, and '
                 . 'the properties that generate the most requests. Resolution time needs '
                 . 'completed jobs on file, so parts of this report stay empty until there are '
                 . 'some.',
];
require dirname(__DIR__) . '/_pending.php';
