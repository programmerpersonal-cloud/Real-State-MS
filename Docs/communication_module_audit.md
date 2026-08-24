# Communication Module — Audit & Relationship Map

> **Purpose**: the Phase 1 record for the Communication & Messaging module. What the system
> already is, how its people are really connected, who may therefore talk to whom, where the data
> is thin, and what was built on top of it in Phase 2.
>
> Written against the live `saxane_realestate` database. Every number here is reproducible — the
> queries are in Appendix A.

---

## 1. What the system already is

The audit found a mature, deliberately-designed application. Nothing about the communication
module needed inventing; almost all of it is an extension of patterns that were already there.

| Layer | What exists | Where |
|---|---|---|
| Routing | One front controller. `?page=<slug>&action=<verb>`, resolved through a `match()` per page and a `dispatch()` helper. No rewriting, no autoloader — a controller `require`s the models it needs. | `index.php` |
| Authorization L1/L2 | `permissionMatrix()` maps role → `page.action` strings. `can()` for the UI, `authorize()` for the server. Fails closed: an unknown role, an unknown permission and a signed-out visitor all resolve to false. | `includes/permissions.php` |
| Authorization L3 | Record scoping. Every scope function returns `[sqlPredicate, boundParams]` so the same rule drives a list query, a form's options and an INSERT's WHERE. Parameter prefixes `:scope_` and `:ra_` keep them mergeable. | `includes/property_access.php`, `includes/record_access.php` |
| Navigation | Data, not markup — and a pure projection of the permission matrix. A section whose items are all denied disappears. | `includes/navigation.php` |
| Notifications | `notifications` table, written by `notify()` / `notifyAdmins()`. The header bell reads count and preview in one query via `COUNT(*) OVER ()`. Reference targets mapped to page slugs. | `includes/functions.php`, `views/components/header.php` |
| Private files | `storage/documents`, denied by three layers of `.htaccess`, delivered only through an action that authorizes first. Filenames are random hex; extensions derived from the sniffed MIME type, never the upload's name. | `includes/documents.php` |
| CSRF | `requirePost()` then `enforceCSRF()`. Single-use token. | `includes/csrf.php` |
| UI | The SAXANE design system: light-only, Inter + Raleway, `--primary #0075c0`, hairline borders, navy rail. Load order is load-bearing and documented. A page may add its own stylesheet via `$pageStyles`. | `assets/css/`, `views/components/styles.php` |
| **AJAX / JSON** | **None.** There is no JSON endpoint anywhere in the codebase. Every state change is POST → redirect → GET. | — |

That last row settled the real-time question by itself: the module is server-rendered, and
introducing the application's first JSON endpoint for a chat panel was not worth the architectural
precedent.

### The `messages` table is already taken

The single most important audit finding. A table called `messages` exists — 8 columns, 4 rows —
and it is **not** free. It is the inquiry reply log:

- written by `Inquiry::addMessage()` — `models/Inquiry.php`
- read by `Inquiry::getMessages()`
- rendered by `views/admin/inquiries/show.php`

Its shape is `sender_id` / `receiver_id` / `inquiry_id` / `body` / `is_read`. There is no
conversation, no per-participant read state, no business context and no soft delete. Reshaping it
would have broken the enquiry screen to save one `CREATE TABLE`.

**Decision: it is left exactly as it is.** The new message table is `conversation_messages`, and
the name states the difference in one word — a row there belongs to a conversation, not to a pair
of people.

---

## 2. The real relationship map

Derived from actual foreign keys and cross-checked against live rows. Nothing inferred.

```
                                 ┌──────────────────────┐
  owners.user_id ────────────────│  properties.owner_id │
                                 │                      │──▶ properties.agent_id ──▶ users (agent)
  customers.user_id ──┬── leases (status='active') ─────│
                      │                                 │
                      ├── sales.agent_id ───────────────┘   (falls back to the property's agent)
                      │
                      └── reservations (active|confirmed) ──▶ properties.agent_id

  users (maintenance) ─── maintenance_requests.assigned_to ──▶ .property_id ──▶ properties.agent_id
```

`properties.agent_id` is the hub. Every non-staff edge in the system terminates there.

Three findings worth recording:

1. **`inquiries` is not a usable edge.** All 10 rows have `assigned_to` NULL and `customer_id`
   NULL. The table describes enquiries from the public site, not a relationship between accounts.
   Nothing in the communication module is built on it.
2. **A customer can reach the same agent by more than one route at once.** In this database Ahmed
   reaches agent #7 through both a tenancy and a reservation. Any rule that assumes one route is
   wrong.
3. **`rentals` is downstream of `leases` and adds nothing.** The access layer already scopes
   tenants on `leases.status = 'active'`. The conversation context column is therefore `lease_id`,
   not `rental_id`.

### How each route is filtered, and why the three answers differ

