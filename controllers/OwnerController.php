<?php
/**
 * Owner Controller
 */
require_once BASE_PATH . '/models/Owner.php';
require_once BASE_PATH . '/models/User.php';

class OwnerController
{
    /**
     * Login-access filter. Not a column — Owner::getAll() reads it to answer
     * "who can actually sign in". Keyed by the value, valued by the label the
     * control shows, so the list offered and the list accepted are one list.
     */
    private const LOGIN_STATES = [
        'enabled'  => 'Can sign in',
        'disabled' => 'No login access',
    ];

    private Owner $model;
    private User $users;

    public function __construct()
    {
        $this->model = new Owner();
        $this->users = new User();
    }

    public function index(): void
    {
        authorize('owners.view');

        // The login filter is checked against the same list the control offers,
        // and the sort key against Owner::SORTS — neither reaches SQL as text.
        $filters = [
            'search' => trim((string) ($_GET['search'] ?? '')),
            'login'  => uiPick($_GET['login'] ?? '', array_keys(self::LOGIN_STATES)),
            'sort'   => uiSortValue(array_keys(Owner::SORTS), 'newest'),
        ];
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $owners = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        // The quick-add popup lives on this page, so it needs the entry — and
        // the per-field messages — kept back after a rejected submit.
        [$formData, $formErrors] = $this->takeRejectedForm();

        // Straight after a save, the access question opens over the refreshed
        // list. Only for an owner who has no account yet, so a reload or a
        // shared link cannot reopen it over one that is already sorted.
        $grantOwner = $accountMatch = null;
        if ($createdId = (int)($_GET['created'] ?? 0)) {
            $candidate = $this->model->findById($createdId);
            // Scoped like every other read of an owner row: `?created=` is
            // request text, and the panel it opens names the landlord and
            // their email address.
            if ($candidate && !$candidate['user_id'] && canViewOwnerProfile($candidate)) {
                $grantOwner   = $candidate;
                $accountMatch = !empty($candidate['email'])
                    ? $this->users->findByEmail($candidate['email'])
                    : null;
            }
        }

        renderPage(VIEWS_PATH . '/admin/owners/index.php', [
            'owners'     => $owners,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'formData'   => $formData,
            'formErrors' => $formErrors,
            'grantOwner'   => $grantOwner,
            'accountMatch' => $accountMatch,
            // The filter control is built from the same list the request was
            // validated against, so the two can never disagree.
            'loginStates'  => self::LOGIN_STATES,
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle'  => 'Property Owners',
            'breadcrumbs'=> [['label' => 'Owners']],
            'actionButton' => [
                'can'   => 'owners.create',
                'label' => 'Add Owner',
                'icon'  => 'bi-plus-lg',
                'url'   => APP_URL . '/index.php?page=owners&action=create',
                'attrs' => ['data-modal-open' => 'ownerCreateModal'],
            ],
        ]);
    }

    public function create(): void
    {
        authorize('owners.create');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from a popup returns to that popup, so a rejected
            // entry is corrected where it was typed.
            $failUrl = modalReturnUrl('owners', 'owner',
                APP_URL . '/index.php?page=owners&action=create');

            $data = $this->extractData();

            $errors = $this->validateOwner($data);
            if ($errors) {
                $this->rejectForm($errors, $data, $failUrl);
            }

            $id = $this->model->create($data);
            if (!$id) {
                $this->rejectForm(['Could not save the owner. Nothing was changed — please try again.'],
                    $data, $failUrl);
            }

            logAudit('created_owner', 'owner', $id);
            setFlash('success', 'Owner created successfully.');

            // Back to the list — where the new owner is already visible — with
            // the access question waiting on top of it.
            redirect(APP_URL . '/index.php?page=owners&created=' . $id);
        }
        [$formData, $formErrors] = $this->takeRejectedForm();

