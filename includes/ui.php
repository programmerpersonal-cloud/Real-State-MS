<?php
/**
 * UI component helpers.
 *
 * The pieces of markup that repeat on every list and detail page — a status
 * pill, a person cell, a row-action menu, an empty state, a sortable column
 * header, a field error. They live here rather than in each view because the
 * alternative is what this codebase had: twenty-five hand-built tables that
 * agreed on nothing, and a badge whose padding depended on which page you
 * were looking at.
 *
 * Everything here returns a string rather than echoing, so a caller can put
 * it in a variable, pass it to another helper, or drop it straight into a
 * template with <?= ?>. Every one of them escapes its own input: these are
 * called with database values in the argument position, so escaping at the
 * call site would be one forgotten sanitize() away from an injection.
 *
 * Tone and colour are never chosen here. getStatusBadgeClass() in
 * functions.php already owns the status → tone map that the dashboard ring,
 * the charts and the badges all read; this file asks it rather than keeping
 * a second copy that would drift.
 */

/**
 * A machine token as a human label: `under_review` → "Under Review".
 *
 * Words that a naive ucwords() gets wrong are spelled out. The rest fall
 * through to the general rule, so a status added to an enum next month
 * still renders as something a person can read without a code change.
 */
function uiLabel(string $token): string
{
    static $special = [
        'in_progress'  => 'In Progress',
        'under_review' => 'Under Review',
        'on_hold'      => 'On Hold',
        'partial'      => 'Partially Paid',
        'new'          => 'New',
        'rent'         => 'For Rent',
        'sale'         => 'For Sale',
        'both'         => 'Rent or Sale',
        'e_transfer'   => 'E-Transfer',
        'bank_transfer'=> 'Bank Transfer',
        'mobile_money' => 'Mobile Money',
        'id_card'      => 'ID Card',
        'na'           => 'N/A',
    ];

    $key = strtolower(trim($token));
    if ($key === '') {
        return '';
    }

    return $special[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));
}

/**
 * The one status pill, used for every status in the system.
 *
 * Built on .status rather than .badge because a pill that carries a coloured
 * dot survives being read by someone who cannot separate the tones: the dot
 * gives the shape a second channel, and the label is always spelled out
 * rather than implied by colour alone.
 *
 * @param string      $status Raw enum value from the database.
 * @param string|null $label  Override, when the record's own wording is better.
 */
function uiStatus(string $status, ?string $label = null): string
{
    $status = trim($status);
    if ($status === '') {
        return '<span class="status status--muted">—</span>';
    }

    // The tone map already exists and is read by the charts and the ring —
    // reuse it so a status can never be green here and amber there.
    $tone = substr(getStatusBadgeClass(strtolower($status)), strlen('badge--'));
    $text = $label ?? uiLabel($status);

    return '<span class="status status--' . sanitize($tone) . '">'
         . '<span class="status__dot" aria-hidden="true"></span>'
         . sanitize($text)
         . '</span>';
}

/**
 * The priority pill.
 *
 * Priority is not a status and must not be read as one — a row can be
 * "urgent" and "completed" at the same time. It wears the same pill shape so
 * the table stays one visual family, but carries a direction icon rather than
 * a dot, so the two columns are still distinguishable at a glance and to
 * anyone who cannot separate the tones.
 *
 * Its own map, deliberately: getStatusBadgeClass() answers a different
 * question, and `low`/`medium`/`high` already mean customer risk over there.
 */
function uiPriority(string $priority): string
{
    static $map = [
        'urgent' => ['danger',  'bi-exclamation-octagon-fill'],
        'high'   => ['orange',  'bi-arrow-up'],
        'medium' => ['warning', 'bi-dash-lg'],
        'low'    => ['info',    'bi-arrow-down'],
    ];

    $key = strtolower(trim($priority));
    if ($key === '') {
        return '<span class="status status--muted">—</span>';
    }
    [$tone, $icon] = $map[$key] ?? ['muted', 'bi-dash'];

    return '<span class="status status--' . $tone . '">'
         . '<i class="bi ' . $icon . '" aria-hidden="true"></i>'
         . sanitize(uiLabel($key))
         . '</span>';
}

