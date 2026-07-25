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
('accent_color', '#16C79A');
