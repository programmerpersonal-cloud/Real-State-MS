<?php
/**
 * MessageAttachment Model
 *
 * Files sent inside a conversation. The bytes live in storage/documents — the
 * same private store the document module uses, behind the same three layers of
 * Apache denial — and this table holds only what is needed to find them again
 * and describe them to a reader.
 *
 * Two rules govern everything here:
 *
 *   THE FILE IS NEVER ADDRESSABLE. There is no URL that reaches the bytes
 *   directly. Delivery goes through CommunicationController::attachment(),
 *   which walks attachment → message → conversation and asks
 *   canAccessConversation() before a single byte is read.
 *
 *   THE ORIGINAL NAME IS DISPLAY METADATA. It is never the name on disk, never
 *   a directory, never a path component. storeDocumentFile() generates the
 *   stored name from random_bytes() and takes the extension from the sniffed
 *   MIME type, so an upload called "invoice.php" is stored as
 *   msg_<32 hex>.pdf or refused outright.
 */
class MessageAttachment
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDBConnection();
    }

    // ─── Validating and storing ────────────────────────────────────────

    /**
     * Re-shape PHP's $_FILES entry for a multi-file input into one array per
     * file, dropping the empty slots a browser sends for unused inputs.
     *
     * PHP transposes a `name="attachments[]"` upload into arrays-of-columns
     * rather than a list of files, which is the single most common source of
     * bugs in upload code. Done once, here.
     *
     * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function normalise(?array $files): array
    {
        if (!$files || !isset($files['name'])) {
            return [];
        }

        // A single-file input is already the right shape.
        if (!is_array($files['name'])) {
            return ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }

        $out = [];
        foreach (array_keys($files['name']) as $i) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;                    // an untouched slot, not a failure
            }
            $out[] = [
                'name'     => (string) ($files['name'][$i] ?? ''),
                'type'     => (string) ($files['type'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error'    => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size'     => (int) ($files['size'][$i] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Validate and store a set of uploads, or store none of them.
     *
     * All-or-nothing on purpose: a message that arrives carrying three of the
     * four photographs someone attached is worse than one that is refused with
     * a reason, because nobody notices the missing one. The first failure
     * unlinks whatever has already been written and returns null.
     *
     * Every file goes through storeDocumentFile(), which is the document
     * module's proven sequence — forged-$_FILES guard, size, sniffed MIME
     * against an allow-list, image decode check, extension derived from the
     * type, cryptographically random name, move_uploaded_file. This method
     * passes it the message-attachment policy and adds only the count limit.
     *
     * @param  array<int, array<string,mixed>> $files  From normalise().
     * @param  string[] $errors  Reasons, for the user.
     * @return array<int, array<string,mixed>>|null    Stored metadata, or null.
     */
    public static function storeAll(array $files, array &$errors): ?array
    {
        if (!$files) {
            return [];
        }

        // Enforced here rather than in the view, because `multiple` on an
        // input is a convenience and the form can be edited.
        if (count($files) > MESSAGE_ATTACHMENT_MAX_COUNT) {
            $errors[] = sprintf(
                'You can attach up to %d files to a message. You selected %d.',
                MESSAGE_ATTACHMENT_MAX_COUNT,
                count($files)
            );
            return null;
        }

        $options = [
            'types'  => MESSAGE_ATTACHMENT_TYPES,
            'max'    => MESSAGE_ATTACHMENT_MAX_SIZE,
            'prefix' => 'msg',
            'reject' => 'That file type is not supported. Attach a JPEG, PNG or WebP image, or a PDF.',
        ];

        $stored = [];
        foreach ($files as $file) {
            $reasons = [];
            $meta    = storeDocumentFile($file, $reasons, $options);

            if ($meta === null) {
                // Name the file that failed — with four attached, "that file
                // type is not supported" alone does not say which.
                $label = documentSafeOriginalName((string) ($file['name'] ?? 'file'));
                foreach ($reasons as $reason) {
                    $errors[] = $label . ': ' . $reason;
                }

                self::discard(array_column($stored, 'file_path'));
                return null;
            }

            $stored[] = $meta;
        }

        return $stored;
    }

    /**
     * Validate and store a recorded voice note.
     *
     * Exactly storeAll()'s sequence with the audio policy substituted, and
     * deliberately a separate entry point rather than a wider allow-list on
     * the paperclip: the two controls promise different things, and merging
     * them would let an audio file arrive through the document picker.
     *
     * One file only. A recording is made, not chosen, so there is never a
     * second one — and accepting an array here would be an invitation to send
     * five through a hand-edited form.
     *
     * @param  array<int, array<string,mixed>> $files From normalise().
     * @param  string[] $errors
     * @return array<int, array<string,mixed>>|null
     */
    public static function storeVoice(array $files, array &$errors): ?array
    {
        if (!$files) {
            return [];
        }
        if (count($files) > 1) {
            $errors[] = 'Only one recording can be sent at a time.';
            return null;
        }

        $reasons = [];
        $meta = storeDocumentFile($files[0], $reasons, [
            'types'  => MESSAGE_VOICE_TYPES,
            'max'    => MESSAGE_VOICE_MAX_SIZE,
            'prefix' => 'msg',
            'reject' => 'That recording is not in a format the server accepts.',
        ]);

        if ($meta === null) {
            foreach ($reasons as $reason) {
                $errors[] = $reason;
            }
            return null;
        }

        // A recording has no filename of its own — the browser hands over a
        // blob — so it is given one that says what it is and when it was made.
        $meta['file_name'] = 'Voice note ' . date('j M Y, H:i') . '.' . $meta['file_ext'];

        return [$meta];
    }

    /** Is this attachment a voice note rather than a chosen file? */
    public static function isVoice(string $mime): bool
    {
        return str_starts_with($mime, 'audio/')
            || array_key_exists($mime, MESSAGE_VOICE_TYPES);
    }

    /**
     * Delete stored files, used when something later in the sequence fails.
     *
     * Routed through deleteDocumentFile(), which resolves the path through the
     * store's own validator first — so this can never unlink something outside
     * storage/documents however the path got there.
     *
     * @param string[] $paths
     */
    public static function discard(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                deleteDocumentFile($path);
            }
        }
    }

    /**
     * Write the metadata rows for files already on disk.
     *
     * Called inside the message transaction. If it throws, the caller rolls
     * back *and* discards the files, so a row can never name a file that is
     * not there and a file is never left behind by a failed row.
     *
     * @param array<int, array<string,mixed>> $stored From storeAll().
     */
    public function attachAll(int $messageId, array $stored, int $uploadedBy): void
    {
        if (!$stored) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO message_attachments
                (message_id, original_name, stored_path, mime_type, file_size, checksum, uploaded_by)
            VALUES (:mid, :name, :path, :mime, :size, :sum, :by)
        ");

        foreach ($stored as $meta) {
            $stmt->execute([
                ':mid'  => $messageId,
                ':name' => $meta['file_name'],
                ':path' => $meta['file_path'],
                ':mime' => $meta['file_type'],
                ':size' => $meta['file_size'],
                ':sum'  => $meta['checksum'] ?: null,
                ':by'   => $uploadedBy,
            ]);
        }
    }

    // ─── Reading ───────────────────────────────────────────────────────

    /**
     * Attachments for a page of messages, keyed by message id.
     *
     * One query for the whole page rather than one per message. Takes the ids
     * the thread already fetched, so there is nothing to scope here — those
     * messages came out of forConversation(), which asked
     * canAccessConversation() before returning anything.
     *
     * @param int[] $messageIds
     * @return array<int, array<int, array<string,mixed>>>
     */
    public function forMessages(array $messageIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $messageIds)));
        if (!$ids) {
            return [];
        }

        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT id, message_id, original_name, mime_type, file_size
            FROM message_attachments
            WHERE message_id IN ({$in})
            ORDER BY id ASC
        ");
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['message_id']][] = $row;
        }

        return $out;
    }

    /**
     * One attachment with the whole chain back to its conversation.
     *
     * The delivery endpoint needs all of it in one go: the conversation id to
     * authorise against, the message's deleted_at so a withdrawn message does
     * not keep serving its files, and the stored path to resolve.
     *
     * Returns null for an id that does not exist — the caller treats that
     * identically to one it may not have, so probing ids reveals nothing.
     */
    public function findForDelivery(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, m.conversation_id, m.deleted_at AS message_deleted_at
            FROM message_attachments a
            JOIN conversation_messages m ON a.message_id = m.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** The URL of the delivery endpoint. One spelling, used everywhere. */
    public static function url(int $id, bool $inline = false): string
    {
        return APP_URL . '/index.php?page=messages&action=attachment&id=' . $id
             . ($inline ? '&disposition=inline' : '');
    }

    /** Whether this type may be shown in place rather than downloaded. */
    public static function isInlineImage(string $mime): bool
    {
        return in_array($mime, MESSAGE_ATTACHMENT_INLINE_TYPES, true);
    }
}
