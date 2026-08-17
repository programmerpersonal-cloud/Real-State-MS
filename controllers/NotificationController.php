<?php
/**
 * Notification Controller — in-app notification inbox.
 *
 * All three actions ask for the same permission. Marking your own notification
 * read is not a capability worth separating from reading it — what stops one
 * user touching another's is the `user_id = ?` on every statement below, which
 * is record scoping rather than a permission question.
 */
class NotificationController
{
    /** The two things anyone ever wants to see. */
    public const STATES = [
        'unread' => 'Unread',
        'read'   => 'Read',
    ];

    public function index(): void
    {
        authorize('notifications.view');
        $db = getDBConnection();

        // Checked against the same list the pills are built from, so a
        // hand-edited value is an absent filter rather than an empty inbox.
        $state = uiPick($_GET['state'] ?? '', array_keys(self::STATES));
        $where = 'user_id = :uid';
        if ($state === 'unread') {
            $where .= ' AND is_read = 0';
        } elseif ($state === 'read') {
            $where .= ' AND is_read = 1';
        }

        // Both figures in one pass: the pill counts, and the page count the
        // list below is paginated by. The inbox used to stop dead at 100 rows
        // with nothing to say it had.
        $stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(is_read = 0) AS unread
                              FROM notifications WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $tally  = $stmt->fetch() ?: [];
        $total  = (int) ($tally['total'] ?? 0);
        $unread = (int) ($tally['unread'] ?? 0);

        $counts = ['unread' => $unread, 'read' => $total - $unread];
        $shown  = $state === '' ? $total : $counts[$state];

        $page   = max(1, (int) ($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;

        $stmt = $db->prepare("SELECT * FROM notifications WHERE {$where}
                              ORDER BY is_read ASC, created_at DESC
                              LIMIT :l OFFSET :o");
        $stmt->bindValue(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':l', ITEMS_PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();

        renderPage(VIEWS_PATH . '/admin/notifications/index.php', [
            'notifications' => $stmt->fetchAll(),
            'states'        => self::STATES,
            'state'         => $state,
            'counts'        => $counts,
            'unreadCount'   => $unread,
            'totalCount'    => $shown,
            'page'          => $page,
            'totalPages'    => (int) ceil($shown / ITEMS_PER_PAGE),
            'pageTitle'     => 'Notifications',
            'pageSubtitle'  => 'What the system has told you about your properties, tenancies and jobs.',
            'breadcrumbs'   => [['label' => 'Notifications']],
        ]);
    }

    /**
     * Mark one notification read.
     *
     * Was a GET link, which meant a prefetcher or a link scanner could clear
     * someone's inbox for them. It now takes a CSRF-signed POST; a stray GET
     * falls through to the redirect and changes nothing.
     */
    public function markRead(): void
    {
        authorize('notifications.view');
        $id = (int)($_GET['id'] ?? 0);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            getDBConnection()
                ->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
                ->execute([$id, $_SESSION['user_id']]);
        }
        redirect($this->backUrl());
    }

    public function readAll(): void
    {
        authorize('notifications.view');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            getDBConnection()->prepare("UPDATE notifications SET is_read=1 WHERE user_id = ?")
                             ->execute([$_SESSION['user_id']]);
            setFlash('success', 'All notifications marked as read.');
        }
        redirect(APP_URL . '/index.php?page=notifications');
    }

    /**
     * Where to send the user back to.
     *
     * The referrer decided this before, unchecked, which made the action an
     * open redirect: a link from anywhere could bounce someone off this app to
     * an address of the sender's choosing. A referrer is only honoured now if
     * it points back into this installation.
     */
    private function backUrl(): string
    {
        $home    = APP_URL . '/index.php?page=notifications';
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        return $referer !== '' && str_starts_with($referer, APP_URL . '/') ? $referer : $home;
    }
}
