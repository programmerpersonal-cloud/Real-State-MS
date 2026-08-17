<?php
/**
 * Terms Acceptance Model — the permanent record of who agreed to what.
 *
 * This class has no update() and no delete(), and that is the design. An
 * acceptance is evidence: once written it must read the same forever, however
 * many times the terms are later revised.
 *
 * Three things keep that true:
 *   * content_hash is copied from the version at acceptance time, so the row
 *     proves the exact wording even if the versions table is tampered with;
 *   * fk_ta_version is ON DELETE RESTRICT, so the version it points at cannot
 *     be removed — and the RESTRICT propagates up to the type as well;
 *   * TermsVersion::update() refuses anything past draft, so the wording a row
 *     points at is never rewritten underneath it.
 */
class TermsAcceptance
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    /**
     * Record an acceptance.
     *
     * The hash is read from the version row rather than taken from the caller,
     * so a caller cannot record agreement to wording that was never published.
     *
     * @param array $ctx user_id, customer_id, reference_type, reference_id,
     *                   accepted_name, method
     */
    public function record(int $versionId, array $ctx = []): int|false
    {
        try {
            $stmt = $this->db->prepare("SELECT content_hash FROM terms_versions WHERE id = :id");
            $stmt->execute([':id' => $versionId]);
            $hash = $stmt->fetchColumn();

            if ($hash === false) {
                error_log('TermsAcceptance: no such version ' . $versionId);
                return false;
            }

            $stmt = $this->db->prepare("
                INSERT INTO terms_acceptances
                    (terms_version_id, user_id, customer_id, reference_type, reference_id,
                     content_hash, acceptance_method, accepted_name, ip_address, user_agent)
                VALUES
                    (:vid, :uid, :cid, :rtype, :rid,
                     :hash, :method, :name, :ip, :ua)
            ");
            $stmt->execute([
                ':vid'    => $versionId,
                ':uid'    => $ctx['user_id']     ?: null,
                ':cid'    => $ctx['customer_id'] ?: null,
                ':rtype'  => $ctx['reference_type'] ?? '',
                ':rid'    => $ctx['reference_id']   ?: null,
                ':hash'   => $hash,
                ':method' => $ctx['method'] ?? 'checkbox',
                ':name'   => mb_substr((string) ($ctx['accepted_name'] ?? ''), 0, 120),
                ':ip'     => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                ':ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('TermsAcceptance record error: ' . $e->getMessage());
            return false;
        }
    }

    private const SELECT_LIST = "
        SELECT a.*,
               v.version_code, v.title AS version_title, v.status AS version_status,
               v.content_hash AS current_hash,
               t.slug, t.name AS terms_name,
               u.full_name AS user_name,
               c.full_name AS customer_name
          FROM terms_acceptances a
          JOIN terms_versions v  ON v.id = a.terms_version_id
          JOIN terms_documents t ON t.id = v.terms_document_id
          LEFT JOIN users u      ON u.id = a.user_id
          LEFT JOIN customers c  ON c.id = a.customer_id
    ";

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_LIST . " WHERE a.id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @return array{0:string,1:array} [$whereSql, $params]
     */
    private function buildFilters(array $f): array
    {
        $where  = [];
        $params = [];

        if (!empty($f['terms_version_id'])) {
            $where[] = "a.terms_version_id = :vid";
            $params[':vid'] = (int) $f['terms_version_id'];
        }
        if (!empty($f['terms_document_id'])) {
            $where[] = "v.terms_document_id = :tdid";
            $params[':tdid'] = (int) $f['terms_document_id'];
        }
        if (!empty($f['slug'])) {
            $where[] = "t.slug = :slug";
            $params[':slug'] = $f['slug'];
        }
        if (!empty($f['reference_type'])) {
            $where[] = "a.reference_type = :rtype";
            $params[':rtype'] = $f['reference_type'];
        }
        if (!empty($f['reference_id'])) {
            $where[] = "a.reference_id = :rid";
            $params[':rid'] = (int) $f['reference_id'];
        }
        if (!empty($f['search'])) {
            $where[] = "(u.full_name LIKE :s OR c.full_name LIKE :s OR a.accepted_name LIKE :s
                         OR v.version_code LIKE :s OR t.name LIKE :s)";
            $params[':s'] = '%' . $f['search'] . '%';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public function getAll(array $filters = [], int $limit = ITEMS_PER_PAGE, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);

        $stmt = $this->db->prepare(self::SELECT_LIST . " {$where} ORDER BY a.accepted_at DESC, a.id DESC LIMIT :l OFFSET :o");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
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
              FROM terms_acceptances a
              JOIN terms_versions v  ON v.id = a.terms_version_id
              JOIN terms_documents t ON t.id = v.terms_document_id
              LEFT JOIN users u      ON u.id = a.user_id
              LEFT JOIN customers c  ON c.id = a.customer_id
            {$where}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Everything accepted in the course of one reservation, lease or sale. */
    public function forReference(string $refType, int $refId): array
    {
        $stmt = $this->db->prepare(self::SELECT_LIST . "
             WHERE a.reference_type = :rtype AND a.reference_id = :rid
             ORDER BY a.accepted_at DESC");
        $stmt->execute([':rtype' => $refType, ':rid' => $refId]);
        return $stmt->fetchAll();
    }

    public function countForVersion(int $versionId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM terms_acceptances WHERE terms_version_id = :id");
        $stmt->execute([':id' => $versionId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Does the recorded hash still match the version it points at?
     *
     * A mismatch means the stored wording changed after somebody accepted it —
     * which the application cannot do, so it would mean direct database
     * interference. Surfacing it is the whole point of keeping the copy.
     */
    public function verify(int $acceptanceId): array
    {
        $row = $this->findById($acceptanceId);
        if (!$row) {
            return ['found' => false, 'matches' => false];
        }

        return [
            'found'   => true,
            'matches' => hash_equals((string) $row['current_hash'], (string) $row['content_hash']),
            'stored'  => $row['content_hash'],
            'current' => $row['current_hash'],
        ];
    }
}
