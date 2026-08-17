<?php
/**
 * Audit Controller — view audit logs.
 *
 * Read-only by design. Nothing in the application writes here except
 * logAudit(), and nothing anywhere edits or deletes a row: a trail that can be
 * rewritten from the screen it is read on is not a trail.
 */
class AuditController
{
    /** Sortable columns, keyed by the token a request may ask for. */
    public const SORTS = [
        'newest'     => 'a.created_at DESC, a.id DESC',
        'oldest'     => 'a.created_at ASC, a.id ASC',
        'action_asc' => 'a.action ASC, a.created_at DESC',
        'action_desc'=> 'a.action DESC, a.created_at DESC',
        'user_asc'   => 'u.full_name IS NULL, u.full_name ASC, a.created_at DESC',
        'user_desc'  => 'u.full_name IS NULL, u.full_name DESC, a.created_at DESC',
    ];

    public function index(): void
    {
        authorize('audit-logs.view');
        $db = getDBConnection();

        // The actions that actually occur in this installation. Turning the
        // filter from a free-text box into a list of what is really there is
        // the difference between a filter you can use and one you have to
        // guess the spelling of — and it doubles as the allow-list.
        $actions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")
                      ->fetchAll(PDO::FETCH_COLUMN);

        $search = trim((string) ($_GET['search'] ?? ''));
        $action = uiPick($_GET['action_filter'] ?? '', $actions);
        $sort   = uiSortValue(array_keys(self::SORTS), 'newest');

        $page   = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;

        $where = []; $params = [];
        if ($search !== '') {
            $where[] = "(u.full_name LIKE :s OR a.action LIKE :s OR a.entity_type LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }
        if ($action !== '') { $where[] = "a.action = :ac"; $params[':ac'] = $action; }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        // Never interpolated from the request: resolved out of self::SORTS.
        $orderBy = self::SORTS[$sort];

        $stmt = $db->prepare("
            SELECT a.*, u.full_name, u.avatar
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            {$wc}
            ORDER BY {$orderBy}
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
            // Keyed by value so the <option> list and the accepted list are
            // literally one array.
            'actions' => array_combine($actions, array_map('uiLabel', $actions)),
            'pageTitle' => 'Audit Logs',
            'pageSubtitle' => 'Every recorded change, who made it and when. Read-only.',
            'breadcrumbs' => [['label' => 'Audit Logs']],
        ]);
    }
}