| Route | Filter | Reasoning |
|---|---|---|
| Tenancy | `leases.status = 'active'` | Moving out ends the conversation. |
| Sale | none | A completed purchase is permanent — the agent who sold you the flat stays reachable about it years later, and a *cancelled* sale is exactly when a buyer most needs to ask why. |
| Reservation | `status IN ('active','confirmed')` | A hold that expired is over. Without this a seven-day reservation taken once would keep a channel open forever. |
| Maintenance | none on status | A job closed last week is exactly what a technician and an agent still need to discuss. |

---

## 3. The permission matrix

Five `page.action` strings, on the four non-admin roles. Administrators inherit them from the
existing `*` wildcard.

| Role | `messages.view` | `.show` | `.create` | `.send` | `.archive` |
|---|:--:|:--:|:--:|:--:|:--:|
| admin | via `*` | via `*` | via `*` | via `*` | via `*` |
| agent | ✓ | ✓ | ✓ | ✓ | ✓ |
| owner | ✓ | ✓ | ✓ | ✓ | ✓ |
| customer | ✓ | ✓ | ✓ | ✓ | ✓ |
| maintenance | ✓ | ✓ | ✓ | ✓ | ✓ |

Every role holds the same five, and that is the point: **the difference between an owner and an
agent here is not what they may do, it is who the scope resolves for them.** Holding
`messages.create` means "starting conversations is part of your job", never "with anyone".

`messages` is deliberately **not** added to `personalPages()` — it is a capability, not a personal
record like "my lease".

### 3a. Counterpart resolution — the L3 rule

**A permission wildcard is not a communication relationship.** `ROLE_ADMIN => ['*']` governs
administrative authorization: which modules and actions are open. It does not make every
administrator a valid correspondent for every account, and the module does not read it that way.

Counterparts resolve in this fixed order, stopping at the first step that yields anything:

1. **Validate the actor.** Authenticated, `users.is_active = 1`, a live role, and holding
   `messages.create`.
2. **Resolve the real agent relationship** through the routes in §2. A resolved agent must still
   be active and still hold the `agent` role.
3. **Only if step 2 is empty**, fall back to active administrators. No agent is invented, and
   administrators are never added *alongside* a resolved agent.
4. **Several administrators** → the user chooses; the list is ordered `full_name ASC, id ASC` so
   it is stable between requests, and where no choice can be offered that ordering plus `LIMIT 1`
   is the deterministic selection.
5. **Staff are a separate case.** Agents and administrators are colleagues: an agent always reaches
   the office, and an administrator always reaches the agency team. An administrator additionally
   reaches exactly the clients who have no agent — the population step 3 sends to them. *Not* the
   whole user table: a tenant who has an agent is their agent's correspondent, and an administrator
   wanting to reach them goes through the record, not around it.

Because staff have no fallback to reach, their scope is reported as source `staff` rather than
`agent` — flattering it to `agent` would make the "the fallback fired only when it should" test
meaningless.

### 3b. Authorization is always live

Nothing in the module reads `conversation_participants.role_at_join` to decide anything. Every
access decision re-derives from the **current** `users.role_id` → `roles.name`, the **current**
`users.is_active`, and the **current** business rows.

This matters more than it looks, because the session does not. `getUserRole()` reads
`$_SESSION['user_role']`, written at login — an account deactivated an hour ago still carries a
valid-looking session. For most of the application that is an acceptable trade; for deciding who
may read correspondence about someone's tenancy it is not. So `communicationActor()` re-reads the
user's live row once per request, and everything derives from that.

The consequence, verified: a tenant whose lease ends, an agent who is deactivated, and an owner
whose properties are reassigned all **lose access to existing conversations**, while their
participant rows, the `role_at_join` snapshot and the messages themselves all survive for the
audit trail.

---

## 4. Data gaps (measured, not guessed)

These are why the fallback decision mattered. Under a strict agent-only rule, most portal accounts
would open the module and find nothing.

| Finding | Measured |
|---|---|
| Owner accounts reaching an agent | **5 of 10** |
| Tenant/customer accounts reaching an agent | **2 of 5** |
| Maintenance staff reaching an agent through an assigned job | **0 of 2** — both jobs sit on properties with `agent_id` NULL |
| Properties with an owner but no agent | 1 |
| Owner rows with no login account | 5 of 15 — unreachable by design, not a bug |
| Customer rows with no login account | 3 of 8 — same |
| Users with an avatar | **2 of 24** → initials are the default, not the exception |
| `inquiries.assigned_to` populated | 0 of 10 |

**Net effect with the fallback in place:** every one of the 24 active accounts resolves at least
one contact. 9 portal accounts route to their real agent; 9 route to the office; the 7 staff
accounts reach each other and their clients.

