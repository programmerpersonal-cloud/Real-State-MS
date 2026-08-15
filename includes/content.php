<?php
/**
 * Marketing content catalogue.
 *
 * Copy for the public pages that describe the agency itself rather than its
 * inventory — services, service detail, legal. Kept here instead of inline in
 * the views so the services index, the detail page, the footer and the nav all
 * read from one list and can never drift apart.
 *
 * Property, agent and enquiry data stays in the database; this is only the
 * editorial copy that has no table behind it.
 */

/**
 * The service catalogue, keyed by URL slug.
 *
 * Each entry carries enough for both the grid card (icon, tone, title, lede)
 * and the full detail page (hero image, body, deliverables, process, stats).
 */
function siteServices(): array
{
    static $services = null;
    if ($services !== null) return $services;

    $services = [
        'buying' => [
            'icon'    => 'bi-house-heart',
            'tone'    => '',
            'title'   => 'Buying advisory',
            'lede'    => 'Find and secure the right property, with someone on your side of the table for the whole negotiation.',
            'image'   => 'property-exterior-2.webp',
            'intro'   => 'Most buyers see a handful of listings and hope one of them is right. We start from your budget, your commute and how you actually live, then shortlist against that brief — including properties that have not reached the public site yet.',
            'body'    => 'Once you have chosen, we handle the offer, the counter-offers and the paperwork. You get one named agent from first viewing to handover, so nothing is re-explained to a new person halfway through.',
            'includes' => [
                ['bi-search',          'Brief and shortlist',   'A written brief, then a shortlist matched against it — not whatever happens to be listed this week.'],
                ['bi-calendar-check',  'Viewings arranged',     'Grouped by area so you can see four properties in an afternoon instead of four separate trips.'],
                ['bi-graph-up',        'Price guidance',        'What comparable properties actually sold for, so your offer is grounded in evidence.'],
                ['bi-file-earmark-text', 'Offer and paperwork', 'We draft the offer, manage the back-and-forth and keep the document trail in one place.'],
            ],
            'process' => [
                ['Tell us the brief',   'Budget, area, must-haves and deal-breakers. Half an hour, in person or on a call.'],
                ['Review the shortlist', 'We send matched properties with honest notes on each — including the drawbacks.'],
                ['View and compare',    'Walk the shortlist with your agent. We record what you liked and refine from there.'],
                ['Offer and close',     'We negotiate, handle the paperwork and stay on it until you have the keys.'],
            ],
            'stats' => [['1,200+', 'Buyers advised'], ['18 days', 'Median offer to acceptance'], ['96%', 'Would recommend']],
        ],

        'selling' => [
            'icon'    => 'bi-tag',
            'tone'    => '--green',
            'title'   => 'Selling & marketing',
            'lede'    => 'Photography, pricing and a listing that reaches genuine buyers rather than just sitting on a portal.',
            'image'   => 'property-exterior-5.webp',
            'intro'   => 'A property that is priced correctly and photographed properly sells faster and closer to asking. We do both before your listing goes anywhere near the public site.',
            'body'    => 'Your listing goes live with full specifications, a floor area, a real location and a named agent answering enquiries. Every enquiry is logged against the property, so you can see exactly how much genuine interest there is rather than guessing from view counts.',
            'includes' => [
                ['bi-camera',        'Professional photography', 'A full shoot of the exterior, every room and the detail that sells the place.'],
                ['bi-cash-stack',    'Evidence-based pricing',   'A valuation built from recent comparable sales in your area, explained line by line.'],
                ['bi-megaphone',     'Listing and distribution', 'Published to the public site and pushed to buyers whose saved brief matches.'],
                ['bi-inbox',         'Enquiry management',       'Every enquiry captured, answered and tracked — nothing lost in a personal inbox.'],
            ],
            'process' => [
                ['Valuation visit',   'We walk the property, take measurements and give you an honest asking price.'],
                ['Prepare the listing', 'Photography, floor area, description and specifications assembled and approved by you.'],
                ['Go live',           'Your listing is published and matched buyers are notified the same day.'],
                ['Offers and close',  'We qualify every offer, advise on which to take and manage the sale through to completion.'],
            ],
            'stats' => [['34 days', 'Median time to offer'], ['98.2%', 'Of asking price achieved'], ['2,400+', 'Properties sold']],
        ],

        'leasing' => [
            'icon'    => 'bi-key',
            'tone'    => '--purple',
            'title'   => 'Leasing solutions',
            'lede'    => 'Tenant sourcing, screening and lease administration — for a single flat or a whole portfolio.',
            'image'   => 'property-interior-3.webp',
            'intro'   => 'The expensive part of letting is not finding a tenant, it is finding the wrong one. We screen properly, document the agreement and keep the renewal calendar so a lease never lapses by accident.',
            'body'    => 'Every lease is held in the system with its start date, rent, deposit and renewal date. Rent is recorded against the lease as it is received, so the arrears position is always current rather than reconstructed at month end.',
            'includes' => [
                ['bi-people',            'Tenant sourcing',    'Advertised, shown and shortlisted, with feedback after every viewing.'],
                ['bi-shield-check',      'Screening',          'Identity, employment and reference checks completed before an offer is accepted.'],
                ['bi-file-earmark-check','Lease preparation',  'A clear written agreement, signed, stored and searchable.'],
                ['bi-arrow-repeat',      'Renewals tracked',   'Reminders ahead of every expiry so renewals are a decision, not a scramble.'],
            ],
            'process' => [
                ['Set the terms',    'Rent, deposit, term length and what is included. We advise on the local market rate.'],
                ['Market and view',  'The unit is listed and shown; you see a shortlist of screened applicants.'],
                ['Sign the lease',   'Agreement drawn up, signed and filed with the deposit recorded.'],
                ['Ongoing admin',    'Rent tracked, receipts issued, renewals flagged before they expire.'],
            ],
            'stats' => [['21 days', 'Median time to let'], ['1.4%', 'Annual arrears rate'], ['87%', 'Renewal rate']],
        ],

        'management' => [
            'icon'    => 'bi-building-gear',
            'tone'    => '--gold',
            'title'   => 'Property management',
            'lede'    => 'Day-to-day oversight of your units: rent collection, maintenance, inspections and owner statements.',
            'image'   => 'property-exterior-8.webp',
            'intro'   => 'Full management for owners who would rather not field a plumbing call at 9pm. We handle the operational side and report on it monthly, with the underlying records available to you at any time.',
            'body'    => 'Maintenance requests come in through the tenant portal, get triaged, assigned to a contractor and closed out — with every step timestamped. Your monthly statement reconciles rent received against costs incurred, so the number you are paid is one you can check.',
            'includes' => [
                ['bi-wallet2',    'Rent collection',    'Collected, receipted and reconciled, with arrears chased on a set schedule.'],
                ['bi-tools',      'Maintenance triage', 'Logged, prioritised, assigned and closed — with the full history on the record.'],
                ['bi-clipboard-check', 'Inspections',   'Scheduled condition checks with dated photographs filed against the unit.'],
                ['bi-receipt',    'Owner statements',   'A monthly statement of income and expenditure, produced in about a minute.'],
            ],
            'process' => [
                ['Onboard the portfolio', 'Units, leases and tenants loaded in, with existing documents attached.'],
                ['Set the rules',         'Who approves what spend, how arrears are chased, when inspections happen.'],
                ['We run the day-to-day', 'Rent, maintenance and tenant contact handled by your management team.'],
                ['You get the statement', 'Monthly reporting with the underlying detail one click away.'],
            ],
            'stats' => [['99.1%', 'Rent collected on time'], ['2.3 days', 'Median maintenance close'], ['24/7', 'Tenant issue logging']],
        ],

        'advisory' => [
            'icon'    => 'bi-graph-up-arrow',
            'tone'    => '--purple',
            'title'   => 'Investment advisory',
            'lede'    => 'Yield analysis, acquisition strategy and portfolio review for investors buying to hold.',
            'image'   => 'property-exterior-9.webp',
            'intro'   => 'Investment property is a numbers question first and a taste question second. We model the yield, the running costs and the realistic void period before you commit, and we will tell you when the numbers do not work.',
            'body'    => 'For existing portfolios we review performance unit by unit — which are carrying the returns, which are quietly losing money, and what to do about each. The analysis is handed over as a document you keep, not a sales meeting.',
            'includes' => [
                ['bi-calculator',    'Yield modelling',    'Gross and net yield modelled against realistic costs, voids and financing.'],
                ['bi-map',           'Area research',      'Rental demand, price movement and pipeline supply for the areas you are considering.'],
                ['bi-diagram-3',     'Portfolio review',   'Unit-by-unit performance with a clear hold, improve or exit recommendation.'],
                ['bi-bank',          'Acquisition support','Sourcing, negotiation and due diligence through to completion.'],
            ],
            'process' => [
                ['Define the mandate', 'Capital available, target return, risk appetite and time horizon.'],
                ['Research and model', 'We analyse candidate areas and assets, and model each against the mandate.'],
                ['Recommend',          'A written recommendation with the numbers behind it, including what we would avoid.'],
                ['Acquire and review',  'We support the purchase, then review performance against the original model.'],
            ],
            'stats' => [['$1.2B', 'Property value advised on'], ['7.4%', 'Median net yield achieved'], ['12', 'Markets covered']],
        ],

        'valuation' => [
            'icon'    => 'bi-rulers',
            'tone'    => '--warm',
            'title'   => 'Valuation & research',
            'lede'    => 'A defensible written valuation for sale, finance, probate or internal planning.',
            'image'   => 'property-interior-5.webp',
            'intro'   => 'A valuation is only useful if it holds up when someone challenges it. Ours is built from comparable evidence, adjusted for condition and specification, and set out so the reasoning is visible.',
            'body'    => 'We produce valuations for sale pricing, mortgage and refinancing, probate and internal portfolio reporting. Turnaround is typically three working days from the inspection.',
            'includes' => [
                ['bi-house-check',  'Inspection',        'A full condition and specification inspection, photographed and recorded.'],
                ['bi-bar-chart',    'Comparable evidence','Recent comparable transactions, adjusted and shown line by line.'],
                ['bi-file-text',    'Written report',    'A document you can hand to a lender, a court or a board.'],
                ['bi-clock-history','Three-day turnaround','Report delivered within three working days of the inspection.'],
            ],
            'process' => [
                ['Book the inspection', 'Usually within two working days of your request.'],
                ['On-site assessment',  'Condition, specification, floor area and location factors recorded.'],
                ['Comparable analysis', 'Recent transactions gathered and adjusted against the subject property.'],
                ['Report delivered',    'A written, defensible valuation with the evidence attached.'],
            ],
            'stats' => [['3 days', 'Median turnaround'], ['5,000+', 'Valuations issued'], ['100%', 'Written and evidenced']],
        ],
    ];

    return $services;
}