/**
 * A person's initials — one or two letters, upper case.
 *
 * "Maryan Aba-Nuur Maxamed" → MM. First and last word, so a middle name does
 * not displace the surname; hyphens and punctuation are stepped over rather
 * than counted as letters, and a single-word name yields one letter.
 *
 * mb_* throughout because the names in this database are not all ASCII and a
 * byte-wise substr would cut a multi-byte character in half.
 */
function uiInitials(?string $name): string
{
    $words = preg_split('/[\s\-_.]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) {
        return '?';
    }

    $first = mb_substr($words[0], 0, 1);
    $last  = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

    return mb_strtoupper($first . $last);
}

/**
 * An avatar that works when there is no avatar.
 *
 * Only 2 of the 24 accounts in this system carry an uploaded photograph, so a
 * grid of identical default-avatar silhouettes is the normal case rather than
 * the exception — and it tells the reader nothing about which row is which.
 * Initials on a tinted disc do, and they cost no request.
 *
 * The tint is derived from the name, so the same person is the same colour on
 * every screen and two people in one list are unlikely to collide. It is
 * decoration only: `aria-hidden`, because the name it stands for is always
 * rendered beside it, and colour never carries meaning here.
 *
 * @param string $size 'sm' | 'md' | 'lg'
 */
function uiAvatar(?string $name, ?string $avatar = null, string $size = 'md'): string
{
    $class = 'avatar avatar--' . preg_replace('/[^a-z]/', '', $size);

    if ($avatar && is_file(BASE_PATH . '/' . $avatar)) {
        return '<img src="' . sanitize(getAvatarUrl($avatar)) . '" alt="" aria-hidden="true"'
             . ' class="' . $class . '" loading="lazy">';
    }

    // Six hues, picked by a stable hash of the name. crc32 rather than a sum
    // of bytes so "Ali" and "Ila" do not land on the same colour.
    $tone = crc32(mb_strtolower(trim((string) $name))) % 6;

    return '<span class="' . $class . ' avatar--t' . $tone . '" aria-hidden="true">'
         . sanitize(uiInitials($name)) . '</span>';
}

/**
 * Avatar + name + sub-line: the shape a person takes in a table row.
 *
 * One helper for users, customers, owners and agents, so the avatar is the
 * same size and the name the same weight on every page that lists people.
 * The sub-line is optional because not every table has room for one.
 */
function uiPersonCell(string $name, ?string $avatar = null, string $meta = '', ?string $url = null): string
{
    $name = trim($name) !== '' ? $name : '—';

    $inner = '<img src="' . sanitize(getAvatarUrl($avatar)) . '" alt="" class="person__avatar" loading="lazy">'
           . '<span class="person__text">'
           . '<span class="person__name">' . sanitize($name) . '</span>'
           . ($meta !== '' ? '<span class="person__meta">' . sanitize($meta) . '</span>' : '')
           . '</span>';

    return $url
        ? '<a class="person" href="' . sanitize($url) . '">' . $inner . '</a>'
        : '<span class="person">' . $inner . '</span>';
}

/**
 * The compact row-action menu — a single ⋮ button that opens a list.
 *
 * Replaces the two-to-five icon buttons that used to sit in every Actions
 * column. Those columns were the widest thing in most tables and the least
 * readable: an eye, a pencil and a power symbol side by side say nothing
 * about what they do until you hover each one.
 *
 * An action is skipped when its `can` permission is not held. That is a
 * display decision only — the controller behind the URL still calls
 * authorize(), which is what actually refuses the request. Hiding a control
 * the user may not use is courtesy; it is never the check.
 *
 * Each action: [
 *   'label'   => 'Edit',                    required
 *   'icon'    => 'bi-pencil',
 *   'url'     => '…',                       required unless 'method' is post
 *   'can'     => 'properties.edit',         permission gate
 *   'danger'  => true,                      red styling, pushed below a divider
 *   'method'  => 'post',                    submit a CSRF-signed form instead
 *   'fields'  => ['id' => 7],               extra hidden inputs, POST only
 *   'confirm' => ['title' => …, 'body' => …, 'action' => 'Delete', 'record' => …],
 *   'attrs'   => ['data-modal-open' => 'id'],
 * ]
 *
 * @param array<int, array<string, mixed>> $actions
 */
