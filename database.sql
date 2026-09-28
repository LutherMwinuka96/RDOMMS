-- Create database
CREATE DATABASE IF NOT EXISTS rdomms_db;
USE rdomms_db;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) UNIQUE NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'staff',
    department VARCHAR(100),
    organization VARCHAR(100),
    phone VARCHAR(20),
    employee_id VARCHAR(50),
    status ENUM('active', 'pending', 'suspended') DEFAULT 'pending',
    profile_picture TEXT,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    two_factor_secret VARCHAR(255) NULL,
    two_factor_backup_codes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
);

-- Password reset tokens
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(128) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at),
    INDEX idx_user (user_id)
);

-- Sessions table
CREATE TABLE user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP,
    last_active_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_expires_at (expires_at),
    INDEX idx_last_active (last_active_at)
);

-- Activity logs table
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    module VARCHAR(100),
    severity ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
);

-- Organizations table
CREATE TABLE organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    registration_no VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    website VARCHAR(200),
    address TEXT,
    mission TEXT,
    logo_url TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Departments table
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    organization_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Projects table
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    organization VARCHAR(100),
    district VARCHAR(100),
    location VARCHAR(200),
    sector VARCHAR(100),
    start_date DATE,
    end_date DATE,
    budget DECIMAL(15,2) DEFAULT 0,
    funding_source VARCHAR(100),
    donor_name VARCHAR(200),
    beneficiaries INT DEFAULT 0,
    progress INT DEFAULT 0,
    status ENUM('not-started', 'ongoing', 'delayed', 'completed') DEFAULT 'not-started',
    budget_approval_status ENUM('draft', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'draft',
    budget_submitted_at TIMESTAMP NULL DEFAULT NULL,
    budget_approved_by INT NULL DEFAULT NULL,
    budget_approved_at TIMESTAMP NULL DEFAULT NULL,
    budget_rejected_by INT NULL DEFAULT NULL,
    budget_reject_reason TEXT NULL DEFAULT NULL,
    team TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_sector (sector)
);

-- Budget allocations table
CREATE TABLE budget_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    allocation_id VARCHAR(50) UNIQUE NOT NULL,
    department VARCHAR(100) NOT NULL,
    quarter ENUM('q1', 'q2', 'q3', 'q4') NOT NULL,
    fiscal_year YEAR NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    station VARCHAR(100),
    description TEXT,
    linked_type VARCHAR(32) NULL,
    linked_id VARCHAR(64) NULL,
    linked_label VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT,
    approved_at TIMESTAMP,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_department (department),
    INDEX idx_quarter_year (quarter, fiscal_year)
);

-- Expenses table
CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id VARCHAR(50) UNIQUE NOT NULL,
    expense_date DATE NOT NULL,
    category VARCHAR(50) NOT NULL,
    department VARCHAR(100) NOT NULL,
    quarter ENUM('q1', 'q2', 'q3', 'q4'),
    fiscal_year YEAR,
    amount DECIMAL(15,2) NOT NULL,
    station VARCHAR(100),
    description TEXT,
    reference VARCHAR(100),
    linked_type VARCHAR(32) NULL,
    linked_id VARCHAR(64) NULL,
    linked_label VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT,
    approved_at TIMESTAMP,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_department (department),
    INDEX idx_date (expense_date)
);

-- Beneficiaries table
CREATE TABLE beneficiaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id VARCHAR(50) UNIQUE NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    beneficiary_code VARCHAR(50) UNIQUE,
    gender ENUM('male', 'female', 'other') NOT NULL,
    age INT,
    category VARCHAR(100) NOT NULL,
    group_count INT DEFAULT 1,
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    phone VARCHAR(20),
    national_id VARCHAR(50),
    location VARCHAR(200),
    district VARCHAR(100),
    ward VARCHAR(100),
    household_size INT,
    registered_date DATE,
    assigned_officer VARCHAR(100),
    services_needed TEXT,
    notes TEXT,
    attachments INT DEFAULT 0,
    registered_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_status (status)
);

-- Service tracking table
CREATE TABLE service_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id VARCHAR(50) UNIQUE NOT NULL,
    beneficiary_id INT NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    service_date DATE NOT NULL,
    quantity INT,
    unit VARCHAR(50),
    provider VARCHAR(100),
    location VARCHAR(200),
    reference VARCHAR(100),
    description TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
    INDEX idx_beneficiary (beneficiary_id),
    INDEX idx_date (service_date)
);

