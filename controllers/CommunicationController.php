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
 * the application. There is no JSON endpoint and no AJAX: a message is sent by
 * submitting a form, and the page that comes back is the conversation.
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
        $contacts = can('messages.create') ? messageContacts() : [];

        // A conversation can be opened *about* something — "message my agent
        // about Villa V-102" — by arriving with the record in the query
        // string. It is validated here rather than carried through to the
        // form on trust, so an unauthorised id is dropped before it is ever
        // rendered, and dropped again on POST.
        $composeContext = $composing ? $this->composeContext() : [];

        $view = [
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

        renderPage(VIEWS_PATH . '/messages/index.php', $view);
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
