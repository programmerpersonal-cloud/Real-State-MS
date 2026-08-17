<?php
/**
 * Document Model — files attached to a property (and, later, to anything else).
 *
 * The reference_type/reference_id pair is polymorphic and unenforced by design,
 * matching how payments and notifications already point at their subjects. Only
 * 'property' is used today; leases and customers can be added without touching
 * the schema.
 *
 * Two things this model deliberately does NOT do:
 *   * store an "expired" status — expiry is derived on read by documentStatus(),
 *     because nothing in this project runs on a schedule to keep a column true;
 *   * decide who may see a row — the visibility_in filter is passed in by the
 *     caller from documentVisibilityScope(), and the download endpoint checks
 *     again with documentVisibilityAllows().
 */
class Document
{
    private PDO $db;

    /** Columns a caller may set through update(). */
    private const EDITABLE = [
        'category_id', 'title', 'description', 'doc_number', 'document_date',
        'expiry_date', 'visibility', 'status', 'doc_type', 'is_restricted',
        'file_path', 'file_name', 'file_type', 'file_ext', 'file_size', 'checksum',
    ];

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /** Full row with category and uploader names, for detail and edit screens. */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug,
                   u.full_name AS uploaded_by_name,
                   a.full_name AS archived_by_name
              FROM documents d
              LEFT JOIN document_categories c ON c.id = d.category_id
              LEFT JOIN users u ON u.id = d.uploaded_by
              LEFT JOIN users a ON a.id = d.archived_by
             WHERE d.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The row plus everything the download endpoint needs to authorise it, in
     * one query: a public document is only public while its parent property is
     * approved and unarchived, so that has to be known before any byte is read.
     */
    public function findForDelivery(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   c.name AS category_name,
                   p.id       AS property_id,
                   p.title    AS property_title,
                   p.owner_id AS property_owner_id,
                   p.approval_status,
                   p.is_archived
              FROM documents d
              LEFT JOIN document_categories c ON c.id = d.category_id
              LEFT JOIN properties p
                     ON d.reference_type = 'property' AND p.id = d.reference_id
             WHERE d.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Insert a document.
     *
     * The code is unique, and generateCode() is uniqid()-based rather than
     * sequential, so a collision is improbable but not impossible. One retry
     * turns a 500 into a saved document.
     */
    public function create(array $d): int|false
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO documents
                        (category_id, document_code, doc_type, title, description, doc_number,
                         document_date, file_path, file_name, file_type, file_ext, file_size, checksum,
                         reference_type, reference_id, uploaded_by, expiry_date, visibility, status, is_restricted)
                    VALUES
                        (:cat, :code, :dtype, :title, :desc, :num,
                         :ddate, :path, :fname, :ftype, :fext, :fsize, :sum,
                         :rtype, :rid, :uid, :exp, :vis, 'active', :restricted)
                ");
                $stmt->execute([
                    ':cat'        => $d['category_id'] ?: null,
                    ':code'       => $d['document_code'] ?? generateCode('DOC'),
                    ':dtype'      => $d['doc_type'] ?? null,
                    ':title'      => $d['title'],
                    ':desc'       => $d['description'] ?? '',
                    ':num'        => $d['doc_number'] ?: null,
                    ':ddate'      => $d['document_date'] ?: null,
                    ':path'       => $d['file_path'],
                    ':fname'      => $d['file_name'] ?? '',
                    ':ftype'      => $d['file_type'] ?? '',
                    ':fext'       => $d['file_ext'] ?? '',
                    ':fsize'      => (int) ($d['file_size'] ?? 0),
                    ':sum'        => $d['checksum'] ?? null,
                    ':rtype'      => $d['reference_type'] ?? 'property',
                    ':rid'        => $d['reference_id'] ?: null,
                    ':uid'        => $_SESSION['user_id'] ?? null,
                    ':exp'        => $d['expiry_date'] ?: null,
                    ':vis'        => $d['visibility'] ?? 'staff',
                    // Keep the legacy flag consistent so anything still reading
                    // it sees the same answer as the visibility column.
                    ':restricted' => ($d['visibility'] ?? 'staff') === 'private' ? 1 : 0,
                ]);
                return (int) $this->db->lastInsertId();
            } catch (PDOException $e) {
                $duplicateCode = $e->errorInfo[1] ?? 0;
                if ($duplicateCode === 1062 && $attempt === 0 && empty($d['document_code'])) {
                    continue; // regenerate the code and try once more
                }
                error_log('Document create error: ' . $e->getMessage());
                return false;
            }
        }
        return false;
    }

    public function update(int $id, array $d): bool
    {
        $fields = [];
        $params = [':id' => $id];

        foreach (self::EDITABLE as $f) {
            if (array_key_exists($f, $d)) {
                $fields[] = "$f = :$f";
                $params[":$f"] = $d[$f];
            }
        }
        if (!$fields) return false;

        // The legacy is_restricted flag tracks visibility rather than drifting.
        if (array_key_exists('visibility', $d) && !array_key_exists('is_restricted', $d)) {
            $fields[] = "is_restricted = :is_restricted";
            $params[':is_restricted'] = $d['visibility'] === 'private' ? 1 : 0;
        }

        try {
            return $this->db->prepare(
                "UPDATE documents SET " . implode(', ', $fields) . " WHERE id = :id"
            )->execute($params);
        } catch (PDOException $e) {
            error_log('Document update error: ' . $e->getMessage());
            return false;
        }
    }

    /** Withdraw a document from circulation without destroying it. */
    public function archive(int $id, ?int $userId = null): bool
    {
        $stmt = $this->db->prepare("
            UPDATE documents
               SET status = 'archived', archived_at = NOW(), archived_by = :uid
             WHERE id = :id AND status = 'active'
        ");
        return $stmt->execute([':id' => $id, ':uid' => $userId ?? ($_SESSION['user_id'] ?? null)]);
    }

    public function restore(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE documents
               SET status = 'active', archived_at = NULL, archived_by = NULL
             WHERE id = :id AND status = 'archived'
        ");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Permanently remove a document.
     *
     * The row goes first: an orphaned file on disk is a housekeeping problem,
     * while a row pointing at a file that no longer exists is a broken screen.
     */
    public function delete(int $id): bool
    {
        $doc = $this->findById($id);
        if (!$doc) return false;

        try {
            $ok = $this->db->prepare("DELETE FROM documents WHERE id = :id")->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('Document delete error: ' . $e->getMessage());
            return false;
        }

        if ($ok) {
            deleteDocumentFile($doc['file_path'] ?? '');
        }
        return $ok;
    }

    /**
     * Build the shared WHERE clause.
     *
     * @return array{0:string,1:array} [$whereSql, $params]
     */
    private function buildFilters(array $f): array
    {
        $where  = [];
        $params = [];

        if (!empty($f['search'])) {
            $where[] = "(d.title LIKE :s OR d.description LIKE :s OR d.doc_number LIKE :s
                         OR d.document_code LIKE :s OR d.file_name LIKE :s)";
            $params[':s'] = '%' . $f['search'] . '%';
        }

        if (!empty($f['category_id'])) {
            $where[] = "d.category_id = :cat";
            $params[':cat'] = (int) $f['category_id'];
        }

        if (!empty($f['reference_type'])) {
            $where[] = "d.reference_type = :rtype";
            $params[':rtype'] = $f['reference_type'];
        }

        if (!empty($f['reference_id'])) {
            $where[] = "d.reference_id = :rid";
            $params[':rid'] = (int) $f['reference_id'];
        }

        if (!empty($f['uploaded_by'])) {
            $where[] = "d.uploaded_by = :uid";
            $params[':uid'] = (int) $f['uploaded_by'];
        }

        if (!empty($f['visibility'])) {
            $where[] = "d.visibility = :vis";
            $params[':vis'] = $f['visibility'];
        }

        // Visibility scope. The values are intersected against the known set
        // before being inlined, so nothing user-supplied reaches the SQL even
        // though this branch does not use placeholders.
        if (!empty($f['visibility_in']) && is_array($f['visibility_in'])) {
            $scope = array_values(array_intersect($f['visibility_in'], ['private', 'staff', 'public']));
            if (!$scope) {
                return ['WHERE 1 = 0', []]; // no permitted level: return nothing
            }
            $where[] = "d.visibility IN ('" . implode("','", $scope) . "')";
        }

        // Archived rows are hidden unless explicitly asked for.
        if (empty($f['include_archived']) && empty($f['state'])) {
            $where[] = "d.status = 'active'";
        }

        // Derived lifecycle state. CURDATE() keeps the boundary in the database
        // so a filtered list and a rendered badge cannot disagree.
        if (!empty($f['state'])) {
            $warn = (int) ($f['warn_days'] ?? documentExpiryWarningDays());
            switch ($f['state']) {
                case 'archived':
                    $where[] = "d.status = 'archived'";
                    break;
                case 'expired':
                    $where[] = "d.status = 'active' AND d.expiry_date IS NOT NULL AND d.expiry_date < CURDATE()";
                    break;
                case 'expiring':
                    $where[] = "d.status = 'active' AND d.expiry_date IS NOT NULL
                                AND d.expiry_date >= CURDATE()
                                AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$warn} DAY)";
                    break;
                case 'active':
                    $where[] = "d.status = 'active' AND (d.expiry_date IS NULL
                                OR d.expiry_date > DATE_ADD(CURDATE(), INTERVAL {$warn} DAY))";
                    break;
            }
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private const SELECT_LIST = "
        SELECT d.*,
               c.name AS category_name, c.icon AS category_icon,
               u.full_name AS uploaded_by_name,
               p.title AS property_title, p.property_code
          FROM documents d
          LEFT JOIN document_categories c ON c.id = d.category_id
          LEFT JOIN users u ON u.id = d.uploaded_by
          LEFT JOIN properties p ON d.reference_type = 'property' AND p.id = d.reference_id
    ";

    /**
     * Sortable columns, keyed by the token a request may ask for.
     *
     * `expiry_asc` puts NULLs last: a document with no expiry never needs
     * renewing, so it does not belong at the top of a list someone opened to
     * find what does.
     */
    public const SORTS = [
        'newest'     => 'd.created_at DESC, d.id DESC',
        'oldest'     => 'd.created_at ASC, d.id ASC',
        'title_asc'  => 'd.title ASC',
        'title_desc' => 'd.title DESC',
        'expiry_asc' => 'd.expiry_date IS NULL, d.expiry_date ASC',
        'expiry_desc'=> 'd.expiry_date IS NULL, d.expiry_date DESC',
        'size_desc'  => 'd.file_size DESC',
        'size_asc'   => 'd.file_size ASC',
        'cat_asc'    => 'c.sort_order, c.name ASC, d.created_at DESC',
    ];

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'];

        $stmt = $this->db->prepare(self::SELECT_LIST . " {$where}
             ORDER BY {$orderBy}
             LIMIT :l OFFSET :o");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        // Emulated prepares stringify integers, which MySQL rejects after LIMIT.
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
              FROM documents d
              LEFT JOIN document_categories c ON c.id = d.category_id
              LEFT JOIN users u ON u.id = d.uploaded_by
              LEFT JOIN properties p ON d.reference_type = 'property' AND p.id = d.reference_id
            {$where}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Every document attached to one subject, unpaginated. */
    public function forReference(string $refType, int $refId, array $filters = []): array
    {
        $filters['reference_type'] = $refType;
        $filters['reference_id']   = $refId;

        [$where, $params] = $this->buildFilters($filters);

        $stmt = $this->db->prepare(self::SELECT_LIST . " {$where}
             ORDER BY c.sort_order, d.created_at DESC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Tab counts for one subject, in a single query.
     *
     * @return array{total:int,active:int,expiring:int,expired:int,archived:int}
     */
    public function statsForReference(string $refType, int $refId, array $visibilityScope = []): array
    {
        $warn  = documentExpiryWarningDays();
        $scope = array_values(array_intersect($visibilityScope ?: ['private', 'staff', 'public'], ['private', 'staff', 'public']));
        if (!$scope) {
            return ['total' => 0, 'active' => 0, 'expiring' => 0, 'expired' => 0, 'archived' => 0];
        }
        $scopeSql = "AND visibility IN ('" . implode("','", $scope) . "')";

        $stmt = $this->db->prepare("
            SELECT
              COUNT(*) AS total,
              SUM(status = 'archived') AS archived,
              SUM(status = 'active' AND (expiry_date IS NULL OR expiry_date > DATE_ADD(CURDATE(), INTERVAL {$warn} DAY))) AS active,
              SUM(status = 'active' AND expiry_date IS NOT NULL AND expiry_date >= CURDATE()
                  AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$warn} DAY)) AS expiring,
              SUM(status = 'active' AND expiry_date IS NOT NULL AND expiry_date < CURDATE()) AS expired
            FROM documents
            WHERE reference_type = :rtype AND reference_id = :rid {$scopeSql}
        ");
        $stmt->execute([':rtype' => $refType, ':rid' => $refId]);
        $row = $stmt->fetch() ?: [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'expiring' => (int) ($row['expiring'] ?? 0),
            'expired'  => (int) ($row['expired'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
        ];
    }

    /** Documents inside the warning window, soonest first — for the dashboard. */
    public function expiring(?int $withinDays = null, int $limit = 10): array
    {
        $warn = (int) ($withinDays ?? documentExpiryWarningDays());
        $stmt = $this->db->prepare(self::SELECT_LIST . "
             WHERE d.status = 'active'
               AND d.expiry_date IS NOT NULL
               AND d.expiry_date >= CURDATE()
               AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$warn} DAY)
             ORDER BY d.expiry_date ASC
             LIMIT :l");
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Past-expiry documents, most overdue first. Never auto-deleted. */
    public function expired(int $limit = 10): array
    {
        $stmt = $this->db->prepare(self::SELECT_LIST . "
             WHERE d.status = 'active'
               AND d.expiry_date IS NOT NULL
               AND d.expiry_date < CURDATE()
             ORDER BY d.expiry_date ASC
             LIMIT :l");
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Headline numbers for the dashboard warning card. */
    public function expiryCounts(array $visibilityScope = []): array
    {
        $warn = documentExpiryWarningDays();

        // Scoped like every other read, so the counts describe the library the
        // reader can actually open. The values are intersected against the
        // known set before being inlined — nothing user-supplied reaches the
        // SQL even though this branch uses no placeholder.
        $scope = array_values(array_intersect(
            $visibilityScope ?: ['private', 'staff', 'public'],
            ['private', 'staff', 'public']
        ));
        if (!$scope) {
            return ['expired' => 0, 'expiring' => 0, 'active' => 0, 'archived' => 0, 'total' => 0];
        }
        $scopeSql = "WHERE visibility IN ('" . implode("','", $scope) . "')";

        // One pass over the table answers all five figures — the banner at the
        // top of the page and the count on every state pill.
        $row = $this->db->query("
            SELECT
              SUM(status = 'active' AND expiry_date IS NOT NULL AND expiry_date < CURDATE()) AS expired,
              SUM(status = 'active' AND expiry_date IS NOT NULL AND expiry_date >= CURDATE()
                  AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$warn} DAY)) AS expiring,
              SUM(status = 'active' AND (expiry_date IS NULL
                  OR expiry_date > DATE_ADD(CURDATE(), INTERVAL {$warn} DAY))) AS active,
              SUM(status = 'archived') AS archived,
              COUNT(*) AS total
            FROM documents
            {$scopeSql}
        ")->fetch() ?: [];

        return [
            'expired'  => (int) ($row['expired'] ?? 0),
            'expiring' => (int) ($row['expiring'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
            'total'    => (int) ($row['total'] ?? 0),
        ];
    }
}
