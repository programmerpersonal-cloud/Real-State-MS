<?php
/**
 * MaintenanceRequest Model
 *
 * Every query in here is scoped by includes/property_access.php. That is
 * deliberate and it is the security boundary: the scope is applied where the
 * SQL is built, not where the controller happens to remember to check, so a
 * new caller cannot accidentally read or write outside the signed-in user's
 * properties.
 */
class MaintenanceRequest
{
    /**
     * Sortable columns, keyed by the token a request may ask for.
     *
     * The default is 'priority', not 'newest': this is a work queue, and the
     * urgent job filed last week outranks the low-priority one filed today.
     * Nothing here is built from request text — the request names a key and
     * this constant supplies the clause.
     */
    public const SORTS = [
        'priority'      => "FIELD(m.priority,'urgent','high','medium','low'), m.created_at DESC",
        'priority_asc'  => "FIELD(m.priority,'low','medium','high','urgent'), m.created_at DESC",
        'newest'        => 'm.created_at DESC',
        'oldest'        => 'm.created_at ASC',
        'code_asc'      => 'm.request_code ASC',
        'code_desc'     => 'm.request_code DESC',
        'status_asc'    => 'm.status ASC, m.created_at DESC',
        'status_desc'   => 'm.status DESC, m.created_at DESC',
        'cost_desc'     => 'm.actual_cost DESC, m.cost_estimate DESC',
        'cost_asc'      => 'm.actual_cost ASC, m.cost_estimate ASC',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * A single request, with the owning property's owner_id and agent_id
     * attached so the row-level access checks can run without a second query.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT m.*, p.title AS property_title, p.property_code,
                   p.owner_id AS property_owner_id, p.agent_id AS property_agent_id,
                   c.full_name AS customer_name,
                   r.full_name AS reporter_name,
                   a.full_name AS assigned_name
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            LEFT JOIN customers c ON m.customer_id = c.id
            LEFT JOIN users r ON m.reported_by = r.id
            LEFT JOIN users a ON m.assigned_to = a.id
            WHERE m.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * File a request.
     *
     * The property id is never written straight from the submission. The row
     * is selected out of `properties` through the caller's access scope, so an
     * id belonging to someone else matches nothing, no row is inserted and
     * this returns false. An owner or tenant posting a hand-edited property_id
     * is stopped here even if every check above it were removed.
     *
     * @return int|false The new request id, or false when the property is out
     *                   of scope, archived, or the insert failed.
     */
    public function create(array $d): int|false
    {
        [$scope, $scopeParams] = maintenancePropertyScope('p');

        try {
            $stmt = $this->db->prepare("
                INSERT INTO maintenance_requests (request_code, property_id, customer_id, reported_by,
                    issue_type, description, priority, photos, status)
                SELECT :code, p.id, :cid, :rb, :it, :desc, :pri, :ph, 'new'
                FROM properties p
                WHERE p.id = :pid AND p.is_archived = 0 AND ({$scope})
            ");
            $stmt->execute($scopeParams + [
                ':code' => $d['request_code'] ?? generateCode('MNT'),
                ':pid'  => (int) $d['property_id'],
                ':cid'  => $d['customer_id'] ?? null,
                ':rb'   => $_SESSION['user_id'] ?? null,
                ':it'   => $d['issue_type'] ?? '',
                ':desc' => $d['description'],
                ':pri'  => $d['priority'] ?? 'medium',
                ':ph'   => $d['photos'] ?? '',
            ]);

            // No matching property means the scope rejected it, which is an
            // authorisation failure rather than a database error.
            if ($stmt->rowCount() === 0) {
                return false;
            }
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Maintenance create error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Apply a partial update.
     *
     * Authorisation belongs to the caller (canManageMaintenanceRequest()):
     * by the time a status change is written the row has already been fetched
     * and checked, so re-deriving the scope here would only re-run the same
     * query. What this does guarantee is that only whitelisted columns move —
     * property_id, customer_id and reported_by can never be rewritten.
     */
    public function update(int $id, array $d): bool
    {
        $allowed = ['issue_type','description','priority','assigned_to','cost_estimate','actual_cost',
            'status','completion_date','completion_notes','photos'];
        $fields = []; $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (!$fields) return false;
        return $this->db->prepare("UPDATE maintenance_requests SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    /**
     * Build the WHERE clause shared by getAll() and count().
     *
     * Kept in one place so the list and its result count can never disagree —
     * count() previously ignored every filter but status, which reported the
     * wrong number of pages, and would now also have leaked the total number
     * of requests in the system to a scoped user.
     *
     * @return array{0:string,1:array<string,mixed>} [whereClause, params]
     */
    private function buildFilters(array $filters): array
    {
        [$scope, $params] = maintenanceViewScope('m', 'p');
        $where = ['(' . $scope . ')'];

        if (!empty($filters['status']))   { $where[] = "m.status = :st";      $params[':st'] = $filters['status']; }
        if (!empty($filters['priority'])) { $where[] = "m.priority = :pr";    $params[':pr'] = $filters['priority']; }
        if (!empty($filters['assigned_to'])) { $where[] = "m.assigned_to = :at"; $params[':at'] = $filters['assigned_to']; }
        if (!empty($filters['property_id'])) { $where[] = "m.property_id = :pid"; $params[':pid'] = (int) $filters['property_id']; }
        if (!empty($filters['search'])) {
            $where[] = "(m.request_code LIKE :s OR m.description LIKE :s OR p.title LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$wc, $params] = $this->buildFilters($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['priority'];

        $stmt = $this->db->prepare("
            SELECT m.*, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name, a.full_name AS assigned_name, a.avatar AS assigned_avatar
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            LEFT JOIN customers c ON m.customer_id = c.id
            LEFT JOIN users a ON m.assigned_to = a.id
            {$wc}
            ORDER BY {$orderBy}
            LIMIT :l OFFSET :o
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        [$wc, $params] = $this->buildFilters($filters);
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            {$wc}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * How many requests sit in each status, for the filter pills.
     *
     * Scoped like every other read in this model. A technician's counts
     * describe their own queue and an owner's their own buildings — a raw
     * GROUP BY over the table would publish the size of the whole company's
     * workload to everyone who can open the page, which is exactly the leak
     * count() was fixed for.
     *
     * Carries every filter *except* status, so each pill reports what the
     * user would actually see if they clicked it while the search and
     * priority they already chose stay applied.
     *
     * @return array<string, int>
     */
    public function countsByStatus(array $filters = []): array
    {
        unset($filters['status']);
        [$wc, $params] = $this->buildFilters($filters);

        $stmt = $this->db->prepare("
            SELECT m.status, COUNT(*) AS n
            FROM maintenance_requests m
            JOIN properties p ON m.property_id = p.id
            {$wc}
            GROUP BY m.status
        ");
        $stmt->execute($params);

        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'status'));
    }
}
