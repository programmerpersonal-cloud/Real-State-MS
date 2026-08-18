<?php
/**
 * Customer Controller
 *
 * A customer (tenant, buyer, or both) is a business record. A user account is
 * a way to sign in. They are separate rows on purpose — most tenants never log
 * in — but when one person has both, they are joined by customers.user_id and
 * that link is the single source of truth for the Customers list, the Users &
 * Roles list, and every permission scope that asks "which lease is theirs?".
 *
 * Saving a customer therefore never silently creates an account, and never
 * leaves a second copy of the same person behind: the profile is written
 * first, then the access question is asked separately, and the answer either
 * creates one account with the Customer role or adopts the existing account
 * that already carries their email.
 */
require_once BASE_PATH . '/models/Customer.php';
require_once BASE_PATH . '/models/User.php';

class CustomerController
{
    /** The values customers.customer_type accepts — anything else is refused. */
    private const TYPES  = ['tenant', 'buyer', 'both'];
    private const RISKS  = ['low', 'medium', 'high'];
    private const GENDER = ['male', 'female', 'other'];

    /**
     * The same enums again, with the labels the filter controls show.
     *
     * Keyed by the value TYPES already validates, so the list offered in the
     * UI and the list the query accepts cannot drift: a type added above but
     * not here simply has no filter, rather than a filter that is refused.
     */
    private const TYPE_LABELS = [
        'tenant' => 'Tenant',
        'buyer'  => 'Buyer',
        'both'   => 'Tenant & Buyer',
    ];

    /** Login-access filter. Not a column — Customer::buildFilters() reads it. */
    private const LOGIN_STATES = [
        'enabled'  => 'Can sign in',
        'disabled' => 'No login access',
    ];

    private Customer $model;
    private User $users;

    public function __construct()
    {
        $this->model = new Customer();
        $this->users = new User();
    }

    public function index(): void
    {
        authorize('customers.view');

        // Enumerated filters are checked against the same lists the form builds
        // its <option>s from, so a hand-edited value is an absent filter rather
        // than something carried into the query. Free text stays a bound param.
        $filters = [
            'search'        => trim((string) ($_GET['search'] ?? '')),
            'customer_type' => uiPick($_GET['customer_type'] ?? '', self::TYPES),
            'login'         => uiPick($_GET['login'] ?? '', array_keys(self::LOGIN_STATES)),
            // Never interpolated: Customer::SORTS resolves this key and falls
            // back to 'newest' for anything it does not recognise.
            'sort'          => uiSortValue(array_keys(Customer::SORTS), 'newest'),
        ];
        if (isset($_GET['blacklisted']) && $_GET['blacklisted'] !== '') {
            $filters['is_blacklisted'] = (int)$_GET['blacklisted'];
        }
        $page = max(1, (int)($_GET['p'] ?? 1));
        $offset = ($page - 1) * ITEMS_PER_PAGE;
        $customers = $this->model->getAll($filters, ITEMS_PER_PAGE, $offset);
        $totalCount = $this->model->count($filters);
        $totalPages = (int) ceil($totalCount / ITEMS_PER_PAGE);

        // The quick-add popup lives on this page, so it needs the entry — and
        // the per-field messages — kept back after a rejected submit.
        [$formData, $formErrors] = $this->takeRejectedForm();

        // Straight after a save, the access question opens over the refreshed
        // list. Only for a customer who has no account yet, so a reload or a
        // shared link cannot reopen it over one that is already sorted.
        $grantCustomer = $accountMatch = null;
        if ($createdId = (int)($_GET['created'] ?? 0)) {
            $candidate = $this->model->findById($createdId);
            if ($candidate && !$candidate['user_id']) {
                $grantCustomer = $candidate;
                $accountMatch  = !empty($candidate['email'])
                    ? $this->users->findByEmail($candidate['email'])
                    : null;
            }
        }

        renderPage(VIEWS_PATH . '/admin/customers/index.php', [
            'customers'  => $customers,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'formData'   => $formData,
            'formErrors' => $formErrors,
            'grantCustomer' => $grantCustomer,
            'accountMatch'  => $accountMatch,
            // The filter controls are built from the same lists the request was
            // validated against, so the two can never disagree.
            'typeLabels'    => self::TYPE_LABELS,
            'loginStates'   => self::LOGIN_STATES,
            'openCreateModal' => ($_GET['modal'] ?? '') === 'create',
            'pageTitle'  => 'Customers',
            'breadcrumbs'=> [['label' => 'Customers']],
            'actionButton' => [
                'can'   => 'customers.create',
                'label' => 'Add Customer',
                'icon'  => 'bi-person-plus',
                'url'   => APP_URL . '/index.php?page=customers&action=create',
                'attrs' => ['data-modal-open' => 'customerCreateModal'],
            ],
        ]);
    }

