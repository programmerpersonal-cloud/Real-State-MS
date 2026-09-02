<?php
/**
 * Backup — the record layer.
 *
 * Reads only. Every write to `backups` goes through BackupManager, because a
 * row in this table is a claim about a file on disk and the two must never be
 * changed independently: a model method that could set `status = 'verified'`
 * without opening the archive would undo the guarantee the whole module
 * exists to provide.
 *
 * The one exception is setProtection(), which changes a retention preference
 * and touches no file.
 */
class Backup
{
    /**
     * Sortable orders, keyed by the token a request may ask for.
     *
     * Never interpolated from the request: the controller resolves a key out
     * of this array with uiSortValue(), so an unknown sort becomes the default
     * rather than a fragment of SQL.
     */
    public const SORTS = [
        'newest'    => 'b.created_at DESC, b.id DESC',
        'oldest'    => 'b.created_at ASC, b.id ASC',
        'largest'   => 'b.file_size DESC, b.created_at DESC',
        'smallest'  => 'b.file_size ASC, b.created_at DESC',
        'name_asc'  => 'b.name ASC, b.created_at DESC',
        'name_desc' => 'b.name DESC, b.created_at DESC',
    ];

    /** The tabs on the dashboard. Doubles as the allow-list for ?type=. */
    public const TABS = [
        ''         => 'All Backups',
        'database' => 'Database Only',
        'files'    => 'Files Only',
        'full'     => 'Full Backups',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * One page of the register.
     *
     * Deleted rows are excluded by default. They are kept so the audit trail
     * has something to point at, but they have no file and no action, and a
     * list full of tombstones is harder to read during the incident it exists
     * for.
     *
     * @param array{type?: string, status?: string, sort?: string, include_deleted?: bool} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getAll(array $filters = [], int $limit = 15, int $offset = 0): array
    {
        [$where, $params] = $this->conditions($filters);
        $orderBy = self::SORTS[$filters['sort'] ?? 'newest'] ?? self::SORTS['newest'];

        $stmt = $this->db->prepare("
            SELECT b.*, u.full_name AS created_by_name, u.avatar AS created_by_avatar
              FROM backups b
              LEFT JOIN users u ON b.created_by = u.id
            {$where}
             ORDER BY {$orderBy}
             LIMIT :l OFFSET :o
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** How many rows the same filters match. */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->conditions($filters);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM backups b {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Counts for the tab strip, in one query.
     *
     * Asking four times — once per tab — is four round trips for a number
     * each, on a page that already draws six statistics.
     *
     * @return array<string, int>
     */
    public function countsByType(): array
    {
        $counts = ['' => 0];
        foreach (array_keys(backupTypes()) as $t) {
            $counts[$t] = 0;
        }

        foreach ($this->db->query("
            SELECT type, COUNT(*) AS n
              FROM backups
             WHERE status <> 'deleted'
             GROUP BY type
        ")->fetchAll() as $r) {
            $counts[$r['type']] = (int) $r['n'];
            $counts['']        += (int) $r['n'];
        }

        return $counts;
    }

    /**
     * Resolve the public identifier a request carries.
     *
     * The only way a controller turns a request parameter into a backup. The
     * UUID is matched exactly against an indexed unique column — there is no
     * pattern match, no id arithmetic, and nothing a caller can do with a
     * malformed value except fail to find a row.
     */
    public function findByPublicId(string $publicId): ?array
    {
        // A 36-character canonical UUID and nothing else. Cheap, and it keeps
        // obviously-forged handles from reaching the database at all.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $publicId)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT b.*, u.full_name AS created_by_name
              FROM backups b
              LEFT JOIN users u ON b.created_by = u.id
             WHERE b.public_id = :p
        ");
        $stmt->execute([':p' => $publicId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Backups a restore may actually be run from.
     *
     * Verified only. An unverified archive is not offered in the restore
     * dialog, because the dialog is used under pressure and anything listed
     * there reads as endorsed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function restorable(int $limit = 25): array
    {
        $stmt = $this->db->prepare("
            SELECT id, public_id, name, type, file_size, completed_at, verified_at
              FROM backups
             WHERE status = 'verified' AND verification_status = 'passed'
             ORDER BY completed_at DESC
             LIMIT :l
        ");
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Anything currently in flight — used to decide whether the page should poll. */
    public function running(): array
    {
        return $this->db->query("
            SELECT public_id, name, type, started_at
              FROM backups
             WHERE status IN ('pending','running')
             ORDER BY started_at DESC
        ")->fetchAll();
    }

    /** The most recent restore, for the activity panel and the health copy. */
    public function lastRestore(): ?array
    {
        return $this->db->query("
            SELECT r.*, b.name AS backup_name, u.full_name AS performed_by_name
              FROM backup_restores r
              LEFT JOIN backups b ON r.backup_id = b.id
              LEFT JOIN users u ON r.performed_by = u.id
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT 1
        ")->fetch() ?: null;
    }

    /**
     * Hold a backup back from the retention sweep, or release it.
     *
     * Setting protection clears the expiry outright rather than pushing it
     * further out; releasing it re-derives one from the row's own retention
     * class, so a backup that spends a month protected does not come back
     * with a stale date already in the past.
     */
    public function setProtection(int $id, bool $protected): bool
    {
        $stmt = $this->db->prepare("SELECT retention_class FROM backups WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $class = (string) ($stmt->fetchColumn() ?: 'manual');

        return $this->db->prepare("
            UPDATE backups SET is_protected = :p, expires_at = :e WHERE id = :id
        ")->execute([
            ':p'  => $protected ? 1 : 0,
            ':e'  => backupExpiryFor($class, $protected),
            ':id' => $id,
        ]);
    }

    /**
     * WHERE fragment and bound parameters for the filters above.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(array $filters): array
    {
        $where  = [];
        $params = [];

        if (empty($filters['include_deleted'])) {
            $where[] = "b.status <> 'deleted'";
        }

        // Checked against the known set rather than bound blindly: an unknown
        // type should show everything, not nothing, because an empty table
        // during an incident reads as "the backups are gone".
        $type = (string) ($filters['type'] ?? '');
        if ($type !== '' && array_key_exists($type, backupTypes())) {
            $where[] = 'b.type = :type';
            $params[':type'] = $type;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && in_array($status, ['pending', 'running', 'completed', 'verified', 'failed'], true)) {
            $where[] = 'b.status = :status';
            $params[':status'] = $status;
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