function uiRowActions(array $actions, string $label = 'Actions'): string
{
    $allowed = array_values(array_filter(
        $actions,
        static fn(array $a): bool => empty($a['can']) || can((string) $a['can'])
    ));
    if (!$allowed) {
        return '';
    }

    // Destructive actions always sit last, behind a divider, whatever order
    // the caller listed them in — so "Delete" is never adjacent to the item
    // someone actually meant to click.
    usort($allowed, static fn(array $a, array $b): int
        => (int) !empty($a['danger']) <=> (int) !empty($b['danger']));

    $items       = '';
    $dividerDone = false;
    foreach ($allowed as $a) {
        if (!empty($a['danger']) && !$dividerDone) {
            $items .= '<div class="row-menu__divider" role="separator"></div>';
            $dividerDone = true;
        }
        $items .= uiActionItem($a);
    }

    $id = 'rowmenu-' . substr(md5(serialize(array_column($allowed, 'label')) . random_int(0, PHP_INT_MAX)), 0, 8);

    return '<div class="row-menu" data-row-menu>'
         . '<button type="button" class="row-menu__trigger" aria-haspopup="menu" aria-expanded="false"'
         . ' aria-controls="' . $id . '" aria-label="' . sanitize($label) . '">'
         . '<i class="bi bi-three-dots-vertical" aria-hidden="true"></i>'
         . '</button>'
         . '<div class="row-menu__list" id="' . $id . '" role="menu" hidden>' . $items . '</div>'
         . '</div>';
}

/**
 * One entry inside a row-action menu.
 *
 * A GET action is a link. A POST action is a real form with a CSRF token,
 * because anything that changes state must not be reachable by a link that
 * a prefetcher or a crawler could follow.
 *
 * `fields` become hidden inputs in that form. Controllers that already read
 * their record id out of the request body keep working unchanged — the menu
 * adapts to them rather than the other way round, which is what stops a
 * presentation change from turning into a route change.
 */
function uiActionItem(array $a): string
{
    $classes = 'row-menu__item' . (!empty($a['danger']) ? ' row-menu__item--danger' : '');
    $icon    = !empty($a['icon'])
        ? '<i class="bi ' . sanitize((string) $a['icon']) . '" aria-hidden="true"></i>'
        : '';
    $text    = $icon . '<span>' . sanitize((string) ($a['label'] ?? 'Action')) . '</span>';

    $attrs = '';
    foreach (($a['attrs'] ?? []) as $k => $v) {
        $attrs .= ' ' . sanitize((string) $k) . '="' . sanitize((string) $v) . '"';
    }
    $attrs .= uiConfirmAttrs($a['confirm'] ?? null);

    if (strtolower((string) ($a['method'] ?? 'get')) === 'post') {
        $hidden = '';
        foreach (($a['fields'] ?? []) as $name => $value) {
            $hidden .= '<input type="hidden" name="' . sanitize((string) $name) . '"'
                     . ' value="' . sanitize((string) $value) . '">';
        }

        return '<form method="POST" action="' . sanitize((string) ($a['url'] ?? '#')) . '" class="row-menu__form" role="none">'
             . csrfField() . $hidden
             . '<button type="submit" class="' . $classes . '" role="menuitem"' . $attrs . '>' . $text . '</button>'
             . '</form>';
    }

    return '<a href="' . sanitize((string) ($a['url'] ?? '#')) . '" class="' . $classes . '" role="menuitem"' . $attrs . '>'
         . $text . '</a>';
}

/**
 * The data attributes that hand a control to the confirmation dialog.
 *
 * Returns nothing when there is nothing to confirm, so callers can pass a
 * null straight through without a branch.
 *
 * @param array{title?:string, body?:string, action?:string, record?:string, tone?:string}|string|null $confirm
 */
