<?php
/**
 * Owner Portal Controller — for logged-in property owners.
 */
class OwnerPortalController
{
    public function myProperties(): void
    {
        requireRole(ROLE_OWNER);
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT id FROM owners WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $ownerId = (int)$stmt->fetchColumn();

        $properties = [];
        if ($ownerId) {
            $stmt = $db->prepare("SELECT * FROM properties WHERE owner_id = ? AND is_archived = 0 ORDER BY created_at DESC");
            $stmt->execute([$ownerId]);
            $properties = $stmt->fetchAll();
        }

        renderPage(VIEWS_PATH . '/owner/my_properties.php', [
            'properties' => $properties,
            'pageTitle' => 'My Properties',
            'breadcrumbs' => [['label' => 'Properties']],
        ]);
    }

    public function myIncome(): void
    {
        requireRole(ROLE_OWNER);
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