**The real long-term fix is populating `properties.agent_id`.** The fallback is a floor, not a
substitute — every property that gains an agent moves a client off the office's queue and onto the
person actually handling their business.

---

## 5. Schema

Three tables. `message_attachments` is deliberately absent — it belongs to the attachments phase,
and a table nothing writes to invites a later reader to assume a feature exists.

| Table | Carries |
|---|---|
| `conversations` | `conversation_type` (`direct`/`property`/`rental`/`maintenance`), `property_id`, `lease_id`, `maintenance_request_id`, `subject`, `created_by`, `status`, `last_message_id`, `last_message_at` |
| `conversation_participants` | `conversation_id`, `user_id`, `role_at_join`, `last_read_message_id`, `last_read_at`, `joined_at`, `archived_at`, `is_active`, `UNIQUE (conversation_id, user_id)` |
| `conversation_messages` | `conversation_id`, `sender_id`, `body`, `message_type`, `reply_to_message_id`, `created_at`, `edited_at`, `deleted_at` |

Full DDL with reasoning: `database/migrations/2026_08_23_communication_module.sql`.

### 5a. `role_at_join` is a snapshot

It exists so a year-old thread still reads as "the tenant said this, the agent replied that" after
the tenant has moved out. It is written once, on insert, from the live roles table, and read only
by the presentation layer. See §3b for what is used instead.

### 5b. The circular foreign key

`conversations.last_message_id → conversation_messages.id` and
`conversation_messages.conversation_id → conversations.id` are a genuine cycle: no ordering of
`CREATE TABLE` satisfies both, because whichever is built first references one that does not exist.

**The integrity model is not weakened to avoid it.** Both constraints are declared; they are
applied in an order MySQL accepts:

1. `CREATE TABLE conversations` — `last_message_id INT DEFAULT NULL`, no FK on it yet
2. `CREATE TABLE conversation_participants` — `last_read_message_id INT DEFAULT NULL`, no FK yet
3. `CREATE TABLE conversation_messages` — all its FKs inline, including the reply self-reference
4. `ALTER TABLE conversations ADD CONSTRAINT fk_conv_last_message … ON DELETE SET NULL`
5. `ALTER TABLE conversation_participants ADD CONSTRAINT fk_cp_last_read … ON DELETE SET NULL`

Both columns stay **nullable**, and that is correct rather than a concession: a conversation exists
from the moment it is created and before its first message, so NULL is a real state the schema must
express. `ON DELETE SET NULL` matches the convention used across the existing schema; in practice
it rarely fires, because messages are soft-deleted rather than removed.

Result: **11 foreign keys** across the three tables, all present and verified.

---

## 6. What Phase 2 built

| File | Role |
|---|---|
| `database/migrations/2026_08_23_communication_module.sql` | The schema above, guard-query-first. **Executed once.** |
| `includes/communication_access.php` | The authorization layer. Same idiom as `property_access.php`: `[predicate, params]`, `:ca_` prefix, fails closed. |
| `models/Conversation.php` | Inbox, participants, duplicate-prevention, transactional creation, per-participant archiving. |
| `models/ConversationMessage.php` | Thread pagination, transactional send, read watermarks, unread counts. |
| `database/tools/verify_communication_access.php` | The proof. 93 assertions across all five roles. |
| `includes/init.php` | One `require_once`. |
| `includes/permissions.php` | The five `messages.*` grants, plus a note on the admin row that `*` grants no communication edge. |

**Navigation and routing were deliberately not touched.** Granting `messages.view` makes
`canAccessPage('messages')` true, and `navigation.php` is a pure projection of the matrix — so
adding the menu entry before the route exists would render a link to nothing. The nav entry and
`case 'messages'` ship together in Phase 3.

### Duplicate prevention

Two conversations are equivalent when type, all three context ids, and the exact participant set
match, and the conversation is still active. "Message my agent about Villa V-102" pressed twice
reaches the same thread — a duplicate splits correspondence and gives each half its own unread
count. The check uses MariaDB's NULL-safe `<=>` so "both have no property" matches rather than
evaluating to NULL.

---

## 7. Verification results

`php database/tools/verify_communication_access.php` — **93 passed, 0 failed.**

The tool changes nothing that survives it: fixture conversations are deleted in a shutdown handler
(so an interrupted run still cleans up), and the three revocation scenarios run in child processes
inside transactions that are always rolled back. Children rather than inline, because the access
layer caches the actor and their agent edge per request — correctly, since a role does not change
mid-request — and a cache filled before the mutation would hide the very thing being tested.

