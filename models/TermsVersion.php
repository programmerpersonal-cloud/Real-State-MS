<?php
/**
 * Terms Version Model — one immutable revision of a legal document.
 *
 * The rule that makes acceptance records trustworthy lives here: **a version
 * that has left draft is never edited again**. Changing published terms means
 * creating a new version and activating it, which leaves the old wording and
 * its content_hash exactly as the customer saw them.
 *
 * Lifecycle: draft → active → superseded (or withdrawn). Only one version of a
 * type may be active at a time; activate() enforces that in a transaction and
 * the uq_tv_one_active index enforces it again in the database.
 */
class TermsVersion
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT v.*,
                   t.slug, t.name, t.requires_acceptance,
                   cu.full_name AS created_by_name,
                   au.full_name AS activated_by_name,
                   (SELECT COUNT(*) FROM terms_acceptances a WHERE a.terms_version_id = v.id) AS acceptance_count
              FROM terms_versions v
              JOIN terms_documents t ON t.id = v.terms_document_id
              LEFT JOIN users cu ON cu.id = v.created_by
              LEFT JOIN users au ON au.id = v.activated_by
             WHERE v.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Version history for one type, newest first. */
    public function forDocument(int $docId): array
    {
        $stmt = $this->db->prepare("
            SELECT v.*,
                   cu.full_name AS created_by_name,
                   au.full_name AS activated_by_name,
                   (SELECT COUNT(*) FROM terms_acceptances a WHERE a.terms_version_id = v.id) AS acceptance_count
              FROM terms_versions v
              LEFT JOIN users cu ON cu.id = v.created_by
              LEFT JOIN users au ON au.id = v.activated_by
             WHERE v.terms_document_id = :doc
             ORDER BY v.version_number DESC
        ");
        $stmt->execute([':doc' => $docId]);
        return $stmt->fetchAll();
    }

    /** The live version for a slug, or null. Mirrors activeTerms() without its cache. */
    public function activeFor(string $slug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT v.*, t.slug, t.name, t.requires_acceptance
              FROM terms_versions v
              JOIN terms_documents t ON t.id = v.terms_document_id
             WHERE t.slug = :slug AND v.status = 'active'
             LIMIT 1
        ");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create a new draft.
     *
     * The content hash is computed here rather than by the caller so every
     * version — however it was created — carries a hash of exactly what was
     * stored, which is what acceptance records copy and later verify against.
     */
    public function create(int $docId, array $d): int|false
    {
        $body   = (string) ($d['body'] ?? '');
        $number = $this->nextVersionNumber($docId);

        try {
            $stmt = $this->db->prepare("
                INSERT INTO terms_versions
                    (terms_document_id, version_number, version_code, title, summary, body,
                     content_hash, status, effective_from, created_by)
                VALUES
                    (:doc, :num, :code, :title, :summary, :body,
                     :hash, 'draft', :from, :cb)
            ");
            $stmt->execute([
                ':doc'     => $docId,
                ':num'     => $number,
                ':code'    => 'v' . $number,
                ':title'   => $d['title'],
                ':summary' => $d['summary'] ?? '',
                ':body'    => $body,
                ':hash'    => hash('sha256', $body),
                ':from'    => $d['effective_from'] ?: null,
                ':cb'      => $_SESSION['user_id'] ?? null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('TermsVersion create error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a draft.
     *
     * Refuses anything that is not editable. This is the guard that keeps
     * history honest, so it is checked here in the model rather than only in
     * the controller — any future caller inherits it.
     */
    public function update(int $id, array $d): bool
    {
        if (!$this->isEditable($id)) {
            return false;
        }

        $allowed = ['title', 'summary', 'body', 'effective_from'];
        $fields = [];
        $params = [':id' => $id];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) {
                $fields[] = "$f = :$f";
                $params[":$f"] = $d[$f] === '' && $f === 'effective_from' ? null : $d[$f];
            }
        }
        if (!$fields) return false;

        // The hash always describes the body actually stored.
        if (array_key_exists('body', $d)) {
            $fields[] = "content_hash = :hash";
            $params[':hash'] = hash('sha256', (string) $d['body']);
        }

        return $this->db->prepare(
            "UPDATE terms_versions SET " . implode(', ', $fields) . " WHERE id = :id AND status = 'draft'"
        )->execute($params);
    }

    /**
     * A version may only be edited while it is a draft and nobody has accepted
     * it. The second condition should be impossible — acceptance requires an
     * active version — but it costs one query to be certain.
     */
    public function isEditable(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT status FROM terms_versions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $status = $stmt->fetchColumn();

        return $status === 'draft' && $this->acceptanceCount($id) === 0;
    }

    public function acceptanceCount(int $id): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM terms_acceptances WHERE terms_version_id = :id");
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Make this version the live one.
     *
     * Supersede first, activate second: the unique index on (type, active_flag)
     * would reject the reverse order, which is the point — a bug here becomes a
     * failed transaction rather than two live agreements. The outgoing version
     * keeps its wording and gains an effective_to, so the history reads as a
     * continuous timeline.
     */
    public function activate(int $id, ?string $effectiveFrom = null): bool
    {
        $version = $this->findById($id);
        if (!$version || $version['status'] === 'active') {
            return false;
        }

        try {
            $this->db->beginTransaction();

            $this->db->prepare("
                UPDATE terms_versions
                   SET status = 'superseded',
                       effective_to = COALESCE(effective_to, CURDATE())
                 WHERE terms_document_id = :doc AND status = 'active' AND id <> :id
            ")->execute([':doc' => (int) $version['terms_document_id'], ':id' => $id]);

            $ok = $this->db->prepare("
                UPDATE terms_versions
                   SET status = 'active',
                       activated_at = NOW(),
                       activated_by = :uid,
                       effective_from = COALESCE(:from, effective_from, CURDATE()),
                       effective_to = NULL
                 WHERE id = :id
            ")->execute([
                ':id'   => $id,
                ':uid'  => $_SESSION['user_id'] ?? null,
                ':from' => $effectiveFrom ?: null,
            ]);

            $this->db->commit();
            return $ok;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('TermsVersion activate error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Withdraw the live version, leaving the type with nothing published.
     * The wording is kept — withdrawing is not deleting.
     */
    public function withdraw(int $id): bool
    {
        return $this->db->prepare("
            UPDATE terms_versions
               SET status = 'withdrawn', effective_to = COALESCE(effective_to, CURDATE())
             WHERE id = :id AND status = 'active'
        ")->execute([':id' => $id]);
    }

    /**
     * Copy a published version into a new draft, so revising terms starts from
     * the current wording instead of a blank page.
     */
    public function duplicateAsDraft(int $id): int|false
    {
        $source = $this->findById($id);
        if (!$source) return false;

        return $this->create((int) $source['terms_document_id'], [
            'title'          => $source['title'],
            'summary'        => '',
            'body'           => $source['body'],
            'effective_from' => null,
        ]);
    }

    public function nextVersionNumber(int $docId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(version_number), 0) FROM terms_versions WHERE terms_document_id = :doc");
        $stmt->execute([':doc' => $docId]);
        return (int) $stmt->fetchColumn() + 1;
    }

    /**
     * Delete a draft. Published versions and anything with acceptances are
     * refused — the database would refuse too, via fk_ta_version RESTRICT.
     */
    public function deleteDraft(int $id): bool
    {
        if (!$this->isEditable($id)) {
            return false;
        }
        try {
            return $this->db->prepare("DELETE FROM terms_versions WHERE id = :id AND status = 'draft'")
                            ->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('TermsVersion delete error: ' . $e->getMessage());
            return false;
        }
    }
}