        renderPage(VIEWS_PATH . '/admin/owners/create.php', [
            'formData'   => $formData,
            'formErrors' => $formErrors,
            'pageTitle'  => 'New Owner',
            'breadcrumbs'=> [
                ['label' => 'Owners', 'url' => APP_URL . '/index.php?page=owners'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function edit(): void
    {
        authorize('owners.edit');
        $id = (int)($_GET['id'] ?? 0);
        $owner = $this->model->findById($id);

        // Level 3, the same answer show() gives. This form rewrites the
        // landlord's bank account and commission rate, so an agent edits the
        // landlords whose properties they manage and nobody else's.
        authorizeRecord($owner !== null && canViewOwnerProfile($owner), 'owner', $id);

        $editUrl = APP_URL . '/index.php?page=owners&action=edit&id=' . $id;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = $this->extractData();

            // The same identity rules as creation, minus this owner's own row —
            // an edit must not be able to introduce the duplicate that the
            // create form refuses.
            $errors = $this->validateOwner($data, $id);
            if ($errors) {
                $this->rejectForm($errors, $data + ['id' => $id], $editUrl);
            }

            $this->model->update($id, $data);
            // Keep the account's contact details in step with the profile.
            if ($owner['user_id']) {
                $this->users->update((int) $owner['user_id'], [
                    'full_name' => $data['full_name'],
                    'phone'     => $data['phone'],
                ]);
            }
            logAudit('updated_owner', 'owner', $id);
            setFlash('success', 'Owner updated.');
            redirect(APP_URL . '/index.php?page=owners&action=show&id=' . $id);
        }

        [$formData, $formErrors] = $this->takeRejectedForm();

        renderPage(VIEWS_PATH . '/admin/owners/edit.php', [
            'formData'   => $formData ? array_merge($owner, $formData) : $owner,
            'formErrors' => $formErrors,
            'pageTitle'  => 'Edit Owner',
            'breadcrumbs'=> [
                ['label' => 'Owners', 'url' => APP_URL . '/index.php?page=owners'],
                ['label' => sanitize($owner['full_name']), 'url' => APP_URL . '/index.php?page=owners&action=show&id=' . $id],
                ['label' => 'Edit'],
            ],
        ]);
    }

    public function show(): void
    {
        authorize('owners.show');
        $id = (int)($_GET['id'] ?? 0);
        $owner = $this->model->findById($id);

        // Level 3. `owners.show` is held by staff and by owners alike, but it
        // means "any landlord I manage property for" to one and "my own
        // profile" to the other — this page carries the portfolio, the bank
        // details and the income to date. A landlord who does not exist is
        // refused identically to one who is not theirs.
        authorizeRecord($owner !== null && canViewOwnerProfile($owner), 'owner', $id);

        $properties = $this->model->getProperties($id);

        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(p.amount),0) AS total_income
            FROM payments p JOIN properties pr ON p.property_id = pr.id
            WHERE pr.owner_id = ? AND p.status = 'paid' AND p.payment_type IN ('rent','sale')
        ");
        $stmt->execute([$id]);
        $totalIncome = (float) $stmt->fetchColumn();

        // If an account already carries this owner's email, the "enable login"
        // panel offers to adopt it rather than making a second one.
        $accountMatch = (!$owner['user_id'] && !empty($owner['email']))
            ? $this->users->findByEmail($owner['email'])
            : null;

        renderPage(VIEWS_PATH . '/admin/owners/show.php', [
            'owner'        => $owner,
            'properties'   => $properties,
            'totalIncome'  => $totalIncome,
            'accountMatch' => $accountMatch,
            'pageTitle'   => $owner['full_name'],
            // The parent crumb is only a link for someone who can open the
            // directory. An owner reading their own profile gets the label
            // without a link they would be refused.
            'breadcrumbs' => array_values(array_filter([
                can('owners.view')
                    ? ['label' => 'Owners', 'url' => APP_URL . '/index.php?page=owners']
                    : null,
                ['label' => sanitize($owner['full_name'])],
            ])),
        ]);
    }

    /**
     * Give an owner login access — creating the account, or adopting the one
     * that already carries their email.
     */
    public function enableLogin(): void
    {
        authorize('owners.enable-login');
        enforceCSRF();

        $id    = (int)($_GET['id'] ?? 0);
        $owner = $this->model->findById($id);
        if (!$owner) { setFlash('error', 'Owner not found.'); redirect(APP_URL . '/index.php?page=owners'); }

        // A grant that started from the list returns to the list, where the
        // owner's new "Enabled" badge is the confirmation.
        $backUrl = ($_POST['return_to'] ?? '') === 'list'
            ? APP_URL . '/index.php?page=owners'
            : APP_URL . '/index.php?page=owners&action=show&id=' . $id;

        // Already linked: this is a reactivation, not a new account.
        if ($owner['user_id']) {
            $this->users->update((int) $owner['user_id'], ['is_active' => 1]);
            logAudit('enabled_owner_login', 'owner', $id);
            setFlash('success', 'Login access re-enabled for ' . $owner['full_name'] . '.');
            redirect($backUrl);
        }

        $email    = sanitize($_POST['email'] ?? $owner['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');
        $existing = $email !== '' ? $this->users->findByEmail($email) : null;

        // Adopting an existing account never touches its password: the person
        // signing in keeps the credentials they already have.
        $errors = $existing
            ? $this->validateAdoption($existing, $id)
            : $this->validateAccount(['email' => $email, 'full_name' => $owner['full_name']],
                                     ['password' => $password, 'confirm' => $confirm]);
        if ($errors) {
            setFlash('error', implode(' ', $errors));
            redirect($backUrl);
        }

        $db = getDBConnection();
        $db->beginTransaction();
        try {
            if ($existing) {
                $userId = (int) $existing['id'];
                $roleId = $this->users->roleIdByName(ROLE_OWNER);
                if (!$roleId) throw new RuntimeException('Owner role is missing from the roles table.');
                if ((int) $existing['role_id'] !== $roleId && !$this->users->setRole($userId, $roleId)) {
                    throw new RuntimeException('Role could not be set to owner.');
                }
                if (!$existing['is_active']) $this->users->update($userId, ['is_active' => 1]);
                if (!$this->model->linkUser($id, $userId)) throw new RuntimeException('Owner could not be linked.');
            } else {
                $userId = $this->createAccountFor($id, [
                    'full_name' => $owner['full_name'],
                    'email'     => $email,
                    'phone'     => $owner['phone'],
                ], $password);
                if (!$userId) throw new RuntimeException('User account could not be written.');
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('Enable owner login failed, rolled back: ' . $e->getMessage());
            setFlash('error', 'Could not enable login access. Nothing was changed.');
            redirect($backUrl);
        }

        logAudit('enabled_owner_login', 'owner', $id);
        setFlash('success', $existing
            ? 'Existing account linked to this owner. Their current password is unchanged.'
            : 'Login access enabled. Role set to Owner.');
        redirect($backUrl);
    }

    /**
     * Build the missing owner profile behind an account that already carries
     * the Owner role.
     *
     * These are the rows that made the two lists disagree: an account listed
     * under Users & Roles as an owner, with nothing in the Owners module to
     * match it. The profile is created from the account's own details and
     * linked, so one person ends up with one record on each side — never a
     * second, unrelated owner.
     */
    public function createProfileForUser(): void
    {
        authorize('owners.create-profile');
        enforceCSRF();

        $userId  = (int)($_GET['user_id'] ?? 0);
        $listUrl = APP_URL . '/index.php?page=users';
        $user    = $this->users->findById($userId);

        if (!$user) { setFlash('error', 'User not found.'); redirect($listUrl); }
        if ($user['role_name'] !== ROLE_OWNER) {
            setFlash('error', 'Only accounts with the Owner role can have an owner profile.');
            redirect($listUrl);
        }
        if ($existing = $this->model->findByUserId($userId)) {
            setFlash('error', 'This account already has an owner profile.');
            redirect(APP_URL . '/index.php?page=owners&action=show&id=' . $existing['id']);
        }

        $db = getDBConnection();
        $db->beginTransaction();
        try {
            $ownerId = $this->model->create([
                'full_name' => $user['full_name'] ?: $user['username'],
                'phone'     => $user['phone'] ?: '',
                'email'     => $user['email'],
                'user_id'   => $userId,
            ]);
            if (!$ownerId) throw new RuntimeException('Owner profile could not be written.');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('Owner profile backfill failed, rolled back: ' . $e->getMessage());
            setFlash('error', 'Could not create the owner profile. Nothing was changed.');
            redirect($listUrl);
        }

        logAudit('created_owner_profile_for_user', 'owner', $ownerId);
        setFlash('success', 'Owner profile created and linked to this account. Fill in the phone and payout details when you have them.');
        redirect(APP_URL . '/index.php?page=owners&action=show&id=' . $ownerId);
    }

    /**
     * Take login access away without touching the business record: the owner,
     * their properties, leases, sales and payment history all stay exactly
     * where they are — only the account stops working.
     */
    public function disableLogin(): void
    {
        authorize('owners.disable-login');
        enforceCSRF();

        $id    = (int)($_GET['id'] ?? 0);
        $owner = $this->model->findById($id);
        if (!$owner) { setFlash('error', 'Owner not found.'); redirect(APP_URL . '/index.php?page=owners'); }

        if ($owner['user_id']) {
            $this->users->update((int) $owner['user_id'], ['is_active' => 0]);
            logAudit('disabled_owner_login', 'owner', $id);
            setFlash('success', 'Login disabled. The owner and all their records are unchanged.');
        } else {
            setFlash('error', 'This owner has no account to disable.');
        }
        redirect(APP_URL . '/index.php?page=owners&action=show&id=' . $id);
    }

    // ─── Validation ─────────────────────────────────────────

    /** @return string[] messages keyed for the flash, with field hints stored separately */
    private function validateOwner(array $d, ?int $excludeId = null): array
    {
        $errors = [];
        validateSharedFields($d, $errors, ['full_name', 'phone']);

        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $this->fieldError($errors, 'email', 'That email address is not valid.');
        }
        // A second profile for the same person is the very thing that split the
        // Owners and Users lists apart, so identity clashes are refused here.
        if ($d['email'] !== '' && ($clash = $this->model->findByEmail($d['email'], $excludeId))) {
            $this->fieldError($errors, 'email',
                'An owner with this email already exists (' . $clash['full_name'] . '). Edit that owner instead.');
        }
        if ($d['phone'] !== '' && ($clash = $this->model->findByPhone($d['phone'], $excludeId))) {
            $this->fieldError($errors, 'phone',
                'An owner with this phone already exists (' . $clash['full_name'] . '). Edit that owner instead.');
        }
        return $errors;
    }

    /** Checks that only apply when login access is being switched on. */
    private function validateAccount(array $d, array $creds, ?int $excludeUserId = null): array
    {
        $errors = [];
        $email  = trim((string) ($d['email'] ?? ''));

        if ($email === '') {
            $this->fieldError($errors, 'email', 'Email is required to give this owner login access — it is how they sign in.');
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) && $this->users->emailExists($email, $excludeUserId)) {
            $this->fieldError($errors, 'email',
                'This email already belongs to another user account. Use a different email, or enable login from that owner’s page.');
        }

        if (strlen($creds['password']) < PASSWORD_MIN_LENGTH) {
            $this->fieldError($errors, 'password', 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.');
        } elseif ($creds['password'] !== $creds['confirm']) {
            $this->fieldError($errors, 'confirm_password', 'The two passwords do not match.');
        }
        return $errors;
    }

    /** Whether an account found by email may be adopted by this owner. */
    private function validateAdoption(array $user, int $ownerId): array
    {
        $errors = [];
        $linked = $this->model->findByUserId((int) $user['id']);
        if ($linked && (int) $linked['id'] !== $ownerId) {
            $errors[] = 'That account already belongs to owner "' . $linked['full_name'] . '". Use a different email.';
        }
        return $errors;
    }

    /**
     * The entry and field messages held over from a rejected submit, consumed
     * once so a later refresh shows a clean form.
     *
     * @return array{0:array,1:array<string,string>}
     */
    private function takeRejectedForm(): array
    {
        $data   = $_SESSION['form_data'] ?? [];
        $errors = $_SESSION['form_errors'] ?? [];
        unset($_SESSION['form_data'], $_SESSION['form_errors']);
        return [$data, $errors];
    }

    /** Records a message for the flash and the per-field hint for the form. */
    private function fieldError(array &$errors, string $field, string $message): void
    {
        $errors[] = $message;
        $_SESSION['form_errors'][$field] = $message;
    }

    /**
     * Send the form back with everything the administrator typed still in it —
     * except the passwords, which are never put in the session.
     */
    private function rejectForm(array $errors, array $data, string $failUrl): void
    {
        setFlash('error', implode(' ', $errors));
        $_SESSION['form_data'] = $data;
        redirect($failUrl);
    }

    /**
     * Create the account behind an owner and link the two.
     * Caller owns the transaction; failure is reported, never swallowed.
     */
    private function createAccountFor(int $ownerId, array $d, string $password): int|false
    {
        $roleId = $this->users->roleIdByName(ROLE_OWNER);
        if (!$roleId) throw new RuntimeException('Owner role is missing from the roles table.');

        $userId = $this->users->create([
            'full_name' => $d['full_name'],
            'email'     => $d['email'],
            'phone'     => $d['phone'] ?? '',
            'username'  => $this->users->suggestUsername($d['email'], $d['full_name']),
            'password'  => $password,   // hashed inside User::create()
            'role_id'   => $roleId,
        ]);
        if (!$userId) return false;

        return $this->model->linkUser($ownerId, $userId) ? $userId : false;
    }

    private function extractData(): array
    {
        $d = [
            'full_name'       => sanitize($_POST['full_name'] ?? ''),
            'phone'           => sanitize($_POST['phone'] ?? ''),
            'email'           => sanitize($_POST['email'] ?? ''),
            'address'         => sanitize($_POST['address'] ?? ''),
            'national_id'     => sanitize($_POST['national_id'] ?? ''),
            'bank_name'       => sanitize($_POST['bank_name'] ?? ''),
            'bank_account'    => sanitize($_POST['bank_account'] ?? ''),
            'commission_rate' => ($_POST['commission_rate'] ?? '') !== '' ? $_POST['commission_rate'] : commissionRate(),
            'revenue_share'   => ($_POST['revenue_share'] ?? '') !== '' ? $_POST['revenue_share'] : null,
            'notes'           => sanitize($_POST['notes'] ?? ''),
        ];

        /* Number and country selector folded into the one value that gets
           stored, before validation or any duplicate check reads it. */
        normalisePhoneFields($d);

        return $d;
    }
}
