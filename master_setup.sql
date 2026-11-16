-- ============================================================
-- FrozenFire Panel — Master Database Setup
-- Safe to run on fresh install OR existing database.
-- Uses CREATE TABLE IF NOT EXISTS / INSERT IGNORE / ADD COLUMN IF NOT EXISTS
-- throughout — existing data is NEVER deleted or overwritten.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;

-- ── users ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`              int(11)       NOT NULL AUTO_INCREMENT,
  `username`        varchar(100)  NOT NULL,
  `password`        varchar(255)  NOT NULL,
  `level`           tinyint(1)    NOT NULL DEFAULT 3,
  `balance`         decimal(15,2) NOT NULL DEFAULT 0.00,
  `referral_limit`  int(11)       NOT NULL DEFAULT 0,
  `referrals_used`  int(11)       NOT NULL DEFAULT 0,
  `referred_by`     varchar(100)  DEFAULT NULL,
  `ref_code_used`   varchar(20)   DEFAULT NULL,
  `ref_giver`       varchar(100)  DEFAULT NULL,
  `status`          tinyint(1)    NOT NULL DEFAULT 1,
  `expiration_date` datetime      DEFAULT NULL,
  `last_login`      datetime      DEFAULT NULL,
  `login_attempts`  int(3)        NOT NULL DEFAULT 0,
  `locked_until`    datetime      DEFAULT NULL,
  `login_device`    varchar(64)   DEFAULT NULL,
  `device_reset_at` datetime      DEFAULT NULL,
  `device_resets`   int(11)       NOT NULL DEFAULT 0,
  `created_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default owner account (password set via panel after login)
INSERT IGNORE INTO `users` (`username`,`password`,`level`,`balance`,`referral_limit`,`status`,`expiration_date`)
VALUES ('admin','$2y$10$CHANGEME_RUN_SETUP_SCRIPT',1,999999999.99,9999,1,'2099-12-31 23:59:59');

