<?php
/**
 * Terms & Conditions helpers.
 *
 * Legal content is versioned in the database rather than written into source,
 * because the rules change and every change has to leave the previous wording
 * intact for the acceptance records that point at it.
 *
 * Kept separate from includes/documents.php on purpose: property documents are
 * uploaded files, terms are authored content. They share nothing but a menu.
 */

/**
 * The live version of a legal type, or null when nothing is published.
 *
 * Cached per request the way setting() is, because the reservation form, the
 * public terms page and the acceptance check can all ask for the same slug
 * within one request.
 *
 * @return array{id:int,terms_document_id:int,version_code:string,title:string,
 *               body:string,content_hash:string,effective_from:?string,
 *               slug:string,name:string,requires_acceptance:int}|null
 */
function activeTerms(string $slug): ?array
{
    static $cache = [];
    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }

    try {
        $stmt = getDBConnection()->prepare("
            SELECT v.*, d.slug, d.name, d.requires_acceptance, d.is_active AS type_active
              FROM terms_versions v
              JOIN terms_documents d ON d.id = v.terms_document_id
             WHERE d.slug = :slug
               AND d.is_active = 1
               AND v.status = 'active'
             LIMIT 1
        ");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        $cache[$slug] = $row ?: null;
    } catch (PDOException $e) {
        error_log('activeTerms error: ' . $e->getMessage());
        $cache[$slug] = null;
    }

    return $cache[$slug];
}

/**
 * The booking terms a new reservation must carry, or null when acceptance is
 * not being enforced (switched off in Settings, or no version published yet).
 *
 * Returning null rather than throwing means an install that has not written
 * its terms yet still takes bookings — the feature adds a gate, it does not
 * become a prerequisite for using the system.
 */
function termsRequiredForBooking(): ?array
{
    if ((setting('terms_require_on_reservation') ?? '1') !== '1') {
        return null;
    }

    $version = activeTerms('booking');
    if (!$version || (int) ($version['requires_acceptance'] ?? 1) !== 1) {
        return null;
    }

    return $version;
}

/**
 * Render stored legal text as safe HTML.
 *
 * The project vendors no rich-text editor and loads nothing from a CDN, so the
 * body is plain text with a small set of conventions:
 *
 *   ## Heading          a section heading
 *   ### Sub-heading     a sub-heading
 *   - item              a bullet list
 *   1. item             a numbered list
 *   **bold**  *italic*  inline emphasis
 *   [text](https://…)   a link
 *   blank line          a new paragraph
 *
 * The whole input is escaped *before* any markup is generated, so nothing an
 * author types can become live HTML. That ordering is the entire security
 * model here — there is no allow-list to get wrong and no sanitiser to keep
 * up to date.
 */
function renderLegalText(string $body): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($body));
    if ($text === '') {
        return '';
    }

    // Escape first. Everything below operates on already-safe text.
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $out    = [];
    $blocks = preg_split('/\n{2,}/', $text) ?: [];

    foreach ($blocks as $block) {
        $block = trim($block, "\n");
        if (trim($block) === '') {
            continue;
        }

        $lines = explode("\n", $block);
        $first = ltrim($lines[0]);

        // ── Headings ────────────────────────────────────────────────
        if (str_starts_with($first, '### ')) {
            $out[] = '<h3>' . legalInline(substr($first, 4)) . '</h3>';
            if (count($lines) > 1) {
                $out[] = '<p>' . legalInline(implode("\n", array_slice($lines, 1))) . '</p>';
            }
            continue;
        }
        if (str_starts_with($first, '## ')) {
            $out[] = '<h2>' . legalInline(substr($first, 3)) . '</h2>';
            if (count($lines) > 1) {
                $out[] = '<p>' . legalInline(implode("\n", array_slice($lines, 1))) . '</p>';
            }
            continue;
        }

        // ── Bullet list ─────────────────────────────────────────────
        if (preg_match('/^[-*]\s+/', $first)) {
            $items = [];
            foreach ($lines as $line) {
                $line = ltrim($line);
                if ($line === '') continue;
                $items[] = '<li>' . legalInline(preg_replace('/^[-*]\s+/', '', $line) ?? $line) . '</li>';
            }
            $out[] = '<ul>' . implode('', $items) . '</ul>';
            continue;
        }

        // ── Numbered list ───────────────────────────────────────────
        if (preg_match('/^\d+[.)]\s+/', $first)) {
            $items = [];
            foreach ($lines as $line) {
                $line = ltrim($line);
                if ($line === '') continue;
                $items[] = '<li>' . legalInline(preg_replace('/^\d+[.)]\s+/', '', $line) ?? $line) . '</li>';
            }
            $out[] = '<ol>' . implode('', $items) . '</ol>';
            continue;
        }

        // ── Paragraph ───────────────────────────────────────────────
        $out[] = '<p>' . legalInline($block) . '</p>';
    }

    return implode("\n", $out);
}

/**
 * Inline formatting inside an already-escaped block.
 *
 * Link targets are re-checked here even though the text is escaped: escaping
 * stops markup, it does not stop a javascript: URL being placed in an href.
 * Only absolute http(s) links and root-relative paths survive; anything else
 * is left as plain text.
 */
function legalInline(string $escaped): string
{
    // Links: [label](target)
    $html = preg_replace_callback(
        '/\[([^\]\n]{1,200})\]\(([^)\s]{1,300})\)/',
        static function (array $m): string {
            // The URL arrived escaped; undo that to test it, then re-escape.
            $raw = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $ok  = preg_match('#^https?://#i', $raw) === 1 || str_starts_with($raw, '/');
            if (!$ok) {
                return $m[1]; // not a usable link — keep the label as text
            }
            $href = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $href . '" rel="noopener nofollow" target="_blank">' . $m[1] . '</a>';
        },
        $escaped
    ) ?? $escaped;

    // Bold before italic, so **text** is not eaten by the single-asterisk rule.
    $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/(?<!\*)\*(?=\S)([^*\n]+?)(?<=\S)\*(?!\*)/', '<em>$1</em>', $html) ?? $html;

    // Single newlines inside a block are soft breaks.
    return nl2br($html, false);
}

/**
 * A one-line description of a version for logs and confirmation messages,
 * e.g. "Booking / Reservation Terms v2".
 */
function termsVersionLabel(array $version): string
{
    return trim(($version['name'] ?? $version['title'] ?? 'Terms') . ' ' . ($version['version_code'] ?? ''));
}