function uiConfirmAttrs(array|string|null $confirm): string
{
    if ($confirm === null || $confirm === '' || $confirm === []) {
        return '';
    }
    if (is_string($confirm)) {
        $confirm = ['body' => $confirm];
    }

    $out = ' data-confirm="' . sanitize((string) ($confirm['body'] ?? 'This action cannot be undone.')) . '"';
    foreach (['title' => 'title', 'action' => 'action', 'record' => 'record', 'tone' => 'tone'] as $key => $attr) {
        if (!empty($confirm[$key])) {
            $out .= ' data-confirm-' . $attr . '="' . sanitize((string) $confirm[$key]) . '"';
        }
    }
    return $out;
}

/**
 * The one empty state.
 *
 * Two different situations wear the same shape but must not say the same
 * thing: a list that is empty because nothing has been created yet needs an
 * invitation to create the first record, while a list that is empty because
 * a filter excluded everything needs a way back out. Passing `filtered`
 * switches the wording and adds the escape hatch, so no page leaves someone
 * staring at a blank table wondering which of the two happened.
 *
 * @param array{
 *   icon?:string, title?:string, desc?:string, filtered?:bool,
 *   clearUrl?:string, actions?:array<int, array<string,mixed>>
 * } $o
 */
function uiEmptyState(array $o = []): string
{
    $filtered = !empty($o['filtered']);
    $title    = $o['title'] ?? ($filtered ? 'No matches found' : 'Nothing here yet');
    $desc     = $o['desc']  ?? ($filtered
        ? 'No records match the filters you have applied.'
        : 'Records will appear here once they are created.');

    $buttons = '';
    if ($filtered && !empty($o['clearUrl'])) {
        $buttons .= '<a href="' . sanitize((string) $o['clearUrl']) . '" class="btn btn--outline btn--sm">'
                  . '<i class="bi bi-x-circle" aria-hidden="true"></i> Clear filters</a>';
    }
    foreach ($o['actions'] ?? [] as $a) {
        if (!empty($a['can']) && !can((string) $a['can'])) {
            continue;
        }
        $attrs = '';
        foreach (($a['attrs'] ?? []) as $k => $v) {
            $attrs .= ' ' . sanitize((string) $k) . '="' . sanitize((string) $v) . '"';
        }
        $buttons .= '<a href="' . sanitize((string) ($a['url'] ?? '#')) . '"'
                  . ' class="btn ' . sanitize((string) ($a['class'] ?? 'btn--primary')) . ' btn--sm"' . $attrs . '>'
                  . (!empty($a['icon']) ? '<i class="bi ' . sanitize((string) $a['icon']) . '" aria-hidden="true"></i> ' : '')
                  . sanitize((string) ($a['label'] ?? 'Action')) . '</a>';
    }

    return '<div class="empty-state">'
         . '<div class="empty-state__icon"><i class="bi ' . sanitize((string) ($o['icon'] ?? 'bi-inbox')) . '" aria-hidden="true"></i></div>'
         . '<div class="empty-state__title">' . sanitize($title) . '</div>'
         . '<div class="empty-state__desc">' . sanitize($desc) . '</div>'
         . ($buttons !== '' ? '<div class="empty-state__actions">' . $buttons . '</div>' : '')
         . '</div>';
}

/**
 * A sortable column header.
 *
 * Nothing here reaches SQL. This writes a sort *key* into a URL; the model
 * looks that key up in its own allow-list constant — Property::SORTS and its
 * equivalents — and falls back to a documented default when it does not
 * recognise it. Because the caller passes the very keys that constant holds,
 * a key the model would refuse is never offered as a link to begin with.
 *
 * Keys are paired rather than a column plus a direction, because that is the
 * convention the models and the public listings page already use: one key
 * names both the column and the order (`price_asc`, `price_desc`). A column
 * with only one sensible direction passes just that one.
 *
 * aria-sort is what makes the current order audible. Without it a screen
 * reader reads a column header that gives no hint the table is ordered by it.
 *
 * @param array{asc?:string, desc?:string} $keys Sort keys from the model's allow-list.
 */
