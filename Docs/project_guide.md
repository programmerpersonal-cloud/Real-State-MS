# Saxane Real Estate Management System — Project Guide

> **Purpose**: Complete handoff guide. Reflects the current state of the system (all 5 phases delivered).

---

## 1. TECH STACK

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.x (vanilla, no framework) |
| Database | MySQL 8 via XAMPP |
| Frontend | HTML5, CSS3 (custom design system), vanilla JavaScript |
| Charts | Chart.js 4.4.1 (self-hosted, `assets/vendor/chartjs/`) |
| Icons | Bootstrap Icons 1.11.3 (self-hosted, `assets/vendor/bootstrap-icons/`) |
| Font | Inter variable (self-hosted, `assets/vendor/inter/`) |
| Server | Apache via XAMPP |
| URL | `http://localhost/Real-State-MS/` |

---

## 2. ARCHITECTURE

Custom **MVC-like** modular structure with a front-controller router and a single master layout.

```
Real-State-MS/
├── config/
│   ├── app.php              # Constants
│   └── database.php         # PDO singleton
├── controllers/
│   ├── AuthController.php
│   ├── PropertyController.php
│   ├── CustomerController.php
│   ├── OwnerController.php
│   ├── LeaseController.php
│   ├── PaymentController.php
│   ├── SaleController.php
│   ├── ReservationController.php
│   ├── MaintenanceController.php
│   ├── InquiryController.php
│   ├── NotificationController.php
│   ├── SettingsController.php
│   ├── BranchController.php
│   ├── UserController.php
│   ├── ProfileController.php
│   ├── AuditController.php
│   ├── ReportController.php
│   ├── CustomerPortalController.php
│   ├── OwnerPortalController.php
│   └── FavoritesController.php
├── models/
│   ├── User.php
│   ├── Property.php
│   ├── Customer.php
│   ├── Owner.php
│   ├── Lease.php
│   ├── Payment.php
│   ├── Sale.php
│   ├── Reservation.php
│   ├── MaintenanceRequest.php
│   └── Inquiry.php
├── database/
│   └── schema.sql           # 26-table schema
├── includes/
│   ├── init.php
│   ├── session.php
│   ├── csrf.php
│   ├── auth.php
│   └── functions.php        # sanitize/redirect/flash/upload/audit/notify/setting/renderPage/paginateUrl
├── views/
│   ├── layout.php           # Master shell (sidebar + header + content + footer)
│   ├── auth/
│   │   ├── login.php
│   │   └── register.php
│   ├── components/
│   │   ├── sidebar.php
│   │   ├── header.php
│   │   ├── footer.php
│   │   ├── page_header.php
│   │   └── pagination.php
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── properties/   (index, create, edit, show)
│   │   ├── customers/    (index, create, edit, show)
│   │   ├── owners/       (index, create, edit, show)
│   │   ├── leases/       (index, create, show, renew)
│   │   ├── payments/     (index, create, receipt)
│   │   ├── sales/        (index, create, show)
│   │   ├── reservations/ (index, create)
│   │   ├── maintenance/  (index, create, show)
│   │   ├── inquiries/    (index, show)
│   │   ├── notifications/(index)
│   │   ├── branches/     (index, form)
│   │   ├── users/        (index, form)
│   │   ├── settings/     (index)
│   │   ├── reports/      (index — Chart.js dashboard)
│   │   ├── audit/        (index)
│   │   └── profile/      (index)
│   ├── agent/dashboard.php
│   ├── customer/         (dashboard, my_lease, my_payments, favorites)
│   ├── owner/            (dashboard, my_properties, my_income)
│   └── maintenance/dashboard.php
├── assets/
│   ├── css/style.css     # Design system + pagination, gallery, profile, timeline, receipt
│   ├── js/main.js
│   ├── img/default-avatar.svg
│   └── uploads/          # properties/, documents/, avatars/, maintenance/
├── index.php             # Front-controller router
└── Docs/
```

---

## 3. ROUTING — `index.php`

All requests enter `index.php`. Auth-free routes (login/register/logout) handle themselves. All other routes:

