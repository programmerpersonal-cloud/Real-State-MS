<?php
/**
 * Saxane Real Estate Management System
 * Owner Model
 */

class Owner
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * Owner columns plus the account that backs them, if any.
     *
     * Every owner read goes through this so the Owners module and the Users
     * module can never describe the same person differently: the account
     * fields come from the linked users row, not from a second copy.
     */
    private const WITH_ACCOUNT = "
        SELECT o.*,
               u.id            AS account_id,
               u.email         AS account_email,
               u.username      AS account_username,
               u.is_active     AS account_active,
               u.last_login_at AS account_last_login,
               r.name          AS account_role,
               r.display_name  AS account_role_display
        FROM owners o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
    ";

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(self::WITH_ACCOUNT . " WHERE o.id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** The owner profile behind a signed-in user, if that user has one. */
    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare(self::WITH_ACCOUNT . " WHERE o.user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * An existing owner with this email — used to stop a second profile being
     * created for someone the system already knows.
     */
    public function findByEmail(string $email, ?int $excludeId = null): ?array
    {
        if (trim($email) === '') return null;
        $sql = "SELECT * FROM owners WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email))";
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
        $sql = "SELECT * FROM owners WHERE REPLACE(REPLACE(TRIM(phone),' ',''),'-','') = REPLACE(REPLACE(TRIM(:phone),' ',''),'-','')";
        $params = [':phone' => $phone];
        if ($excludeId) { $sql .= " AND id != :id"; $params[':id'] = $excludeId; }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Point an owner at its account. Runs inside the caller's transaction and
     * reports failure rather than swallowing it, so a half-made owner/user
     * pair can be rolled back.
     */
    public function linkUser(int $ownerId, ?int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE owners SET user_id = :uid WHERE id = :id");
        return $stmt->execute([':uid' => $userId, ':id' => $ownerId]);
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO owners (user_id, full_name, phone, email, address, national_id, bank_name, bank_account, commission_rate, revenue_share, notes)
                VALUES (:uid,:name,:phone,:email,:addr,:nid,:bank,:acct,:comm,:rev,:notes)
            ");
            $stmt->execute([
                ':uid' => $d['user_id'] ?? null, ':name' => $d['full_name'], ':phone' => $d['phone'],
                ':email' => $d['email'] ?? '', ':addr' => $d['address'] ?? '', ':nid' => $d['national_id'] ?? '',
                ':bank' => $d['bank_name'] ?? '', ':acct' => $d['bank_account'] ?? '',
                ':comm' => $d['commission_rate'] ?? commissionRate(), ':rev' => $d['revenue_share'] ?? null,
                ':notes' => $d['notes'] ?? '',
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Owner create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $data): bool
    {
        $fields = []; $params = [':id' => $id];
        $allowed = ['full_name','phone','email','address','national_id','bank_name','bank_account','commission_rate','revenue_share','notes'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) { $fields[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        }
        if (empty($fields)) return false;
        return $this->db->prepare("UPDATE owners SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    /**
     * Every ordering this model accepts, and the only place a column name is
     * allowed to reach the ORDER BY below. The request supplies a key, never a
     * column: anything unrecognised falls to 'newest'.
     */
    public const SORTS = [
        'newest'     => 'o.created_at DESC',
        'oldest'     => 'o.created_at ASC',
        'name_asc'   => 'o.full_name ASC',
        'name_desc'  => 'o.full_name DESC',
        'comm_desc'  => 'o.commission_rate DESC, o.full_name ASC',
        'comm_asc'   => 'o.commission_rate ASC, o.full_name ASC',
    ];

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['search'])) {
            $where[] = "(o.full_name LIKE :s OR o.phone LIKE :s OR o.email LIKE :s)";
            $params[':s'] = '%'.$filters['search'].'%';
        }
        // Login-access filter, so the list can answer "who can actually sign in".
        if (($filters['login'] ?? '') === 'enabled') {
            $where[] = "o.user_id IS NOT NULL AND u.is_active = 1";
        } elseif (($filters['login'] ?? '') === 'disabled') {
            $where[] = "(o.user_id IS NULL OR u.is_active = 0)";
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'];
        $stmt = $this->db->prepare(self::WITH_ACCOUNT . " {$wc} ORDER BY {$orderBy} LIMIT :l OFFSET :o");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        // Mirrors getAll()'s filters, so the count and the page can never disagree.
        $where = []; $params = [];
        if (!empty($filters['search'])) {
            $where[] = "(o.full_name LIKE :s OR o.phone LIKE :s OR o.email LIKE :s)";
            $params[':s'] = '%'.$filters['search'].'%';
        }
        if (($filters['login'] ?? '') === 'enabled') {
            $where[] = "o.user_id IS NOT NULL AND u.is_active = 1";
        } elseif (($filters['login'] ?? '') === 'disabled') {
            $where[] = "(o.user_id IS NULL OR u.is_active = 0)";
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM owners o LEFT JOIN users u ON o.user_id = u.id {$wc}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function getProperties(int $ownerId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM properties WHERE owner_id = :oid AND is_archived = 0 ORDER BY created_at DESC");
        $stmt->execute([':oid' => $ownerId]);
        return $stmt->fetchAll();
    }

    public function getAllSimple(): array
    {
        return $this->db->query("SELECT id, full_name FROM owners ORDER BY full_name")->fetchAll();
    }
}
