-- Run once on existing rdomms_db databases to enable planning API integration.

ALTER TABLE monthly_plans
    MODIFY approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'draft',
    MODIFY submitted_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE quarterly_plans
    MODIFY approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'draft',
    MODIFY submitted_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE annual_plans
    MODIFY approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'draft',
    MODIFY submitted_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE monthly_plans
    ADD COLUMN IF NOT EXISTS objective TEXT NULL AFTER station,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER objective,
    ADD COLUMN IF NOT EXISTS reject_reason TEXT NULL AFTER approval_status,
    ADD COLUMN IF NOT EXISTS approved_by VARCHAR(100) NULL AFTER reject_reason,
    ADD COLUMN IF NOT EXISTS rejected_by VARCHAR(100) NULL AFTER approved_by,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER rejected_by,
    ADD COLUMN IF NOT EXISTS saved_at TIMESTAMP NULL AFTER submitted_at;

ALTER TABLE quarterly_plans
    ADD COLUMN IF NOT EXISTS objective TEXT NULL AFTER station,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER objective,
    ADD COLUMN IF NOT EXISTS reject_reason TEXT NULL AFTER approval_status,
    ADD COLUMN IF NOT EXISTS approved_by VARCHAR(100) NULL AFTER reject_reason,
    ADD COLUMN IF NOT EXISTS rejected_by VARCHAR(100) NULL AFTER approved_by,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER rejected_by,
    ADD COLUMN IF NOT EXISTS saved_at TIMESTAMP NULL AFTER submitted_at;

ALTER TABLE annual_plans
    ADD COLUMN IF NOT EXISTS objective TEXT NULL AFTER station,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER objective,
    ADD COLUMN IF NOT EXISTS reject_reason TEXT NULL AFTER approval_status,
    ADD COLUMN IF NOT EXISTS approved_by VARCHAR(100) NULL AFTER reject_reason,
    ADD COLUMN IF NOT EXISTS rejected_by VARCHAR(100) NULL AFTER approved_by,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER rejected_by,
    ADD COLUMN IF NOT EXISTS saved_at TIMESTAMP NULL AFTER submitted_at;

ALTER TABLE targets
    ADD COLUMN IF NOT EXISTS progress_history JSON NULL AFTER status,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER progress_history;

CREATE TABLE IF NOT EXISTS progress_updates (
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

CREATE TABLE IF NOT EXISTS project_documents (
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

CREATE TABLE IF NOT EXISTS planning_evidence (
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