1. Require login via `requireLogin()`
2. Dispatch via `dispatch('Controller', 'method')` — controllers render full pages with `renderPage()`
3. View-only routes (`dashboard`) fall through to a default render path

**Wired routes:**

| Page | Controller | Default action | Other actions |
|------|------------|----------------|---------------|
| `dashboard` | (none — view file) | — | role-mapped view |
| `properties` | PropertyController | index | create, edit, show, delete-image, approve, archive |
| `customers` | CustomerController | index | create, edit, show, blacklist, unlist |
| `owners` | OwnerController | index | create, edit, show |
| `leases` | LeaseController | index | create, show, renew, terminate |
| `payments` | PaymentController | index | create, show, receipt |
| `sales` | SaleController | index | create, show |
| `reservations` | ReservationController | index | create, cancel, confirm |
| `maintenance` | MaintenanceController | index | create, show, update, assign |
| `inquiries` | InquiryController | index | show, reply, create |
| `notifications` | NotificationController | index | read, read-all |
| `branches` | BranchController | index | create, edit |
| `users` | UserController | index | create, edit, toggle, reset-pass |
| `settings` | SettingsController | index | (POST → update) |
| `reports` | ReportController | index | occupancy, revenue, commission, arrears |
| `audit-logs` | AuditController | index | — |
| `profile` | ProfileController | index | (POST → update) |
| `my-lease` | CustomerPortalController | myLease | — |
| `my-payments` | CustomerPortalController | myPayments | — |
| `favorites` | FavoritesController | index | add, remove |
| `my-properties` | OwnerPortalController | myProperties | — |
| `my-income` | OwnerPortalController | myIncome | — |

---

## 4. LAYOUT & VIEW PATTERN

Controllers render pages by calling **`renderPage($viewFile, $vars)`** in `includes/functions.php`. The helper:

1. Extracts `$vars` into scope
2. Loads `$currentUser` and `$role`
3. Requires `views/layout.php`, which renders sidebar + header + flash + page_header + the view content + footer

**Standard view variables** the layout reads:
- `$pageTitle` — string shown in `<title>` and `<h1>`
- `$breadcrumbs` — array of `['label' => '…', 'url' => '…']` (URL optional)
- `$actionButton` — `['label', 'icon', 'url']`

**Controller example:**
```php
renderPage(VIEWS_PATH . '/admin/properties/index.php', [
    'properties' => $list,
    'pageTitle' => 'Properties',
    'breadcrumbs' => [['label' => 'Properties']],
    'actionButton' => ['label' => 'Add Property', 'icon' => 'bi-plus-lg', 'url' => '…'],
]);
```

---

## 5. DATABASE (26 TABLES)

Schema in `database/schema.sql`. All tables use InnoDB + utf8mb4 + FK constraints.

`branches`, `roles`, `users`, `customers`, `owners`, `properties`, `property_images`, `property_history`, `leases`, `rentals`, `sales`, `payments`, `deposits`, `payment_schedules`, `expenses`, `commissions`, `reservations`, `maintenance_requests`, `inquiries`, `messages`, `documents`, `notifications`, `favorites`, `blacklist_records`, `audit_logs`, `settings`.

**Default admin**: `admin` / `Admin@123` (bcrypt cost 12).

---

## 6. DESIGN SYSTEM (`assets/css/style.css`)

CSS custom properties drive the theme. Components:

