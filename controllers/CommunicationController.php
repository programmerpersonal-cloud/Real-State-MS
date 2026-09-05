<?php
/**
 * Communication Controller — the Messages workspace.
 *
 * Thin on purpose. Every question worth asking was already answered in
 * includes/communication_access.php and the two conversation models, and this
 * controller's job is to turn a request into one of those calls and a redirect.
 * Nothing here re-implements a rule; where it looks like it might, it is
 * producing a readable refusal before work starts, not deciding anything.
 *
 * Authorization runs at all three levels described in includes/permissions.php:
 *
 *   1. authorize('messages.x')      may this role use this action at all
 *   2. canAccessConversation(...)   may this user touch this conversation
 *   3. the scope predicate          enforced inside the SQL that reads or
 *                                   writes, in the model and the access layer
 *
 * Level 3 is the one that cannot be bypassed. ConversationMessage::create()
 * asks canSendToConversation() itself; Conversation::create() asks
 * canMessageUser() for every counterpart and canCreateContextConversation()
 * for the context. The checks in here exist so a refusal explains itself.
 *
 * Everything that changes state is POST + CSRF + redirect, like the rest of
 * the application: a message is sent by submitting a form, and the page that
 * comes back is the conversation.
 *
 * There is one JSON action, poll(), and it changes nothing a form does not.
 * It exists because a page that is correct when rendered is not the same
 * thing as a conversation — the other participant's browser has to be able to
 * learn that something was said. See its docblock for why the answer here is
 * a held poll rather than a socket, and note that it re-runs the same
 * canAccessConversation() check the thread does and renders the same partials
 * the full page does. It can show nothing show() would not.
 */
require_once BASE_PATH . '/models/Conversation.php';
require_once BASE_PATH . '/models/ConversationMessage.php';
require_once BASE_PATH . '/models/MessageAttachment.php';
require_once BASE_PATH . '/models/MessageReaction.php';

class CommunicationController
{
    /**
     * The inbox filters, keyed by the token a request may ask for.
     *
     * Checked against this list rather than trusted, so a hand-edited
     * ?filter=;DROP resolves to 'all' instead of reaching a query.
     */
    public const FILTERS = [
        'all'      => 'All',
        'unread'   => 'Unread',
        'archived' => 'Archived',
    ];

    /**
     * How long one poll may hold its connection open, in seconds.
     *
     * Comfortably under PHP's default 30s max_execution_time and under the
     * idle timeout Apache and most proxies apply, so the request always ends
     * on this application's terms rather than being cut off somewhere that
     * would look to the browser like a network failure. Shorter means more
     * requests; longer means a worker held for longer with no gain, because
     * delivery latency is set by the check interval inside the hold, not by
     * the length of the hold.
     */
    private const POLL_HOLD = 15;

    private Conversation $conversations;
    private ConversationMessage $messages;

    public function __construct()
    {
        $this->conversations = new Conversation();
        $this->messages      = new ConversationMessage();
    }

    // ─── Reading ───────────────────────────────────────────────────────

    /**
     * The workspace with no conversation open: the inbox, and either the
     * "select a conversation" panel or the new-message recipient picker.
     */
    public function index(): void
    {
        authorize('messages.view');
        $this->render(null);
    }

    /**
     * The workspace with one conversation open.
     *
     * Same view as index() — deliberately. The two-panel layout is one page,
     * and which panel is filled is a property of the URL rather than of
     * JavaScript. That is what makes the mobile takeover work with the
     * browser's own Back button and no script at all.
     */
    public function show(): void
    {
        authorize('messages.show');

        $id = (int) ($_GET['id'] ?? 0);

        // The row-level check. A conversation this user is not party to, or
        // one whose relationship has since ended, refuses here — and refuses
        // identically whether the id was clicked or typed.
        authorizeRecord(canAccessConversation($id), 'conversation', $id);

        // Opening a conversation is what "read" means. Only this
        // participant's watermark moves; the other side's is untouched, and
        // the watermark never travels backwards.
        $this->messages->markReadUpTo($id);

        $this->render($id);
    }

    // ─── Live updates ──────────────────────────────────────────────────

    /**
     * The one endpoint the workspace polls, and the only JSON in this module.
     *
     * Messages were being written to the database correctly and read back
     * correctly — the missing half was that nothing ever asked again. A page
     * rendered at 10:00 still showed 10:00 at 10:05, so the second browser
     * only learned about a message when its reader pressed reload.
     *
     * WebSockets were considered and rejected for this deployment: Apache and
     * mod_php answer a request and end, so a socket server means a second
     * long-running PHP process (Ratchet or similar) started and supervised
     * beside XAMPP, its own port opened, and its own copy of the
     * authorization rules. That is a lot of moving parts for two people
     * typing at each other on localhost, and every one of them is a place the
     * permission checks could drift out of step with the ones here.
     *
     * What this does instead is a held poll — long polling. The request does
     * not answer straight away: it takes a cheap fingerprint of the
     * conversation and the inbox, then waits, re-taking it about once a
     * second until it changes or the hold runs out. A message therefore lands
     * on the other screen within roughly a second, at the cost of one HTTP
     * request every POLL_HOLD seconds per open tab rather than one a second.
     * When nothing is happening it is one connection sitting idle.
     *
     * Three things make it safe to hold a request open in this application:
     *
     *   1. session_write_close() runs before the wait. PHP's session file is
     *      locked for the life of a request, so without this a held poll
     *      would block every other request from the same browser — including
     *      the POST that sends the next message. It is the single most
     *      important line in the method.
     *   2. Only statements already prepared are re-executed in the loop, and
     *      each is one indexed aggregate. Nothing renders until something has
     *      actually changed.
     *   3. The hold is bounded well under max_execution_time, and the client
     *      reconnects, so a stalled connection costs one worker for a few
     *      seconds rather than for ever.
     *
     * Authorization is not relaxed by a byte. canAccessConversation() is
     * asked here and asked again by ConversationMessage::forConversation()
     * when the thread is rendered, and the fragments are produced by the same
     * partials the full page uses — so an update can never show a message the
     * reader could not have seen by pressing reload.
     */
    public function poll(): void
    {
        // A read, so GET — and never renderPage(), because the answer is JSON
        // and the admin layout would wrap it in a document.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $this->sendJson(['ok' => false, 'error' => 'method'], 405);
        }

