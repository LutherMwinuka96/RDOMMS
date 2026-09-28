-- Project deregistration workflow (removal with approval)
ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS deregistration_status ENUM('none', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'none',
    ADD COLUMN IF NOT EXISTS deregistration_requested_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deregistration_requested_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deregistration_reason TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deregistration_approved_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deregistration_approved_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deregistration_rejected_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deregistration_reject_reason TEXT NULL DEFAULT NULL;
