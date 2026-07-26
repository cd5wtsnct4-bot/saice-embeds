-- ============================================================
-- Migration 002 — Calendar, Proposals, Invoicing, Expenses
-- Safe to run against an existing elevatesjc_crm database:
--   mysql -u <user> -p elevatesjc_crm < migrations/002_calendar_proposals_invoices_expenses.sql
-- All statements are idempotent (IF NOT EXISTS / INSERT ... ON DUPLICATE KEY).
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- calendar_events — meetings, training sessions, follow-up calls
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_events (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(200) NOT NULL,
  description    TEXT NULL,
  start_datetime DATETIME NOT NULL,
  end_datetime   DATETIME NULL,
  all_day        TINYINT(1) NOT NULL DEFAULT 0,
  location       VARCHAR(200) NULL,
  contact_id     INT UNSIGNED NULL,
  deal_id        INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cal_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_cal_deal    FOREIGN KEY (deal_id)    REFERENCES deals(id)    ON DELETE SET NULL,
  CONSTRAINT fk_cal_user    FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL,
  INDEX idx_cal_start (start_datetime)
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

-- ---------------------------------------------------------------
-- New settings: company letterhead details + banking details for
-- invoices, and a default VAT/tax rate used to prefill new invoices.
-- ---------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
('company_address', '123 Example Street, Johannesburg, 2000'),
('company_phone', '011 000 0000'),
('company_email', 'info@elevatesjc.co.za'),
('vat_number', ''),
('default_tax_rate', '15.00'),
('bank_name', ''),
('bank_account_holder', 'Elevate SJC'),
('bank_account_number', ''),
('bank_branch_code', '')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