| Group | Covered |
|---|---|
| A | Contact resolution for all 24 active accounts. Where an agent resolves, no administrator appears alongside; where none does, the fallback is *exactly* the active administrators. |
| B | An administrator holds `messages.create` through `*` and still cannot message a tenant who has their own agent — and *can* message one who has none. |
| C | Cross-owner, cross-tenant, technician→owner, unrelated-agent, self-messaging, and nonsense ids all refused. |
| D | Conversation lifecycle: creation, duplicate reuse, participant enrolment, sending, unread accounting, one-sided archiving. **All 22 non-participant accounts refused the conversation id**, and refused its messages and its composer. A closed conversation stays readable and stops accepting messages. |
| D2 | A property outside the user's scope is refused as context, and no row is written. |
| E | A deactivated agent vanishes from the scope and has no usable actor despite a valid session. An owner whose properties are unassigned loses the conversation while both participant rows, both `role_at_join` snapshots and the message survive. |
| F | The legacy `messages` table still has its 8 columns; `Inquiry::getMessages()` still returns its thread. |

Separately, all six main pages were rendered for all five roles (30 renders) with `E_ALL`
displayed: **no PHP warning, notice, deprecation or SQL error in any of them.**

### A limitation this cannot prove here

Both tenants in this database also hold a sale, and a sale is a permanent relationship by design —
so ending their tenancy correctly does *not* revoke the channel. Conversation-level revocation is
therefore proved through the owner route, which has exactly one link in it. Seed a tenant with no
sale and the third scenario covers it directly.

---

## 8. Recommended next phase

**Phase 3 — Core Conversations and Messaging.** The data foundation and authorization are done and
proved; what is missing is the way in.

1. `case 'messages'` in `index.php` — `index | show | start | send | archive | unarchive`
2. `controllers/CommunicationController.php`, thin: authorize, delegate, redirect
3. The nav entry — `['messages', 'Messages', 'bi-chat-dots']` under Operations — shipping in the
   same change as the route, so no dead link ever exists
4. `views/messages/` and `assets/css/pages/messages.css`, loaded via `$pageStyles`
5. Desktop: two panels inside `.app__content`. Mobile: a URL-driven takeover (`?id=` present →
   thread only, with a real back link), which keeps browser-back correct and needs no JavaScript.
   The shell is `min-height:100dvh` flex, so the workspace sizes off `dvh` — never `100vh`.
6. Unread badge announced as an atomic `role="status"` phrase, not a bare number

Business context (Phase 4) is already carried by the schema and honoured by the access layer; it
becomes a rendering job rather than a modelling one.

---

## Appendix A — Reproducing the numbers

Run against `saxane_realestate`. Each returns one of the figures in §4.

```sql
-- Owner accounts reaching an agent (expect 5 of 10)
SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id=r.id
 WHERE r.name='owner' AND u.is_active=1
   AND EXISTS (SELECT 1 FROM owners o JOIN properties p ON p.owner_id=o.id
                WHERE o.user_id=u.id AND p.agent_id IS NOT NULL);

-- Customer accounts reaching an agent (expect 2 of 5)
SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id=r.id
 WHERE r.name='customer' AND u.is_active=1
   AND EXISTS (
     SELECT 1 FROM customers c WHERE c.user_id=u.id AND (
       EXISTS (SELECT 1 FROM leases l JOIN properties lp ON l.property_id=lp.id
                WHERE l.customer_id=c.id AND l.status='active' AND lp.agent_id IS NOT NULL)
    OR EXISTS (SELECT 1 FROM sales s LEFT JOIN properties sp ON s.property_id=sp.id
                WHERE s.customer_id=c.id AND COALESCE(s.agent_id, sp.agent_id) IS NOT NULL)
    OR EXISTS (SELECT 1 FROM reservations rv JOIN properties rp ON rv.property_id=rp.id
                WHERE rv.customer_id=c.id AND rv.status IN ('active','confirmed')
                  AND rp.agent_id IS NOT NULL)));

-- Maintenance staff reaching an agent (expect 0 of 2)
SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id=r.id
 WHERE r.name='maintenance' AND u.is_active=1
   AND EXISTS (SELECT 1 FROM maintenance_requests m JOIN properties p ON m.property_id=p.id
                WHERE m.assigned_to=u.id AND p.agent_id IS NOT NULL);

-- Linkage and thin-data counts
SELECT (SELECT COUNT(*) FROM owners WHERE user_id IS NULL)                    AS owners_no_account,
       (SELECT COUNT(*) FROM customers WHERE user_id IS NULL)                 AS customers_no_account,
       (SELECT COUNT(*) FROM properties WHERE owner_id IS NOT NULL
                                          AND agent_id IS NULL)               AS owned_no_agent,
       (SELECT COUNT(*) FROM users WHERE avatar IS NULL OR avatar='')         AS users_no_avatar,
       (SELECT COUNT(*) FROM inquiries WHERE assigned_to IS NOT NULL)         AS inquiries_assigned;

-- The migration landed, and the legacy table did not move (expect 3, then 8)
SELECT COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE()
   AND TABLE_NAME IN ('conversations','conversation_participants','conversation_messages');
SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages';
```