    public function create(): void
    {
        authorize('customers.create');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            // A submit from a popup returns to that popup, so a rejected
            // entry is corrected where it was typed.
            $failUrl = modalReturnUrl('customers', 'customer',
                APP_URL . '/index.php?page=customers&action=create');

            $data = $this->extractData();

            $errors = $this->validateCustomer($data);
            if ($errors) {
                $this->rejectForm($errors, $data, $failUrl);
            }

            if (!empty($_FILES['profile_photo']['name'])) {
                $data['profile_photo'] = uploadFile($_FILES['profile_photo'], 'avatars', ALLOWED_IMAGE_TYPES);
            }

            $id = $this->model->create($data);
            if (!$id) {
                $this->rejectForm(['Could not save the customer. Nothing was changed — please try again.'],
                    $data, $failUrl);
            }

            logAudit('created_customer', 'customer', $id);
            setFlash('success', 'Customer created successfully.');

            // Back to the list — where the new customer is already visible —
            // with the access question waiting on top of it.
            redirect(APP_URL . '/index.php?page=customers&created=' . $id);
        }
        [$formData, $formErrors] = $this->takeRejectedForm();

        renderPage(VIEWS_PATH . '/admin/customers/create.php', [
            'formData'   => $formData,
            'formErrors' => $formErrors,
            'pageTitle'  => 'New Customer',
            'breadcrumbs'=> [
                ['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function edit(): void
    {
        authorize('customers.edit');
        $id = (int)($_GET['id'] ?? 0);
        $customer = $this->model->findById($id);
        if (!$customer) { setFlash('error', 'Customer not found.'); redirect(APP_URL . '/index.php?page=customers'); }

        $editUrl = APP_URL . '/index.php?page=customers&action=edit&id=' . $id;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $data = $this->extractData();

            // The same identity rules as creation, minus this customer's own
            // row — an edit must not be able to introduce the duplicate that
            // the create form refuses.
            $errors = $this->validateCustomer($data, $id);
            if ($errors) {
                $this->rejectForm($errors, $data + ['id' => $id], $editUrl);
            }

            if (!empty($_FILES['profile_photo']['name'])) {
                $data['profile_photo'] = uploadFile($_FILES['profile_photo'], 'avatars', ALLOWED_IMAGE_TYPES);
            }

            $this->model->update($id, $data);
            // Keep the account's contact details in step with the profile, so
            // the two lists still describe one person after a rename.
            if ($customer['user_id']) {
                $this->users->update((int) $customer['user_id'], [
                    'full_name' => $data['full_name'],
                    'phone'     => $data['phone'],
                ]);
            }
            logAudit('updated_customer', 'customer', $id);
            setFlash('success', 'Customer updated.');
            redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $id);
        }

        [$formData, $formErrors] = $this->takeRejectedForm();

        renderPage(VIEWS_PATH . '/admin/customers/edit.php', [
            'formData'   => $formData ? array_merge($customer, $formData) : $customer,
            'formErrors' => $formErrors,
            'customer'   => $customer,
            'pageTitle'  => 'Edit Customer',
            'breadcrumbs'=> [
                ['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'],
                ['label' => sanitize($customer['full_name']), 'url' => APP_URL . '/index.php?page=customers&action=show&id=' . $id],
                ['label' => 'Edit'],
            ],
        ]);
    }

    public function show(): void
    {
        authorize('customers.show');
        $id = (int)($_GET['id'] ?? 0);
        $customer = $this->model->findById($id);
        if (!$customer) { setFlash('error', 'Customer not found.'); redirect(APP_URL . '/index.php?page=customers'); }
        $rentalHistory = $this->model->getRentalHistory($id);
        $paymentHistory = $this->model->getPaymentHistory($id);

        // If an account already carries this customer's email, the "enable
        // login" panel offers to adopt it rather than making a second one.
        $accountMatch = (!$customer['user_id'] && !empty($customer['email']))
            ? $this->users->findByEmail($customer['email'])
            : null;

        renderPage(VIEWS_PATH . '/admin/customers/show.php', [
            'customer'       => $customer,
            'rentalHistory'  => $rentalHistory,
            'paymentHistory' => $paymentHistory,
            'accountMatch'   => $accountMatch,
            'pageTitle'      => $customer['full_name'],
            'breadcrumbs'    => [
                ['label' => 'Customers', 'url' => APP_URL . '/index.php?page=customers'],
                ['label' => sanitize($customer['full_name'])],
            ],
        ]);
    }

    /**
     * Give a customer login access — creating the account, or adopting the one
     * that already carries their email.
     */
    public function enableLogin(): void
    {
        authorize('customers.enable-login');
        enforceCSRF();

        $id       = (int)($_GET['id'] ?? 0);
        $customer = $this->model->findById($id);
        if (!$customer) { setFlash('error', 'Customer not found.'); redirect(APP_URL . '/index.php?page=customers'); }

        // A grant that started from the list returns to the list, where the
        // customer's new "Enabled" badge is the confirmation.
        $backUrl = ($_POST['return_to'] ?? '') === 'list'
            ? APP_URL . '/index.php?page=customers'
            : APP_URL . '/index.php?page=customers&action=show&id=' . $id;

        // Already linked: this is a reactivation, not a new account.
        if ($customer['user_id']) {
            $this->users->update((int) $customer['user_id'], ['is_active' => 1]);
            logAudit('enabled_customer_login', 'customer', $id);
            setFlash('success', 'Login access re-enabled for ' . $customer['full_name'] . '.');
            redirect($backUrl);
        }

        $email    = sanitize($_POST['email'] ?? $customer['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');
        $existing = $email !== '' ? $this->users->findByEmail($email) : null;

        // Adopting an existing account never touches its password: the person
        // signing in keeps the credentials they already have.
        $errors = $existing
            ? $this->validateAdoption($existing, $id)
            : $this->validateAccount(['email' => $email], ['password' => $password, 'confirm' => $confirm]);
        if ($errors) {
            setFlash('error', implode(' ', $errors));
            redirect($backUrl);
        }

        $db = getDBConnection();
        $db->beginTransaction();
        try {
            if ($existing) {
                $userId = (int) $existing['id'];
                if (!$existing['is_active']) $this->users->update($userId, ['is_active' => 1]);
                if (!$this->model->linkUser($id, $userId)) throw new RuntimeException('Customer could not be linked.');
            } else {
                $userId = $this->createAccountFor($id, [
                    'full_name' => $customer['full_name'],
                    'email'     => $email,
                    'phone'     => $customer['phone'],
                    'branch_id' => $customer['branch_id'] ?? null,
                ], $password);
                if (!$userId) throw new RuntimeException('User account could not be written.');
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('Enable customer login failed, rolled back: ' . $e->getMessage());
            setFlash('error', 'Could not enable login access. Nothing was changed.');
            redirect($backUrl);
        }

        logAudit('enabled_customer_login', 'customer', $id);
        setFlash('success', $existing
            ? 'Existing account linked to this customer. Their current password is unchanged.'
            : 'Login access enabled. Role set to Customer.');
        redirect($backUrl);
    }

    /**
     * Build the missing customer profile behind an account that already carries
     * the Customer role.
     *
     * These are the rows that made the two lists disagree: an account listed
     * under Users & Roles as a customer, with nothing in the Customers module
     * to match it. Such an account can do nothing useful — it cannot see a
     * lease, a payment or file a maintenance request, because every one of
     * those scopes is read through customers.user_id.
     *
     * The profile is created from the account's own details and linked, so one
     * person ends up with one record on each side — never a second, unrelated
     * customer.
     */
    public function createProfileForUser(): void
    {
        authorize('customers.create-profile');
        enforceCSRF();

        $userId  = (int)($_GET['user_id'] ?? 0);
        $listUrl = APP_URL . '/index.php?page=users';
        $user    = $this->users->findById($userId);

        if (!$user) { setFlash('error', 'User not found.'); redirect($listUrl); }
        if ($user['role_name'] !== ROLE_CUSTOMER) {
            setFlash('error', 'Only accounts with the Customer role can have a customer profile.');
            redirect($listUrl);
        }
        if ($existing = $this->model->findByUserId($userId)) {
            setFlash('error', 'This account already has a customer profile.');
            redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $existing['id']);
        }

        $db = getDBConnection();
        $db->beginTransaction();
        try {
            // 'both' rather than a guess at tenant or buyer: the account itself
            // says nothing about which, and narrowing it wrongly would hide the
            // customer from the list the office actually searches.
            $customerId = $this->model->create([
                'full_name'     => $user['full_name'] ?: $user['username'],
                'phone'         => $user['phone'] ?: '',
                'email'         => $user['email'],
                'customer_type' => 'both',
                'branch_id'     => $user['branch_id'] ?? null,
                'user_id'       => $userId,
            ]);
            if (!$customerId) throw new RuntimeException('Customer profile could not be written.');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('Customer profile backfill failed, rolled back: ' . $e->getMessage());
            setFlash('error', 'Could not create the customer profile. Nothing was changed.');
            redirect($listUrl);
        }

        logAudit('created_customer_profile_for_user', 'customer', $customerId);
        setFlash('success', 'Customer profile created and linked to this account. Set the type — tenant or buyer — and the rest of the details when you have them.');
        redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $customerId);
    }

    /**
     * Take login access away without touching the business record: the
     * customer, their leases, payments and maintenance history all stay
     * exactly where they are — only the account stops working.
     */
    public function disableLogin(): void
    {
        authorize('customers.disable-login');
        enforceCSRF();

        $id       = (int)($_GET['id'] ?? 0);
        $customer = $this->model->findById($id);
        if (!$customer) { setFlash('error', 'Customer not found.'); redirect(APP_URL . '/index.php?page=customers'); }

        if ($customer['user_id']) {
            $this->users->update((int) $customer['user_id'], ['is_active' => 0]);
            logAudit('disabled_customer_login', 'customer', $id);
            setFlash('success', 'Login disabled. The customer and all their records are unchanged.');
        } else {
            setFlash('error', 'This customer has no account to disable.');
        }
        redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $id);
    }

    public function blacklist(): void
    {
        authorize('customers.blacklist');
        $id = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            enforceCSRF();
            $reason = $_POST['reason'] ?? 'other';
            $description = sanitize($_POST['description'] ?? '');
            $db = getDBConnection();
            $db->prepare("INSERT INTO blacklist_records (customer_id, reason, description, blacklisted_by) VALUES (?,?,?,?)")
               ->execute([$id, $reason, $description, $_SESSION['user_id'] ?? null]);
            $this->model->update($id, ['is_blacklisted' => 1]);
            logAudit('blacklisted_customer', 'customer', $id, '', $reason);
            setFlash('success', 'Customer blacklisted.');
        }
        redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $id);
    }

    public function unlist(): void
    {
        authorize('customers.unlist');
        $id = (int)($_GET['id'] ?? 0);
        $db = getDBConnection();
        $db->prepare("UPDATE blacklist_records SET is_active=0, lifted_at=NOW(), lifted_by=? WHERE customer_id=? AND is_active=1")
           ->execute([$_SESSION['user_id'] ?? null, $id]);
        $this->model->update($id, ['is_blacklisted' => 0]);
        logAudit('unblacklisted_customer', 'customer', $id);
        setFlash('success', 'Customer removed from blacklist.');
        redirect(APP_URL . '/index.php?page=customers&action=show&id=' . $id);
    }

    // ─── Validation ─────────────────────────────────────────

    /** @return string[] messages for the flash, with field hints stored separately */
    private function validateCustomer(array $d, ?int $excludeId = null): array
    {
        $errors = [];
        // Shape first, from the shared ruleset: required, letters-only names,
        // a real email, a phone that matches its own country's digit lengths.
        validateSharedFields($d, $errors, ['full_name', 'phone']);

        // The type decides which lists and reports this person belongs in, so a
        // value outside the column's enum is refused here rather than being
        // rejected later by the database with an error nobody can act on.
        if (!in_array($d['customer_type'], self::TYPES, true)) {
            $this->fieldError($errors, 'customer_type', 'Choose a customer type: Tenant, Buyer, or Both.');
        }

        // A second profile for the same person is the very thing that split the
        // Customers and Users lists apart, so identity clashes are refused here.
        if ($d['email'] !== '' && ($clash = $this->model->findByEmail($d['email'], $excludeId))) {
            $this->fieldError($errors, 'email',
                'A customer with this email already exists (' . $clash['full_name'] . '). Edit that customer instead.');
        }
        if ($d['phone'] !== '' && ($clash = $this->model->findByPhone($d['phone'], $excludeId))) {
            $this->fieldError($errors, 'phone',
                'A customer with this phone already exists (' . $clash['full_name'] . '). Edit that customer instead.');
        }
        return $errors;
    }

    /** Checks that only apply when login access is being switched on. */
    private function validateAccount(array $d, array $creds, ?int $excludeUserId = null): array
    {
        $errors = [];
        $email  = trim((string) ($d['email'] ?? ''));

        if ($email === '') {
            $this->fieldError($errors, 'email', 'Email is required to give this customer login access — it is how they sign in.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fieldError($errors, 'email', 'That email address is not valid.');
        } elseif ($this->users->emailExists($email, $excludeUserId)) {
            $this->fieldError($errors, 'email',
                'This email already belongs to another user account. Use a different email, or enable login from that customer’s page.');
        }

        if (strlen($creds['password']) < PASSWORD_MIN_LENGTH) {
            $this->fieldError($errors, 'password', 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.');
        } elseif ($creds['password'] !== $creds['confirm']) {
            $this->fieldError($errors, 'confirm_password', 'The two passwords do not match.');
        }
        return $errors;
    }

    /**
     * Whether an account found by email may be adopted by this customer.
     *
     * Stricter than the owner equivalent, and deliberately so: an account holds
     * exactly one role, so adopting a staff account would silently demote it
     * and take away everything that person does all day. A role change is a
     * decision made under Users & Roles, never a side effect of saving a
     * customer.
     */
    private function validateAdoption(array $user, int $customerId): array
    {
        $errors = [];

        $linked = $this->model->findByUserId((int) $user['id']);
        if ($linked && (int) $linked['id'] !== $customerId) {
            $errors[] = 'That account already belongs to customer "' . $linked['full_name'] . '". Use a different email.';
        }

        if ($user['role_name'] !== ROLE_CUSTOMER) {
            $errors[] = 'That email belongs to an account with the "' . ($user['role_display'] ?: $user['role_name'])
                . '" role, not Customer. Giving it the Customer role would take that access away, so it is not done automatically —'
                . ' use a different email for this customer, or change the role deliberately under Users & Roles.';
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
     * Create the account behind a customer and link the two.
     * Caller owns the transaction; failure is reported, never swallowed.
     */
    private function createAccountFor(int $customerId, array $d, string $password): int|false
    {
        $roleId = $this->users->roleIdByName(ROLE_CUSTOMER);
        if (!$roleId) throw new RuntimeException('Customer role is missing from the roles table.');

        $userId = $this->users->create([
            'full_name' => $d['full_name'],
            'email'     => $d['email'],
            'phone'     => $d['phone'] ?? '',
            'username'  => $this->users->suggestUsername($d['email'], $d['full_name']),
            'password'  => $password,   // hashed inside User::create()
            'role_id'   => $roleId,
            'branch_id' => $d['branch_id'] ?? null,
        ]);
        if (!$userId) return false;

        return $this->model->linkUser($customerId, $userId) ? $userId : false;
    }

    private function extractData(): array
    {
        // The three enum columns are whitelisted rather than trusted: a value
        // outside the set is normalised to the default here and reported by
        // validateCustomer(), so a tampered <select> cannot write nonsense.
        $gender = $_POST['gender'] ?? '';

        $d = [
            'full_name'         => sanitize($_POST['full_name'] ?? ''),
            'email'             => sanitize($_POST['email'] ?? ''),
            'phone'             => sanitize($_POST['phone'] ?? ''),
            'address'           => sanitize($_POST['address'] ?? ''),
            'gender'            => in_array($gender, self::GENDER, true) ? $gender : null,
            'national_id'       => sanitize($_POST['national_id'] ?? ''),
            'emergency_contact' => sanitize($_POST['emergency_contact'] ?? ''),
            'emergency_phone'   => sanitize($_POST['emergency_phone'] ?? ''),
            'employment_status' => sanitize($_POST['employment_status'] ?? ''),
            'occupation'        => sanitize($_POST['occupation'] ?? ''),
            'guarantor_name'    => sanitize($_POST['guarantor_name'] ?? ''),
            'guarantor_contact' => sanitize($_POST['guarantor_contact'] ?? ''),
            'customer_type'     => $_POST['customer_type'] ?? 'both',
            'notes'             => sanitize($_POST['notes'] ?? ''),
            'risk_level'        => in_array($_POST['risk_level'] ?? '', self::RISKS, true) ? $_POST['risk_level'] : 'low',
        ];

        /* Number and country selector folded into one stored value before
           anything else looks at it — validation, the duplicate-phone check
           and the write all have to be reading the same string. */
        normalisePhoneFields($d);

        return $d;
    }
}
