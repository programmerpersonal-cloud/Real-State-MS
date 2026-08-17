<?php
/**
 * Document Category Model — the configurable list of document types.
 *
 * These replace what used to be a hard-coded ENUM on documents.doc_type, so an
 * administrator can add "Energy Certificate" without a schema change. Deleting
 * is deliberately restricted: a category holding documents can only be
 * deactivated, which hides it from new uploads while existing rows keep their
 * label.
 */
class DocumentCategory
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM document_categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM document_categories WHERE slug = :slug");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Every category, newest ordering first, with how many documents each holds.
     * The count drives both the admin table and the "cannot delete" rule.
     */
    public function getAll(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE c.is_active = 1' : '';
        return $this->db->query("
            SELECT c.*, (SELECT COUNT(*) FROM documents d WHERE d.category_id = c.id) AS doc_count
              FROM document_categories c
              {$where}
             ORDER BY c.sort_order, c.name
        ")->fetchAll();
    }

    /** Active categories as id => name, for a <select>. */
    public function options(): array
    {
        $rows = $this->db->query("
            SELECT id, name FROM document_categories WHERE is_active = 1 ORDER BY sort_order, name
        ")->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }

    /**
     * Active categories keyed by id, carrying the metadata the upload form
     * needs so picking a category can pre-select its default visibility.
     */
    public function formMeta(): array
    {
        $rows = $this->db->query("
            SELECT id, name, icon, default_visibility, requires_expiry
              FROM document_categories WHERE is_active = 1 ORDER BY sort_order, name
        ")->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = [
                'name'               => $r['name'],
                'icon'               => $r['icon'],
                'default_visibility' => $r['default_visibility'],
                'requires_expiry'    => (int) $r['requires_expiry'],
            ];
        }
        return $out;
    }

    public function create(array $d): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO document_categories
                    (name, slug, description, icon, default_visibility, requires_expiry, sort_order, is_active)
                VALUES (:name, :slug, :desc, :icon, :vis, :exp, :sort, :active)
            ");
            $stmt->execute([
                ':name'   => $d['name'],
                ':slug'   => $d['slug'] ?? self::slugify($d['name']),
                ':desc'   => $d['description'] ?? '',
                ':icon'   => $d['icon'] ?: 'bi-file-earmark-text',
                ':vis'    => $d['default_visibility'] ?? 'staff',
                ':exp'    => !empty($d['requires_expiry']) ? 1 : 0,
                ':sort'   => $d['sort_order'] ?? $this->nextSortOrder(),
                ':active' => array_key_exists('is_active', $d) ? (int) (bool) $d['is_active'] : 1,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('DocumentCategory create error: ' . $e->getMessage());
            return false;
        }
    }

    /** Partial update. The slug is intentionally not editable — code keys off it. */
    public function update(int $id, array $d): bool
    {
        $allowed = ['name', 'description', 'icon', 'default_visibility', 'requires_expiry', 'sort_order', 'is_active'];
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
            "UPDATE document_categories SET " . implode(', ', $fields) . " WHERE id = :id"
        )->execute($params);
    }

    public function setActive(int $id, bool $active): bool
    {
        return $this->update($id, ['is_active' => $active ? 1 : 0]);
    }

    /**
     * Move a category one place up or down.
     *
     * Swapping with the neighbour keeps the operation to two rows, so a list
     * being reordered by two people at once cannot end up with everything
     * renumbered from one of their views. Wrapped in a transaction because a
     * half-applied swap would leave two categories sharing a position.
     */
    public function move(int $id, string $direction): bool
    {
        $current = $this->findById($id);
        if (!$current) return false;

        $isUp = $direction === 'up';
        $stmt = $this->db->prepare("
            SELECT id, sort_order FROM document_categories
             WHERE (sort_order " . ($isUp ? '<' : '>') . " :sort)
                OR (sort_order = :sort AND id " . ($isUp ? '<' : '>') . " :id)
             ORDER BY sort_order " . ($isUp ? 'DESC' : 'ASC') . ", id " . ($isUp ? 'DESC' : 'ASC') . "
             LIMIT 1
        ");
        $stmt->execute([':sort' => (int) $current['sort_order'], ':id' => $id]);
        $neighbour = $stmt->fetch();

        if (!$neighbour) return false; // already at the end

        try {
            $this->db->beginTransaction();
            $set = $this->db->prepare("UPDATE document_categories SET sort_order = :s WHERE id = :id");

            // Equal sort_order values would swap to no effect, so nudge them apart.
            $a = (int) $current['sort_order'];
            $b = (int) $neighbour['sort_order'];
            if ($a === $b) {
                $b = $isUp ? $a - 1 : $a + 1;
            }

            $set->execute([':s' => $b, ':id' => $id]);
            $set->execute([':s' => $a, ':id' => (int) $neighbour['id']]);
            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('DocumentCategory move error: ' . $e->getMessage());
            return false;
        }
    }

    public function countDocuments(int $id): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM documents WHERE category_id = :id");
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete a category. Refuses while documents still reference it — the
     * database would refuse too (fk_doc_category is RESTRICT), but checking
     * here lets the UI explain itself instead of surfacing a SQL error.
     */
    public function delete(int $id): bool
    {
        if ($this->countDocuments($id) > 0) {
            return false;
        }
        try {
            return $this->db->prepare("DELETE FROM document_categories WHERE id = :id")->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('DocumentCategory delete error: ' . $e->getMessage());
            return false;
        }
    }

    public function nextSortOrder(): int
    {
        $max = (int) $this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM document_categories")->fetchColumn();
        return $max + 10;
    }

    /** Is this slug free? Used to keep generated slugs unique. */
    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM document_categories WHERE slug = :slug AND id <> :id");
        $stmt->execute([':slug' => $slug, ':id' => $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        return $slug === '' ? 'category' : substr($slug, 0, 90);
    }
}