| Component | Classes |
|-----------|---------|
| Layout | `.app`, `.app__sidebar`, `.app__main`, `.app__header`, `.app__content` |
| Cards | `.card`, `.card__header`, `.card__title`, `.card__body`, `.card__footer` |
| Stats | `.stats`, `.stat-card`, `.mini-stats`, `.mini-stat` |
| Buttons | `.btn`, `.btn--primary/outline/danger/success`, `.btn--sm/lg/block/icon` |
| Forms | `.form-group`, `.form-control`, `.form-row`, `.form-grid--2/3`, `.form-checkbox`, `.form-actions` |
| Tables | `.table-wrap`, `.table` |
| Badges | `.badge`, `.badge--success/warning/danger/info/primary/purple/orange/muted` |
| Alerts | `.alert`, `.alert--success/danger/warning/info` |
| Filter bar | `.filter-bar` |
| Pagination | `.pagination`, `.pagination__link.is-active/is-disabled` |
| Gallery / Uploads | `.gallery`, `.gallery__item`, `.upload-zone` |
| Property cards | `.prop-card`, `.prop-card__cover/body/title/meta/price/specs` |
| Profile | `.profile-grid`, `.profile-card`, `.profile-meta`, `.profile-meta__row` |
| Tabs | `.tabs`, `.tabs__item`, `.tab-panel` |
| Timeline | `.timeline`, `.timeline__item`, `.timeline__dot` |
| Receipt | `.receipt`, `.receipt__head`, `.receipt__table` (print-friendly) |
| Empty state | `.empty-state` |

---

## 7. KEY HELPERS (`includes/functions.php`)

- `sanitize($input)` — XSS-safe output
- `redirect($url)` — header + exit
- `setFlash($type, $msg)` / `renderFlash()` — one-shot messages
- `formatCurrency($amount, $symbol)` / `formatDate()` / `formatDateTime()`
- `generateCode($prefix)` — unique reference codes (PRP, LSE, PAY, SAL, RSV, MNT, RCP)
- `uploadFile($file, $subDir, $allowedTypes)` — validated upload, returns relative path
- `logAudit($action, $entityType, $entityId, $old, $new)` — adds to `audit_logs`
- `notify($userId, $title, $msg, $type, $refType, $refId)` — in-app notification
- `setting($key, $default)` — read from `settings` table (cached per-request)
- `getUnreadNotificationCount()` — used by header bell badge
- `renderPage($viewFile, $vars)` — full-page render via layout
- `paginateUrl($page)` — pagination URL preserving query params
- `getStatusBadgeClass($status)` — maps status → badge class
- `getAvatarUrl($avatar)` — avatar or default

---

## 8. BUSINESS LOGIC HIGHLIGHTS

### Leases (`models/Lease.php`)
- **Atomic creation**: lease + rental record + auto-generated `payment_schedules` + deposit + property status update — all in one DB transaction.
- `generateSchedule()` builds monthly/quarterly/yearly due rows between start & end dates.
- `renew(id, newEnd, newRent?)` extends and continues the schedule.
- `terminate(id, reason, moveOut)` closes lease + frees property + ends rental.
- `hasOverlap(propId, start, end)` prevents double-booking.
- `markOverdue()` flips `pending` schedules past their due date — invoked on every lease list view.
- `getArrears(leaseId)` returns total unpaid (amount + penalty) for a lease.

### Payments (`models/Payment.php`)
- Records any payment type (`rent`, `sale`, `deposit`, `refund`, `late_fee`, `other`).
- When `schedule_id` is passed, the corresponding row in `payment_schedules` is auto-marked paid.
- Receipts printable (browser print, `.receipt` styles include `@media print`).

### Sales (`models/Sale.php`)
- Records sale + auto-creates commission row + flips property status to `sold`/`reserved`.

### Reservations (`models/Reservation.php`)
- Creates reservation + flips property to `reserved`.
- `expireOld()` auto-expires past-due reservations and frees the property — invoked on every reservation list view.

### Maintenance (`models/MaintenanceRequest.php`)
- Customers/agents/admins report issues with photos.
- Admin/agent assigns technician → status `assigned` → technician updates to `in_progress` → `completed`.
- Auto-notifies admins on creation and the technician on assignment.

### Inquiries (`models/Inquiry.php`)
- Customer submits → admins notified → admin replies → message thread stored in `messages` table → customer notified.

---

## 9. SECURITY CHECKLIST

| Item | Status |
|------|--------|
| Passwords bcrypt (cost 12) | ✅ |
| PDO prepared statements everywhere | ✅ |
| CSRF on all POST forms | ✅ |
| XSS prevention via `sanitize()` on output | ✅ |
| Session timeout + regeneration on login | ✅ |
| Account lockout after 5 failed logins | ✅ |
| File upload validation (mime + size) | ✅ |
| Role-based access control | ✅ |
| Audit log on all create/update/delete | ✅ |
| Security headers (X-Frame, X-XSS, etc.) | ✅ |
| Soft-delete (archive) for properties | ✅ |
| HTTPS enforcement | 🔲 (production) |
| Rate limiting (login) | 🔲 (optional) |