        // requireLogin() has already run in the router. can() rather than
        // authorize(), because authorize() answers a refusal with an HTML 403
        // page and this caller is parsing JSON.
        if (!isLoggedIn() || !can('messages.view')) {
            $this->sendJson(['ok' => false, 'error' => 'auth', 'reload' => true], 403);
        }

        $conversationId = (int) ($_GET['id'] ?? 0);
        $clientSig      = (string) ($_GET['sig'] ?? '');
        $wait           = ($_GET['wait'] ?? '') === '1';
        $visible        = ($_GET['visible'] ?? '') === '1';

        // The row-level check, before anything is read or waited on. A
        // conversation that is no longer this user's — the lease ended, the
        // participant was deactivated — answers `reload`, and the reader gets
        // the real 403 page from the ordinary route rather than a thread that
        // quietly stops updating.
        if ($conversationId > 0 && !canAccessConversation($conversationId)) {
            $this->sendJson(['ok' => false, 'error' => 'forbidden', 'reload' => true], 403);
        }

        /* Everything above needed the session; nothing below does. Releasing
           the lock here is what keeps a held poll from blocking this
           browser's other requests — see the note in the docblock. $_SESSION
           stays readable in memory, which is all communicationActor() and the
           access layer want from it. */
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        set_time_limit(self::POLL_HOLD + 30);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Accel-Buffering: no');

        $signature = $this->pollSignature($conversationId);

        /* The wait. A first call arrives with no fingerprint and is answered
           at once, so a tab that has just loaded gets its baseline without
           holding a connection for nothing. */
        if ($wait && $clientSig !== '' && $clientSig === $signature) {
            $deadline = microtime(true) + self::POLL_HOLD;
            while (microtime(true) < $deadline) {
                usleep(900000);           // ~1s: the granularity of "instant"

                /* Best effort only, and worth knowing why: PHP notices a
                   disconnected client when it next writes, and this loop
                   writes nothing, so a tab closed mid-hold is usually
                   reaped when the answer is finally sent rather than here.
                   That is what bounds the hold at POLL_HOLD rather than
                   leaving it to the client to end. */
                if (connection_aborted()) {
                    exit;
                }
                $signature = $this->pollSignature($conversationId);
                if ($signature !== $clientSig) {
                    break;
                }
            }
        }

        // Nothing moved. Say so in a few bytes and let the client come
        // straight back — no query beyond the fingerprint has run.
        if ($signature === $clientSig) {
            $this->sendJson(['ok' => true, 'changed' => false, 'sig' => $signature]);
        }

        /* Something changed, so the panels are rebuilt. Reading a thread that
           is open in front of someone is what "read" means, exactly as it
           does in show() — but only when the tab is actually being looked at.
           A backgrounded tab must not clear the other side's unread badge or
           turn their single tick into a double one for a message nobody has
           seen. */
        if ($conversationId > 0 && $visible) {
            $this->messages->markReadUpTo($conversationId);
        }

        /* Re-taken, because the line above has just moved a watermark and so
           changed the very thing being fingerprinted. Handing back the older
           value would have the client come straight back for a second render
           of a conversation nothing else had happened to.

           Deliberately re-taken *before* the panels are built rather than
           after: a message arriving while they render then leaves the client
           holding a fingerprint older than its markup, which costs one extra
           refresh. Taking it afterwards would leave the client holding a
           fingerprint newer than its markup, and that message would never
           appear. One is a wasted round trip; the other is the bug this whole
           endpoint exists to fix. */
        $signature = $this->pollSignature($conversationId);

        // Built after the watermark moves, so the badge and the read receipt
        // in this very response already reflect it.
        $view = $this->viewData($conversationId > 0 ? $conversationId : null, false);

        /* The composer is never part of an update — it holds whatever the
           reader has typed — so the draft a rejected submit left behind is
           not consumed here. The session is closed anyway, but relying on
           that would be relying on an accident. */
        $view['draft'] = '';

        $payload = [
            'ok'      => true,
            'changed' => true,
            'sig'     => $signature,
            'items'   => $this->fragment('_conversation_items.php', $view),
            'total'   => $this->fragment('_unread_total.php', $view),
        ];

        /* The thread is withheld while an editor is open: ?edit= means the
           reader is part-way through rewriting a message, and replacing the
           stream would throw their text away. The inbox still updates, so
           they can still see that something arrived. */
        if ($conversationId > 0 && !empty($view['conversation']) && (int) ($_GET['edit'] ?? 0) === 0) {
            $payload['stream'] = $this->fragment('_stream.php', $view);
        }

