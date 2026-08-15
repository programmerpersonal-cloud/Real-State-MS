<?php
/**
 * Customer Portal Controller — for logged-in customers viewing their own data.
 */
class CustomerPortalController
{
    public function myLease(): void
    {
        requireRole(ROLE_CUSTOMER);
        $db = getDBConnection();
        // Find customer record linked to this user
        $stmt = $db->prepare("SELECT * FROM customers WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $customer = $stmt->fetch();

        $leases = [];
        $activeLease = null;
        $schedule = [];
        if ($customer) {
            $stmt = $db->prepare("
                SELECT l.*, p.title AS property_title, p.property_code, p.address AS property_address
                FROM leases l
                JOIN properties p ON l.property_id = p.id
                WHERE l.customer_id = ?
                ORDER BY l.status='active' DESC, l.start_date DESC
            ");
            $stmt->execute([$customer['id']]);
            $leases = $stmt->fetchAll();
            foreach ($leases as $l) if ($l['status'] === 'active') { $activeLease = $l; break; }
            if ($activeLease) {
                $stmt = $db->prepare("SELECT * FROM payment_schedules WHERE lease_id = ? ORDER BY due_date");
                $stmt->execute([$activeLease['id']]);
                $schedule = $stmt->fetchAll();
            }
        }

        renderPage(VIEWS_PATH . '/customer/my_lease.php', [
            'customer' => $customer, 'leases' => $leases,
            'activeLease' => $activeLease, 'schedule' => $schedule,
            'pageTitle' => 'My Lease',
            'breadcrumbs' => [['label' => 'My Lease']],
        ]);
    }

    public function myPayments(): void
    {
        requireRole(ROLE_CUSTOMER);
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT id FROM customers WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $customerId = (int)$stmt->fetchColumn();

        $payments = [];
        if ($customerId) {
            $stmt = $db->prepare("
                SELECT py.*, p.title AS property_title
                FROM payments py
                LEFT JOIN properties p ON py.property_id = p.id
                WHERE py.customer_id = ?
                ORDER BY py.payment_date DESC
            ");
            $stmt->execute([$customerId]);
            $payments = $stmt->fetchAll();
        }

        renderPage(VIEWS_PATH . '/customer/my_payments.php', [
            'payments' => $payments,
            'pageTitle' => 'My Payments',
            'breadcrumbs' => [['label' => 'My Payments']],
        ]);
    }
}
