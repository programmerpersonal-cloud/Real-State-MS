<?php
/**
 * Saxane Real Estate — Front Controller Router
 * Maps ?page= parameter to controller actions and views.
 */

require_once __DIR__ . '/includes/init.php';

$page = $_GET['page'] ?? '';
$action = $_GET['action'] ?? '';

// ─── Public / Auth Routes (no login required) ──────────
switch ($page) {
    // ─── SEO endpoints (served via .htaccess rewrites) ─
    case 'robots':
        renderRobotsTxt();
        exit;

    case 'sitemap':
        renderSitemapXml();
        exit;

    case 'login':
        require_once BASE_PATH . '/controllers/AuthController.php';
        (new AuthController())->login();
        exit;

    case 'register':
        require_once BASE_PATH . '/controllers/AuthController.php';
        (new AuthController())->register();
        exit;

    case 'logout':
        require_once BASE_PATH . '/controllers/AuthController.php';
        (new AuthController())->logout();
        exit;

    // ─── Document delivery ─────────────────────────────
    // Documents are stored outside the web root and are never served by
    // Apache, so every read goes through PHP. This is routed here, ahead of
    // requireLogin(), because a document published on a listing has to work
    // for a visitor with no account — download() checks visibility itself and
    // demands a session for anything that is not public.
    //
    // Note the `break` rather than `exit`: every other arm in this switch ends
    // the request, but the remaining document actions (the admin list, upload,
    // archive…) belong to the authenticated switch further down, so this one
    // has to fall through to it.
    case 'documents':
        if ($action === 'download') {
            require_once BASE_PATH . '/controllers/DocumentController.php';
            (new DocumentController())->download();
            exit;
        }
        break;

    // ─── Public marketing & browsing pages ─────────────
    case 'home':
    case 'about':
    case 'contact':
    case 'privacy':
    case 'terms':
    case 'services':
    case 'thanks':
        $publicView = $page;
        if ($page === 'home') {
            require_once BASE_PATH . '/models/Property.php';
            $propertyModel = new Property();

            // One extra row: the first becomes the spotlight, the rest fill
            // the featured grid, so both sections come from a single query.
            $homeProperties     = $propertyModel->getAll(['status' => 'available'], 7, 0);
            $spotlightProperty  = array_shift($homeProperties) ?: null;
            $featuredProperties = $homeProperties;

            // Covers for every property shown on the page, in one query.
            $homeIds = array_column(
                array_filter(array_merge([$spotlightProperty], $featuredProperties)),
                'id'
            );
            $homeCovers   = $propertyModel->getCoversFor($homeIds);
            $categoryCounts = $propertyModel->countByCategory('available');

            try {
                $db = getDBConnection();
                $statTotal     = (int) $db->query("SELECT COUNT(*) FROM properties WHERE is_archived = 0")->fetchColumn();
                $statAvailable = (int) $db->query("SELECT COUNT(*) FROM properties WHERE status = 'available' AND is_archived = 0")->fetchColumn();
                $statCustomers = (int) $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
                $statBranches  = (int) $db->query("SELECT COUNT(*) FROM branches")->fetchColumn();

                // Real agents, ranked by how many listings they carry.
                $agentStmt = $db->query("
                    SELECT u.id, u.full_name, u.email, u.phone, u.avatar, b.name AS branch_name,
                           COUNT(p.id) AS listing_count
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN branches b ON u.branch_id = b.id
                    LEFT JOIN properties p ON p.agent_id = u.id AND p.is_archived = 0
                    WHERE r.name = 'agent' AND u.is_active = 1
                      AND TRIM(COALESCE(u.full_name, '')) <> ''
                    GROUP BY u.id, u.full_name, u.email, u.phone, u.avatar, b.name
                    ORDER BY listing_count DESC, u.full_name ASC
                    LIMIT 4
                ");
                $featuredAgents = $agentStmt->fetchAll();
            } catch (Throwable $e) {
                $statTotal = $statAvailable = $statCustomers = $statBranches = 0;
                $featuredAgents = [];
            }
        }
        if ($page === 'contact' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Public inquiry submission — store via Inquiry model if available.
            $inquirySubject = trim((string) ($_POST['subject'] ?? '')) ?: 'General inquiry';
            try {
                require_once BASE_PATH . '/models/Inquiry.php';
                $inq = new Inquiry();
                $inq->create([
                    'property_id' => !empty($_POST['property_id']) ? (int)$_POST['property_id'] : null,
                    'name'        => $_POST['name']    ?? '',
                    'email'       => $_POST['email']   ?? '',
                    'phone'       => $_POST['phone']   ?? '',
                    'subject'     => $inquirySubject,
                    'message'     => $_POST['message'] ?? '',
                ]);
            } catch (Throwable $e) {
                // The visitor still gets a confirmation; the failure is ours to fix.
            }
            // Post/Redirect/Get onto a dedicated confirmation page: a refresh
            // can't resubmit the enquiry, and the thank-you URL is a clean
            // conversion goal in analytics rather than a flash message that
            // disappears on the next navigation.
            $_SESSION['inquiry_subject'] = $inquirySubject;
            redirect(APP_URL . '/index.php?page=thanks');
        }

        // About: the team is read from the staff records that actually exist,
        // so the page shows the real organisation rather than invented people.
        if ($page === 'about') {
            try {
                $db = getDBConnection();
                $stmt = $db->query("
                    SELECT u.id, u.full_name, u.email, u.phone, u.avatar, u.created_at,
                           r.name AS role_name, b.name AS branch_name,
                           (SELECT COUNT(*) FROM properties p
                             WHERE p.agent_id = u.id AND p.is_archived = 0) AS listing_count
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN branches b ON u.branch_id = b.id
                    WHERE r.name IN ('admin', 'agent', 'maintenance')
                      AND u.is_active = 1
                      AND TRIM(COALESCE(u.full_name, '')) <> ''
                    ORDER BY FIELD(r.name, 'admin', 'agent', 'maintenance'), u.full_name
                ");
                $teamMembers = $stmt->fetchAll();
                $teamSince   = (int) $db->query(
                    "SELECT COALESCE(YEAR(MIN(created_at)), 0) FROM users"
                )->fetchColumn();
            } catch (Throwable $e) {
                $teamMembers = [];
                $teamSince   = 0;
            }
        }

        // Data the home page needs beyond listings.
        if ($page === 'home') {
            require_once BASE_PATH . '/models/Testimonial.php';
            $testimonialModel = new Testimonial();
            $homeReviews      = $testimonialModel->getApproved(3);
            $reviewSummary    = $testimonialModel->ratingSummary();
            $caseStudies      = recentCaseStudies(3);
        }

        // Pages carrying the FAQ accordion also emit FAQPage structured data.
        // Set here rather than in the view so the layout <head> can read it.
        if (in_array($page, ['home', 'contact'], true)) {
            $pageFaqs = siteFaqs();
        }
        require VIEWS_PATH . '/public/layout.php';
        exit;

    case 'listings':
        require_once BASE_PATH . '/models/Property.php';
        $propertyModel = new Property();
        $filters = [
            'search'        => trim($_GET['search']   ?? ''),
            'property_type' => $_GET['property_type'] ?? '',
            'category'      => $_GET['category']      ?? '',
            'status'        => 'available',
            'min_price'     => $_GET['min_price']     ?? '',
            'max_price'     => $_GET['max_price']     ?? '',
            'min_rooms'     => $_GET['min_rooms']     ?? '',
            'min_baths'     => $_GET['min_baths']     ?? '',
            'sort'          => $_GET['sort']          ?? 'newest',
        ];
        $perPage     = 12;
        $currentPage = max(1, (int)($_GET['p'] ?? 1));
        $totalCount  = $propertyModel->count($filters);
        $totalPages  = max(1, (int) ceil($totalCount / $perPage));

        // Clamp out-of-range page numbers so a deep link to ?p=99 shows the
        // last real page instead of an empty grid.
        $currentPage = min($currentPage, $totalPages);
        $properties  = $propertyModel->getAll($filters, $perPage, ($currentPage - 1) * $perPage);
        $covers      = $propertyModel->getCoversFor(array_column($properties, 'id'));

        $publicView  = 'listings';
        require VIEWS_PATH . '/public/layout.php';
        exit;

    case 'listing':
        require_once BASE_PATH . '/models/Property.php';
        $propertyModel = new Property();
        $id = (int)($_GET['id'] ?? 0);
        $property = $id > 0 ? $propertyModel->findById($id) : null;
        if (!$property || !empty($property['is_archived'])) {
            http_response_code(404);
            $publicView = '404';
        } else {
            $images  = $propertyModel->getImages($id);
            $similar = $propertyModel->getSimilar($id, (string) $property['category'], 3);
            $similarCovers = $propertyModel->getCoversFor(array_column($similar, 'id'));

            // Documents published with this listing. Scoped to public+active
            // here as well as in the delivery endpoint: this page is rendered
            // for anonymous visitors, so it must never load anything else.
            $publicDocuments = [];
            if (documentPublicEnabled() && propertyIsPubliclyVisible($property)) {
                require_once BASE_PATH . '/models/Document.php';
                $publicDocuments = (new Document())->forReference('property', $id, [
                    'visibility_in' => ['public'],
                    'state'         => 'active',
                ]);
            }

            $publicView = 'listing';
        }
        require VIEWS_PATH . '/public/layout.php';
        exit;

    case 'service':
        // Slug-addressed marketing page; unknown slugs 404 rather than
        // rendering an empty shell.
        $serviceSlug = (string) ($_GET['id'] ?? '');
        $service     = siteService($serviceSlug);
        if (!$service) {
            http_response_code(404);
            $publicView = '404';
        } else {
            $publicView = 'service';
        }
        require VIEWS_PATH . '/public/layout.php';
        exit;

    case 'agents':
        // Public directory of active agents, ranked by live inventory.
        try {
            $db = getDBConnection();
            $agents = $db->query("
                SELECT u.id, u.full_name, u.email, u.phone, u.avatar, b.name AS branch_name,
                       COUNT(p.id) AS listing_count
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN branches b ON u.branch_id = b.id
                LEFT JOIN properties p
                       ON p.agent_id = u.id AND p.is_archived = 0 AND p.status = 'available'
                WHERE r.name = 'agent' AND u.is_active = 1
                  AND TRIM(COALESCE(u.full_name, '')) <> ''
                GROUP BY u.id, u.full_name, u.email, u.phone, u.avatar, b.name
                ORDER BY listing_count DESC, u.full_name ASC
            ")->fetchAll();

            $agentTotal        = count($agents);
            $agentListingTotal = array_sum(array_column($agents, 'listing_count'));
            $branchTotal       = (int) $db->query("SELECT COUNT(*) FROM branches")->fetchColumn();
        } catch (Throwable $e) {
            $agents = [];
            $agentTotal = $agentListingTotal = $branchTotal = 0;
        }
        $publicView = 'agents';
        require VIEWS_PATH . '/public/layout.php';
        exit;

    case 'agent':
        $agentId = (int) ($_GET['id'] ?? 0);
        $agent   = null;

        if ($agentId > 0) {
            try {
                $db   = getDBConnection();
                $stmt = $db->prepare("
                    SELECT u.id, u.full_name, u.email, u.phone, u.avatar, u.created_at,
                           b.name AS branch_name
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN branches b ON u.branch_id = b.id
                    WHERE u.id = :id AND r.name = 'agent' AND u.is_active = 1
                ");
                $stmt->execute([':id' => $agentId]);
                $agent = $stmt->fetch() ?: null;
            } catch (Throwable $e) {
                $agent = null;
            }
        }

        if (!$agent) {
            http_response_code(404);
            $publicView = '404';
        } else {
            require_once BASE_PATH . '/models/Property.php';
            $propertyModel = new Property();

            $agentProperties = $propertyModel->getAll(
                ['agent_id' => $agentId, 'status' => 'available'], 6, 0
            );
            $agentCovers = $propertyModel->getCoversFor(array_column($agentProperties, 'id'));

            $agentStats = [
                'listings' => $propertyModel->count(['agent_id' => $agentId, 'status' => 'available']),
                'total'    => $propertyModel->count(['agent_id' => $agentId]),
                'since'    => !empty($agent['created_at'])
                    ? date('F Y', strtotime((string) $agent['created_at']))
                    : '',
            ];
            $publicView = 'agent';
        }
        require VIEWS_PATH . '/public/layout.php';
        exit;

    case '':
        if (isLoggedIn()) {
            redirect(APP_URL . '/index.php?page=dashboard');
        } else {
            redirect(APP_URL . '/index.php?page=home');
        }
        exit;
}

// ─── All routes below require authentication ────────────
requireLogin();
$currentUser = getCurrentUser();
$role = getUserRole();

/**
 * Helper: dispatch a controller method as a route action.
 * - 'index' / '' → list
 * - 'create' / 'edit' / 'show' / 'delete' / others → method on controller
 * If the controller method returns, we skip view rendering (controllers manage their own views).
 */
$viewFile = null;
$controllerHandled = false;

function dispatch(string $controllerClass, string $method): void {
    global $controllerHandled;
    $controllerHandled = true;
    require_once BASE_PATH . '/controllers/' . $controllerClass . '.php';
    $c = new $controllerClass();
    if (!method_exists($c, $method)) {
        http_response_code(404);
        echo "Action not found: {$method}";
        exit;
    }
    $c->$method();
}

switch ($page) {

    case 'dashboard':
        $dashboardMap = [
            'admin'       => '/views/admin/dashboard.php',
            'agent'       => '/views/agent/dashboard.php',
            'customer'    => '/views/customer/dashboard.php',
            'owner'       => '/views/owner/dashboard.php',
            'maintenance' => '/views/maintenance/dashboard.php',
        ];
        $viewFile = $dashboardMap[$role] ?? '/views/admin/dashboard.php';
        break;

    // ─── Properties ────────────────────────────────────
    case 'properties':
        $method = match ($action) {
            'create'       => 'create',
            'edit'         => 'edit',
            'show'         => 'show',
            'delete-image' => 'deleteImage',
            'approve'      => 'approve',
            'archive'      => 'archive',
            default        => 'index',
        };
        dispatch('PropertyController', $method);
        break;

    // ─── Customers ─────────────────────────────────────
    case 'customers':
        $method = match ($action) {
            'create'         => 'create',
            'edit'           => 'edit',
            'show'           => 'show',
            'blacklist'      => 'blacklist',
            'unlist'         => 'unlist',
            'enable-login'   => 'enableLogin',
            'disable-login'  => 'disableLogin',
            'create-profile' => 'createProfileForUser',
            default          => 'index',
        };
        dispatch('CustomerController', $method);
        break;

    // ─── Owners ────────────────────────────────────────
    case 'owners':
        $method = match ($action) {
            'create'        => 'create',
            'edit'          => 'edit',
            'show'          => 'show',
            'enable-login'  => 'enableLogin',
            'disable-login' => 'disableLogin',
            'create-profile'=> 'createProfileForUser',
            default         => 'index',
        };
        dispatch('OwnerController', $method);
        break;

    // ─── Leases ────────────────────────────────────────
    case 'leases':
        $method = match ($action) {
            'create'    => 'create',
            'edit'      => 'edit',
            'show'      => 'show',
            'renew'     => 'renew',
            'terminate' => 'terminate',
            default     => 'index',
        };
        dispatch('LeaseController', $method);
        break;

    // ─── Payments ──────────────────────────────────────
    case 'payments':
        $method = match ($action) {
            'create' => 'create',
            'show'   => 'show',
            'receipt'=> 'receipt',
            default  => 'index',
        };
        dispatch('PaymentController', $method);
        break;

    // ─── Sales ─────────────────────────────────────────
    case 'sales':
        $method = match ($action) {
            'create' => 'create',
            'show'   => 'show',
            default  => 'index',
        };
        dispatch('SaleController', $method);
        break;

    // ─── Reservations ──────────────────────────────────
    case 'reservations':
        $method = match ($action) {
            'create'  => 'create',
            'cancel'  => 'cancel',
            'confirm' => 'confirm',
            default   => 'index',
        };
        dispatch('ReservationController', $method);
        break;

    // ─── Maintenance ───────────────────────────────────
    case 'maintenance':
        $method = match ($action) {
            'create' => 'create',
            'show'   => 'show',
            'update' => 'update',
            'assign' => 'assign',
            default  => 'index',
        };
        dispatch('MaintenanceController', $method);
        break;

    // ─── Inquiries ─────────────────────────────────────
    case 'inquiries':
        $method = match ($action) {
            'create' => 'create',
            'show'   => 'show',
            'reply'  => 'reply',
            default  => 'index',
        };
        dispatch('InquiryController', $method);
        break;

    // ─── Testimonials (public review moderation) ───────
    case 'testimonials':
        $method = match ($action) {
            'form'    => 'form',
            'save'    => 'save',
            'approve' => 'approve',
            'delete'  => 'delete',
            default   => 'index',
        };
        dispatch('TestimonialController', $method);
        break;

    // ─── Notifications ─────────────────────────────────
    case 'notifications':
        $method = match ($action) {
            'read-all' => 'readAll',
            'read'     => 'markRead',
            default    => 'index',
        };
        dispatch('NotificationController', $method);
        break;

    // ─── Reports ───────────────────────────────────────
    case 'reports':
        $method = match ($action) {
            'occupancy'  => 'occupancy',
            'revenue'    => 'revenue',
            'commission' => 'commission',
            'arrears'    => 'arrears',
            default      => 'index',
        };
        dispatch('ReportController', $method);
        break;

    // ─── Audit Logs ────────────────────────────────────
    case 'audit-logs':
        dispatch('AuditController', 'index');
        break;

    // ─── Documents ─────────────────────────────────────
    // action=download never reaches here: the public switch above handled and
    // exited it before requireLogin() ran.
    case 'documents':
        $method = match ($action) {
            'create'  => 'create',
            'edit'    => 'edit',
            'show'    => 'show',
            'archive' => 'archive',
            'restore' => 'restore',
            'delete'  => 'delete',
            default   => 'index',
        };
        dispatch('DocumentController', $method);
        break;

    // ─── Document categories ───────────────────────────
    case 'document-categories':
        $method = match ($action) {
            'save'   => 'save',
            'toggle' => 'toggle',
            'move'   => 'move',
            'delete' => 'delete',
            default  => 'index',
        };
        dispatch('DocumentCategoryController', $method);
        break;

    // ─── Terms & Conditions ────────────────────────────
    case 'legal':
        $method = match ($action) {
            'version'      => 'version',
            'create'       => 'create',
            'edit'         => 'edit',
            'preview'      => 'preview',
            'publish'      => 'publish',
            'withdraw'     => 'withdraw',
            'revise'       => 'revise',
            'delete-draft' => 'deleteDraft',
            'acceptances'  => 'acceptances',
            'save-type'    => 'saveType',
            'toggle-type'  => 'toggleType',
            default        => 'index',
        };
        dispatch('LegalController', $method);
        break;

    // ─── Settings ──────────────────────────────────────
    case 'settings':
        $method = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'update' : 'index';
        dispatch('SettingsController', $method);
        break;

    // ─── Branches ──────────────────────────────────────
    case 'branches':
        $method = match ($action) {
            'create' => 'create',
            'edit'   => 'edit',
            default  => 'index',
        };
        dispatch('BranchController', $method);
        break;

    // ─── Users (admin) ─────────────────────────────────
    case 'users':
        $method = match ($action) {
            'create'     => 'create',
            'edit'       => 'edit',
            'toggle'     => 'toggle',
            'reset-pass' => 'resetPassword',
            default      => 'index',
        };
        dispatch('UserController', $method);
        break;

    // ─── Profile ───────────────────────────────────────
    case 'profile':
        dispatch('ProfileController', $_SERVER['REQUEST_METHOD'] === 'POST' ? 'update' : 'index');
        break;

    // ─── Customer-facing routes ────────────────────────
    case 'my-lease':
        dispatch('CustomerPortalController', 'myLease');
        break;
    case 'my-payments':
        dispatch('CustomerPortalController', 'myPayments');
        break;
    case 'favorites':
        $method = match ($action) {
            'add'    => 'add',
            'remove' => 'remove',
            default  => 'index',
        };
        dispatch('FavoritesController', $method);
        break;

    // ─── Owner portal ──────────────────────────────────
    case 'my-properties':
        dispatch('OwnerPortalController', 'myProperties');
        break;
    case 'my-income':
        dispatch('OwnerPortalController', 'myIncome');
        break;

    default:
        $viewFile = null;
        break;
}

// If a controller handled the request (rendered its own view), exit.
if ($controllerHandled) {
    exit;
}

// Render Layout for view-only routes (dashboard, etc.)
$pageTitle = ucfirst($page ?: 'Dashboard');
require VIEWS_PATH . '/layout.php';