        $this->sendJson($payload);
    }

    /**
     * A cheap fingerprint of everything an update would change.
     *
     * This is the query that runs once a second during a hold, so it is two
     * statements of aggregates and no join to users, properties or anything
     * else the rendered panels need. Rendering happens once, after this has
     * moved.
     *
     * What it covers, and why each is here rather than left to the next page
     * load:
     *
     *   the inbox    a new message in any conversation, an archive, and this
     *                user's own watermark moving in another tab
     *   the thread   new messages, edits, withdrawals, reactions, and both
     *                participants' read watermarks — the last so a single
     *                tick becomes a double one without a refresh
     *
     * Presence is deliberately absent. last_seen_at moves on every request
     * either party makes, so including it would force a full re-render of
     * both panels every minute for a dot that is already refreshed whenever
     * anything real happens.
     */
    private function pollSignature(int $conversationId): string
    {
        $actor = communicationActor();
        if ($actor === null) {
            return 'gone';
        }

        // Prepared once per request and re-executed inside the hold, so a
        // fifteen-second wait re-parses nothing.
        static $inboxStmt = null, $threadStmt = null;

        $db = getDBConnection();

        if ($inboxStmt === null) {
            $inboxStmt = $db->prepare("
                SELECT CONCAT(
                           COALESCE(MAX(c.last_message_id), 0), '.',
                           COALESCE(MAX(UNIX_TIMESTAMP(c.last_message_at)), 0), '.',
                           COUNT(*), '.',
                           COALESCE(SUM(COALESCE(cp.last_read_message_id, 0)), 0), '.',
                           COALESCE(SUM(cp.archived_at IS NOT NULL), 0)
                       )
                FROM conversations c
                JOIN conversation_participants cp
                  ON cp.conversation_id = c.id
                 AND cp.user_id = :me
                 AND cp.is_active = 1
            ");
        }

        $inboxStmt->execute([':me' => $actor['id']]);
        $parts = ['i' . (string) $inboxStmt->fetchColumn()];

        if ($conversationId > 0) {
            if ($threadStmt === null) {
                $threadStmt = $db->prepare("
                    SELECT CONCAT(
                        (SELECT CONCAT(COALESCE(MAX(cm.id), 0), '.', COUNT(*), '.',
                                       COALESCE(MAX(UNIX_TIMESTAMP(cm.edited_at)), 0), '.',
                                       COALESCE(MAX(UNIX_TIMESTAMP(cm.deleted_at)), 0))
                           FROM conversation_messages cm
                          WHERE cm.conversation_id = :cid),
                        '/',
                        (SELECT CONCAT(COALESCE(MAX(mr.id), 0), '.', COUNT(*))
                           FROM message_reactions mr
                           JOIN conversation_messages cmr ON cmr.id = mr.message_id
                          WHERE cmr.conversation_id = :cid),
                        '/',
                        (SELECT COALESCE(SUM(COALESCE(cp2.last_read_message_id, 0)), 0)
                           FROM conversation_participants cp2
                          WHERE cp2.conversation_id = :cid)
                    )
                ");
            }

            $threadStmt->execute([':cid' => $conversationId]);
            $parts[] = 'c' . $conversationId . ':' . (string) $threadStmt->fetchColumn();
        }

        return implode('|', $parts);
    }

    /**
     * Render one of the messages partials on its own and return the markup.
     *
     * The partial is named from a fixed list at the call site, never from the
     * request, and is required from this module's own directory — there is no
     * path here a query string can reach.
     */
    private function fragment(string $partial, array $vars): string
    {
        extract($vars, EXTR_SKIP);

        ob_start();
        require VIEWS_PATH . '/messages/' . $partial;

        return (string) ob_get_clean();
    }

    /**
     * Answer with JSON and stop.
     *
     * nosniff and an explicit charset for the same reason the attachment
     * streamer sets them: this response carries names and message previews
     * that people typed, and a browser must never be left to guess what it is
     * looking at.
     */
    private function sendJson(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // ─── Writing ───────────────────────────────────────────────────────

    /**
     * Start a conversation, or land on the equivalent one that already exists.
     *
     * The recipient arrives as a number in a POST body, which is to say it
     * arrives untrusted. It is resolved against the live contact scope before
     * anything else happens, and then again inside Conversation::create().
     * A recipient the user may not reach produces no row and a refusal.
     */
    public function start(): void
    {
        requirePost();
        enforceCSRF();
        authorize('messages.create');

        $inbox       = APP_URL . '/index.php?page=messages';
        $recipientId = (int) ($_POST['recipient_id'] ?? 0);

        if (!canMessageUser($recipientId)) {
            setFlash('error', 'That recipient is not available to you. Choose someone from your contacts.');
            redirect($inbox . '&compose=1');
        }

        // Context is optional, and is validated rather than trusted: an
        // unauthorised property id is refused before a conversation exists,
        // not after. canCreateContextConversation() defers to the same
        // canViewProperty()/canViewLease()/canViewMaintenanceRequest() the
        // rest of the application uses.
        $propertyId    = (int) ($_POST['property_id'] ?? 0);
        $leaseId       = (int) ($_POST['lease_id'] ?? 0);
        $maintenanceId = (int) ($_POST['maintenance_request_id'] ?? 0);

        if (!canCreateContextConversation($propertyId, $leaseId, $maintenanceId)) {
            setFlash('error', 'That record is not available to you, so a conversation cannot be attached to it.');
            redirect($inbox . '&compose=1');
        }

        $type = 'direct';
        if ($maintenanceId > 0)   { $type = 'maintenance'; }
        elseif ($leaseId > 0)     { $type = 'rental'; }
        elseif ($propertyId > 0)  { $type = 'property'; }

        // create() returns the existing conversation when an equivalent one is
        // already open, so pressing New Message twice lands in the same thread
        // rather than splitting the correspondence in half.
        $conversationId = $this->conversations->create($type, [
            'property_id'            => $propertyId ?: null,
            'lease_id'               => $leaseId ?: null,
            'maintenance_request_id' => $maintenanceId ?: null,
        ], [$recipientId]);

        if ($conversationId <= 0) {
            setFlash('error', 'That conversation could not be started. Please try again.');
            redirect($inbox . '&compose=1');
        }

        // An opening message is optional. If one was written — text, files or
        // both — it goes through the same path as every other message, so it
        // gets the same validation, the same transaction and the same cleanup.
        $body  = trim((string) ($_POST['body'] ?? ''));
        $files = MessageAttachment::normalise($_FILES['attachments'] ?? null);

        if ($body !== '' || $files) {
            $errors = [];
            $stored = MessageAttachment::storeAll($files, $errors);

            if ($stored === null) {
                // The conversation exists and is fine; only the files were
                // refused. Land the user in it with the reason, rather than
                // throwing away a thread they meant to start.
                setFlash('error', implode(' ', $errors) ?: 'Those files could not be attached.');
                redirect($this->conversationUrl($conversationId));
            }

            $this->messages->create($conversationId, $body, null, $stored);
        }

        redirect($this->conversationUrl($conversationId));
    }

    /**
     * Send a message into an open conversation.
     *
     * The conversation id in the form is a hint about where the user thinks
     * they are, not a grant. canSendToConversation() decides, and it is
     * stricter than reading: the account must be live, the participant active,
     * the relationship intact and the conversation still open.
     */
    public function send(): void
    {
        requirePost();
        enforceCSRF();
        authorize('messages.send');

        $id   = (int) ($_POST['conversation_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));

        if (!canAccessConversation($id)) {
            authorizeRecord(false, 'conversation', $id);
        }

        $files = MessageAttachment::normalise($_FILES['attachments'] ?? null);
        $voice = MessageAttachment::normalise($_FILES['voice'] ?? null);

        // Which message this one answers. Validated inside
        // ConversationMessage::create(), which refuses a target from another
        // conversation — otherwise a hand-edited id would quote a line the
        // sender is not allowed to read.
        $replyTo = (int) ($_POST['reply_to'] ?? 0) ?: null;

        // A message must carry something, but text is no longer the only thing
        // that counts: a photograph of a broken pipe is a complete message, and
        // making someone type "see attached" beside it is busywork.
        if ($body === '' && !$files && !$voice) {
            setFlash('error', 'Write a message, attach a file or record a voice note before sending.');
            redirect($this->conversationUrl($id));
        }

        if (mb_strlen($body) > ConversationMessage::MAX_LENGTH) {
            // The draft is kept so a long message is not lost to a length
            // limit the writer only discovered on submit.
            $_SESSION['form_data'] = ['body' => $body];
            setFlash('error', sprintf(
                'That message is %s characters. The limit is %s — please shorten it.',
                number_format(mb_strlen($body)),
                number_format(ConversationMessage::MAX_LENGTH)
            ));
            redirect($this->conversationUrl($id));
        }

        if (!canSendToConversation($id)) {
            // Distinguished from a plain refusal because the commonest cause
            // is benign and worth naming: the conversation was closed, or the
            // business relationship behind it ended while the tab sat open.
            // Checked before the files are stored, so a refused send writes
            // nothing to the private store.
            setFlash('error', 'This conversation is no longer open for new messages.');
            redirect($this->conversationUrl($id));
        }

        // Validated and written to the private store *before* the transaction
        // opens: holding a database transaction across several megabytes of
        // file writes is how a busy server ends up waiting on locks. The
        // bargain is that whoever stores them owns the cleanup — storeAll()
        // unlinks its own partial set, and create() unlinks the lot if the
        // transaction fails.
        $errors = [];
        $stored = MessageAttachment::storeAll($files, $errors);

        if ($stored === null) {
            $_SESSION['form_data'] = ['body' => $body];
            setFlash('error', implode(' ', $errors) ?: 'Those files could not be attached.');
            redirect($this->conversationUrl($id));
        }

        // A voice note travels through the same storer with the audio policy
        // instead of the document one, so it gets the identical sequence — the
        // forged-upload guard, the sniffed type, the derived extension, the
        // unguessable private name. Nothing about it is trusted from the
        // browser, which matters more here than anywhere: the bytes were
        // produced by a script rather than chosen by a person.
        if ($voice) {
            $recorded = MessageAttachment::storeVoice($voice, $errors);
            if ($recorded === null) {
                MessageAttachment::discard(array_column($stored, 'file_path'));
                $_SESSION['form_data'] = ['body' => $body];
                setFlash('error', implode(' ', $errors) ?: 'That recording could not be attached.');
                redirect($this->conversationUrl($id));
            }
            $stored = array_merge($stored, $recorded);
        }

        if ($this->messages->create($id, $body, $replyTo, $stored) <= 0) {
            $_SESSION['form_data'] = ['body' => $body];
            setFlash('error', 'That message could not be sent. Please try again.');
        }

        redirect($this->conversationUrl($id));
    }

    /**
     * Deliver one attachment's bytes.
     *
     * The whole chain is walked, every link checked, before a byte is read:
     *
     *   attachment id → attachment row → its message → its conversation
     *                 → canAccessConversation() → the file on disk
     *
     * An id that does not exist and an id belonging to someone else's
     * conversation are answered identically for a signed-in user, so walking
     * the id space reveals nothing about what exists.
     *
     * Never renderPage(): the admin layout would emit HTML, and this response
     * is either a file or a bare status line.
     */
    public function attachment(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            $this->denyFile(405);
        }

        requireLogin();

        // Reading a file is reading the conversation, so it takes the same
        // permission the thread does.
        if (!can('messages.show')) {
            $this->denyFile(403);
        }

        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            $this->denyFile(404);
        }

        $attachment = (new MessageAttachment())->findForDelivery($id);
        if (!$attachment) {
            $this->denyFile(404);
        }

        // The live check, exactly the one the thread uses. A participant whose
        // relationship has ended keeps the conversation in their history and
        // loses the files with it.
        if (!canAccessConversation((int) $attachment['conversation_id'])) {
            $this->denyFile(403);
        }

        // A withdrawn message serves no files, for the same reason it serves
        // no body.
        if (!empty($attachment['message_deleted_at'])) {
            $this->denyFile(404);
        }

        // Resolution refuses traversal, absolute paths and symlinks pointing
        // out of the store. Null means "no such file" for any reason — which
        // includes a file deleted from disk behind the application's back.
        $full = documentStoragePath($attachment['stored_path'] ?? '');
        if ($full === null) {
            error_log('Message attachment ' . $id . ': unresolvable path '
                . ($attachment['stored_path'] ?? ''));
            $this->denyFile(404);
        }

        logAudit('downloaded_attachment', 'conversation',
            (int) $attachment['conversation_id'], '', (string) $attachment['original_name']);

        // Same streamer the document store uses: nosniff, a CSP that can load
        // and run nothing, no ranges, and inline only for types that cannot
        // script.
        // Both policies, because both produce attachments in a conversation:
        // the paperclip's images and documents, and the microphone's audio. A
        // type absent from this list still leaves — as opaque bytes, forced to
        // download — so the list controls what may be echoed back as itself,
        // not what may be fetched.
        streamStoredFile(
            $full,
            (string) $attachment['mime_type'],
            (string) $attachment['original_name'],
            array_merge(array_keys(MESSAGE_ATTACHMENT_TYPES), array_keys(MESSAGE_VOICE_TYPES)),
            MESSAGE_ATTACHMENT_INLINE_TYPES,
            ($_GET['disposition'] ?? '') === 'inline'
        );
    }

    /**
     * End a file request with a bare status.
     *
     * Deliberately terse and path-free: the reason a file is refused is not
     * the requester's business, and an error page here would be HTML emitted
     * where bytes were promised.
     */
    private function denyFile(int $code): never
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
        echo match ($code) {
            403     => 'You do not have permission to view this attachment.',
            405     => 'Method not allowed.',
            default => 'Attachment not found.',
        };
        exit;
    }

    /**
     * Add or take back a reaction.
     *
     * A toggle rather than an add and a remove: the control is one button and
     * modelling it as two actions would mean the page had to know which one to
     * send, which goes wrong the moment the same person has the thread open in
     * two tabs. MessageReaction::toggle() decides the direction from the
     * database, not from the request.
     */
    public function react(): void
    {
        requirePost();
        enforceCSRF();
        authorize('messages.show');

        $messageId = (int) ($_POST['message_id'] ?? 0);
        $message   = messageForAuthorship($messageId);

        if (!$message || !canAccessConversation((int) $message['conversation_id'])) {
            authorizeRecord(false, 'conversation_message', $messageId);
        }

        $result = (new MessageReaction())->toggle($messageId, (string) ($_POST['emoji'] ?? ''));
        if ($result !== true) {
            setFlash('error', (string) $result);
        }

        // Back to where they were reading, at the message they reacted to.
        redirect($this->conversationUrl((int) $message['conversation_id']) . '#m' . $messageId);
    }

    /**
     * Rewrite a message the signed-in user wrote.
     *
     * The message id arrives in a POST body and is therefore untrusted;
     * canEditMessage() resolves it back to its conversation and asks the live
     * authorization there. The conversation id is never taken from the
     * request at all — it is read from the message, so a mismatched pair
     * cannot send the redirect somewhere the user does not belong.
     */
    public function edit(): void
    {
        requirePost();
        enforceCSRF();
        authorize('messages.send');

        $messageId = (int) ($_POST['message_id'] ?? 0);
        $message   = messageForAuthorship($messageId);

        // An id that does not exist and one belonging to a conversation the
        // user cannot reach are answered identically.
        if (!$message || !canAccessConversation((int) $message['conversation_id'])) {
            authorizeRecord(false, 'conversation_message', $messageId);
        }

        $conversationId = (int) $message['conversation_id'];
        $result = $this->messages->edit($messageId, (string) ($_POST['body'] ?? ''));

        if ($result !== true) {
            $_SESSION['form_data'] = ['body' => (string) ($_POST['body'] ?? '')];
            setFlash('error', (string) $result);
            // Back into the editor, so the text is not lost to a refusal.
            redirect($this->conversationUrl($conversationId) . '&edit=' . $messageId);
        }

        redirect($this->conversationUrl($conversationId));
    }

    /**
     * Withdraw a message. Soft — see ConversationMessage::softDelete().
     */
    public function deleteMessage(): void
    {
        requirePost();
        enforceCSRF();
        authorize('messages.send');

        $messageId = (int) ($_POST['message_id'] ?? 0);
        $message   = messageForAuthorship($messageId);

        if (!$message || !canAccessConversation((int) $message['conversation_id'])) {
            authorizeRecord(false, 'conversation_message', $messageId);
        }

        $conversationId = (int) $message['conversation_id'];
        $result = $this->messages->softDelete($messageId);

        setFlash(
            $result === true ? 'success' : 'error',
            $result === true ? 'Message deleted. The other participant sees that it was withdrawn.' : (string) $result
        );

        redirect($this->conversationUrl($conversationId));
    }

    /**
     * Mark every conversation read.
     *
     * Offered from the inbox's overflow menu. Only this user's watermarks
     * move — see ConversationMessage::markAllRead(), where the UPDATE carries
     * `user_id = :me`.
     */
    public function readAll(): void
    {
        requirePost();
        enforceCSRF();
        authorize('messages.view');

        $n = $this->messages->markAllRead();
        setFlash('success', $n > 0
            ? 'All conversations marked as read.'
            : 'Everything was already read.');

        redirect(APP_URL . '/index.php?page=messages');
    }

    /** File a conversation away — for this participant, and nobody else. */
    public function archive(): void
    {
        $this->setArchived(true);
    }

    /** Bring it back. */
    public function unarchive(): void
    {
        $this->setArchived(false);
    }

    /**
     * The shared body of archive/unarchive.
     *
     * Conversation::setArchived() writes `user_id = <me>` into its UPDATE, so
     * there is no way to spell "archive this for everyone" through it. The
     * other participant's inbox is untouched and no message is deleted.
     */
    private function setArchived(bool $archived): void
    {
        requirePost();
        enforceCSRF();
        authorize('messages.archive');

        $id = (int) ($_POST['conversation_id'] ?? 0);
        authorizeRecord(canAccessConversation($id), 'conversation', $id);

        if ($this->conversations->setArchived($id, $archived)) {
            setFlash('success', $archived
                ? 'Conversation archived. It stays in the other participant\'s inbox.'
                : 'Conversation restored to your inbox.');
        }

        // Archiving is done *from* the inbox and returns there; unarchiving is
        // done to reopen something, so it returns to the conversation.
        redirect($archived
            ? APP_URL . '/index.php?page=messages'
            : $this->conversationUrl($id));
    }

    // ─── Rendering ─────────────────────────────────────────────────────

    /**
     * The whole workspace, with or without a conversation open.
     *
     * One method for both because it is one page. The alternative — two
     * near-identical render calls — is how the list and the thread drift out
     * of step.
     */
    private function render(?int $conversationId): void
    {
        $view = $this->viewData($conversationId);

        /* The fingerprint this page was built from, handed to the live
           updater so its first request can wait rather than answer "all of
           it changed" and re-render, a second after load, what the reader is
           already looking at. */
        $view['pollSignature'] = $this->pollSignature((int) $conversationId);

        renderPage(VIEWS_PATH . '/messages/index.php', $view);
    }

    /**
     * Everything the workspace renders from, as an array.
     *
     * Split out of render() so the live updater can build the same variables
     * and hand them to the same partials. The page and the update therefore
     * cannot disagree about what a row or a bubble looks like — there is one
     * query set and one renderer, and the only difference is whether a layout
     * is wrapped around the result.
     *
     * $withContacts is false for an update: the recipient list belongs to the
     * compose panel, which an update never touches, and fetching it would be
     * a query per refresh for markup nobody is going to see.
     *
     * @return array<string, mixed>
     */
    private function viewData(?int $conversationId, bool $withContacts = true): array
    {
        // Archived is a filter rather than a separate page, so the inbox query
        // needs to know which set it is looking at.
        $filter = uiPick($_GET['filter'] ?? '', array_keys(self::FILTERS)) ?: 'all';
        $search = trim((string) ($_GET['search'] ?? ''));

        $page   = max(1, (int) ($_GET['p'] ?? 1));
        $perPage = 20;

        $listOptions = [
            'archived' => $filter === 'archived',
            'unread'   => $filter === 'unread',
            'search'   => $search,
            'limit'    => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ];

        $conversations = $this->conversations->forUser($listOptions);
        $totalCount    = $this->conversations->countForUser($listOptions);

        // The compose panel and the thread panel occupy the same space, so
        // only one can be asked for. An open conversation wins: arriving at a
        // conversation should show it, whatever stale ?compose= is in the URL.
        $composing = $conversationId === null && ($_GET['compose'] ?? '') === '1';

        // Only ever the authorized contacts — never a user directory. Fetched
        // for the compose panel, and also to decide whether offering "New
        // Message" would lead anywhere at all.
        $contacts = ($withContacts && can('messages.create')) ? messageContacts() : [];

        // A conversation can be opened *about* something — "message my agent
        // about Villa V-102" — by arriving with the record in the query
        // string. It is validated here rather than carried through to the
        // form on trust, so an unauthorised id is dropped before it is ever
        // rendered, and dropped again on POST.
        $composeContext = $composing ? $this->composeContext() : [];

        /* The two URL fragments every link in the workspace is built from.
           They were computed in the view, which was fine while the view was
           the only renderer; now that the live updater renders the same
           partials on their own, they have to arrive with the data rather
           than be assembled around it. Carrying the filter, the search term
           and the page through every link is what keeps the left panel where
           the reader put it — opening a conversation from the Unread filter
           must not silently reset the list to All. */
        $base      = APP_URL . '/index.php?page=messages';
        $listQuery = array_filter([
            'filter' => $filter !== 'all' ? $filter : null,
            'search' => $search !== '' ? $search : null,
            'p'      => $page > 1 ? $page : null,
        ]);

        $view = [
            'base'           => $base,
            'listSuffix'     => $listQuery ? '&' . http_build_query($listQuery) : '',

            'conversations'  => $conversations,
            'totalCount'     => $totalCount,
            'page'           => $page,
            'perPage'        => $perPage,
            'filter'         => $filter,
            'filters'        => self::FILTERS,
            'search'         => $search,
            'contacts'       => $contacts,
            'contactSource'  => communicationContactSource(),
            'scopeHint'      => communicationScopeHint(),
            'emptyMessage'   => communicationEmptyScopeMessage(),
            // The explanation on its own, for the panel that already
            // states the situation in its heading.
            'emptyDetail'    => communicationEmptyScopeParts()[1],
            'composing'      => $composing,
            'composeContext' => $composeContext,
            'unreadTotal'    => $this->messages->totalUnreadFor(),

            'conversation'   => null,
            'participants'   => [],
            'counterpart'    => null,
            'thread'         => [],
            'earlierUrl'     => null,
            'canSend'        => false,
            'isArchived'     => false,
            'draft'          => $this->takeDraft(),

            'pageTitle'      => 'Messages',
            'pageSubtitle'   => communicationScopeHint(),
            'breadcrumbs'    => [['label' => 'Messages']],
        ];

        if ($conversationId !== null) {
            $view = array_merge($view, $this->threadView($conversationId));
        }

        return $view;
    }

    /**
     * Everything the right-hand panel needs for one conversation.
     *
     * @return array<string, mixed>
     */
    private function threadView(int $conversationId): array
    {
        $conversation = $this->conversations->findById($conversationId);
        if (!$conversation) {
            authorizeRecord(false, 'conversation', $conversationId);
        }

        $participants = $this->conversations->participants($conversationId);
        $me           = (int) ($_SESSION['user_id'] ?? 0);

        // The other party, for the header. A conversation always has one in
        // practice; the null guard is for the case where the counterpart's
        // account has been deleted outright.
        $counterpart = null;
        $mine        = null;
        foreach ($participants as $p) {
            if ((int) $p['user_id'] === $me) { $mine = $p; } elseif ($counterpart === null) { $counterpart = $p; }
        }

        // Pagination is URL-driven — ?before=<id> fetches the page above the
        // one on screen. No AJAX, and every paginated request re-runs the
        // access check, because forConversation() asks it itself.
        $before = (int) ($_GET['before'] ?? 0);

        // Searching within the conversation. The term only ever narrows this
        // thread — forConversation() keeps the conversation id in its WHERE
        // clause — so there is no way for a search to reach another one.
        $findTerm = trim((string) ($_GET['find'] ?? ''));

        $thread = $this->messages->forConversation($conversationId, $before ?: null,
            ConversationMessage::PAGE_SIZE, $findTerm);

        // "Load earlier" belongs to the unfiltered thread. Offering it beside
        // search results would page through matches as though they were the
        // conversation, which is a different thing than it appears to be.
        $earlierUrl = null;
        if ($findTerm === '' && $thread !== []
            && $this->messages->hasEarlierThan($conversationId, (int) $thread[0]['id'])) {
            $earlierUrl = $this->conversationUrl($conversationId) . '&before=' . (int) $thread[0]['id'];
        }

        /* Read receipts, from the watermark that already exists. A message of
           mine is "read" once the other participant's last_read_message_id has
           passed it — real state, not a guess, and the same number the unread
           count is computed from. Null means they have not opened the thread
           at all, which reads as delivered-but-unread. */
        $theirWatermark = (int) ($counterpart['last_read_message_id'] ?? 0);

        return [
            'conversation' => $conversation,
            'participants' => $participants,
            'counterpart'  => $counterpart,
            'theirWatermark' => $theirWatermark,
            'thread'       => $thread,
            'earlierUrl'   => $earlierUrl,
            'findTerm'     => $findTerm,
            // Which message the composer is answering, if any. Read from the
            // URL so opening a reply is a link and cancelling is the link
            // back; validated below before anything is rendered from it.
            'replyTo'      => $this->replyContext($conversationId, (int) ($_GET['reply'] ?? 0)),
            'quickReactions' => MESSAGE_REACTIONS,
            'canSend'      => canSendToConversation($conversationId),
            'isArchived'   => !empty($mine['archived_at']),
            'contextBlocks' => $this->conversationContext($conversation),
            'pageTitle'    => 'Messages',
            'breadcrumbs'  => [
                ['label' => 'Messages', 'url' => APP_URL . '/index.php?page=messages'],
                ['label' => $counterpart['full_name'] ?? 'Conversation'],
            ],
        ];
    }

    /**
     * The business records a conversation is about, as the thread header
     * renders them.
     *
     * Two rules govern this, and they pull in opposite directions:
     *
     *   The correspondence is history. What was said stays said, and stays
     *   readable, whatever happens to the property afterwards.
     *
     *   The context is not. A property that has since been sold, archived or
     *   reassigned must be shown as it is *now* — an interface that keeps
     *   insisting a sold flat is "Available" because that was true when the
     *   thread started is worse than one that shows no status at all.
     *
     * So every field here is read live, from Conversation::findById()'s joins
     * to the current rows. Nothing is snapshotted, and there is nowhere to
     * snapshot it to: the conversations table holds ids, not descriptions.
     *
     * A link is offered only where the destination page will actually open —
     * both halves, the module permission *and* the record scope, using the
     * same canViewProperty()/canViewLease()/canViewMaintenanceRequest() those
     * pages enforce for themselves. Offering a link to a 403 is worse than
     * offering no link.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conversationContext(array $conversation): array
    {
        $db     = getDBConnection();
        $blocks = [];

        if (!empty($conversation['maintenance_request_id'])) {
            $id   = (int) $conversation['maintenance_request_id'];
            $stmt = $db->prepare("
                SELECT m.*, p.owner_id AS property_owner_id, p.agent_id AS property_agent_id
                FROM maintenance_requests m
                JOIN properties p ON m.property_id = p.id
                WHERE m.id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            $blocks[] = [
                'kind'      => 'maintenance',
                'icon'      => 'bi-wrench-adjustable',
                'eyebrow'   => 'Maintenance request',
                'title'     => $conversation['issue_type'] ?: 'Maintenance request',
                'reference' => (string) ($conversation['request_code'] ?? ''),
                'status'    => (string) ($conversation['maintenance_status'] ?? ''),
                'priority'  => (string) ($conversation['maintenance_priority'] ?? ''),
                'image'     => null,
                'url'       => ($row && can('maintenance.show') && canViewMaintenanceRequest($row))
                    ? APP_URL . '/index.php?page=maintenance&action=show&id=' . $id
                    : null,
                'gone'      => $row === false,
            ];
        }

        if (!empty($conversation['lease_id'])) {
            $id   = (int) $conversation['lease_id'];
            $stmt = $db->prepare("
                SELECT l.*, p.owner_id AS property_owner_id, p.agent_id AS property_agent_id
                FROM leases l
                JOIN properties p ON l.property_id = p.id
                WHERE l.id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            $blocks[] = [
                'kind'      => 'lease',
                'icon'      => 'bi-file-earmark-text',
                'eyebrow'   => 'Tenancy',
                'title'     => (string) ($conversation['lease_code'] ?? 'Lease'),
                'reference' => ($conversation['lease_start'] ?? '') && ($conversation['lease_end'] ?? '')
                    ? formatDate($conversation['lease_start']) . ' – ' . formatDate($conversation['lease_end'])
                    : '',
                'status'    => (string) ($conversation['lease_status'] ?? ''),
                'priority'  => '',
                'image'     => null,
                'url'       => ($row && can('leases.show') && canViewLease($row))
                    ? APP_URL . '/index.php?page=leases&action=show&id=' . $id
                    : null,
                'gone'      => $row === false,
            ];
        }

        if (!empty($conversation['property_id'])) {
            $id   = (int) $conversation['property_id'];
            $stmt = $db->prepare("SELECT * FROM properties WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            // Archived is a state the register hides, so it is worth saying
            // rather than showing the status the property carried on its way
            // in — the reader would otherwise see "Available" for something
            // nobody can rent.
            $status = !empty($conversation['property_archived'])
                ? 'archived'
                : (string) ($conversation['property_status'] ?? '');

            $blocks[] = [
                'kind'      => 'property',
                'icon'      => 'bi-building',
                'eyebrow'   => 'Property',
                'title'     => (string) ($conversation['property_title'] ?? 'Property'),
                'reference' => trim(implode(' · ', array_filter([
                    $conversation['property_code'] ?? '',
                    $conversation['property_location'] ?? '',
                ]))),
                'status'    => $status,
                'priority'  => '',
                'image'     => $conversation['property_image'] ?? null,
                'url'       => ($row && can('properties.show') && canViewProperty($row))
                    ? APP_URL . '/index.php?page=properties&action=show&id=' . $id
                    : null,
                'gone'      => $row === false,
            ];
        }

        return $blocks;
    }

    /**
     * The business record a new conversation should be attached to, taken
     * from the query string and validated before it is shown.
     *
     * Returns an empty array unless every id supplied is one this user may
     * actually reach — so a hand-edited `&property_id=` is discarded silently
     * rather than rendered into a hidden field and refused later. The POST
     * validates it again regardless: this is for the reader's benefit, not the
     * boundary.
     *
     * @return array{type:string, property_id:int, lease_id:int, maintenance_request_id:int, label:string}|array{}
     */
    private function composeContext(): array
    {
        $propertyId    = (int) ($_GET['property_id'] ?? 0);
        $leaseId       = (int) ($_GET['lease_id'] ?? 0);
        $maintenanceId = (int) ($_GET['maintenance_request_id'] ?? 0);

        if ($propertyId <= 0 && $leaseId <= 0 && $maintenanceId <= 0) {
            return [];
        }
        if (!canCreateContextConversation($propertyId, $leaseId, $maintenanceId)) {
            return [];
        }

        // A conversation is attached to one record. When a maintenance
        // request is named, the property behind it is implied rather than
        // stored a second time — the request already knows its address, and
        // storing both would let the two disagree.
        $type = 'direct';
        if ($maintenanceId > 0)  { $type = 'maintenance'; }
        elseif ($leaseId > 0)    { $type = 'rental'; }
        elseif ($propertyId > 0) { $type = 'property'; }

        // Described through exactly the same resolver the thread header uses,
        // so "Regarding: Villa V-102 · Available" on the compose screen and
        // the card in the thread afterwards cannot describe the record
        // differently. The shape it expects is a conversation row, so one is
        // assembled from the ids and handed over.
        $blocks = $this->conversationContext($this->contextRowFor($propertyId, $leaseId, $maintenanceId));

        return [
            'type'                   => $type,
            'property_id'            => $propertyId,
            'lease_id'               => $leaseId,
            'maintenance_request_id' => $maintenanceId,
            'blocks'                 => $blocks,
        ];
    }

    /**
     * The columns conversationContext() reads, fetched for a set of ids that
     * has no conversation behind it yet.
     *
     * Exists so the compose screen and the thread header share one describer
     * rather than keeping two that drift. Reads only; every id has already
     * been through canCreateContextConversation() by the time this is called.
     *
     * @return array<string, mixed>
     */
    private function contextRowFor(int $propertyId, int $leaseId, int $maintenanceId): array
    {
        $db  = getDBConnection();
        $row = [
            'property_id'            => $propertyId ?: null,
            'lease_id'               => $leaseId ?: null,
            'maintenance_request_id' => $maintenanceId ?: null,
        ];

        if ($maintenanceId > 0) {
            $stmt = $db->prepare("SELECT request_code, issue_type, status, priority
                                  FROM maintenance_requests WHERE id = ?");
            $stmt->execute([$maintenanceId]);
            $m = $stmt->fetch() ?: [];
            $row['request_code']         = $m['request_code'] ?? null;
            $row['issue_type']           = $m['issue_type'] ?? null;
            $row['maintenance_status']   = $m['status'] ?? null;
            $row['maintenance_priority'] = $m['priority'] ?? null;
        }

        if ($leaseId > 0) {
            $stmt = $db->prepare("SELECT lease_code, status, start_date, end_date FROM leases WHERE id = ?");
            $stmt->execute([$leaseId]);
            $l = $stmt->fetch() ?: [];
            $row['lease_code']   = $l['lease_code'] ?? null;
            $row['lease_status'] = $l['status'] ?? null;
            $row['lease_start']  = $l['start_date'] ?? null;
            $row['lease_end']    = $l['end_date'] ?? null;
        }

        if ($propertyId > 0) {
            $stmt = $db->prepare("
                SELECT p.property_code, p.title, p.status, p.location, p.is_archived,
                       (SELECT pi.file_path FROM property_images pi
                         WHERE pi.property_id = p.id
                         ORDER BY pi.is_cover DESC, pi.sort_order ASC, pi.id ASC
                         LIMIT 1) AS cover
                FROM properties p WHERE p.id = ?
            ");
            $stmt->execute([$propertyId]);
            $p = $stmt->fetch() ?: [];
            $row['property_code']     = $p['property_code'] ?? null;
            $row['property_title']    = $p['title'] ?? null;
            $row['property_status']   = $p['status'] ?? null;
            $row['property_location'] = $p['location'] ?? null;
            $row['property_archived'] = $p['is_archived'] ?? 0;
            $row['property_image']    = $p['cover'] ?? null;
        }

        return $row;
    }

    /**
     * The message the composer is answering, described for the reply banner.
     *
     * Returns null unless the id names a message *in this conversation* — the
     * check that stops `&reply=<id>` quoting a line from a thread the sender
     * cannot read. ConversationMessage::create() applies the same rule again
     * on POST, so this is for the reader's benefit rather than the boundary.
     *
     * A withdrawn message cannot be replied to: there is nothing left to
     * quote, and showing "This message was deleted" above a composer would
     * only puzzle the person writing.
     *
     * @return array<string, mixed>|null
     */
    private function replyContext(int $conversationId, int $messageId): ?array
    {
        if ($messageId <= 0) {
            return null;
        }

        $stmt = getDBConnection()->prepare("
            SELECT m.id, m.body, m.sender_id, m.deleted_at,
                   u.full_name AS sender_name,
                   (SELECT COUNT(*) FROM message_attachments a WHERE a.message_id = m.id) AS attachments
            FROM conversation_messages m
            LEFT JOIN users u ON m.sender_id = u.id
            WHERE m.id = ? AND m.conversation_id = ?
        ");
        $stmt->execute([$messageId, $conversationId]);
        $row = $stmt->fetch();

        if (!$row || !empty($row['deleted_at'])) {
            return null;
        }

        $row['is_mine'] = (int) $row['sender_id'] === (int) ($_SESSION['user_id'] ?? 0);

        return $row;
    }

    /** The canonical URL for a conversation. One spelling, used everywhere. */
    private function conversationUrl(int $conversationId): string
    {
        return APP_URL . '/index.php?page=messages&action=show&id=' . $conversationId;
    }

    /**
     * A message kept back from a rejected submit, taken once so a refresh
     * shows a clean composer.
     */
    private function takeDraft(): string
    {
        $draft = (string) ($_SESSION['form_data']['body'] ?? '');
        unset($_SESSION['form_data']);
        return $draft;
    }
}