-- Plans tables (Monthly, Quarterly, Annual)
CREATE TABLE monthly_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id VARCHAR(50) UNIQUE NOT NULL,
    month VARCHAR(20) NOT NULL,
    year YEAR NOT NULL,
    staff_name VARCHAR(100),
    department VARCHAR(100),
    station VARCHAR(100),
    objective TEXT,
    notes TEXT,
    activities JSON,
    total_budget DECIMAL(15,2) DEFAULT 0,
    approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'draft',
    reject_reason TEXT,
    approved_by VARCHAR(100),
    rejected_by VARCHAR(100),
    created_by INT,
    submitted_at TIMESTAMP NULL,
    saved_at TIMESTAMP NULL,
    INDEX idx_month_year (month, year),
    INDEX idx_approval (approval_status),
    INDEX idx_created_by (created_by)
);

CREATE TABLE quarterly_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id VARCHAR(50) UNIQUE NOT NULL,
    quarter ENUM('Q1', 'Q2', 'Q3', 'Q4') NOT NULL,
    year YEAR NOT NULL,
    staff_name VARCHAR(100),
    department VARCHAR(100),
    station VARCHAR(100),
    objective TEXT,
    notes TEXT,
    activities JSON,
    total_budget DECIMAL(15,2) DEFAULT 0,
    approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'draft',
    reject_reason TEXT,
    approved_by VARCHAR(100),
    rejected_by VARCHAR(100),
    created_by INT,
    submitted_at TIMESTAMP NULL,
    saved_at TIMESTAMP NULL,
    INDEX idx_quarter_year (quarter, year),
    INDEX idx_approval (approval_status),
    INDEX idx_created_by (created_by)
);

CREATE TABLE annual_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id VARCHAR(50) UNIQUE NOT NULL,
    year YEAR NOT NULL,
    staff_name VARCHAR(100),
    department VARCHAR(100),
    station VARCHAR(100),
    objective TEXT,
    notes TEXT,
    activities JSON,
    total_budget DECIMAL(15,2) DEFAULT 0,
    approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'draft',
    reject_reason TEXT,
    approved_by VARCHAR(100),
    rejected_by VARCHAR(100),
    created_by INT,
    submitted_at TIMESTAMP NULL,
    saved_at TIMESTAMP NULL,
    INDEX idx_year (year),
    INDEX idx_approval (approval_status),
    INDEX idx_created_by (created_by)
);

-- Targets and indicators table
CREATE TABLE targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100),
    period VARCHAR(50),
    target_value DECIMAL(15,2),
    unit VARCHAR(50),
    current_value DECIMAL(15,2) DEFAULT 0,
    description TEXT,
    status VARCHAR(50) DEFAULT 'not_started',
    progress_history JSON,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by)
);

-- Progress monitoring updates
CREATE TABLE progress_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    update_id VARCHAR(80) UNIQUE NOT NULL,
    project_name VARCHAR(200) NOT NULL,
    activity_name VARCHAR(200) NOT NULL,
    station VARCHAR(100),
    department VARCHAR(100),
    start_date DATE,
    end_date DATE,
    progress TINYINT UNSIGNED DEFAULT 0,
    status VARCHAR(50) DEFAULT 'behind',
    description TEXT,
    challenges TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by),
    INDEX idx_status (status)
);

-- Project documents register
CREATE TABLE project_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id VARCHAR(80) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'project-evidence',
    project_name VARCHAR(200),
    department VARCHAR(100),
    owner VARCHAR(100),
    document_date DATE,
    status VARCHAR(50) DEFAULT 'draft',
    notes TEXT,
    files JSON,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by),
    INDEX idx_document_date (document_date)
);

-- Planning evidence uploads
CREATE TABLE planning_evidence (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evidence_id VARCHAR(80) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL,
    evidence_type VARCHAR(50),
    related_activity VARCHAR(200),
    evidence_date DATE,
    location VARCHAR(200),
    station VARCHAR(100),
    department VARCHAR(100),
    uploaded_by VARCHAR(100),
    description TEXT,
    files JSON,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by),
    INDEX idx_evidence_date (evidence_date)
);

-- Notifications table
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_id VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    severity ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
    module VARCHAR(100),
    user_id INT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read)
);

