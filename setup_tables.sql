-- Rahausub Missing DB Tables Setup
-- Run this once on the rahausub MySQL database (eduowrav_rahausub)
-- Run via cPanel phpMyAdmin or SSH

-- ── Device tokens for FCM push notifications ─────────────────────────────────
CREATE TABLE IF NOT EXISTS device_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    email      VARCHAR(255) NOT NULL,
    fcm_token  TEXT NOT NULL,
    platform   ENUM('android','ios') DEFAULT 'android',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── In-app notifications (legacy/simple format) ───────────────────────────────
CREATE TABLE IF NOT EXISTS notifications_tbl (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    message      TEXT NOT NULL,
    type         ENUM('info','success','warning','danger') DEFAULT 'info',
    target       ENUM('all','specific') DEFAULT 'all',
    target_email VARCHAR(255) NULL,
    created_by   VARCHAR(255) NULL,
    is_read_by   LONGTEXT NULL DEFAULT '[]',
    status       TINYINT(1) DEFAULT 1,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Admin notifications (full management with delivery tracking) ───────────────
CREATE TABLE IF NOT EXISTS admin_notifications_tbl (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(255) NOT NULL,
    message          TEXT NOT NULL,
    notif_type       ENUM('important','update','promotion','system_alert','general') DEFAULT 'general',
    priority         ENUM('low','medium','high') DEFAULT 'medium',
    target           ENUM('all','specific') DEFAULT 'all',
    target_email     VARCHAR(255) NULL,
    status           ENUM('draft','pending','sent','failed') DEFAULT 'draft',
    channels         VARCHAR(100) DEFAULT 'inapp',
    scheduled_at     DATETIME NULL,
    sent_at          DATETIME NULL,
    created_by       VARCHAR(255) NULL,
    total_recipients INT DEFAULT 0,
    delivered_count  INT DEFAULT 0,
    read_count       INT DEFAULT 0,
    failed_count     INT DEFAULT 0,
    email_sent       INT DEFAULT 0,
    sms_sent         INT DEFAULT 0,
    legacy_notif_id  INT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Delivery tracking per user per notification ───────────────────────────────
CREATE TABLE IF NOT EXISTS admin_notif_delivery_tbl (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    notification_id INT NOT NULL,
    user_id         INT NULL,
    user_name       VARCHAR(255) NULL,
    user_email      VARCHAR(255) NULL,
    user_phone      VARCHAR(50) NULL,
    delivery_status ENUM('pending','sent','delivered','failed','read') DEFAULT 'sent',
    sent_at         DATETIME NULL,
    delivered_at    DATETIME NULL,
    read_at         DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_id (notification_id),
    INDEX idx_user_email (user_email),
    INDEX idx_delivery_status (delivery_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Notification API settings (Resend email, BulkSMS, etc.) ──────────────────
CREATE TABLE IF NOT EXISTS admin_notif_api_settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Referral tables (if not already present) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS referal_tbl (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    referal     VARCHAR(255) NOT NULL,
    referee     VARCHAR(255) NOT NULL,
    date_refer  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_referal (referal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referal_earn_transaction_tbl (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    referal_email VARCHAR(255) NOT NULL,
    buyer_email   VARCHAR(255) NOT NULL,
    earn_amount   DECIMAL(10,2) DEFAULT 0,
    date_trans    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status        TINYINT(1) DEFAULT 0,
    INDEX idx_referal_email (referal_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Add missing columns to users_tbl if not present ──────────────────────────
-- Run these individually; ignore errors if columns already exist
ALTER TABLE users_tbl ADD COLUMN IF NOT EXISTS nin VARCHAR(11) NULL;
ALTER TABLE users_tbl ADD COLUMN IF NOT EXISTS finger TINYINT(1) DEFAULT 0;
ALTER TABLE users_tbl ADD COLUMN IF NOT EXISTS referal_token VARCHAR(100) NULL;
ALTER TABLE users_tbl ADD COLUMN IF NOT EXISTS token TEXT NULL;
ALTER TABLE users_tbl ADD COLUMN IF NOT EXISTS monnify_account_details TEXT NULL;
ALTER TABLE users_tbl ADD COLUMN IF NOT EXISTS date_join TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- ── plan_types — MTN data bundle types ────────────────────────────────────────
-- Run these to ensure all MTN types are present and active (safe to re-run)
INSERT IGNORE INTO plan_types (id, data_type, title, network_id, status) VALUES
  (1,  'mtnsme',     'MTN SME',               1, 1),
  (2,  'mtncg',      'MTN Corporate Gifting',  1, 1),
  (7,  'mtnawoof',   'MTN Awoof',              1, 1),
  (9,  'mtnshare',   'DATA SHARE',             1, 0),
  (10, 'mtncoupons', 'DATA COUPONS',           1, 0),
  (11, 'mtnsme2',    'MTN SME 2',              1, 1),
  (12, 'mtn-sms',    'MTN SMS',                1, 0);

-- Activate any that were disabled
UPDATE plan_types SET status=1 WHERE network_id=1 AND id IN (1,2,7,11);
UPDATE plan_types SET status=0 WHERE network_id=1 AND id IN (9,10,12);
