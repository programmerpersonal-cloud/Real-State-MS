<?php
/**
 * Terms Document Model — the configurable legal *types*.
 *
 * A type is a container ("Rental Terms"); the wording lives in terms_versions.
 * The slug is the contract between the database and the code — 'booking' is
 * what the reservation form asks for and 'general' is what the public terms
 * page renders — so update() deliberately does not expose it.
 */
class TermsDocument
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM terms_documents WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM terms_documents WHERE slug = :slug");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Every type with the state an administrator needs at a glance: which
     * version is live, when it took effect, how many versions exist and how
     * many acceptances have been recorded against the live one.
     */
    public function getAll(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE t.is_active = 1' : '';
        return $this->db->query("
            SELECT t.*,
                   v.id             AS active_version_id,
                   v.version_code   AS active_version_code,
                   v.title          AS active_version_title,
                   v.effective_from AS active_effective_from,
                   (SELECT COUNT(*) FROM terms_versions x WHERE x.terms_document_id = t.id) AS version_count,
                   (SELECT COUNT(*) FROM terms_versions x WHERE x.terms_document_id = t.id AND x.status = 'draft') AS draft_count,
                   (SELECT COUNT(*) FROM terms_acceptances a
                     JOIN terms_versions y ON y.id = a.terms_version_id
                    WHERE y.terms_document_id = t.id) AS acceptance_count
              FROM terms_documents t
              LEFT JOIN terms_versions v
                     ON v.terms_document_id = t.id AND v.status = 'active'
              {$where}
             ORDER BY t.sort_order, t.name
        ")->fetchAll();
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO terms_documents (slug, name, description, requires_acceptance, is_active, sort_order)
                VALUES (:slug, :name, :desc, :req, :active, :sort)
            ");
            $stmt->execute([
                ':slug'   => $d['slug'] ?? self::slugify($d['name']),
                ':name'   => $d['name'],
                ':desc'   => $d['description'] ?? '',
                ':req'    => !empty($d['requires_acceptance']) ? 1 : 0,
                ':active' => array_key_exists('is_active', $d) ? (int) (bool) $d['is_active'] : 1,
                ':sort'   => $d['sort_order'] ?? $this->nextSortOrder(),
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('TermsDocument create error: ' . $e->getMessage());
            return false;
        }
    }

    /** Partial update. The slug is not editable — code and history key off it. */
    public function update(int $id, array $d): bool
    {
        $allowed = ['name', 'description', 'requires_acceptance', 'is_active', 'sort_order'];
        $fields = [];
        $params = [':id' => $id];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) {
                $fields[] = "$f = :$f";
                $params[":$f"] = $d[$f];
            }
        }
        if (!$fields) return false;

        return $this->db->prepare(
            "UPDATE terms_documents SET " . implode(', ', $fields) . " WHERE id = :id"
        )->execute($params);
    }

    public function setActive(int $id, bool $active): bool
    {
        return $this->update($id, ['is_active' => $active ? 1 : 0]);
    }

    public function nextSortOrder(): int
    {
        $max = (int) $this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM terms_documents")->fetchColumn();
        return $max + 10;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM terms_documents WHERE slug = :slug");
        $stmt->execute([':slug' => $slug]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        return $slug === '' ? 'terms' : substr($slug, 0, 45);
    }
}
