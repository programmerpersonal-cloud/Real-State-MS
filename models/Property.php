<?php
/**
 * Saxane Real Estate Management System
 * Property Model
 */

class Property
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, o.full_name AS owner_name,
                   u.full_name AS agent_name, u.avatar AS agent_avatar,
                   u.email AS agent_email, u.phone AS agent_phone,
                   b.name AS branch_name,
                   ap.full_name AS approved_by_name,
                   ab.full_name AS archived_by_name,
                   sb.full_name AS submitted_by_name
            FROM properties p
            LEFT JOIN owners o ON p.owner_id = o.id
            LEFT JOIN users u ON p.agent_id = u.id
            LEFT JOIN branches b ON p.branch_id = b.id
            LEFT JOIN users ap ON p.approved_by = ap.id
            LEFT JOIN users ab ON p.archived_by = ab.id
            LEFT JOIN users sb ON p.created_by  = sb.id
            WHERE p.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO properties (property_code, title, property_type, category, description, location, address,
                    latitude, longitude,
                    size_sqm, num_rooms, num_bathrooms, num_floors, price, rent_amount, deposit_amount,
                    is_furnished, has_parking, has_security, utilities_included, status,
                    approval_status, approved_by, approved_at,
                    owner_id, agent_id, branch_id, created_by)
                VALUES (:code, :title, :type, :cat, :desc, :loc, :addr,
                    :lat, :lng,
                    :size, :rooms, :baths, :floors, :price, :rent, :deposit,
                    :furnished, :parking, :security, :utilities, :status,
                    :approval, :approved_by, :approved_at,
                    :owner_id, :agent_id, :branch_id, :created_by)
            ");
            $stmt->execute([
                ':code'      => $data['property_code'] ?? generateCode('PRP'),
                ':title'     => $data['title'],
                ':type'      => $data['property_type'] ?? 'rent',
                ':cat'       => $data['category'] ?? 'apartment',
                ':desc'      => $data['description'] ?? '',
                ':loc'       => $data['location'] ?? '',
                ':addr'      => $data['address'] ?? '',
                ':lat'       => $data['latitude'] ?? null,
                ':lng'       => $data['longitude'] ?? null,
                ':size'      => $data['size_sqm'] ?? null,
                ':rooms'     => $data['num_rooms'] ?? 0,
                ':baths'     => $data['num_bathrooms'] ?? 0,
                ':floors'    => $data['num_floors'] ?? 1,
                ':price'     => $data['price'] ?? null,
                ':rent'      => $data['rent_amount'] ?? null,
                ':deposit'   => $data['deposit_amount'] ?? null,
                ':furnished' => $data['is_furnished'] ?? 0,
                ':parking'   => $data['has_parking'] ?? 0,
                ':security'  => $data['has_security'] ?? 0,
                ':utilities' => $data['utilities_included'] ?? '',
                ':status'    => $data['status'] ?? 'available',
                // The caller decides the approval state, because the answer
                // depends on who is signed in — see PropertyController::create().
                ':approval'  => $data['approval_status'] ?? 'pending',
                ':approved_by' => $data['approved_by'] ?? null,
                ':approved_at' => $data['approved_at'] ?? null,
                ':owner_id'  => $data['owner_id'] ?: null,
                ':agent_id'  => $data['agent_id'] ?: null,
                ':branch_id' => $data['branch_id'] ?: null,
                ':created_by'=> $data['created_by'] ?? null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Property create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['title','property_type','category','description','location','address',
            'latitude','longitude','size_sqm','num_rooms','num_bathrooms','num_floors','price','rent_amount','deposit_amount',
            'is_furnished','has_parking','has_security','utilities_included','status','approval_status',
            'owner_id','agent_id','branch_id','is_archived',
            // Workflow columns. Never populated from request input — approve(),
            // reject(), archive() and restore() set them from the session and
            // the clock, so a hand-crafted POST cannot forge an approver.
            'approved_by','approved_at','approval_note',
            'archived_at','archived_by','status_before_archive','created_by'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        if (empty($fields)) return false;

        $sql = "UPDATE properties SET " . implode(', ', $fields) . " WHERE id = :id";
        return $this->db->prepare($sql)->execute($params);
    }

    /**
     * Archive a property, remembering the way back.
     *
     * The previous version wrote `is_archived = 1, status = 'inactive'` and
     * threw the old status away, which made archiving a one-way door: nothing
     * left on the row said whether the property had been available, rented or
     * reserved, so no restore could put it back honestly. The status it held
     * on the way in is kept in status_before_archive, and the who/when in
     * archived_by/archived_at.
     *
     * Approval is deliberately untouched. Filing a property away is not a
     * review of it, and a restore must not smuggle an approval through.
     *
     * Already-archived rows are refused rather than re-archived — a double
     * submit would otherwise overwrite the remembered status with 'inactive'
     * and lose it for good.
     */
    public function archive(int $id, ?int $userId = null): bool
    {
        $stmt = $this->db->prepare("SELECT status, is_archived FROM properties WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['is_archived'] === 1) {
            return false;
        }

        return $this->update($id, [
            'is_archived'           => 1,
            'status_before_archive' => $row['status'],
            'status'                => 'inactive',
            'archived_at'           => date('Y-m-d H:i:s'),
            'archived_by'           => $userId ?: ($_SESSION['user_id'] ?? null),
        ]);
    }

    /**
     * Return an archived property to the register in the state it left.
     *
     * Falls back to 'available' only when there is nothing remembered — rows
     * archived before this workflow existed, which the migration could not
     * invent a previous status for.
     *
     * Nothing is deleted and nothing is re-created: images, owner, agent,
     * documents, leases and history all hang off the same row and are
     * untouched by the flag flipping back.
     */
    public function restore(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT status_before_archive, is_archived FROM properties WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['is_archived'] === 0) {
            return false;
        }

        return $this->update($id, [
            'is_archived'           => 0,
            'status'                => $row['status_before_archive'] ?: 'available',
            'archived_at'           => null,
            'archived_by'           => null,
            'status_before_archive' => null,
        ]);
    }

    /** Kept as the soft-delete entry point the rest of the app already calls. */
    public function delete(int $id): bool
    {
        return $this->archive($id);
    }

    /**
     * Build the shared WHERE clause for getAll()/count().
     *
     * Kept in one place so the listing query and its result count can
     * never drift apart — previously count() ignored the price filters,
     * which made pagination report the wrong number of pages.
     *
     * @return array{0:string,1:array<string,mixed>} [whereClause, params]
     */
    private function buildFilters(array $filters): array
    {
        $where  = [];
        $params = [];

        /* Archive state.
         *
         * `p.is_archived = 0` used to be hard-coded here, which is why there
         * was no way to list an archived property from anywhere in the app —
         * the only query that could reach one was findById(). It is now a
         * filter with the same default, so every existing caller behaves
         * exactly as before and the archive page opts in:
         *
         *   (absent) / 0  active only        the register, the public site
         *   1             archived only      the archive page
         *   'any'         both               nothing needs this yet, but a
         *                                    report might, and the alternative
         *                                    is a second copy of this method.
         *
         * The two are mutually exclusive by construction, so a property can
         * never appear in the active list and the archive at the same time.
         */
        $archived = $filters['archived'] ?? 0;
        if ($archived !== 'any') {
            $where[] = 'p.is_archived = ' . ((int) $archived === 1 ? '1' : '0');
        }

        // Approval state. `approved_only` is the public site's gate: a listing
        // an administrator has not approved is not a listing the world sees.
        // `approval_status` is the staff queue's filter.
        if (!empty($filters['approved_only'])) {
            $where[] = "p.approval_status = 'approved'";
        } elseif (!empty($filters['approval_status'])) {
            $where[] = 'p.approval_status = :appr';
            $params[':appr'] = $filters['approval_status'];
        }

        // Opt-in record scoping, for the management register only.
        //
        // This model also serves the public site — the listings grid, the home
        // page, an agent's public profile — where the visitor has no session
        // and every approved listing is meant to be visible. Applying the
        // scope unconditionally would empty those pages, so the staff list
        // asks for it by passing `scoped` and a public route simply does not.
        if (!empty($filters['scoped'])) {
            [$scope, $scopeParams] = propertyRecordScope('p');
            $where[] = '(' . $scope . ')';
            $params += $scopeParams;
        }

        if (!empty($filters['search'])) {
            $where[] = "(p.title LIKE :s OR p.property_code LIKE :s OR p.location LIKE :s OR p.address LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['property_type'])) {
            $where[] = "p.property_type = :ptype";
            $params[':ptype'] = $filters['property_type'];
        }
        if (!empty($filters['category'])) {
            $where[] = "p.category = :cat";
            $params[':cat'] = $filters['category'];
        }
        if (!empty($filters['status'])) {
            $where[] = "p.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['agent_id'])) {
            $where[] = "p.agent_id = :aid";
            $params[':aid'] = $filters['agent_id'];
        }
        if (!empty($filters['owner_id'])) {
            $where[] = "p.owner_id = :oid";
            $params[':oid'] = $filters['owner_id'];
        }
        // Price filters compare against whichever column applies to the
        // listing type, so a rental is matched on rent_amount and a sale
        // on price rather than both being tested blindly.
        if (!empty($filters['min_price'])) {
            $where[] = "(COALESCE(NULLIF(p.rent_amount,0), p.price, 0) >= :minp)";
            $params[':minp'] = $filters['min_price'];
        }
        if (!empty($filters['max_price'])) {
            $where[] = "(COALESCE(NULLIF(p.rent_amount,0), p.price, 0) <= :maxp)";
            $params[':maxp'] = $filters['max_price'];
        }
        if (!empty($filters['min_rooms'])) {
            $where[] = "p.num_rooms >= :minr";
            $params[':minr'] = (int) $filters['min_rooms'];
        }
        if (!empty($filters['min_baths'])) {
            $where[] = "p.num_bathrooms >= :minb";
            $params[':minb'] = (int) $filters['min_baths'];
        }

        // 'archived' => 'any' with no other filter leaves nothing to say, and
        // a bare "WHERE" is a syntax error rather than an empty filter.
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * Whitelist of sort options. Anything unrecognised falls back to
     * newest-first, so the ORDER BY can never be driven by raw input.
     */
    /**
     * Every ordering this model will accept, and the only place a column name
     * is allowed to reach the ORDER BY below.
     *
     * The request supplies a *key*, never a column: an unrecognised key falls
     * to 'newest' rather than being interpolated, which is what keeps
     * `?sort=id;DROP…` a normal first page. The admin register's sortable
     * columns were added alongside the public site's price/rooms/size sorts,
     * so both read from one allow-list.
     */
    public const SORTS = [
        'newest'      => 'p.created_at DESC',
        'oldest'      => 'p.created_at ASC',
        'price_asc'   => 'COALESCE(NULLIF(p.rent_amount,0), p.price, 0) ASC',
        'price_desc'  => 'COALESCE(NULLIF(p.rent_amount,0), p.price, 0) DESC',
        'rooms_desc'  => 'p.num_rooms DESC, p.created_at DESC',
        'size_desc'   => 'p.size_sqm DESC, p.created_at DESC',
        // Admin register columns.
        'title_asc'   => 'p.title ASC',
        'title_desc'  => 'p.title DESC',
        'code_asc'    => 'p.property_code ASC',
        'code_desc'   => 'p.property_code DESC',
        'status_asc'  => 'p.status ASC, p.created_at DESC',
        'status_desc' => 'p.status DESC, p.created_at DESC',
        'updated_desc'=> 'p.updated_at DESC',
        'updated_asc' => 'p.updated_at ASC',
    ];

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$whereClause, $params] = $this->buildFilters($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'];

        /* The archive page and the approval queue name the people behind the
           workflow columns — who filed this away, who submitted it, who signed
           it off. Three more LEFT JOINs on a primary key is nothing, but the
           public listings grid has no use for them, so they are opt-in rather
           than paid for on every page that lists a property. */
        $workflow = !empty($filters['with_workflow'])
            ? ', ab.full_name AS archived_by_name, sb.full_name AS submitted_by_name,'
              . ' ap.full_name AS approved_by_name'
            : '';
        $workflowJoins = !empty($filters['with_workflow'])
            ? 'LEFT JOIN users ab ON p.archived_by = ab.id
               LEFT JOIN users sb ON p.created_by  = sb.id
               LEFT JOIN users ap ON p.approved_by = ap.id'
            : '';

        $stmt = $this->db->prepare("
            SELECT p.*, o.full_name AS owner_name, u.full_name AS agent_name{$workflow}
            FROM properties p
            LEFT JOIN owners o ON p.owner_id = o.id
            LEFT JOIN users u ON p.agent_id = u.id
            {$workflowJoins}
            {$whereClause}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        [$whereClause, $params] = $this->buildFilters($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM properties p {$whereClause}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * How many properties sit in each state of the register, in one query.
     *
     * The three tabs across the top of the Properties page — Active, Pending
     * approval, Archived — are three counts of the same table under the same
     * access scope, and asking for them separately would be three round trips
     * to draw one strip of pills. Conditional SUMs give all three in one pass.
     *
     * The scope is the same predicate the list itself uses, so an agent's
     * "Pending approval" count is their own listings, never the whole office's.
     *
     * @return array{active:int, pending:int, rejected:int, archived:int}
     */
    public function stateCounts(bool $scoped = true): array
    {
        $params = [];
        $scopeSql = '1 = 1';
        if ($scoped) {
            [$scopeSql, $params] = propertyRecordScope('p');
        }

        $stmt = $this->db->prepare("
            SELECT
                SUM(p.is_archived = 0)                                          AS active,
                SUM(p.is_archived = 0 AND p.approval_status = 'pending')         AS pending,
                SUM(p.is_archived = 0 AND p.approval_status = 'rejected')        AS rejected,
                SUM(p.is_archived = 1)                                           AS archived
            FROM properties p
            WHERE ({$scopeSql})
        ");
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        return [
            'active'   => (int) ($row['active']   ?? 0),
            'pending'  => (int) ($row['pending']  ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
        ];
    }

    /**
     * Cover image + photo count for many properties in one query.
     *
     * Listing grids previously called getImages() once per card, which
     * meant a 12-card page fired 13 queries. This collapses that to two.
     *
     * @param  int[] $ids
     * @return array<int,array{path:?string,total:int}> keyed by property id
     */
    public function getCoversFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];

        $in = implode(',', array_fill(0, count($ids), '?'));

        // Cover first, then lowest sort_order, then oldest upload — the
        // same precedence getImages() uses, so cards and the detail page
        // always agree on which photo leads.
        $stmt = $this->db->prepare("
            SELECT property_id, file_path, is_cover, sort_order, id
            FROM property_images
            WHERE property_id IN ({$in})
            ORDER BY property_id, is_cover DESC, sort_order ASC, id ASC
        ");
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $pid = (int) $row['property_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = ['path' => $row['file_path'], 'total' => 0];
            }
            $out[$pid]['total']++;
        }
        return $out;
    }

    /**
     * Count of available listings per category, for the "browse by type"
     * tiles. Returns [category => count] and omits empty categories.
     *
     * @return array<string,int>
     */
    public function countByCategory(string $status = 'available'): array
    {
        $sql = "SELECT category, COUNT(*) AS n FROM properties
                 WHERE is_archived = 0 AND approval_status = 'approved'";
        $params = [];
        if ($status !== '') {
            $sql .= " AND status = :st";
            $params[':st'] = $status;
        }
        $sql .= " GROUP BY category";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['category']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Other available listings that resemble this one — same category
     * first, then anything else available. Used by the detail page.
     */
    public function getSimilar(int $propertyId, string $category, int $limit = 3): array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, u.full_name AS agent_name
            FROM properties p
            LEFT JOIN users u ON p.agent_id = u.id
            WHERE p.is_archived = 0
              AND p.approval_status = 'approved'
              AND p.status = 'available'
              AND p.id <> :pid
            ORDER BY (p.category = :cat) DESC, p.created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':pid', $propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':cat', $category);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getImages(int $propertyId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM property_images WHERE property_id = :pid ORDER BY is_cover DESC, sort_order");
        $stmt->execute([':pid' => $propertyId]);
        return $stmt->fetchAll();
    }

    public function addImage(int $propertyId, string $filePath, bool $isCover = false): int|false
    {
        if ($isCover) {
            $this->db->prepare("UPDATE property_images SET is_cover = 0 WHERE property_id = ?")->execute([$propertyId]);
        }
        $stmt = $this->db->prepare("INSERT INTO property_images (property_id, file_path, is_cover) VALUES (?, ?, ?)");
        $stmt->execute([$propertyId, $filePath, $isCover ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteImage(int $imageId): bool
    {
        $stmt = $this->db->prepare("SELECT file_path FROM property_images WHERE id = ?");
        $stmt->execute([$imageId]);
        $img = $stmt->fetch();
        if ($img && file_exists(BASE_PATH . '/' . $img['file_path'])) {
            unlink(BASE_PATH . '/' . $img['file_path']);
        }
        return $this->db->prepare("DELETE FROM property_images WHERE id = ?")->execute([$imageId]);
    }

    public function getHistory(int $propertyId): array
    {
        $stmt = $this->db->prepare("
            SELECT ph.*, u.full_name AS changed_by_name
            FROM property_history ph
            LEFT JOIN users u ON ph.changed_by = u.id
            WHERE ph.property_id = :pid ORDER BY ph.created_at DESC
        ");
        $stmt->execute([':pid' => $propertyId]);
        return $stmt->fetchAll();
    }

    public function logChange(int $propertyId, string $action, string $field = '', string $old = '', string $new = ''): void
    {
        $stmt = $this->db->prepare("INSERT INTO property_history (property_id, action, field_changed, old_value, new_value, changed_by) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$propertyId, $action, $field, $old, $new, $_SESSION['user_id'] ?? null]);
    }
}
