<?php
/**
 * Audit Controller — view audit logs.
 */
class AuditController
{
    public function index(): void
    {
        requireRole(ROLE_ADMIN);
        $db = getDBConnection();
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $search = $_GET['search'] ?? '';
        $action = $_GET['action_filter'] ?? '';

        $where = []; $params = [];
        if ($search) {
            $where[] = "(u.full_name LIKE :s OR a.action LIKE :s OR a.entity_type LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }
        if ($action) { $where[] = "a.action = :ac"; $params[':ac'] = $action; }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT a.*, u.full_name
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            {$wc}
            ORDER BY a.created_at DESC
            LIMIT :l OFFSET :o
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':l', ITEMS_PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id {$wc}");
        $cntStmt->execute($params);
        $totalCount = (int)$cntStmt->fetchColumn();
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        renderPage(VIEWS_PATH . '/admin/audit/index.php', [
            'logs' => $logs, 'page' => $page, 'totalPages' => $totalPages, 'totalCount' => $totalCount,
            'filters' => ['search' => $search, 'action_filter' => $action],
            'pageTitle' => 'Audit Logs',
            'breadcrumbs' => [['label' => 'Audit Logs']],
        ]);
    }
}
