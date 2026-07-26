-- ============================================================
-- Elevate SJC CRM — MySQL schema
-- Import with: mysql -u <user> -p <database> < schema.sql
-- Requires MySQL 5.7+ / MariaDB 10.2+ (JSON type + utf8mb4)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- users — CRM operators who can log in
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)  NOT NULL,
  username      VARCHAR(60)   NULL UNIQUE,
  email         VARCHAR(160)  NULL UNIQUE,
  password_hash VARCHAR(255)  NULL,        -- NULL for Microsoft-only accounts
  auth_provider ENUM('local','microsoft') NOT NULL DEFAULT 'local',
  ms_oid        VARCHAR(64)   NULL UNIQUE, -- Microsoft Entra ID object id (oid claim)
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  active        TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- programs — training programs / courses Elevate SJC sells
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS programs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  category    ENUM('Leadership Development','Technical Skills','Soft Skills','Data Analytics & Visualisation','E-Learning') NOT NULL,
  description TEXT NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- contacts — people at client organisations
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,
  company    VARCHAR(150) NULL,
  role       VARCHAR(120) NULL,
  email      VARCHAR(160) NULL,
  phone      VARCHAR(40)  NULL,
  tags       VARCHAR(255) NULL,           -- comma-separated, kept simple
  notes      TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_contacts_company (company),
  INDEX idx_contacts_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- deals — pipeline opportunities (a contact enquiring about a program)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS deals (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(180) NOT NULL,
  contact_id     INT UNSIGNED NULL,
  program_id     INT UNSIGNED NULL,
  value          DECIMAL(12,2) NOT NULL DEFAULT 0,
  stage          ENUM('New Enquiry','Needs Assessment','Proposal Sent','Negotiation','Won','Lost') NOT NULL DEFAULT 'New Enquiry',
  expected_close DATE NULL,
  owner_id       INT UNSIGNED NULL,
  notes          TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_deals_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_deals_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
  CONSTRAINT fk_deals_owner   FOREIGN KEY (owner_id)   REFERENCES users(id)    ON DELETE SET NULL,
  INDEX idx_deals_stage (stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- tasks — follow-ups, optionally linked to a contact and/or deal
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(200) NOT NULL,
  due_date   DATE NULL,
  priority   ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  done       TINYINT(1) NOT NULL DEFAULT 0,
  contact_id INT UNSIGNED NULL,
  deal_id    INT UNSIGNED NULL,
  notes      TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tasks_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_deal    FOREIGN KEY (deal_id)    REFERENCES deals(id)    ON DELETE SET NULL,
  INDEX idx_tasks_due (due_date),
  INDEX idx_tasks_done (done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- settings — simple key/value store (company name, tagline, colors)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(60) PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- calendar_events — meetings, training sessions, follow-up calls
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_events (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(200) NOT NULL,
  description     TEXT NULL,
  start_datetime  DATETIME NOT NULL,
  end_datetime    DATETIME NULL,
  all_day         TINYINT(1) NOT NULL DEFAULT 0,
  location        VARCHAR(200) NULL,
  contact_id      INT UNSIGNED NULL,
  deal_id         INT UNSIGNED NULL,
  connection_id   INT UNSIGNED NULL, -- linked Microsoft calendar, if any (see ms_calendar_connections)
  ms_event_id     VARCHAR(300) NULL, -- Microsoft Graph event id, once synced
  ms_last_modified DATETIME NULL,    -- Graph's lastModifiedDateTime, for last-write-wins on pull
  sync_pending    TINYINT(1) NOT NULL DEFAULT 0, -- local edit not yet pushed to Graph
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cal_contact    FOREIGN KEY (contact_id)    REFERENCES contacts(id)                ON DELETE SET NULL,
  CONSTRAINT fk_cal_deal       FOREIGN KEY (deal_id)       REFERENCES deals(id)                    ON DELETE SET NULL,
  CONSTRAINT fk_cal_user       FOREIGN KEY (created_by)    REFERENCES users(id)                    ON DELETE SET NULL,
  CONSTRAINT fk_cal_connection FOREIGN KEY (connection_id) REFERENCES ms_calendar_connections(id)  ON DELETE SET NULL,
  UNIQUE KEY uq_cal_ms_event (connection_id, ms_event_id),
  INDEX idx_cal_start (start_datetime),
  INDEX idx_cal_connection (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- ms_calendar_connections — one row per Microsoft 365 mailbox a CRM
-- user has linked for two-way calendar sync. Tokens are stored
-- encrypted (see includes/crypto.php) — never plaintext.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ms_calendar_connections (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            INT UNSIGNED NOT NULL,
  ms_oid             VARCHAR(64)  NOT NULL,
  ms_email           VARCHAR(160) NULL,
  display_name       VARCHAR(160) NULL,
  color              VARCHAR(7)   NOT NULL DEFAULT '#2563eb',
  access_token_enc   TEXT         NOT NULL,
  refresh_token_enc  TEXT         NOT NULL,
  token_expires_at   DATETIME     NOT NULL,
  last_synced_at     DATETIME     NULL,
  last_sync_error    VARCHAR(500) NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mscal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_mscal_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- document_counters — atomic per-year sequence source for
-- invoice/proposal numbers (see includes/numbering.php)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_counters (
  counter_key VARCHAR(20) PRIMARY KEY,
  year        INT NOT NULL,
  next_seq    INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- proposals + line items
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS proposals (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proposal_number  VARCHAR(30) NOT NULL UNIQUE,
  deal_id          INT UNSIGNED NULL,
  contact_id       INT UNSIGNED NULL,
  title            VARCHAR(200) NOT NULL,
  status           ENUM('draft','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  intro_text       TEXT NULL,
  valid_until      DATE NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  sent_at          DATETIME NULL,
  CONSTRAINT fk_prop_deal    FOREIGN KEY (deal_id)    REFERENCES deals(id)    ON DELETE SET NULL,
  CONSTRAINT fk_prop_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proposal_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proposal_id  INT UNSIGNED NOT NULL,
  description  VARCHAR(255) NOT NULL,
  quantity     DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_propitem_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- invoices + line items
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_number  VARCHAR(30) NOT NULL UNIQUE,
  proposal_id     INT UNSIGNED NULL,
  deal_id         INT UNSIGNED NULL,
  contact_id      INT UNSIGNED NULL,
  status          ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  issue_date      DATE NOT NULL,
  due_date        DATE NULL,
  tax_rate        DECIMAL(5,2) NOT NULL DEFAULT 15.00,
  notes           TEXT NULL,
  paid_at         DATE NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_deal     FOREIGN KEY (deal_id)     REFERENCES deals(id)     ON DELETE SET NULL,
  CONSTRAINT fk_inv_contact  FOREIGN KEY (contact_id)  REFERENCES contacts(id)  ON DELETE SET NULL,
  INDEX idx_inv_status (status),
  INDEX idx_inv_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id   INT UNSIGNED NOT NULL,
  description  VARCHAR(255) NOT NULL,
  quantity     DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_invitem_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- expenses — incl. scanned receipt/slip attachment + best-effort OCR text
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  description     VARCHAR(255) NOT NULL,
  category        ENUM('Travel','Venue & Catering','Materials','Software & Subscriptions','Subsistence','Other') NOT NULL DEFAULT 'Other',
  amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
  expense_date    DATE NOT NULL,
  vendor          VARCHAR(150) NULL,
  payment_method  ENUM('Card','Cash','EFT','Other') NOT NULL DEFAULT 'Card',
  notes           TEXT NULL,
  receipt_path    VARCHAR(255) NULL,
  ocr_text        MEDIUMTEXT NULL,
  status          ENUM('pending','approved','reimbursed') NOT NULL DEFAULT 'pending',
  deal_id         INT UNSIGNED NULL,
  submitted_by    INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exp_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL,
  CONSTRAINT fk_exp_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_exp_date (expense_date),
  INDEX idx_exp_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Seed data
-- ============================================================

-- Default admin user — username: admin / password: ElevateSJC!2026
-- CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN (Settings > Users).
-- Hash below is bcrypt (PHP password_hash, default cost). To generate a new
-- one: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT), PHP_EOL;"
INSERT INTO users (name, username, email, password_hash, auth_provider, role) VALUES
('Administrator', 'admin', 'admin@elevatesjc.co.za', '$2y$12$6nuFi3tkawoGUwRAjQ.0CeAi8Xq46ZYJv4lBuUIzIXVde8ZnPRIb.', 'local', 'admin');

INSERT INTO programs (name, category, description) VALUES
('Leadership Development Programme', 'Leadership Development', 'Empowering individuals to lead with confidence and impact.'),
('Cybersecurity Fundamentals', 'Technical Skills', 'Cutting-edge training in cybersecurity awareness and practice.'),
('Data Analytics Bootcamp', 'Technical Skills', 'Hands-on technical skills training in data analytics.'),
('UX/Design Essentials', 'Technical Skills', 'Design fundamentals training for non-designers and product teams.'),
('Communication & Teamwork Workshop', 'Soft Skills', 'Building communication, teamwork and interpersonal skills.'),
('Data Analytics & Visualisation Consulting', 'Data Analytics & Visualisation', 'Transforming data into actionable insights with expert consulting.'),
('Microsoft Excel — Beginner', 'E-Learning', 'Self-paced e-learning course on Excel fundamentals.'),
('Microsoft Excel — Intermediate', 'E-Learning', 'Self-paced e-learning course on intermediate Excel skills.'),
('Microsoft Excel — Advanced', 'E-Learning', 'Self-paced e-learning course on advanced Excel techniques.');

INSERT INTO contacts (name, company, role, email, phone, tags, notes) VALUES
('Thandiwe Nkosi', 'Ilanga Municipal Services', 'HR Manager', 'thandiwe.nkosi@example.co.za', '011 555 0142', 'municipal,leadership', 'Sample contact — met at SALGA conference, interested in leadership training for regional managers.'),
('Johan van der Merwe', 'Kestrel Logistics', 'Operations Director', 'johan@example.co.za', '021 555 0198', 'logistics,data', 'Sample contact — wants a data analytics bootcamp for the planning team.'),
('Amahle Dlamini', 'Bright Path Retail Group', 'L&D Coordinator', 'amahle.d@example.co.za', '031 555 0177', 'retail,soft-skills', 'Sample contact — recurring client, books soft skills workshops quarterly.');

INSERT INTO deals (title, contact_id, program_id, value, stage, expected_close, notes) VALUES
('Ilanga Municipal — Leadership Cohort (25 pax)', 1, 1, 185000.00, 'Proposal Sent', DATE_ADD(CURDATE(), INTERVAL 21 DAY), 'Sample deal — proposal sent, awaiting procurement sign-off.'),
('Kestrel Logistics — Data Analytics Bootcamp', 2, 3, 96000.00, 'Needs Assessment', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Sample deal — scoping call booked to confirm cohort size.'),
('Bright Path Retail — Q3 Soft Skills Refresh', 3, 5, 42000.00, 'Won', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Sample deal — repeat booking, invoiced.');

INSERT INTO tasks (title, due_date, priority, contact_id, deal_id, notes) VALUES
('Follow up on Ilanga Municipal proposal', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'high', 1, 1, 'Sample task — check in with Thandiwe re: procurement timeline.'),
('Send data analytics bootcamp outline to Kestrel', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'medium', 2, 2, 'Sample task — include sample syllabus and facilitator bios.'),
('Schedule Bright Path Q4 workshop dates', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'low', 3, 3, 'Sample task — coordinate with venue for October.');

INSERT INTO settings (setting_key, setting_value) VALUES
('company_name', 'Elevate SJC'),
('tagline', 'Driving performance. Unlocking potential.'),
('primary_color', '#142850'),
('accent_color', '#16C79A'),
('company_address', '123 Example Street, Johannesburg, 2000'),
('company_phone', '011 000 0000'),
('company_email', 'info@elevatesjc.co.za'),
('vat_number', ''),
('default_tax_rate', '15.00'),
('bank_name', ''),
('bank_account_holder', 'Elevate SJC'),
('bank_account_number', ''),
('bank_branch_code', '');

INSERT INTO calendar_events (title, description, start_datetime, end_datetime, location, contact_id, deal_id) VALUES
('Ilanga Municipal — Proposal Walkthrough', 'Sample event — present the leadership cohort proposal to procurement.', DATE_ADD(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY) + INTERVAL 1 HOUR, 'MS Teams', 1, 1),
('Kestrel Logistics — Scoping Call', 'Sample event — confirm cohort size and dates for the data analytics bootcamp.', DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY) + INTERVAL 30 MINUTE, 'Phone', 2, 2);

INSERT INTO document_counters (counter_key, year, next_seq) VALUES
('proposal', YEAR(CURDATE()), 2),
('invoice', YEAR(CURDATE()), 2);

INSERT INTO proposals (proposal_number, deal_id, contact_id, title, status, intro_text, valid_until) VALUES
(CONCAT('PRO-', YEAR(CURDATE()), '-0001'), 1, 1, 'Leadership Cohort Training Proposal', 'sent', 'Sample proposal — thank you for considering Elevate SJC for your leadership development needs.', DATE_ADD(CURDATE(), INTERVAL 30 DAY));

INSERT INTO proposal_items (proposal_id, description, quantity, unit_price, sort_order) VALUES
(1, 'Leadership Development Programme — 25 delegates', 25, 7400.00, 1),
(1, 'Post-programme coaching (3 sessions)', 1, 15000.00, 2);

INSERT INTO invoices (invoice_number, deal_id, contact_id, status, issue_date, due_date, tax_rate, notes) VALUES
(CONCAT('INV-', YEAR(CURDATE()), '-0001'), 3, 3, 'paid', DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 25 DAY), 15.00, 'Sample invoice — Q3 soft skills refresh, paid on receipt.');

INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, sort_order) VALUES
(1, 'Communication & Teamwork Workshop — 18 delegates', 18, 2333.33, 1);

UPDATE invoices SET paid_at = CURDATE() WHERE id = 1;

INSERT INTO expenses (description, category, amount, expense_date, vendor, payment_method, notes, deal_id, status) VALUES
('Venue hire — Ilanga workshop dry-run', 'Venue & Catering', 3200.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Rosebank Conference Centre', 'Card', 'Sample expense — half-day venue for a facilitator dry-run.', 1, 'approved'),
('Printer paper & flip charts', 'Materials', 640.50, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Waltons', 'Card', 'Sample expense — training material stock-up.', NULL, 'reimbursed');
