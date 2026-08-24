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

        // An opening message is optional. If one was typed it is sent through
        // the same path as every other message, so it gets the same
        // validation and the same transaction.
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body !== '') {
            $this->messages->create($conversationId, $body);
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

        // Validated here so the message says what is wrong, and again in the
        // model so a caller that forgets cannot write a blank row.
        if ($body === '') {
            setFlash('error', 'Write a message before sending.');
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
            setFlash('error', 'This conversation is no longer open for new messages.');
            redirect($this->conversationUrl($id));
        }

        if ($this->messages->create($id, $body) <= 0) {
            $_SESSION['form_data'] = ['body' => $body];
            setFlash('error', 'That message could not be sent. Please try again.');
        }

        redirect($this->conversationUrl($id));
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
        $thread = $this->messages->forConversation($conversationId, $before ?: null);

        $earlierUrl = null;
        if ($thread !== [] && $this->messages->hasEarlierThan($conversationId, (int) $thread[0]['id'])) {
            $earlierUrl = $this->conversationUrl($conversationId) . '&before=' . (int) $thread[0]['id'];
        }

        return [
            'conversation' => $conversation,
            'participants' => $participants,
            'counterpart'  => $counterpart,
            'thread'       => $thread,
            'earlierUrl'   => $earlierUrl,
            'canSend'      => canSendToConversation($conversationId),
            'isArchived'   => !empty($mine['archived_at']),
            'contextLinks' => $this->contextLinks($conversation),
            'pageTitle'    => 'Messages',
            'breadcrumbs'  => [
                ['label' => 'Messages', 'url' => APP_URL . '/index.php?page=messages'],
                ['label' => $counterpart['full_name'] ?? 'Conversation'],
            ],
        ];
    }

    /**
     * Links to the records a conversation is about — but only the ones this
     * user may actually open.
     *
     * A context is shown because the conversation carries it; a *link* is
     * offered only where the underlying page will not refuse them. Offering a
     * link to a 403 is worse than offering no link.
     *
     * @return array<int, array{label:string, url:?string, icon:string}>
     */
    private function contextLinks(array $conversation): array
    {
        $links = [];

        if (!empty($conversation['property_id'])) {
            $links[] = [
                'label' => trim(($conversation['property_code'] ?? '') . ' · ' . ($conversation['property_title'] ?? ''), ' ·'),
                'url'   => can('properties.show')
                    ? APP_URL . '/index.php?page=properties&action=show&id=' . (int) $conversation['property_id']
                    : null,
                'icon'  => 'bi-building',
            ];
        }

        if (!empty($conversation['lease_id'])) {
            $links[] = [
                'label' => (string) ($conversation['lease_code'] ?? 'Lease'),
                'url'   => can('leases.show')
                    ? APP_URL . '/index.php?page=leases&action=show&id=' . (int) $conversation['lease_id']
                    : null,
                'icon'  => 'bi-file-earmark-text',
            ];
        }

        if (!empty($conversation['maintenance_request_id'])) {
            $links[] = [
                'label' => trim(($conversation['request_code'] ?? '') . ' · ' . ($conversation['issue_type'] ?? ''), ' ·'),
                'url'   => can('maintenance.show')
                    ? APP_URL . '/index.php?page=maintenance&action=show&id=' . (int) $conversation['maintenance_request_id']
                    : null,
                'icon'  => 'bi-wrench-adjustable',
            ];
        }

        return $links;
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

        $db    = getDBConnection();
        $type  = 'direct';
        $label = '';

        if ($maintenanceId > 0) {
            $type = 'maintenance';
            $stmt = $db->prepare("SELECT request_code, issue_type FROM maintenance_requests WHERE id = ?");
            $stmt->execute([$maintenanceId]);
            $row   = $stmt->fetch() ?: [];
            $label = trim(($row['issue_type'] ?? 'Request') . ' · ' . ($row['request_code'] ?? ''), ' ·');
        } elseif ($leaseId > 0) {
            $type = 'rental';
            $stmt = $db->prepare("SELECT lease_code FROM leases WHERE id = ?");
            $stmt->execute([$leaseId]);
            $label = (string) ($stmt->fetchColumn() ?: 'Lease');
        } elseif ($propertyId > 0) {
            $type = 'property';
            $stmt = $db->prepare("SELECT property_code, title FROM properties WHERE id = ?");
            $stmt->execute([$propertyId]);
            $row   = $stmt->fetch() ?: [];
            $label = trim(($row['property_code'] ?? '') . ' · ' . ($row['title'] ?? ''), ' ·');
        }

        return [
            'type'                   => $type,
            'property_id'            => $propertyId,
            'lease_id'               => $leaseId,
            'maintenance_request_id' => $maintenanceId,
            'label'                  => $label,
        ];
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