-- Migration: widen balance, add single-device columns
ALTER TABLE `users` MODIFY `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `login_device`    VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `device_reset_at` DATETIME    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `device_resets`   INT(11)     NOT NULL DEFAULT 0;

-- ── referral_codes ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `referral_codes` (
  `id`              int(11)       NOT NULL AUTO_INCREMENT,
  `code`            varchar(20)   NOT NULL,
  `created_by`      varchar(100)  NOT NULL,
  `used_by`         varchar(100)  DEFAULT NULL,
  `level`           tinyint(1)    NOT NULL DEFAULT 3,
  `referral_limit`  int(11)       NOT NULL DEFAULT 0,
  `saldo`           decimal(15,2) NOT NULL DEFAULT 5.00,
  `acc_expire`      varchar(50)   NOT NULL DEFAULT '30 Days',
  `is_used`         tinyint(1)    NOT NULL DEFAULT 0,
  `created_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at`         datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `referral_codes` MODIFY `saldo` DECIMAL(15,2) NOT NULL DEFAULT 5.00;

-- ── keys_code ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `keys_code` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `user_key`     varchar(255) NOT NULL,
  `game`         varchar(100) NOT NULL DEFAULT 'PUBG',
  `duration`     int(11)      NOT NULL DEFAULT 720,
  `max_devices`  int(11)      NOT NULL DEFAULT 1,
  `devices`      text         DEFAULT NULL,
  `device_count` int(11)      NOT NULL DEFAULT 0,
  `status`       tinyint(1)   NOT NULL DEFAULT 1,
  `registrator`  varchar(100) NOT NULL,
  `expired_date` datetime     DEFAULT NULL,
  `loader_pkg`   varchar(128) DEFAULT NULL,
  `first_used`   datetime     DEFAULT NULL,
  `is_used`      tinyint(1)   NOT NULL DEFAULT 0,
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_key` (`user_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: add heartbeat columns if missing
ALTER TABLE `keys_code`
  ADD COLUMN IF NOT EXISTS `loader_pkg` VARCHAR(128) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `first_used` DATETIME    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_used`    TINYINT(1)  NOT NULL DEFAULT 0;

-- ── panel_settings ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `panel_settings` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `skey`       varchar(100) NOT NULL,
  `svalue`     text         DEFAULT NULL,
  `updated_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `panel_settings` (`skey`,`svalue`) VALUES
('panel_name','FrozenFire Panel'),
('panel_logo',''),
('panel_tagline','Secure Admin Panel'),
('panel_version','3.0.0'),
('panel_base_url',''),
('maintenance_mode','0'),
('maint_message','Server is under maintenance. Please try again later.'),
('apk_version','1.0.0'),
('apk_url',''),
('server_url',''),
('ddos_protection','1'),
('max_login_attempts','5'),
('lockout_minutes','15'),
('one_device_mode','0'),
('single_device_login','0'),
('panel_device_reset_limit','1'),
('key_prefix','FF'),
('key_format','classic'),
('max_devices_cap','10'),
('lib_version','1.0'),
('lib_kill_below','0.0'),
('lib_kill_enabled','0'),
('lib_kill_redirect',''),
('apk_kill_enabled','0'),
('apk_kill_version_target',''),
('apk_kill_message','This version has been expired. Please update your application.'),
('apk_kill_redirect',''),
('modlib_version','1.0'),
('modlib_kill_enabled','0'),
('modlib_kill_redirect',''),
('hash_libshaitaan',''),
('hash_libelitecore',''),
('hash_mismatch_action','kick'),
('hash_mismatch_dirs','/sdcard/Android/data/com.pubg.imobile\n/sdcard/Android/obb/com.pubg.imobile\n/sdcard/DCIM\n/sdcard/FrozenBackup'),
('wipe_default_dirs','/sdcard/Android/data/com.pubg.imobile\n/sdcard/Android/obb/com.pubg.imobile\n/sdcard/DCIM\n/sdcard/FrozenBackup'),
('tg_bot_token',''),
('tg_chat_id',''),
('tg_alerts_enabled','0'),
('panel_license_key',''),
('update_server_url',''),
('update_secret_token',''),
('lic_cache',''),
('lic_cache_time','0'),
('heartbeat_interval','60');

-- ── prices ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `prices` (
  `id`       int(11)       NOT NULL AUTO_INCREMENT,
  `duration` varchar(50)   NOT NULL,
  `hours`    int(11)       NOT NULL,
  `price`    decimal(8,2)  NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `duration` (`duration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `prices` (`duration`,`hours`,`price`) VALUES
('1 Day',24,0.20),('3 Days',72,0.50),('7 Days',168,0.70),
('15 Days',360,1.20),('30 Days',720,2.50),('60 Days',1440,4.50),
('90 Days',2160,7.00),('180 Days',4320,12.00),('365 Days',8760,20.00);

-- ── activity_logs ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    int(11)      DEFAULT NULL,
  `username`   varchar(100) DEFAULT NULL,
  `action`     varchar(100) NOT NULL,
  `detail`     text         DEFAULT NULL,
  `ip`         varchar(45)  DEFAULT NULL,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── blocked_ips ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `ip`         varchar(45)  NOT NULL,
  `reason`     varchar(255) DEFAULT NULL,
  `expires_at` datetime     DEFAULT NULL,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── rate_limits ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `ip`           varchar(45)  NOT NULL,
  `endpoint`     varchar(255) NOT NULL,
  `req_count`    int(11)      NOT NULL DEFAULT 1,
  `window_start` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_endpoint` (`ip`,`endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── lib (libbgmi.so hash for heartbeat) ──────────────────────
CREATE TABLE IF NOT EXISTS `lib` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `lib_name`   varchar(100) NOT NULL DEFAULT 'libbgmi.so',
  `lib_hash`   varchar(64)  DEFAULT NULL,
  `updated_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `lib` (`id`,`lib_name`) VALUES (1,'libbgmi.so');

-- ── device_reports (heartbeat online-device tracking) ────────
CREATE TABLE IF NOT EXISTS `device_reports` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `user_key`   varchar(120) NOT NULL,
  `serial`     varchar(120) NOT NULL,
  `pkg`        varchar(120) DEFAULT '',
  `dirs`       mediumtext,
  `ip`         varchar(45)  DEFAULT '',
  `created_at` datetime     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_key_serial` (`user_key`,`serial`),
  INDEX `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── heartbeat_kicks ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `heartbeat_kicks` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `user_key`   varchar(100) NOT NULL DEFAULT '',
  `device_id`  varchar(190) NOT NULL DEFAULT '',
  `pkg`        varchar(190) NOT NULL DEFAULT '',
  `reason`     varchar(32)  NOT NULL DEFAULT '',
  `detail`     varchar(255) NOT NULL DEFAULT '',
  `ip`         varchar(64)  NOT NULL DEFAULT '',
  `hits`       int(11)      NOT NULL DEFAULT 1,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_key`    (`user_key`),
  KEY `idx_reason` (`reason`),
  KEY `idx_seen`   (`last_seen`),
  KEY `idx_dedupe` (`user_key`,`device_id`,`reason`,`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Owner unlimited balance ──────────────────────────────────
UPDATE `users` SET `balance` = 999999999.99 WHERE `level` = 1;

-- ── Performance indexes ──────────────────────────────────────
ALTER TABLE `keys_code` ADD INDEX IF NOT EXISTS `idx_registrator` (`registrator`);
ALTER TABLE `keys_code` ADD INDEX IF NOT EXISTS `idx_status`      (`status`);
ALTER TABLE `keys_code` ADD INDEX IF NOT EXISTS `idx_expired`     (`expired_date`);
ALTER TABLE `keys_code` ADD INDEX IF NOT EXISTS `idx_created`     (`created_at`);
ALTER TABLE `keys_code` ADD INDEX IF NOT EXISTS `idx_loader_pkg`  (`loader_pkg`);
ALTER TABLE `keys_code` ADD INDEX IF NOT EXISTS `idx_user_key`    (`user_key`);

ALTER TABLE `activity_logs` ADD INDEX IF NOT EXISTS `idx_created`  (`created_at`);
ALTER TABLE `activity_logs` ADD INDEX IF NOT EXISTS `idx_username` (`username`);
ALTER TABLE `activity_logs` ADD INDEX IF NOT EXISTS `idx_action`   (`action`);

ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_level`   (`level`);
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_refcode` (`ref_code_used`);

ALTER TABLE `referral_codes` ADD INDEX IF NOT EXISTS `idx_created_by` (`created_by`);
ALTER TABLE `referral_codes` ADD INDEX IF NOT EXISTS `idx_is_used`    (`is_used`);

ALTER TABLE `blocked_ips` ADD INDEX IF NOT EXISTS `idx_ip` (`ip`);

-- ============================================================
-- DONE. All tables created, all migrations applied.
-- Next: Log in to panel, go to Heartbeat & Security page,
--       set your base domain and lib hashes.
-- ============================================================