-- Roles and permissions
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(50) NOT NULL,
    type ENUM('admin', 'custom') DEFAULT 'custom',
    description TEXT,
    permissions JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- System settings
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(50) DEFAULT 'text',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Report scheduling/history tables
CREATE TABLE scheduled_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id VARCHAR(50) UNIQUE NOT NULL,
    report_type VARCHAR(100) NOT NULL,
    start_date DATE,
    end_date DATE,
    frequency VARCHAR(50),
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_type (report_type),
    INDEX idx_active (active)
);

CREATE TABLE report_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id VARCHAR(50) UNIQUE NOT NULL,
    report_type VARCHAR(100) NOT NULL,
    title VARCHAR(255),
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    period VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_type (report_type),
    INDEX idx_generated_at (generated_at)
);

-- Insert default data
INSERT INTO organizations (org_id, name, email, phone, website, address, mission) VALUES
('ORG001', 'Rural Development Organization', 'info@rdo.or.tz', '+255 22 123456', 'https://rdo.or.tz', 'Dodoma, Tanzania', 'Empowering rural communities through sustainable development initiatives.');

INSERT INTO roles (role_id, name, type, description, permissions) VALUES
('R001', 'Admin', 'admin', 'Full system access', '{"projects":true,"planning":true,"finance":true,"reports":true,"users":true}'),
('R002', 'Field Officer', 'custom', 'Field operations access', '{"projects":true,"planning":false,"finance":false,"reports":true,"users":false}'),
('R003', 'Head of Department', 'admin', 'Department oversight', '{"projects":true,"planning":true,"finance":false,"reports":true,"users":false}'),
('R004', 'Staff', 'custom', 'Standard access', '{"projects":true,"planning":false,"finance":false,"reports":false,"users":false}'),
('R005', 'Accountant', 'custom', 'Financial access', '{"projects":false,"planning":false,"finance":true,"reports":true,"users":false}');

-- Insert default admin user (password: RDOMMS@2012)
INSERT INTO users (user_id, fullname, email, password, role, status) VALUES
('USR001', 'System Administrator', 'ffilipatali@yahoo.com', '$2y$10$ZWHCkX1GIkrkoREdKPY4tev.6HFiEr9PkjQEIQ06QnhnDUWxoKoz6', 'admin', 'active');

-- Insert default departments (canonical list — matches auth signup)
INSERT INTO departments (name, description) VALUES
('Orphanage Support', 'orphanage_support'),
('Water Transmission Association', 'water_transmission_association'),
('Administration', 'administration'),
('Agriculture', 'agriculture'),
('Pottery', 'pottery'),
('Vocational Training Center', 'vocational_training_center'),
('Store', 'store'),
('Department of Finance', 'finance'),
('Department of Marketing, Procurement & Supply', 'marketing_procurement_supply');

-- Insert system settings
INSERT INTO system_settings (setting_key, setting_value, setting_type) VALUES
('app_name', 'RDOMMS', 'text'),
('app_version', '2.0.0', 'text'),
('default_language', 'en', 'text'),
('date_format', 'DD/MM/YYYY', 'text'),
('timezone', 'EAT', 'text'),
('items_per_page', '15', 'text'),
('theme', 'light', 'text'),
('maintenance_mode', '0', 'text'),
('allow_registration', '1', 'text');

-- Notification module tables
CREATE TABLE IF NOT EXISTS approval_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    request_type VARCHAR(100) DEFAULT NULL,
    module VARCHAR(100) DEFAULT NULL,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    requester VARCHAR(150) NOT NULL,
    description TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reject_reason TEXT,
    created_by INT DEFAULT NULL,
    resolved_by INT DEFAULT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_module (module),
    INDEX idx_created_by (created_by)
);

CREATE TABLE IF NOT EXISTS notification_threads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    thread_id VARCHAR(50) UNIQUE NOT NULL,
    participant_name VARCHAR(150) NOT NULL,
    participant_role VARCHAR(100) DEFAULT NULL,
    participant_status ENUM('online', 'away', 'offline') DEFAULT 'offline',
    owner_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_user_id)
);

CREATE TABLE IF NOT EXISTS notification_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(50) UNIQUE NOT NULL,
    thread_id VARCHAR(50) NOT NULL,
    sender ENUM('me', 'them') NOT NULL DEFAULT 'me',
    body TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_thread (thread_id),
    INDEX idx_read (is_read)
);
