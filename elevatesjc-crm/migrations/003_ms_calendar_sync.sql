-- ============================================================
-- Migration 003 — Microsoft 365 calendar connections (two-way sync)
--   mysql -u <user> -p elevatesjc_crm < migrations/003_ms_calendar_sync.sql
-- Run once against a database that already has migration 002 applied
-- (calendar_events must already exist). Not safe to re-run.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- ms_calendar_connections — one row per Microsoft 365 mailbox a CRM
-- user has linked. Tokens are stored encrypted (see includes/crypto.php);
-- this table never holds a plaintext access/refresh token.
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
-- calendar_events — link rows to the connected mailbox they sync
-- with, and track the remote event id / modification time needed
-- for two-way sync.
-- ---------------------------------------------------------------
ALTER TABLE calendar_events
  ADD COLUMN connection_id   INT UNSIGNED NULL AFTER deal_id,
  ADD COLUMN ms_event_id     VARCHAR(300) NULL AFTER connection_id,
  ADD COLUMN ms_last_modified DATETIME NULL AFTER ms_event_id,
  ADD COLUMN sync_pending    TINYINT(1) NOT NULL DEFAULT 0 AFTER ms_last_modified,
  ADD COLUMN updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD CONSTRAINT fk_cal_connection FOREIGN KEY (connection_id) REFERENCES ms_calendar_connections(id) ON DELETE SET NULL,
  ADD UNIQUE KEY uq_cal_ms_event (connection_id, ms_event_id),
  ADD INDEX idx_cal_connection (connection_id);

SET FOREIGN_KEY_CHECKS = 1;
