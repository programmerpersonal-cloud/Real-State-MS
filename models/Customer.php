<?php
/**
 * Saxane Real Estate Management System
 * Customer Model
 */

class Customer
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * Customer columns plus the account that backs them, if any.
     *
     * Every customer read goes through this so the Customers module and the
     * Users & Roles module can never describe the same person differently: the
     * account fields come from the linked users row, not from a second copy.
     *
     * `cb` is the staff member who entered the record; `u` is the customer's
     * own login. They are different people and different joins — conflating
     * them is what made the two lists disagree.
     */
    private const WITH_ACCOUNT = "
        SELECT c.*,
               u.id            AS account_id,
               u.email         AS account_email,
               u.username      AS account_username,
               u.is_active     AS account_active,
               u.last_login_at AS account_last_login,
               r.name          AS account_role,
               r.display_name  AS account_role_display,
               cb.full_name    AS created_by_name
        FROM customers c
        LEFT JOIN users u  ON c.user_id    = u.id
        LEFT JOIN roles r  ON u.role_id    = r.id
        LEFT JOIN users cb ON c.created_by = cb.id
    ";

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(self::WITH_ACCOUNT . " WHERE c.id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** The customer profile behind a signed-in user, if that user has one. */
    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare(self::WITH_ACCOUNT . " WHERE c.user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * An existing customer with this email — used to stop a second profile
     * being created for someone the system already knows.
     */
    public function findByEmail(string $email, ?int $excludeId = null): ?array
    {
        if (trim($email) === '') return null;
        $sql = "SELECT * FROM customers WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email))";
        $params = [':email' => $email];
        if ($excludeId) { $sql .= " AND id != :id"; $params[':id'] = $excludeId; }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /** As findByEmail(), for the phone number — the other strong identifier. */
    public function findByPhone(string $phone, ?int $excludeId = null): ?array
    {
        if (trim($phone) === '') return null;
        $sql = "SELECT * FROM customers WHERE REPLACE(REPLACE(TRIM(phone),' ',''),'-','') = REPLACE(REPLACE(TRIM(:phone),' ',''),'-','')";
        $params = [':phone' => $phone];
        if ($excludeId) { $sql .= " AND id != :id"; $params[':id'] = $excludeId; }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Point a customer at its account. Runs inside the caller's transaction and
     * reports failure rather than swallowing it, so a half-made customer/user
     * pair can be rolled back.
     */
    public function linkUser(int $customerId, ?int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE customers SET user_id = :uid WHERE id = :id");
        return $stmt->execute([':uid' => $userId, ':id' => $customerId]);
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO customers (user_id, full_name, email, phone, address, gender, national_id, profile_photo,
                    emergency_contact, emergency_phone, employment_status, occupation, guarantor_name, guarantor_contact,
                    customer_type, notes, risk_level, created_by, branch_id)
                VALUES (:uid,:name,:email,:phone,:addr,:gender,:nid,:photo,:ec,:ep,:emp,:occ,:gn,:gc,:ctype,:notes,:risk,:cb,:bid)
            ");
            $stmt->execute([
                ':uid' => $d['user_id'] ?? null, ':name' => $d['full_name'], ':email' => $d['email'] ?? '',
                ':phone' => $d['phone'], ':addr' => $d['address'] ?? '', ':gender' => $d['gender'] ?? null,
                ':nid' => $d['national_id'] ?? '', ':photo' => $d['profile_photo'] ?? null,
                ':ec' => $d['emergency_contact'] ?? '', ':ep' => $d['emergency_phone'] ?? '',
                ':emp' => $d['employment_status'] ?? '', ':occ' => $d['occupation'] ?? '',
                ':gn' => $d['guarantor_name'] ?? '', ':gc' => $d['guarantor_contact'] ?? '',
                ':ctype' => $d['customer_type'] ?? 'both', ':notes' => $d['notes'] ?? '',
                ':risk' => $d['risk_level'] ?? 'low', ':cb' => $_SESSION['user_id'] ?? null,
                ':bid' => $d['branch_id'] ?? null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Customer create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $data): bool
    {
        $fields = []; $params = [':id' => $id];
        $allowed = ['full_name','email','phone','address','gender','national_id','profile_photo',
            'emergency_contact','emergency_phone','employment_status','occupation','guarantor_name',
            'guarantor_contact','customer_type','notes','risk_level','is_blacklisted','branch_id'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        }
        if (empty($fields)) return false;
        return $this->db->prepare("UPDATE customers SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$wc, $params] = $this->buildFilters($filters);
        $stmt = $this->db->prepare(self::WITH_ACCOUNT . " {$wc} ORDER BY c.created_at DESC LIMIT :l OFFSET :o");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        // Same predicate and the same joins as getAll(), so the heading count
        // and the rows below it can never disagree.
        [$wc, $params] = $this->buildFilters($filters);
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM customers c
            LEFT JOIN users u ON c.user_id = u.id
            {$wc}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The WHERE clause shared by getAll() and count().
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = []; $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(c.full_name LIKE :s OR c.phone LIKE :s OR c.email LIKE :s OR c.national_id LIKE :s)";
            $params[':s'] = '%'.$filters['search'].'%';
        }
        if (!empty($filters['customer_type'])) {
            $where[] = "c.customer_type = :ct";
            $params[':ct'] = $filters['customer_type'];
        }
        if (isset($filters['is_blacklisted'])) {
            $where[] = "c.is_blacklisted = :bl";
            $params[':bl'] = $filters['is_blacklisted'];
        }
        // Login-access filter, so the list can answer "who can actually sign in".
        if (($filters['login'] ?? '') === 'enabled') {
            $where[] = "c.user_id IS NOT NULL AND u.is_active = 1";
        } elseif (($filters['login'] ?? '') === 'disabled') {
            $where[] = "(c.user_id IS NULL OR u.is_active = 0)";
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public function getRentalHistory(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT l.*, p.title AS property_title, p.property_code
            FROM leases l
            JOIN properties p ON l.property_id = p.id
            WHERE l.customer_id = :cid
            ORDER BY l.start_date DESC
        ");
        $stmt->execute([':cid' => $customerId]);
        return $stmt->fetchAll();
    }

    public function getPaymentHistory(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT py.*, p.title AS property_title
            FROM payments py
            LEFT JOIN properties p ON py.property_id = p.id
            WHERE py.customer_id = :cid
            ORDER BY py.created_at DESC
        ");
        $stmt->execute([':cid' => $customerId]);
        return $stmt->fetchAll();
    }
}