function uiSortHeader(string $label, array $keys, string $param = 'sort', string $extraClass = ''): string
{
    $asc  = $keys['asc']  ?? '';
    $desc = $keys['desc'] ?? '';
    if ($asc === '' && $desc === '') {
        return '<th class="' . sanitize(trim($extraClass)) . '">' . sanitize($label) . '</th>';
    }

    $current = (string) ($_GET[$param] ?? '');
    $isAsc   = $current !== '' && $current === $asc;
    $isDesc  = $current !== '' && $current === $desc;

    // Clicking the column that is already sorted flips it; clicking a new one
    // starts from whichever direction that column offers, ascending first —
    // "sort by this" means A→Z until told otherwise.
    $next = $isAsc ? ($desc ?: $asc) : ($asc ?: $desc);

    $ariaSort = $isAsc ? 'ascending' : ($isDesc ? 'descending' : 'none');
    $glyph    = $isAsc ? 'bi-arrow-up' : ($isDesc ? 'bi-arrow-down' : 'bi-arrow-down-up');

    $params          = $_GET;
    $params[$param]  = $next;
    unset($params['p']);   // a re-sort belongs on page one
    $url = APP_URL . '/index.php?' . http_build_query($params);

    return '<th class="' . trim('th-sort ' . $extraClass) . ($isAsc || $isDesc ? ' is-sorted' : '') . '"'
         . ' aria-sort="' . $ariaSort . '">'
         . '<a href="' . sanitize($url) . '" class="th-sort__link">'
         . sanitize($label)
         . '<i class="bi ' . $glyph . '" aria-hidden="true"></i>'
         . '</a></th>';
}

/**
 * The sort key a request is asking for, validated against what the model
 * accepts. Returns the default for anything unrecognised, so `?sort=` with
 * a hand-edited value is a normal page rather than an error.
 *
 * @param string[] $allowed The model's allow-list keys.
 */
function uiSortValue(array $allowed, string $default, string $param = 'sort'): string
{
    $key = (string) ($_GET[$param] ?? '');

    return in_array($key, $allowed, true) ? $key : $default;
}

/**
 * A request value, but only if it is one we recognise.
 *
 * The companion to uiSortValue() for enumerated filters. Anything the
 * allow-list does not contain becomes '' — an *absent* filter — so a
 * hand-edited `?status=<script>` widens the result set rather than reaching
 * the query or being echoed back into a <select>. Filters fail open to
 * "everything" on purpose: a filter is a narrowing convenience, and refusing
 * the whole page over one bad parameter helps nobody.
 *
 * Callers pass the keys of the very map their <option>s are built from, so an
 * option that exists in the form and not in the query is not possible.
 *
 * @param string[] $allowed
 */
function uiPick(mixed $value, array $allowed): string
{
    $value = is_string($value) ? $value : '';

    return in_array($value, $allowed, true) ? $value : '';
}

/* ── Form field state ────────────────────────────────────────────────
   Promoted from the pattern proven in the customer and owner forms, so
   every form in the system marks a rejected field the same way. */

/** The error message for a field, already escaped, or '' when it is fine. */
function uiFieldError(array $errors, string $field): string
{
    $msg = (string) ($errors[$field] ?? '');
    if ($msg === '') {
        return '';
    }

    return '<div class="form-error" id="' . uiErrorId($field) . '">'
         . '<i class="bi bi-exclamation-circle" aria-hidden="true"></i> '
         . sanitize($msg) . '</div>';
}

/** The class suffix that outlines a rejected control. */
function uiInvalidClass(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' form-control--error' : '';
}

/**
 * aria-invalid + aria-describedby for a control, so the error is announced
 * as part of the field rather than as loose text somewhere after it.
 */
function uiFieldAria(array $errors, string $field, string $hintId = ''): string
{
    $describedBy = array_filter([
        isset($errors[$field]) ? uiErrorId($field) : '',
        $hintId,
    ]);

    return (isset($errors[$field]) ? ' aria-invalid="true"' : '')
         . ($describedBy ? ' aria-describedby="' . sanitize(implode(' ', $describedBy)) . '"' : '');
}

/** Stable DOM id for a field's error text, shared by the input and the summary. */
function uiErrorId(string $field): string
{
    return 'err-' . preg_replace('/[^a-z0-9_-]/i', '-', $field);
}
