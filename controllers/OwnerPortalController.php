<?php
/**
 * Owner Portal Controller — for logged-in property owners.
 */
class OwnerPortalController
{
    public function myProperties(): void
    {
        authorize('my-properties.view');
        $db = getDBConnection();
        // currentOwnerId() rather than a local lookup, so the portfolio and
        // the record check on the detail page agree on which owner this is.
        $ownerId = currentOwnerId();

        $properties = [];
        if ($ownerId) {
            // One query rather than four per row. Each subquery summarises a
            // relationship the detail page re-checks when it is opened; this
            // only decides what to show on the card.
            $stmt = $db->prepare("
                SELECT p.*,
                       (SELECT file_path FROM property_images pi
                         WHERE pi.property_id = p.id
                         ORDER BY pi.is_cover DESC, pi.sort_order LIMIT 1) AS cover_image,
                       (SELECT c.full_name FROM leases l
                          JOIN customers c ON l.customer_id = c.id
                         WHERE l.property_id = p.id AND l.status = 'active'
                         ORDER BY l.start_date DESC LIMIT 1) AS tenant_name,
                       (SELECT COUNT(*) FROM maintenance_requests m
                         WHERE m.property_id = p.id
                           AND m.status NOT IN ('completed','rejected','cancelled')) AS open_issues,
                       (SELECT COUNT(*) FROM documents d
                         WHERE d.reference_type = 'property' AND d.reference_id = p.id
                           AND d.status = 'active') AS document_count
                  FROM properties p
                 WHERE p.owner_id = ? AND p.is_archived = 0
                 ORDER BY p.created_at DESC
            ");
            $stmt->execute([$ownerId]);
            $properties = $stmt->fetchAll();
        }

        renderPage(VIEWS_PATH . '/owner/my_properties.php', [
            'properties'   => $properties,
            'ownerLinked'  => $ownerId !== null,
            'pageTitle'    => 'My Properties',
            'pageSubtitle' => $properties
                ? count($properties) . ' propert' . (count($properties) === 1 ? 'y' : 'ies') . ' under your ownership'
                : null,
            'breadcrumbs'  => [['label' => 'Properties']],
        ]);
    }

    public function myIncome(): void
    {
        authorize('my-income.view');
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT id, commission_rate FROM owners WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $owner = $stmt->fetch();

        $payments = []; $totalGross = 0; $commissionRate = (float)($owner['commission_rate'] ?? 5);
        if ($owner) {
            $stmt = $db->prepare("
                SELECT py.*, p.title AS property_title, p.property_code
                FROM payments py
                JOIN properties p ON py.property_id = p.id
                WHERE p.owner_id = ? AND py.status = 'paid' AND py.payment_type IN ('rent','sale')
                ORDER BY py.payment_date DESC
            ");
            $stmt->execute([$owner['id']]);
            $payments = $stmt->fetchAll();
            foreach ($payments as $p) $totalGross += (float)$p['amount'];
        }
        $commission = $totalGross * ($commissionRate / 100);
        $net = $totalGross - $commission;

        renderPage(VIEWS_PATH . '/owner/my_income.php', [
            'payments' => $payments,
            'totalGross' => $totalGross,
            'commission' => $commission,
            'commissionRate' => $commissionRate,
            'net' => $net,
            'pageTitle' => 'Income & Statements',
            'breadcrumbs' => [['label' => 'Income']],
        ]);
    }
}
