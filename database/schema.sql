-- ============================================================
-- Saxane Real Estate Management System
-- Database Schema v1.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS saxane_realestate
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE saxane_realestate;

-- ─── BRANCHES ──────────────────────────────────────────────
CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    manager_name VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── ROLES ─────────────────────────────────────────────────
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO roles (name, display_name, description) VALUES
('admin', 'Administrator', 'Full system access and management'),
('agent', 'Real Estate Agent', 'Manages properties, customers, and transactions'),
('customer', 'Customer', 'Property seeker, buyer, or tenant'),
('owner', 'Property Owner', 'Legal owner of managed properties'),
('maintenance', 'Maintenance Staff', 'Handles maintenance and repair tasks');

-- ─── USERS ─────────────────────────────────────────────────
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    role_id INT NOT NULL,
    branch_id INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    email_verified_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    INDEX idx_users_email (email),
    INDEX idx_users_role (role_id)
) ENGINE=InnoDB;

-- ─── CUSTOMERS ─────────────────────────────────────────────
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20) NOT NULL,
    address TEXT,
    gender ENUM('male','female','other') DEFAULT NULL,
    national_id VARCHAR(50),
    profile_photo VARCHAR(255),
    emergency_contact VARCHAR(100),
    emergency_phone VARCHAR(20),
    employment_status VARCHAR(50),
    occupation VARCHAR(100),
    guarantor_name VARCHAR(100),
    guarantor_contact VARCHAR(20),
    customer_type ENUM('tenant','buyer','both') DEFAULT 'both',
    is_blacklisted TINYINT(1) DEFAULT 0,
    notes TEXT,
    risk_level ENUM('low','medium','high') DEFAULT 'low',
    created_by INT DEFAULT NULL,
    branch_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    INDEX idx_customers_phone (phone),
    INDEX idx_customers_national_id (national_id)
) ENGINE=InnoDB;

-- ─── OWNERS ────────────────────────────────────────────────
CREATE TABLE owners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    address TEXT,
    national_id VARCHAR(50),
    bank_name VARCHAR(100),
    bank_account VARCHAR(50),
    commission_rate DECIMAL(5,2) DEFAULT 5.00,
    revenue_share DECIMAL(5,2) DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── PROPERTIES ────────────────────────────────────────────
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_code VARCHAR(20) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    property_type ENUM('rent','sale','both') DEFAULT 'rent',
    category ENUM('house','apartment','villa','land','office','commercial','warehouse') DEFAULT 'apartment',
    description TEXT,
    location VARCHAR(255),
    address TEXT,
    latitude DECIMAL(10,8) DEFAULT NULL,
    longitude DECIMAL(11,8) DEFAULT NULL,
    size_sqm DECIMAL(10,2) DEFAULT NULL,
    num_rooms INT DEFAULT 0,
    num_bathrooms INT DEFAULT 0,
    num_floors INT DEFAULT 1,
    price DECIMAL(15,2) DEFAULT NULL,
    rent_amount DECIMAL(15,2) DEFAULT NULL,
    deposit_amount DECIMAL(15,2) DEFAULT NULL,
    is_furnished TINYINT(1) DEFAULT 0,
    has_parking TINYINT(1) DEFAULT 0,
    has_security TINYINT(1) DEFAULT 0,
    utilities_included TEXT,
    status ENUM('available','reserved','rented','sold','inactive','maintenance') DEFAULT 'available',
    -- Approval gates public visibility. An agent's listing is created
    -- 'pending' and an administrator decides; an administrator's own listing
    -- is created 'approved', because self-approval is not a review.
    approval_status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    approval_note TEXT DEFAULT NULL,
    owner_id INT DEFAULT NULL,
    agent_id INT DEFAULT NULL,
    branch_id INT DEFAULT NULL,
    -- Who submitted the listing. Distinct from agent_id, which is the
    -- assignment and can be reassigned by an administrator.
    created_by INT DEFAULT NULL,
    -- Archive is reversible: status_before_archive holds the status the
    -- property carried on its way in, so a restore returns it to the
    -- register in the state it left. Independent of approval — archiving
    -- neither approves nor un-approves.
    is_archived TINYINT(1) DEFAULT 0,
    archived_at DATETIME DEFAULT NULL,
    archived_by INT DEFAULT NULL,
    status_before_archive VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE SET NULL,
    FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_prop_status (status),
    INDEX idx_prop_type (property_type),
    INDEX idx_prop_category (category),
    INDEX idx_prop_code (property_code),
    INDEX idx_prop_approval (approval_status, is_archived, created_at),
    INDEX idx_prop_archived (is_archived, archived_at)
) ENGINE=InnoDB;

