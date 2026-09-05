<?php
/**
 * The inbox panel: search, filters, and the conversation list.
 *
 * Everything here is a link or a plain GET form. There is no client-side
 * filtering, and no conversation is fetched that the access layer did not
 * return — Conversation::forUser() applies conversationViewScope() itself, so
 * this file renders what it is given and asks no questions of its own.
 *
 * Expects: $conversations $totalCount $page $perPage $filter $filters $search
 *          $base $listSuffix $conversation $contacts $unreadTotal
 */
$openId    = (int) ($conversation['id'] ?? 0);
$activeF   = $filter ?? 'all';
$totalPages = (int) ceil(($totalCount ?? 0) / max(1, $perPage ?? 20));
?>
<div class="msg__list-head">
    <div class="msg__list-title">
        <h1 class="msg__list-heading">Messages</h1>

        <?php /* The count is announced as a phrase rather than a bare number:
                 a screen reader meeting "3" on its own has been told nothing.
                 role=status so a changed total is spoken without stealing
                 focus from whatever the reader was doing. */ ?>
        <span class="msg__total" role="status" aria-atomic="true" data-msg-total>
            <?php require __DIR__ . '/_unread_total.php'; ?>
        </span>
    </div>

    <?php /* The overflow menu replaces a permanent New message button: the
             inbox header should say what this is, not carry the widest control
             on the panel. A <details>, so it opens with a click and with Enter
             and needs no script; every item is a real link or a signed POST.

             Nothing dead is offered — "Starred" is absent because there is no
             star in the schema, and a menu item that cannot work is worse than
             one that is missing. */ ?>
    <details class="msg__more" data-msg-more>
        <summary class="msg__round msg__round--sm" title="Inbox options">
            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
            <span class="sr-only">Inbox options</span>
        </summary>

        <div class="msg__more-menu" role="menu">
            <?php if (($unreadTotal ?? 0) > 0): ?>
                <form method="post" action="<?= APP_URL ?>/index.php?page=messages&amp;action=read-all">
                    <?= csrfField() ?>
                    <button class="msg__more-item" type="submit" role="menuitem">
                        <i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read
                    </button>
                </form>
            <?php else: ?>
                <span class="msg__more-item is-disabled" role="menuitem" aria-disabled="true">
                    <i class="bi bi-check2-all" aria-hidden="true"></i> Nothing unread
                </span>
            <?php endif; ?>

            <a class="msg__more-item" role="menuitem"
               href="<?= APP_URL ?>/index.php?page=messages&amp;filter=archived">
                <i class="bi bi-archive" aria-hidden="true"></i> Archived conversations
            </a>

            <?php if (!empty($contacts) && can('messages.create')): ?>
                <a class="msg__more-item" role="menuitem" href="<?= sanitize($base . '&compose=1') ?>">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i> New message
                </a>
            <?php endif; ?>
        </div>
    </details>
</div>

<?php /* One rounded field. The separate Search button is gone: the form still
         submits on Enter, which is how anyone actually searches, and a button
         as wide as the field was the loudest thing on the panel. */ ?>
<form class="msg__search" method="get" action="<?= APP_URL ?>/index.php" role="search">
    <input type="hidden" name="page" value="messages">
    <?php if ($activeF !== 'all'): ?>
        <input type="hidden" name="filter" value="<?= sanitize($activeF) ?>">
    <?php endif; ?>

    <label class="sr-only" for="msgSearch">Search conversations by name, property or request</label>
    <div class="input-icon">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" type="search" id="msgSearch" name="search"
               value="<?= sanitize($search ?? '') ?>"
               placeholder="Search name, property or request">
    </div>
    <button class="sr-only" type="submit">Search conversations</button>
</form>

<?php /* Filters are links, not buttons, because each is a real URL that can be
         bookmarked and reached with Back. aria-current marks the one in force. */ ?>
<nav class="msg__filters" aria-label="Filter conversations">
    <?php foreach (($filters ?? []) as $key => $label): ?>
        <?php
        $q = array_filter([
            'page'   => 'messages',
            'filter' => $key !== 'all' ? $key : null,
            'search' => ($search ?? '') !== '' ? $search : null,
        ]);
        $isOn = $key === $activeF;
        ?>
        <a class="msg__filter<?= $isOn ? ' is-active' : '' ?>"
           href="<?= APP_URL ?>/index.php?<?= sanitize(http_build_query($q)) ?>"
           <?= $isOn ? 'aria-current="true"' : '' ?>><?= sanitize($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php /* The rows and the pager live in _conversation_items.php, because the
         live updater re-renders exactly this much and swaps it in. Everything
         above stays put: replacing the search field would take the caret out
         of a search someone was in the middle of typing. */ ?>
<div id="msgItems" data-msg-items>
    <?php require __DIR__ . '/_conversation_items.php'; ?>
</div>
