<?php
/**
 * Saxane Real Estate Management System
 * User Model
 */

class User
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * Find a user by ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name AS role_name, r.display_name AS role_display
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find a user by email.
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name AS role_name, r.display_name AS role_display
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = :email
        ");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find a user by username.
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name AS role_name, r.display_name AS role_display
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.username = :username
        ");
        $stmt->execute([':username' => $username]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Authenticate user with email/username and password.
     */
    public function authenticate(string $login, string $password): array
    {
        // Try email first, then username
        $user = $this->findByEmail($login) ?? $this->findByUsername($login);

        if (!$user) {
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        if (!$user['is_active']) {
            return ['success' => false, 'error' => 'Your account has been deactivated.'];
        }

        // Check if account is locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'error' => "Account locked. Try again in {$mins} minute(s)."];
        }

        if (!password_verify($password, $user['password'])) {
            $this->incrementLoginAttempts($user['id']);
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Successful login — reset attempts, update last login
        $this->resetLoginAttempts($user['id']);
        $this->updateLastLogin($user['id']);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Create a new user.
     */
    public function create(array $data): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO users (full_name, email, phone, username, password, role_id, branch_id, avatar)
                VALUES (:full_name, :email, :phone, :username, :password, :role_id, :branch_id, :avatar)
            ");
            $stmt->execute([
                ':full_name'  => $data['full_name'],
                ':email'      => $data['email'],
                ':phone'      => $data['phone'] ?? null,
                ':username'   => $data['username'],
                ':password'   => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
                ':role_id'    => $data['role_id'],
                ':branch_id'  => $data['branch_id'] ?? null,
                ':avatar'     => $data['avatar'] ?? null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('User creation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update user profile.
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        foreach (['full_name', 'email', 'phone', 'username', 'avatar', 'branch_id', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) return false;

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Change password.
     */
    public function changePassword(int $id, string $newPassword): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET password = :password WHERE id = :id");
        return $stmt->execute([
            ':password' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
            ':id'       => $id,
        ]);
    }

    /**
     * The id of a role, by its name ('owner', 'agent', …).
     *
     * Roles are seeded rows rather than constants, so the id is looked up
     * instead of hard-coded — an install whose roles were inserted in a
     * different order still assigns the right one.
     */
    public function roleIdByName(string $name): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM roles WHERE name = :name");
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * A username that is free, derived from the email's local part.
     *
     * The table requires a unique username even though owners sign in with
     * their email, so one is generated rather than asked for.
     */
    public function suggestUsername(string $email, string $fallback = 'user'): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9._-]/i', '', explode('@', trim($email))[0] ?? ''));
        if ($base === '') {
            $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $fallback)) ?: 'user';
        }
        $base = substr($base, 0, 40);

        $candidate = $base;
        for ($n = 2; $this->usernameExists($candidate); $n++) {
            $candidate = $base . $n;
            if ($n > 999) { $candidate = $base . bin2hex(random_bytes(3)); break; }
        }
        return $candidate;
    }

    /**
     * Move an account to another role. Kept here rather than in update() so a
     * role change is always a deliberate call.
     */
    public function setRole(int $id, int $roleId): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET role_id = :role WHERE id = :id");
        return $stmt->execute([':role' => $roleId, ':id' => $id]);
    }

    /**
     * Check if email is already taken (optionally excluding a user ID).
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM users WHERE email = :email";
        $params = [':email' => $email];
        if ($excludeId) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Check if username is already taken.
     */
    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM users WHERE username = :username";
        $params = [':username' => $username];
        if ($excludeId) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get all users with optional filters.
     */
    /**
     * Sortable columns, keyed by the token a request may ask for.
     *
     * `signin_desc` puts accounts that have never signed in last rather than
     * first: a NULL last_login_at is not "longest ago", it is "not yet", and
     * sorting by most recent activity should not open on it.
     */
    public const SORTS = [
        'newest'      => 'u.created_at DESC',
        'oldest'      => 'u.created_at ASC',
        'name_asc'    => 'u.full_name ASC',
        'name_desc'   => 'u.full_name DESC',
        'role_asc'    => 'r.id ASC, u.full_name ASC',
        'role_desc'   => 'r.id DESC, u.full_name ASC',
        'signin_desc' => 'u.last_login_at IS NULL, u.last_login_at DESC',
        'signin_asc'  => 'u.last_login_at IS NULL, u.last_login_at ASC',
    ];

    /**
     * The WHERE clause and its bound parameters for one filter set.
     *
     * Shared by getAll() and count(), which had drifted: count() knew nothing
     * about the search, so searching reported the unfiltered total and offered
     * pages that rendered empty.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['role_id'])) {
            $where[] = "u.role_id = :role_id";
            $params[':role_id'] = (int) $filters['role_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = "u.branch_id = :branch_id";
            $params[':branch_id'] = (int) $filters['branch_id'];
        }
        if (isset($filters['is_active'])) {
            $where[] = "u.is_active = :is_active";
            $params[':is_active'] = (int) $filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(u.full_name LIKE :search OR u.email LIKE :search OR u.username LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$whereClause, $params] = $this->buildWhere($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'];

        // The owner and customer profiles are joined in rather than looked up
        // separately, so this page and the Owners/Customers pages describe one
        // person from one record. An account has at most one of each — both
        // links are UNIQUE on user_id — so neither join can multiply the rows.
        $stmt = $this->db->prepare("
            SELECT u.*, r.name AS role_name, r.display_name AS role_display,
                   b.name          AS branch_name,
                   o.id            AS owner_profile_id,
                   o.full_name     AS owner_profile_name,
                   o.phone         AS owner_profile_phone,
                   c.id            AS customer_profile_id,
                   c.full_name     AS customer_profile_name,
                   c.phone         AS customer_profile_phone,
                   c.customer_type AS customer_profile_type
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN branches b  ON b.id = u.branch_id
            LEFT JOIN owners o    ON o.user_id = u.id
            LEFT JOIN customers c ON c.user_id = u.id
            {$whereClause}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count total users with filters.
     */
    public function count(array $filters = []): int
    {
        [$whereClause, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM users u
            JOIN roles r ON u.role_id = r.id
            {$whereClause}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * How many accounts sit under each role, for the filter pills.
     *
     * @return array<int, int> role id => account count
     */
    public function countsByRole(): array
    {
        $rows = $this->db->query("SELECT role_id, COUNT(*) AS n FROM users GROUP BY role_id")->fetchAll();

        return array_map('intval', array_column($rows, 'n', 'role_id'));
    }

    /**
     * How many *other* accounts hold a role and can still sign in.
     *
     * The guard against locking the company out of its own installation: an
     * administrator disabling the last administrator, or demoting themselves
     * out of the only account that can restore anything, leaves nobody able to
     * put it back. Asked before either change is written.
     */
    public function otherActiveWithRole(string $roleName, int $excludeUserId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM users u JOIN roles r ON u.role_id = r.id
            WHERE r.name = ? AND u.is_active = 1 AND u.id <> ?
        ");
        $stmt->execute([$roleName, $excludeUserId]);

        return (int) $stmt->fetchColumn();
    }

    // ─── Private Helpers ────────────────────────────────────

    private function incrementLoginAttempts(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE users SET 
                login_attempts = login_attempts + 1,
                locked_until = IF(login_attempts + 1 >= :max, DATE_ADD(NOW(), INTERVAL :lockout SECOND), locked_until)
            WHERE id = :id
        ");
        $stmt->execute([
            ':max'     => MAX_LOGIN_ATTEMPTS,
            ':lockout' => LOCKOUT_DURATION,
            ':id'      => $id,
        ]);
    }

    private function resetLoginAttempts(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    private function updateLastLogin(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}
