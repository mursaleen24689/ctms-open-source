-- Configurable report workflow: Draft -> Technical Review -> Issued.
-- Apply once to ctms_db before enabling the workflow in CTMS.
ALTER TABLE users
  ADD COLUMN can_technical_review TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE report_workflow_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  require_technical_review TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_report_workflow_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO report_workflow_settings (id, require_technical_review)
VALUES (1, 1);

CREATE TABLE report_workflow_reviewers (
  user_id INT NOT NULL PRIMARY KEY,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_report_workflow_reviewer_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE report_approvals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  stage ENUM('draft','technical_review','changes_requested','issued') NOT NULL DEFAULT 'draft',
  revision_no INT UNSIGNED NOT NULL DEFAULT 1,
  submitted_by INT NULL,
  submitted_at DATETIME NULL,
  submission_hash CHAR(64) NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_notes TEXT NULL,
  issued_by INT NULL,
  issued_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_approvals_report (report_id),
  KEY idx_report_approvals_stage (stage, submitted_at),
  CONSTRAINT fk_report_approvals_report FOREIGN KEY (report_id) REFERENCES reports(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_report_approvals_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_report_approvals_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_report_approvals_issued_by FOREIGN KEY (issued_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE report_workflow_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  actor_id INT NULL,
  action VARCHAR(32) NOT NULL,
  from_stage VARCHAR(32) NULL,
  to_stage VARCHAR(32) NOT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_report_workflow_events_report (report_id, created_at),
  CONSTRAINT fk_report_workflow_events_report FOREIGN KEY (report_id) REFERENCES reports(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_report_workflow_events_actor FOREIGN KEY (actor_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historical reports remain available as already issued records. New reports
-- are added to report_approvals as Draft by report_generate.php.
INSERT INTO report_approvals (report_id, stage, issued_at)
SELECT id, 'issued', generated_at FROM reports WHERE is_deleted = 0;