/** A single service by slug, or null when the slug is unknown. */
function siteService(string $slug): ?array
{
    return siteServices()[$slug] ?? null;
}

/* ─── Case studies ────────────────────────────────────────────────────── */

/**
 * Recent completed deals, assembled from real sales and lease records.
 *
 * This is deliberately derived from the database rather than written as copy:
 * a "case study" that cannot be traced to a transaction is just a claim. Each
 * entry reports what actually happened — the property, the outcome, the value
 * and how long it took — and returns [] when there is nothing closed yet, so
 * the section hides rather than inventing a track record.
 *
 * Customer names are never exposed. The counterparty is described by role
 * only ("Private buyer"), because publishing who bought what would be a
 * privacy breach regardless of what the marketing value might be.
 */
function recentCaseStudies(int $limit = 3): array
{
    $out = [];

    try {
        $db = getDBConnection();

        // Completed sales.
        $stmt = $db->prepare("
            SELECT s.sale_amount, s.sale_date, s.created_at,
                   p.id AS property_id, p.title, p.location, p.category,
                   p.num_rooms, p.size_sqm, p.created_at AS listed_at,
                   u.full_name AS agent_name
            FROM sales s
            JOIN properties p ON s.property_id = p.id
            LEFT JOIN users u ON s.agent_id = u.id
            WHERE s.status = 'completed'
            ORDER BY COALESCE(s.sale_date, s.created_at) DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt as $r) {
            $out[] = [
                'outcome'   => 'Sold',
                'tone'      => '--green',
                'icon'      => 'bi-patch-check',
                'property'  => $r['title'] ?: 'Property',
                'property_id' => (int) $r['property_id'],
                'location'  => $r['location'] ?: BIZ_CITY,
                'category'  => categoryLabel((string) $r['category']),
                'headline'  => formatCurrency((float) $r['sale_amount']),
                'headline_label' => 'Sale price',
                'days'      => caseStudyDays($r['listed_at'] ?? null, $r['sale_date'] ?? $r['created_at']),
                'agent'     => $r['agent_name'] ?: null,
                'date'      => !empty($r['sale_date']) ? date('F Y', strtotime((string) $r['sale_date'])) : null,
                'specs'     => caseStudySpecs($r),
            ];
        }

        // Active leases, to fill the remaining slots.
        $remaining = $limit - count($out);
        if ($remaining > 0) {
            $stmt = $db->prepare("
                SELECT l.rent_amount, l.start_date, l.created_at,
                       p.id AS property_id, p.title, p.location, p.category,
                       p.num_rooms, p.size_sqm, p.created_at AS listed_at,
                       u.full_name AS agent_name
                FROM leases l
                JOIN properties p ON l.property_id = p.id
                LEFT JOIN users u ON l.created_by = u.id
                WHERE l.status IN ('active', 'renewed')
                ORDER BY l.start_date DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':lim', $remaining, PDO::PARAM_INT);
            $stmt->execute();

            foreach ($stmt as $r) {
                $out[] = [
                    'outcome'   => 'Leased',
                    'tone'      => '',
                    'icon'      => 'bi-key',
                    'property'  => $r['title'] ?: 'Property',
                    'property_id' => (int) $r['property_id'],
                    'location'  => $r['location'] ?: BIZ_CITY,
                    'category'  => categoryLabel((string) $r['category']),
                    'headline'  => formatCurrency((float) $r['rent_amount']) . '/mo',
                    'headline_label' => 'Agreed rent',
                    'days'      => caseStudyDays($r['listed_at'] ?? null, $r['start_date'] ?? $r['created_at']),
                    'agent'     => $r['agent_name'] ?: null,
                    'date'      => !empty($r['start_date']) ? date('F Y', strtotime((string) $r['start_date'])) : null,
                    'specs'     => caseStudySpecs($r),
                ];
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $out;
}

/** Whole days between listing and close, or null when the dates are unusable. */
function caseStudyDays(?string $from, ?string $to): ?int
{
    if (!$from || !$to) return null;
    $a = strtotime($from);
    $b = strtotime($to);
    if (!$a || !$b || $b < $a) return null;
    return (int) floor(($b - $a) / 86400);
}

/** Short spec line for a case study card. */
function caseStudySpecs(array $row): string
{
    $bits = [];
    if (!empty($row['num_rooms'])) $bits[] = (int) $row['num_rooms'] . ' bed';
    if (!empty($row['size_sqm']))  $bits[] = number_format((float) $row['size_sqm']) . ' m²';
    return implode(' · ', $bits);
}

/* ─── FAQs ────────────────────────────────────────────────────────────── */

/**
 * The public FAQ, as [question => answer].
 *
 * Returned as a plain map so the same array drives both the rendered
 * accordion and the FAQPage structured data — the two can never disagree,
 * which is the usual cause of a rich-result mismatch penalty.
 */
function siteFaqs(): array
{
    return [
        'How quickly will someone reply to my enquiry?'
            => 'Within ' . BIZ_RESPONSE_HOURS . ' hours on business days, and usually the same working day. '
             . 'Every enquiry is logged against the property and assigned to the agent who represents it, '
             . 'so it does not sit in a shared inbox waiting for someone to claim it.',

        'Are the listings real and currently available?'
            => 'Yes. Every listing is reviewed and approved by a person before it appears on the site, and '
             . 'each one names the agent responsible for it. When a property is reserved, let or sold, its '
             . 'status changes immediately rather than staying up to attract enquiries.',

        'Can I book a viewing without creating an account?'
            => 'Yes. Use the enquiry form on any property page — name, email and a message is all that is '
             . 'needed. An account is only useful if you want to save properties and track your enquiries '
             . 'in one place.',

        'What does it cost to list my property?'
            => 'Creating an account and publishing a listing is free. Agency, management and advisory '
             . 'services are charged under a written engagement agreed with you in advance — there are no '
             . 'fees applied that you have not seen in writing first.',

        'How do I know the price is fair?'
            => 'Ask for the comparable evidence. We price from recent comparable transactions in the same '
             . 'area, adjusted for condition, specification and floor area, and we will show you the '
             . 'workings for any property we market or value.',
    ];
}
