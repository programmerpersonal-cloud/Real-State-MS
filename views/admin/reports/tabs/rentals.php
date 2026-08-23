<?php
/**
 * Rental analytics — not built yet.
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
    'icon'  => 'bi-house-check',
    'title' => 'Rental analytics',
    'desc'  => 'Occupancy and vacancy over time, leases expiring in the next 7, 30 and 60 '
                 . 'days, and rent collection by status. Occupancy is derived from leases rather '
                 . 'than from the property status column, which the audit found is not '
                 . 'maintained.',
];
require dirname(__DIR__) . '/_pending.php';
