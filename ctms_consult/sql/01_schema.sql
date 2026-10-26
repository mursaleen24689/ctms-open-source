-- ============================================================================
--  CTMS CONSULTANCY REPORTING PLATFORM  —  DATABASE SCHEMA
--  Version 1.0
--
--  A SEPARATE, INDEPENDENT system. It shares NO tables with ctms_db.
--  Nothing here reads or writes the existing CTMS database.
--
--  Design principle:
--     Laboratory values are stored as DATA with a permanent address.
--     The printed report is a VIEW built from that data.
--     Deleting a table from a report can never delete a measurement.
--
--  Safe to re-run: every CREATE uses IF NOT EXISTS, every seed INSERT uses
--  INSERT IGNORE or checks for existence first.
-- ============================================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================================
--  SECTION 1 — PEOPLE AND ACCESS
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_users (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  -- The id of the same person in the main CTMS `users` table. This is what
  -- links the two systems for single sign-on. NULL means the account was made
  -- here and has no CTMS counterpart.
  ctms_user_id        INT NULL,
  employee_id         VARCHAR(190) NOT NULL,          -- login (email or code)
  full_name           VARCHAR(190) NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  role                ENUM('superadmin','admin','professor','technician','reception','viewer')
                        NOT NULL DEFAULT 'technician',
  lab_stream          VARCHAR(100) NULL,              -- e.g. Tensile Lab, XRF, OES, Metallography
  designation         VARCHAR(120) NULL,              -- prints under the signature line
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  is_deleted          TINYINT(1) NOT NULL DEFAULT 0,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  pw_changed_at       DATETIME NULL,
  last_login          DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NULL,
  report_mode         ENUM('guided','direct') NOT NULL DEFAULT 'guided',
  UNIQUE KEY uk_users_employee (employee_id),
  KEY idx_users_role (role),
  KEY idx_users_ctms (ctms_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use sign-in tickets, for the handoff from the main CTMS.
--
-- Only the HASH of a ticket is stored, never the ticket itself, so a copy of
-- this table cannot be used to sign in. A ticket lasts 60 seconds and is spent
-- on first use, which is what lets the session token stay out of URLs entirely.
CREATE TABLE IF NOT EXISTS c_sso_tickets (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  ticket_hash   CHAR(64) NOT NULL,
  ctms_user_id  INT NOT NULL,
  mode          ENUM('guided','direct') NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  used_at       DATETIME NULL,
  used_ip       VARCHAR(45) NULL,
  UNIQUE KEY uq_sso_ticket (ticket_hash),
  KEY idx_sso_expiry (expires_at),
  KEY idx_sso_ctms_user (ctms_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable record of everything anyone did. Never updated, never deleted.
CREATE TABLE IF NOT EXISTS c_audit (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NULL,
  actor_name    VARCHAR(190) NULL,      -- snapshot: survives user deletion
  actor_role    VARCHAR(40)  NULL,      -- snapshot
  action        VARCHAR(60)  NOT NULL,  -- e.g. INVOICE_CREATED, READING_EDITED
  module        VARCHAR(40)  NOT NULL,
  record_id     INT NULL,
  parent_id     INT NULL,
  old_value     LONGTEXT NULL,          -- JSON snapshot before change
  new_value     LONGTEXT NULL,          -- JSON snapshot after change
  reason        VARCHAR(400) NULL,      -- required for corrections and deletes
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(300) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_record (module, record_id),
  KEY idx_audit_time (created_at),
  KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 2 — THE STANDARDS LIBRARY
--
--  This is the system's memory of laboratory standards. It is used to SUGGEST
--  a method and to CHECK entered values. It never blocks a technician, because
--  in real consultancy work the instrument may be uncalibrated or the material
--  non-standard. Every suggestion is offered for human approval.
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_standards (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(60)  NOT NULL,   -- e.g. 'ASTM E8/E8M'
  title               VARCHAR(300) NOT NULL,
  body                VARCHAR(40)  NOT NULL DEFAULT 'ASTM',  -- ASTM, ISO, BS, DIN, Example Client
  revision            VARCHAR(40)  NULL,       -- e.g. '2022', '2016a'
  scope_summary       TEXT         NULL,       -- plain-language purpose
  notes               TEXT         NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_std_code_rev (code, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The kinds of test the laboratory performs.
CREATE TABLE IF NOT EXISTS c_test_types (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(40) NOT NULL,      -- TENSILE, HARDNESS_BRINELL, ...
  name              VARCHAR(120) NOT NULL,
  category          ENUM('mechanical','chemical','hardness','impact','corrosion','metallography','other')
                      NOT NULL DEFAULT 'mechanical',
  default_standard_id INT NULL,
  result_style      ENUM('single_value','multi_reading','depth_profile','composition_multi','parameter_table')
                      NOT NULL DEFAULT 'single_value',
  -- how the results table for this test looks on the printed report
  layout_json       TEXT NULL,
  report_title      VARCHAR(160) NULL,        -- heading printed above the table
  sort_order        INT NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_testtype_code (code),
  CONSTRAINT fk_tt_std FOREIGN KEY (default_standard_id) REFERENCES c_standards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Definitive list of measurable quantities. This is the heart of the system:
-- every reading points at one of these, which is what makes values comparable
-- across years and reports.
CREATE TABLE IF NOT EXISTS c_measurements (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(60)  NOT NULL,     -- UTS, YS, ELONGATION, HBW, ELEMENT_C
  name              VARCHAR(160) NOT NULL,
  symbol            VARCHAR(40)  NULL,         -- e.g. 'Rm', 'ReH'
  canonical_unit    VARCHAR(30)  NULL,         -- the ONE unit all values are stored in
  accepted_units    VARCHAR(300) NULL,         -- JSON: {"MPa":1,"N/mm2":1,"ksi":6.894757}
  value_kind        ENUM('numeric','numeric_or_text','text','boolean')
                      NOT NULL DEFAULT 'numeric',
  -- text markers that are legitimate in a lab and must not become 0
  marker_json       VARCHAR(400) NULL,         -- {"< 0.001":"below_detection","Balance":"remainder","--":"not_done"}
  decimals          TINYINT NOT NULL DEFAULT 4,
  is_analyte        TINYINT(1) NOT NULL DEFAULT 0,  -- is a chemical element
  sort_order        INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_meas_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which quantities a given test produces, and their normal ranges.
-- normal_min / normal_max are the "is this plausible at all" guard rails.
-- spec_min / spec_max are the acceptance criteria for PASS/FAIL (may be NULL).
CREATE TABLE IF NOT EXISTS c_test_measurements (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  test_type_id      INT NOT NULL,
  measurement_id    INT NOT NULL,
  column_label      VARCHAR(120) NULL,        -- exact heading printed on the report
  is_required       TINYINT(1) NOT NULL DEFAULT 0,
  is_computed       TINYINT(1) NOT NULL DEFAULT 0,   -- calculated, not typed
  formula           VARCHAR(255) NULL,        -- e.g. 'MEAN(readings)', 'UTS/YS'
  normal_min        DECIMAL(18,6) NULL,
  normal_max        DECIMAL(18,6) NULL,
  spec_min          DECIMAL(18,6) NULL,
  spec_max          DECIMAL(18,6) NULL,
  spec_source       VARCHAR(120) NULL,        -- which standard the limits came from
  warn_message      VARCHAR(300) NULL,        -- shown when outside normal range
  sort_order        INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_tm (test_type_id, measurement_id),
  CONSTRAINT fk_tm_tt   FOREIGN KEY (test_type_id)   REFERENCES c_test_types(id)   ON DELETE CASCADE,
  CONSTRAINT fk_tm_meas FOREIGN KEY (measurement_id) REFERENCES c_measurements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which material grades have known acceptance limits, per measurement.
-- Powers the automatic PASS/FAIL suggestion for e.g. ASTM A615 Grade 60 rebar.
CREATE TABLE IF NOT EXISTS c_grade_limits (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  grade_code        VARCHAR(80)  NOT NULL,     -- 'ASTM A615 Gr.60', 'ASTM A36'
  grade_name        VARCHAR(160) NOT NULL,
  material_group    VARCHAR(80)  NULL,         -- steel, stainless, aluminium, polymer, cement
  measurement_id    INT NULL,                  -- NULL = note applies to whole grade
  min_value         DECIMAL(18,6) NULL,
  max_value         DECIMAL(18,6) NULL,
  requirement_text  VARCHAR(300) NULL,         -- as printed in the standard
  standard_id       INT NULL,
  notes             TEXT NULL,
  KEY idx_grade (grade_code),
  CONSTRAINT fk_gl_meas FOREIGN KEY (measurement_id) REFERENCES c_measurements(id) ON DELETE CASCADE,
  CONSTRAINT fk_gl_std  FOREIGN KEY (standard_id)    REFERENCES c_standards(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suggests which tests a sample of a given material/description usually needs,
-- learned from what the lab actually did before.
CREATE TABLE IF NOT EXISTS c_test_suggestions (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  keyword           VARCHAR(120) NOT NULL,     -- 'rebar', 'handfree', 'marble'
  material_group    VARCHAR(80)  NULL,
  test_type_id      INT NOT NULL,
  confidence        DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
  times_used        INT NOT NULL DEFAULT 0,
  source            ENUM('seeded','learned') NOT NULL DEFAULT 'seeded',
  UNIQUE KEY uk_sugg (keyword, test_type_id),
  CONSTRAINT fk_ts_tt FOREIGN KEY (test_type_id) REFERENCES c_test_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 3 — CLIENTS, INVOICES  (workflow step 1)
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_clients (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  company_name  VARCHAR(220) NOT NULL,
  short_name    VARCHAR(80)  NULL,
  contact_person VARCHAR(150) NULL,
  phone         VARCHAR(40)  NULL,
  email         VARCHAR(150) NULL,
  address       TEXT         NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  UNIQUE KEY uk_client_company (company_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS c_billing (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  inv_number      VARCHAR(40) NOT NULL,
  client_id       INT NOT NULL,
  company_name    VARCHAR(220) NOT NULL,      -- snapshot: printed on the report
  contact_person  VARCHAR(150) NULL,
  invoice_date    DATE NOT NULL,
  due_date        DATE NULL,
  status          ENUM('draft','issued','partial','paid','cancelled') NOT NULL DEFAULT 'draft',
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tax_rate        DECIMAL(5,2)  NOT NULL DEFAULT 13.00,
  tax_amount      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  grand_total     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  payment_method  ENUM('bank_voucher','online_transfer','cash','cheque','credit','other') NULL,
  payment_reference VARCHAR(200) NULL,
  payment_date    DATE NULL,
  notes           TEXT NULL,
  created_by      INT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL,
  UNIQUE KEY uk_inv_number (inv_number),
  KEY idx_inv_client (client_id),
  CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES c_clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What was actually sold. Each line is a test the client is paying for.
-- The sample count on a line eventually drives the report.
CREATE TABLE IF NOT EXISTS c_invoice_lines (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id      INT NOT NULL,
  test_type_id    INT NULL,
  description     VARCHAR(300) NOT NULL,
  sample_count    INT NOT NULL DEFAULT 1,
  unit_price      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  line_total      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sort_order      INT NOT NULL DEFAULT 0,
  KEY idx_il_invoice (invoice_id),
  CONSTRAINT fk_il_inv  FOREIGN KEY (invoice_id)   REFERENCES c_billing(id)   ON DELETE CASCADE,
  CONSTRAINT fk_il_test FOREIGN KEY (test_type_id) REFERENCES c_test_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 4 — SAMPLES AND CUT PIECES  (workflow step 2)
--
--  Real scenario: a client sends ONE one-foot bar and asks for five tests.
--  The bar is SAMPLE #1. It is cut into several PIECES, numbered 1.1, 1.2 ...
--  Each piece is consumed by one or more TESTS. All results trace back to the
--  piece, and therefore to the original sample.
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_samples (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  sample_code       VARCHAR(60) NOT NULL,     -- lab's own number for the submitted item
  invoice_id        INT NULL,
  client_id         INT NULL,
  company_name      VARCHAR(220) NULL,        -- pulled from the invoice
  description       VARCHAR(300) NULL,        -- what the technician observes it to be
  material_group    VARCHAR(80)  NULL,        -- steel, stainless, polymer, cement, ...
  grade_claimed     VARCHAR(120) NULL,        -- grade the client claims, if any
  grade_confirmed   VARCHAR(120) NULL,        -- grade the lab concluded
  heat_number       VARCHAR(80)  NULL,
  quantity_received INT NOT NULL DEFAULT 1,
  received_date     DATE NULL,
  received_by       INT NULL,
  condition_note    TEXT NULL,                -- as-received appearance, damage, etc.
  status            ENUM('received','in_testing','awaiting_report','reported','dispatched','on_hold')
                      NOT NULL DEFAULT 'received',
  report_number     VARCHAR(60) NULL,         -- assigned when the report is issued
  created_by        INT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NULL,
  is_deleted        TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_sample_code (sample_code),
  KEY idx_sample_invoice (invoice_id),
  KEY idx_sample_status (status),
  CONSTRAINT fk_sample_inv FOREIGN KEY (invoice_id) REFERENCES c_billing(id) ON DELETE SET NULL,
  CONSTRAINT fk_sample_cli FOREIGN KEY (client_id)  REFERENCES c_clients(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The pieces a sample is cut into. Identity is permanent: piece 1.2 keeps its
-- identity even if pieces are added or removed later.
CREATE TABLE IF NOT EXISTS c_sample_pieces (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  sample_id         INT NOT NULL,
  piece_code        VARCHAR(60) NOT NULL,     -- '1.1', '1.2', '1A'
  parent_piece_id   INT NULL,                 -- if a piece is cut again
  description       VARCHAR(200) NULL,
  dimensions        VARCHAR(120) NULL,        -- free text as measured
  dimension_kind    ENUM('diameter','width','thickness','size','mixed','not_recorded')
                      NOT NULL DEFAULT 'not_recorded',
  -- diameter/width/thickness held numerically so they can be printed and checked
  dim_a             DECIMAL(14,4) NULL,
  dim_b             DECIMAL(14,4) NULL,
  dim_unit          VARCHAR(12) NULL DEFAULT 'mm',
  mass_kg           DECIMAL(12,4) NULL,
  piece_condition   VARCHAR(120) NULL,
  sort_order        INT NOT NULL DEFAULT 0,
  is_deleted        TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_piece (sample_id, piece_code),
  CONSTRAINT fk_piece_sample FOREIGN KEY (sample_id) REFERENCES c_samples(id) ON DELETE CASCADE,
  CONSTRAINT fk_piece_parent FOREIGN KEY (parent_piece_id) REFERENCES c_sample_pieces(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 5 — TEST JOBS  (workflow step 3: issued to a lab / technician)
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_test_jobs (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  sample_id         INT NOT NULL,
  piece_id          INT NULL,
  test_type_id      INT NOT NULL,
  standard_id       INT NULL,                 -- SUGGESTED standard; technician approves
  standard_approved TINYINT(1) NOT NULL DEFAULT 0,
  standard_note     VARCHAR(300) NULL,        -- why a different standard was used
  assigned_to       INT NULL,                 -- technician
  lab_stream        VARCHAR(100) NULL,
  status            ENUM('pending','in_progress','entered','checked','approved','cancelled')
                      NOT NULL DEFAULT 'pending',
  priority          ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  due_date          DATE NULL,
  entered_by        INT NULL,
  entered_at        DATETIME NULL,
  approved_by       INT NULL,
  approved_at       DATETIME NULL,
  remarks           TEXT NULL,
  created_by        INT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NULL,
  KEY idx_job_sample (sample_id),
  KEY idx_job_status (status),
  KEY idx_job_assigned (assigned_to),
  CONSTRAINT fk_job_sample FOREIGN KEY (sample_id)    REFERENCES c_samples(id)    ON DELETE CASCADE,
  CONSTRAINT fk_job_piece  FOREIGN KEY (piece_id)     REFERENCES c_sample_pieces(id) ON DELETE SET NULL,
  CONSTRAINT fk_job_tt     FOREIGN KEY (test_type_id) REFERENCES c_test_types(id),
  CONSTRAINT fk_job_std    FOREIGN KEY (standard_id)  REFERENCES c_standards(id)  ON DELETE SET NULL,
  CONSTRAINT fk_job_user   FOREIGN KEY (assigned_to)  REFERENCES c_users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 6 — READINGS  (the ledger)
--
--  One row per measurement. This is the data that survives every redesign of
--  the report. type='computed' means the system calculated it; those carry a
--  formula so they can be reproduced years later.
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_readings (
  id                BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id            INT NOT NULL,
  measurement_id    INT NOT NULL,
  row_index         INT NOT NULL DEFAULT 0,   -- which sample/row inside the test
  col_index         INT NOT NULL DEFAULT 0,   -- which reading (1,2,3) inside the row
  row_key           VARCHAR(60) NULL,         -- STABLE identity of the row (piece code)
  raw_value         VARCHAR(120) NULL,        -- EXACTLY as typed, incl. '< 0.001', 'Balance'
  value_num         DECIMAL(20,6) NULL,       -- parsed number when one exists
  value_text        VARCHAR(160) NULL,        -- resolved meaning for non-numeric
  is_below_detection TINYINT(1) NOT NULL DEFAULT 0,
  is_reference      TINYINT(1) NOT NULL DEFAULT 0,  -- spec/reference row, NOT a measurement
  unit              VARCHAR(30) NULL,         -- as entered
  unit_canonical    VARCHAR(30) NULL,         -- converted
  value_canonical   DECIMAL(20,6) NULL,       -- converted to canonical unit
  entry_type        ENUM('entered','computed','imported','copied') NOT NULL DEFAULT 'entered',
  formula           VARCHAR(255) NULL,        -- for computed rows
  is_current        TINYINT(1) NOT NULL DEFAULT 1,
  superseded_by     BIGINT NULL,
  -- validation outcome, recorded rather than enforced
  validation_state  ENUM('ok','warning','error','overridden','not_checked') NOT NULL DEFAULT 'not_checked',
  validation_note   VARCHAR(400) NULL,
  override_reason   VARCHAR(400) NULL,
  entered_by        INT NULL,
  entered_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rd_job (job_id),
  KEY idx_rd_meas (measurement_id),
  KEY idx_rd_current (job_id, is_current),
  KEY idx_rd_lookup (measurement_id, value_num),
  CONSTRAINT fk_rd_job  FOREIGN KEY (job_id)         REFERENCES c_test_jobs(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rd_meas FOREIGN KEY (measurement_id) REFERENCES c_measurements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change to a reading is appended here. Readings are never truly
-- overwritten: the old row is marked superseded and this records why.
CREATE TABLE IF NOT EXISTS c_reading_events (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  reading_id    BIGINT NULL,
  job_id        INT NULL,
  measurement_id INT NULL,
  event_type    ENUM('insert','update','delete','restore','validate','override','copy')
                  NOT NULL,
  old_raw       VARCHAR(120) NULL,
  new_raw       VARCHAR(120) NULL,
  reason        VARCHAR(400) NULL,
  actor_id      INT NULL,
  actor_name    VARCHAR(190) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_re_reading (reading_id),
  KEY idx_re_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 7 — REPORTS  (workflow step 4)
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_reports (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  report_number     VARCHAR(60) NOT NULL,
  sample_id         INT NULL,
  invoice_id        INT NULL,
  company_name      VARCHAR(220) NULL,
  title             VARCHAR(200) NULL,
  status            ENUM('draft','in_review','approved','issued','superseded')
                      NOT NULL DEFAULT 'draft',
  -- the exact facts printed in the report header block (structured, not text)
  header_json       LONGTEXT NULL,
  summary_html      LONGTEXT NULL,            -- the professor's narrative
  remarks_html      LONGTEXT NULL,
  revision_no       INT NOT NULL DEFAULT 1,
  supersedes_id     INT NULL,
  issued_at         DATETIME NULL,
  issued_by         INT NULL,
  content_hash      VARCHAR(64) NULL,         -- SHA-256 of the frozen data at issue
  verify_code       VARCHAR(16) NULL,         -- short code under the barcode
  barcode_value     VARCHAR(190) NULL,        -- what the barcode encodes
  created_by        INT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NULL,
  UNIQUE KEY uk_report_number (report_number),
  KEY idx_report_sample (sample_id),
  CONSTRAINT fk_rep_sample  FOREIGN KEY (sample_id)  REFERENCES c_samples(id)  ON DELETE SET NULL,
  CONSTRAINT fk_rep_invoice FOREIGN KEY (invoice_id) REFERENCES c_billing(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which jobs appear on which report, and in what order.
CREATE TABLE IF NOT EXISTS c_report_sections (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  report_id     INT NOT NULL,
  job_id        INT NULL,
  test_type_id  INT NULL,
  title         VARCHAR(200) NULL,       -- heading printed above the table
  section_order INT NOT NULL DEFAULT 0,
  page_break_before TINYINT(1) NOT NULL DEFAULT 1,
  is_included   TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_rs_report (report_id),
  CONSTRAINT fk_rs_report FOREIGN KEY (report_id) REFERENCES c_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_job    FOREIGN KEY (job_id)    REFERENCES c_test_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photographs, micrographs, scanned instrument output. The original file is
-- always kept, even after a value has been parsed out of it.
CREATE TABLE IF NOT EXISTS c_report_assets (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  report_id     INT NULL,
  sample_id     INT NULL,
  job_id        INT NULL,
  asset_type    ENUM('micrograph','photo','specimen','instrument_export','scan','other')
                  NOT NULL DEFAULT 'photo',
  caption       VARCHAR(300) NULL,
  magnification VARCHAR(40) NULL,
  stored_name   VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime_type     VARCHAR(100) NULL,
  byte_size     INT NULL,
  sha256        VARCHAR(64) NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  uploaded_by   INT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_asset_report (report_id),
  CONSTRAINT fk_asset_report FOREIGN KEY (report_id) REFERENCES c_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 8 — DISPATCH  (workflow step 5)
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_dispatches (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  report_id       INT NOT NULL,
  dispatched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispatched_by   INT NULL,
  received_by     VARCHAR(190) NULL,      -- who took delivery
  method          ENUM('hand','courier','email','post','other') NOT NULL DEFAULT 'hand',
  courier_ref     VARCHAR(120) NULL,
  copies          INT NOT NULL DEFAULT 1,
  notes           TEXT NULL,
  is_reversed     TINYINT(1) NOT NULL DEFAULT 0,
  reversed_at     DATETIME NULL,
  reversed_by     INT NULL,
  reverse_reason  VARCHAR(400) NULL,
  KEY idx_disp_report (report_id),
  CONSTRAINT fk_disp_report FOREIGN KEY (report_id) REFERENCES c_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 9 — AI / ASSISTANT SUGGESTIONS
--
--  Every automated suggestion is recorded with its outcome. The assistant may
--  suggest, flag, normalise and compare. It may NEVER invent a measured value,
--  approve a result, or issue a report.
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_suggestions (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  context       ENUM('standard','test_set','unit','value_check','grade','similar_report','template')
                  NOT NULL,
  job_id        INT NULL,
  sample_id     INT NULL,
  report_id     INT NULL,
  suggestion    VARCHAR(500) NOT NULL,
  payload_json  LONGTEXT NULL,
  confidence    DECIMAL(5,4) NULL,
  basis         VARCHAR(300) NULL,       -- which rule or which past data produced it
  status        ENUM('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
  acted_by      INT NULL,
  acted_at      DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sug_job (job_id),
  KEY idx_sug_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SECTION 10 — SETTINGS AND NUMBERING
-- ============================================================================

CREATE TABLE IF NOT EXISTS c_settings (
  setting_key   VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NULL,
  description   VARCHAR(300) NULL,
  updated_by    INT NULL,
  updated_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atomic number sequences. Uses LAST_INSERT_ID() so two people generating a
-- report at the same moment can never receive the same number.
CREATE TABLE IF NOT EXISTS c_counters (
  counter_key   VARCHAR(80) PRIMARY KEY,
  next_number   INT NOT NULL DEFAULT 1,
  prefix        VARCHAR(30) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  END OF SCHEMA
-- ============================================================================
