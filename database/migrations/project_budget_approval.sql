-- Project budget approval workflow
ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS budget_approval_status ENUM('draft', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'draft',
    ADD COLUMN IF NOT EXISTS budget_submitted_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS budget_approved_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS budget_approved_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS budget_rejected_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS budget_reject_reason TEXT NULL DEFAULT NULL;

-- Grandfather existing projects that already have a budget.
UPDATE projects
SET budget_approval_status = 'approved'
WHERE budget > 0
  AND (budget_approval_status IS NULL OR budget_approval_status = 'draft');