-- ─── PROPERTY IMAGES ───────────────────────────────────────
CREATE TABLE property_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    is_cover TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── PROPERTY HISTORY ──────────────────────────────────────
CREATE TABLE property_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    field_changed VARCHAR(100),
    old_value TEXT,
    new_value TEXT,
    changed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── LEASES ────────────────────────────────────────────────
CREATE TABLE leases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_code VARCHAR(20) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    property_id INT NOT NULL,
    owner_id INT DEFAULT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    rent_amount DECIMAL(15,2) NOT NULL,
    deposit_amount DECIMAL(15,2) DEFAULT 0,
    payment_schedule ENUM('monthly','quarterly','yearly') DEFAULT 'monthly',
    late_fee_rate DECIMAL(5,2) DEFAULT 2.00,
    terms TEXT,
    contract_file VARCHAR(255),
    signature_status ENUM('pending','signed','expired') DEFAULT 'pending',
    status ENUM('active','expired','terminated','renewed') DEFAULT 'active',
    termination_reason TEXT,
    move_in_date DATE,
    move_out_date DATE,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (property_id) REFERENCES properties(id),
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_lease_status (status),
    INDEX idx_lease_dates (start_date, end_date)
) ENGINE=InnoDB;

-- ─── RENTALS ───────────────────────────────────────────────
CREATE TABLE rentals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    property_id INT NOT NULL,
    customer_id INT NOT NULL,
    move_in_date DATE,
    move_out_date DATE,
    monthly_rent DECIMAL(15,2) NOT NULL,
    deposit_paid DECIMAL(15,2) DEFAULT 0,
    status ENUM('active','completed','terminated') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lease_id) REFERENCES leases(id),
    FOREIGN KEY (property_id) REFERENCES properties(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;

-- ─── SALES ─────────────────────────────────────────────────
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_code VARCHAR(20) NOT NULL UNIQUE,
    property_id INT NOT NULL,
    customer_id INT NOT NULL,
    sale_amount DECIMAL(15,2) NOT NULL,
    -- Tax is stored as an amount plus the rate it was charged at, so reprinting
    -- an old receipt never applies today's rate to yesterday's sale.
    tax_amount DECIMAL(15,2) DEFAULT 0,
    tax_rate DECIMAL(5,2) DEFAULT 0,
    commission_amount DECIMAL(15,2) DEFAULT 0,
    payment_type ENUM('full','installment') DEFAULT 'full',
    status ENUM('pending','completed','cancelled') DEFAULT 'pending',
    sale_date DATE,
    agent_id INT DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── PAYMENTS ──────────────────────────────────────────────
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_code VARCHAR(20) NOT NULL UNIQUE,
    payment_type ENUM('rent','sale','deposit','refund','late_fee','other') NOT NULL,
    reference_type ENUM('lease','sale','reservation') NOT NULL,
    reference_id INT NOT NULL,
    customer_id INT NOT NULL,
    property_id INT DEFAULT NULL,
    amount DECIMAL(15,2) NOT NULL,
    due_date DATE,
    payment_date DATE,
    payment_method ENUM('cash','bank_transfer','check','card','other') DEFAULT 'cash',
    received_by INT DEFAULT NULL,
    receipt_number VARCHAR(50),
    balance_remaining DECIMAL(15,2) DEFAULT 0,
    penalty_amount DECIMAL(15,2) DEFAULT 0,
    status ENUM('pending','paid','partial','overdue','cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pay_status (status),
    INDEX idx_pay_type (payment_type),
    INDEX idx_pay_due (due_date)
) ENGINE=InnoDB;

-- ─── DEPOSITS ──────────────────────────────────────────────
CREATE TABLE deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    received_date DATE,
    refund_status ENUM('held','refunded','partial_refund','forfeited') DEFAULT 'held',
    refund_amount DECIMAL(15,2) DEFAULT 0,
    deductions DECIMAL(15,2) DEFAULT 0,
    deduction_reason TEXT,
    refund_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lease_id) REFERENCES leases(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;

-- ─── PAYMENT SCHEDULES ─────────────────────────────────────
CREATE TABLE payment_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    penalty DECIMAL(15,2) DEFAULT 0,
    status ENUM('pending','paid','overdue','partial') DEFAULT 'pending',
    paid_date DATE,
    payment_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lease_id) REFERENCES leases(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── EXPENSES ──────────────────────────────────────────────
CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category ENUM('maintenance','cleaning','utilities','advertising','commission','transport','office','other') NOT NULL,
    description TEXT,
    amount DECIMAL(15,2) NOT NULL,
    expense_date DATE,
    property_id INT DEFAULT NULL,
    branch_id INT DEFAULT NULL,
    recorded_by INT DEFAULT NULL,
    receipt_file VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── COMMISSIONS ───────────────────────────────────────────
CREATE TABLE commissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    reference_type ENUM('rental','sale') NOT NULL,
    reference_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    percentage DECIMAL(5,2),
    status ENUM('pending','approved','paid') DEFAULT 'pending',
    approved_by INT DEFAULT NULL,
    paid_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── RESERVATIONS ──────────────────────────────────────────
CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_code VARCHAR(20) NOT NULL UNIQUE,
    property_id INT NOT NULL,
    customer_id INT NOT NULL,
    reservation_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    deposit_amount DECIMAL(15,2) DEFAULT 0,
    status ENUM('active','confirmed','expired','cancelled') DEFAULT 'active',
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── MAINTENANCE REQUESTS ──────────────────────────────────
CREATE TABLE maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(20) NOT NULL UNIQUE,
    property_id INT NOT NULL,
    customer_id INT DEFAULT NULL,
    reported_by INT DEFAULT NULL,
    issue_type VARCHAR(100),
    description TEXT NOT NULL,
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    photos TEXT,
    assigned_to INT DEFAULT NULL,
    cost_estimate DECIMAL(15,2) DEFAULT 0,
    actual_cost DECIMAL(15,2) DEFAULT 0,
    status ENUM('new','under_review','assigned','in_progress','completed','rejected','cancelled') DEFAULT 'new',
    completion_date DATE,
    completion_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── INQUIRIES ─────────────────────────────────────────────
CREATE TABLE inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    name VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    subject VARCHAR(200),
    message TEXT NOT NULL,
    assigned_to INT DEFAULT NULL,
    status ENUM('open','pending','replied','closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── MESSAGES ──────────────────────────────────────────────
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    inquiry_id INT DEFAULT NULL,
    subject VARCHAR(200),
    body TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (receiver_id) REFERENCES users(id),
    FOREIGN KEY (inquiry_id) REFERENCES inquiries(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── DOCUMENTS ─────────────────────────────────────────────
CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_type ENUM('ownership','lease','contract','id_copy','receipt','invoice','inspection','maintenance_report','letter','other') NOT NULL,
    title VARCHAR(200) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    uploaded_by INT DEFAULT NULL,
    expiry_date DATE,
    is_restricted TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── NOTIFICATIONS ─────────────────────────────────────────
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    type VARCHAR(50),
    reference_type VARCHAR(50),
    reference_id INT,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── FAVORITES ─────────────────────────────────────────────
CREATE TABLE favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    property_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    UNIQUE KEY unique_favorite (user_id, property_id)
) ENGINE=InnoDB;

-- ─── BLACKLIST RECORDS ─────────────────────────────────────
CREATE TABLE blacklist_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    reason ENUM('non_payment','fraud','property_damage','contract_violation','complaints','other') NOT NULL,
    description TEXT,
    blacklisted_by INT DEFAULT NULL,
    blacklisted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    lifted_at TIMESTAMP NULL,
    lifted_by INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (blacklisted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (lifted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── AUDIT LOGS ────────────────────────────────────────────
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    old_value TEXT,
    new_value TEXT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB;

-- ─── SETTINGS ──────────────────────────────────────────────
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_group VARCHAR(50) DEFAULT 'general',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value, setting_group) VALUES
('company_name', 'Saxane Real Estate', 'general'),
('company_legal_name', '', 'general'),
('company_logo', NULL, 'general'),
('company_address', '', 'general'),
('company_phone', '', 'general'),
('company_email', '', 'general'),
('currency', 'USD', 'financial'),
('currency_symbol', '$', 'financial'),
('tax_rate', '0', 'financial'),
('commission_rate', '5', 'financial'),
('late_fee_percentage', '2', 'financial'),
('reservation_expiry_days', '7', 'booking');

-- ─── DEFAULT ADMIN USER ────────────────────────────────────
-- Password: omar@123 (bcrypt hash)
INSERT INTO users (full_name, email, phone, username, password, role_id, is_active, email_verified_at) VALUES
('System Administrator', 'omar@gmail.com', '+1234567890', 'admin', '$2y$12$/.LrRQxAXxBKW7WAF1RdPuqcZha.rpaHxKGkZ3bX0yLxv/P7XzGsu', 1, 1, NOW());