---

## 10. ROLE-BASED FEATURE MATRIX

| Feature | Admin | Agent | Customer | Owner | Maintenance |
|---------|-------|-------|----------|-------|-------------|
| Dashboard | ✅ admin | ✅ agent | ✅ customer | ✅ owner | ✅ maintenance |
| Properties CRUD | ✅ | ✅ | view-only | own | — |
| Customers CRUD | ✅ | ✅ | — | — | — |
| Owners CRUD | ✅ | ✅ | — | self | — |
| Leases | ✅ | ✅ | view own | — | — |
| Payments | ✅ | ✅ | view own | view income | — |
| Sales | ✅ | ✅ | — | — | — |
| Reservations | ✅ | ✅ | ✅ create | — | — |
| Maintenance | ✅ | ✅ | report | report | view assigned, update |
| Inquiries | ✅ | ✅ | submit | — | — |
| Notifications | ✅ all roles | ✅ | ✅ | ✅ | ✅ |
| Favorites | ✅ all roles | ✅ | ✅ | ✅ | ✅ |
| Users & Roles | ✅ admin only | — | — | — | — |
| Branches | ✅ admin only | — | — | — | — |
| Settings | ✅ admin only | — | — | — | — |
| Audit Logs | ✅ admin only | — | — | — | — |
| Reports | ✅ | ✅ | — | — | — |

---

## 11. SETUP

1. Install XAMPP, start Apache + MySQL
2. Copy project to `C:\xampp\htdocs\Real-State-MS\`
3. Open phpMyAdmin → import `database/schema.sql`
4. Visit `http://localhost/Real-State-MS/`
5. Login: `admin` / `Admin@123`
6. From **Settings**, configure company info, currency, late fee, etc.
7. From **Branches**, add at least one branch
8. From **Users**, create staff accounts (agent, maintenance) as needed

---

## 12. EXTENDING

### Add a new module (full example pattern)
1. **Schema** — add table to `database/schema.sql`
2. **Model** — `models/Foo.php` with `findById/create/update/getAll/count`
3. **Controller** — `controllers/FooController.php` with `index/create/edit/show` (use `renderPage()`)
4. **Views** — `views/admin/foos/{index,create,edit,show}.php` (use design-system classes, include `views/components/pagination.php` for lists)
5. **Router** — add a `case 'foos':` block in `index.php` with `dispatch('FooController', $method)`
6. **Sidebar** — add link in `views/components/sidebar.php` for the relevant role
7. **Audit** — call `logAudit('action_name', 'foo', $id)` on every create/update/delete
8. **Notifications** — call `notify($userId, $title, $msg)` where relevant

### Conventions (must follow)
- **Every form**: `<?= csrfField() ?>` inside the form, `enforceCSRF()` at the top of the POST handler.
- **Every DB query**: PDO prepared statements, never raw concatenation.
- **Every output**: `sanitize($var)` for HTML, `formatCurrency()` / `formatDate()` for money & dates.
- **Every redirect** after action: `setFlash()` + `redirect()` (PRG pattern).
- **Every protected route**: `requireRole(ROLE_X, ROLE_Y, …)` at the top of the controller method.

---

## 13. STATUS

✅ **Phase 1** — Foundation, DB, security
✅ **Phase 2** — Auth, RBAC, UI shell
✅ **Phase 3** — Properties, customers, owners
✅ **Phase 4** — Leases, payments, sales, reservations
✅ **Phase 5** — Maintenance, inquiries, notifications, settings, users, branches, reports, audit, profile, customer/owner portals, favorites

**Optional polish remaining** (not blocking submission):
- Email delivery (PHPMailer)
- PDF generation for leases/receipts (Dompdf)
- CSV/Excel export from reports
- Side-by-side property comparison view
- Multi-language switcher
- Real-time chat (vs current message threads)
