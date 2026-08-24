<?php
/**
 * Messages — the communication workspace.
 *
 * One page, two panels, and which panel is filled is a property of the URL:
 *
 *   ?page=messages                          the inbox, nothing open
 *   ?page=messages&compose=1                the inbox, recipient picker open
 *   ?page=messages&action=show&id=7         the inbox, conversation 7 open
 *
 * That is what makes the mobile takeover work. On a narrow screen the panels
 * do not coexist: the list fills the screen until a conversation is opened,
 * then the thread does, and the browser's own Back button returns to the list
 * because going back is going back a URL. No JavaScript is involved in the
 * navigation at all — see assets/css/pages/messages.css, which does the whole
 * takeover with two display rules.
 *
 * Expects from CommunicationController::render():
 *   $conversations $totalCount $page $perPage $filter $filters $search
 *   $contacts $contactSource $scopeHint $emptyMessage $composing $unreadTotal
 *   $conversation $participants $counterpart $thread $earlierUrl $canSend
 *   $isArchived $draft  and, when a conversation is open, $contextLinks
 */
$pageStyles   = ['pages/messages'];
$extraScripts = ['messages'];

$base       = APP_URL . '/index.php?page=messages';
$isOpen     = !empty($conversation);
$composing  = !empty($composing);
$hasContacts = !empty($contacts);

// One modifier drives the whole narrow-screen takeover: the detail side is
// showing, so the list steps aside. True for a conversation and for the
// recipient picker, because both occupy the same column.
$detailOpen = $isOpen || $composing;

/* Carrying the current filter and search through every link in the workspace
   keeps the left panel where the user put it — opening a conversation from
   the Unread filter must not silently reset the list to All. */
$listQuery = array_filter([
    'filter' => ($filter ?? 'all') !== 'all' ? $filter : null,
    'search' => ($search ?? '') !== '' ? $search : null,
    'p'      => ($page ?? 1) > 1 ? $page : null,
]);
$listSuffix = $listQuery ? '&' . http_build_query($listQuery) : '';
?>

<div class="msg<?= $detailOpen ? ' msg--detail' : '' ?>">

    <?php /* ─── Left: the inbox ───────────────────────────────────── */ ?>
    <section class="msg__list" aria-label="Conversations">
        <?php require __DIR__ . '/_conversation_list.php'; ?>
    </section>

    <?php /* ─── Right: thread, recipient picker, or nothing yet ───── */ ?>
    <section class="msg__panel" aria-label="Conversation">
        <?php if ($composing): ?>
            <?php require __DIR__ . '/_new_conversation.php'; ?>

        <?php elseif ($isOpen): ?>
            <?php require __DIR__ . '/_thread.php'; ?>

        <?php elseif (empty($conversations) && ($search ?? '') === '' && ($filter ?? 'all') === 'all'): ?>
            <?php /* Nothing in the inbox and nothing open. Say which of the two
                     situations this is — having no conversations yet is very
                     different from having no one to talk to, and the second
                     cannot be fixed by the reader. */ ?>
            <div class="msg__blank">
                <?= uiEmptyState([
                    'icon'  => $hasContacts ? 'bi-chat-dots' : 'bi-person-x',
                    'title' => $hasContacts ? 'No conversations yet' : 'No contacts available',
                    'desc'  => $hasContacts ? $emptyMessage : $emptyMessage,
                    'actions' => $hasContacts ? [[
                        'label' => 'New message',
                        'icon'  => 'bi-pencil-square',
                        'url'   => $base . '&compose=1',
                        'can'   => 'messages.create',
                    ]] : [],
                ]) ?>
            </div>

        <?php else: ?>
            <div class="msg__blank">
                <?= uiEmptyState([
                    'icon'  => 'bi-chat-square-text',
                    'title' => 'Select a conversation',
                    'desc'  => 'Choose a conversation from the list to read it, or start a new one.',
                    'actions' => $hasContacts ? [[
                        'label' => 'New message',
                        'icon'  => 'bi-pencil-square',
                        'url'   => $base . '&compose=1',
                        'can'   => 'messages.create',
                    ]] : [],
                ]) ?>
            </div>
        <?php endif; ?>
    </section>
</div>
