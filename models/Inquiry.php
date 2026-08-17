<?php
/**
 * Inquiry Model
 *
 * Every read is cut to the caller by inquiryViewScope() (property_access.php).
 * The scope is applied here, in the SQL, rather than by filtering rows the
 * query already returned: the pagination and the totals then describe the same
 * set the user is allowed to see, so an owner's list does not report "48
 * inquiries" and show four.
 */
class Inquiry
{
    /**
     * Sortable columns, keyed by the token a request may ask for. Newest first
     * by default — an inbox is read from the top. Anything unrecognised
     * resolves to that default rather than reaching the ORDER BY as text.
     */
    public const SORTS = [
        'newest'      => 'i.created_at DESC',
        'oldest'      => 'i.created_at ASC',
        'name_asc'    => 'i.name ASC, i.created_at DESC',
        'name_desc'   => 'i.name DESC, i.created_at DESC',
        'subject_asc' => 'i.subject ASC, i.created_at DESC',
        'subject_desc'=> 'i.subject DESC, i.created_at DESC',
        'status_asc'  => 'i.status ASC, i.created_at DESC',
        'status_desc' => 'i.status DESC, i.created_at DESC',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        // owner_id/agent_id come back on the row so canViewInquiry() can judge
        // it without a second query.
        $stmt = $this->db->prepare("
            SELECT i.*, p.title AS property_title, c.full_name AS customer_name,
                   u.full_name AS assigned_name,
                   p.owner_id AS property_owner_id, p.agent_id AS property_agent_id
            FROM inquiries i
            LEFT JOIN properties p ON i.property_id = p.id
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u ON i.assigned_to = u.id
            WHERE i.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO inquiries (property_id, customer_id, name, email, phone, subject, message, status)
                VALUES (:pid, :cid, :name, :em, :ph, :sub, :msg, 'open')
            ");
            $stmt->execute([
                ':pid' => $d['property_id'] ?? null,
                ':cid' => $d['customer_id'] ?? null,
                ':name'=> $d['name'] ?? '',
                ':em'  => $d['email'] ?? '',
                ':ph'  => $d['phone'] ?? '',
                ':sub' => $d['subject'] ?? '',
                ':msg' => $d['message'],
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Inquiry create error: ' . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $d): bool
    {
        $allowed = ['status','assigned_to','subject','message'];
        $fields = []; $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (!$fields) return false;
        return $this->db->prepare("UPDATE inquiries SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'];

        $stmt = $this->db->prepare("
            SELECT i.*, p.title AS property_title, p.property_code,
                   c.full_name AS customer_name, c.profile_photo AS customer_photo,
                   u.full_name AS assigned_name
            FROM inquiries i
            LEFT JOIN properties p ON i.property_id = p.id
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u ON i.assigned_to = u.id
            WHERE {$where}
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
        [$where, $params] = $this->buildWhere($filters);

        // Same joins as getAll(), because the access scope reaches through the
        // properties table — counting without them would count rows the list
        // will not show.
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM inquiries i
            LEFT JOIN properties p ON i.property_id = p.id
            WHERE {$where}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The WHERE shared by getAll() and count(): the caller's filters ANDed
     * with the access scope.
     *
     * Built in one place so the two can never disagree — the bug that makes a
     * list say one thing and its pagination another.
     *
     * @return array{0:string,1:array<string,mixed>} [predicate, bound params]
     */
    private function buildWhere(array $filters): array
    {
        [$scope, $params] = inquiryViewScope('i', 'p');
        $where = [$scope];

        if (!empty($filters['status'])) {
            $where[] = "i.status = :st";
            $params[':st'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(i.name LIKE :s OR i.email LIKE :s OR i.subject LIKE :s OR i.message LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * How many enquiries sit in each status, for the filter pills.
     *
     * Scoped exactly like getAll() and count(), so an owner's pills describe
     * their own correspondence rather than publishing the size of the agency's
     * whole inbox. Carries every filter except status, so each pill reports
     * what clicking it would actually show.
     *
     * @return array<string, int>
     */
    public function countsByStatus(array $filters = []): array
    {
        unset($filters['status']);
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("
            SELECT i.status, COUNT(*) AS n
            FROM inquiries i
            LEFT JOIN properties p ON i.property_id = p.id
            WHERE {$where}
            GROUP BY i.status
        ");
        $stmt->execute($params);

        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'status'));
    }

    public function getMessages(int $inquiryId): array
    {
        $stmt = $this->db->prepare("
            SELECT m.*, u.full_name AS sender_name
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.inquiry_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$inquiryId]);
        return $stmt->fetchAll();
    }

    public function addMessage(int $inquiryId, int $senderId, int $receiverId, string $body): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO messages (sender_id, receiver_id, inquiry_id, subject, body)
            VALUES (?,?,?, 'Re: inquiry', ?)
        ");
        $stmt->execute([$senderId, $receiverId, $inquiryId, $body]);
        return (int) $this->db->lastInsertId();
    }
}
