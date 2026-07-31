-- ============================================================================
-- LRMS - Loan Recovery Management System
-- MySQL 5.7+ / 8.0+ / MariaDB 10.3+ schema
--
-- Charset:   utf8mb4 / utf8mb4_unicode_ci
-- Engine:    InnoDB
--
-- PII POLICY
--   Mobile numbers and Aadhaar numbers are NEVER stored in plaintext.
--   Each has two columns:
--     *_enc   VARBINARY  -> AES-256-GCM ciphertext (app-layer, see Core/Crypto.php)
--     *_hash  CHAR(64)   -> HMAC-SHA256 of the normalised value, for exact-match search
--   A short masked form (e.g. "XXXXXX1234") is stored for display in list views so
--   that grids never need to decrypt 25+ rows per page.
--
-- APPEND-ONLY POLICY
--   visit_reports and visit_history are append-only. There is no UPDATE path in the
--   application for either table. A new field visit always INSERTs a new row.
--
-- Designed for: 100+ branches, 1,000+ agents, 500,000+ customers, millions of visits.
--
-- ############################################################################
-- #                                                                          #
-- #  THIS FILE DESTROYS DATA. IT IS AN INSTALL SCRIPT, NOT A MIGRATION.       #
-- #                                                                          #
-- #  Every table below begins with DROP TABLE IF EXISTS, so importing this    #
-- #  file into a database that already holds records DELETES ALL OF THEM -    #
-- #  every customer, visit, promise, photo reference and user account.        #
-- #                                                                          #
-- #  Run it ONCE, on an empty database, when first installing.               #
-- #                                                                          #
-- #  Never run it to "refresh" or "repair" a live installation. To upgrade,   #
-- #  apply the migration named in the release notes. Take a backup first:     #
-- #      php /home/USER/public_html/cron/backup.php                          #
-- #                                                                          #
-- ############################################################################
-- ============================================================================

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- Off only so the tables below can be created in any order. Switched back on at
-- the very end of this file - leaving it off would let the rest of the session
-- (a phpMyAdmin tab, an operator's shell) write rows that break referential
-- integrity without any complaint.
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. RBAC
-- ============================================================================

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`         VARCHAR(50)  NOT NULL COMMENT 'super_admin | branch_manager | agent',
  `display_name` VARCHAR(100) NOT NULL,
  `description`  VARCHAR(255) DEFAULT NULL,
  `is_system`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = cannot be deleted',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(80)  NOT NULL COMMENT 'e.g. leads.assign',
  `module`       VARCHAR(50)  NOT NULL COMMENT 'grouping for the permission matrix UI',
  `display_name` VARCHAR(120) NOT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`),
  KEY `idx_permissions_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `idx_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `roles` (`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. BRANCHES
-- ============================================================================

DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_code` VARCHAR(30)  NOT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `district`    VARCHAR(100) DEFAULT NULL,
  `state`       VARCHAR(100) DEFAULT NULL,
  `pincode`     VARCHAR(10)  DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`branch_code`),
  KEY `idx_branches_status` (`status`),
  KEY `idx_branches_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. USERS  (super admins + branch managers + BC/DC agents)
-- ============================================================================

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_code`        VARCHAR(40)  NOT NULL COMMENT 'login identifier',
  `name`                 VARCHAR(150) NOT NULL,
  `email`                VARCHAR(190) DEFAULT NULL,
  `mobile_enc`           VARBINARY(255) DEFAULT NULL,
  `mobile_hash`          CHAR(64)     DEFAULT NULL COMMENT 'HMAC-SHA256, alternate login key',
  `mobile_masked`        VARCHAR(20)  DEFAULT NULL,
  `password_hash`        VARCHAR(255) NOT NULL COMMENT 'bcrypt (PASSWORD_BCRYPT)',
  `role_id`              INT UNSIGNED NOT NULL,
  `branch_id`            INT UNSIGNED DEFAULT NULL COMMENT 'NULL for super admin',
  `bc_code`              VARCHAR(40)  DEFAULT NULL COMMENT 'BC/DC code for agents',
  `designation`          VARCHAR(100) DEFAULT NULL,
  `status`               ENUM('active','suspended','inactive') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'force change on first login',
  `last_login_at`        DATETIME     DEFAULT NULL,
  `last_login_ip`        VARCHAR(45)  DEFAULT NULL,
  `failed_attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`         DATETIME     DEFAULT NULL,
  `created_by`           INT UNSIGNED DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_employee_code` (`employee_code`),
  KEY `idx_users_mobile_hash` (`mobile_hash`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_branch_status` (`branch_id`, `status`),
  KEY `idx_users_bc_code` (`bc_code`),
  CONSTRAINT `fk_users_role`   FOREIGN KEY (`role_id`)   REFERENCES `roles` (`id`),
  CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Long-lived refresh tokens for the Android app ("remember login").
DROP TABLE IF EXISTS `refresh_tokens`;
CREATE TABLE `refresh_tokens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64)     NOT NULL COMMENT 'SHA-256 of the opaque refresh token',
  `device_info` VARCHAR(255) DEFAULT NULL,
  `ip`          VARCHAR(45)  DEFAULT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `revoked_at`  DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refresh_token_hash` (`token_hash`),
  KEY `idx_refresh_user` (`user_id`, `revoked_at`),
  KEY `idx_refresh_expires` (`expires_at`),
  CONSTRAINT `fk_refresh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Forgot-password OTPs (SMS gateway) — no email dependency required.
DROP TABLE IF EXISTS `password_otps`;
CREATE TABLE `password_otps` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `otp_hash`   CHAR(64)     NOT NULL,
  `channel`    ENUM('sms','email','admin') NOT NULL DEFAULT 'sms',
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `ip`         VARCHAR(45)  DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`, `used_at`),
  KEY `idx_otp_expires` (`expires_at`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. CUSTOMERS  (borrower identity)
-- ============================================================================

DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`           INT UNSIGNED NOT NULL,
  `name`                VARCHAR(150) NOT NULL,
  `father_husband_name` VARCHAR(150) DEFAULT NULL,
  `mobile_enc`          VARBINARY(255) DEFAULT NULL,
  `mobile_hash`         CHAR(64)     DEFAULT NULL,
  `mobile_masked`       VARCHAR(20)  DEFAULT NULL,
  `aadhaar_enc`         VARBINARY(255) DEFAULT NULL,
  `aadhaar_hash`        CHAR(64)     DEFAULT NULL,
  `aadhaar_masked`      VARCHAR(20)  DEFAULT NULL,
  `village`             VARCHAR(150) DEFAULT NULL,
  `address`             VARCHAR(500) DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customers_branch` (`branch_id`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_village` (`village`),
  KEY `idx_customers_mobile_hash` (`mobile_hash`),
  KEY `idx_customers_aadhaar_hash` (`aadhaar_hash`),
  KEY `idx_customers_branch_village` (`branch_id`, `village`),
  CONSTRAINT `fk_customers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. LEAD IMPORTS  (declared before loan_accounts for the FK)
-- ============================================================================

DROP TABLE IF EXISTS `lead_imports`;
CREATE TABLE `lead_imports` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_name`   VARCHAR(255) NOT NULL,
  `stored_path`     VARCHAR(500) DEFAULT NULL,
  `total_rows`      INT UNSIGNED NOT NULL DEFAULT 0,
  `inserted_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `error_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `status`          ENUM('pending','validating','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `error_log_path`  VARCHAR(500) DEFAULT NULL COMMENT 'downloadable CSV of rejected rows',
  `failure_message` VARCHAR(500) DEFAULT NULL,
  `default_agent_id` INT UNSIGNED DEFAULT NULL COMMENT 'bulk assignment target chosen at upload',
  `uploaded_by`     INT UNSIGNED NOT NULL,
  `branch_id`       INT UNSIGNED DEFAULT NULL,
  `started_at`      DATETIME     DEFAULT NULL,
  `finished_at`     DATETIME     DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_imports_user` (`uploaded_by`),
  KEY `idx_imports_status` (`status`, `created_at`),
  CONSTRAINT `fk_imports_user`   FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_imports_branch` FOREIGN KEY (`branch_id`)   REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. LOAN ACCOUNTS  (this is "the lead")
-- ============================================================================

DROP TABLE IF EXISTS `loan_accounts`;
CREATE TABLE `loan_accounts` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_account_number`  VARCHAR(60)  NOT NULL COMMENT 'duplicate-detection key on import',
  `customer_id`          BIGINT UNSIGNED NOT NULL,
  `branch_id`            INT UNSIGNED NOT NULL,
  `bc_code`              VARCHAR(40)  DEFAULT NULL,
  `loan_type`            VARCHAR(80)  DEFAULT NULL,
  `outstanding_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `overdue_amount`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `npa_date`             DATE         DEFAULT NULL,
  `is_npa`              TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'derived: npa_date IS NOT NULL',
  `current_status`       ENUM('pending','visited','promise','followup','legal','closed') NOT NULL DEFAULT 'pending',
  `assigned_agent_id`    INT UNSIGNED DEFAULT NULL,
  `assigned_at`          DATETIME     DEFAULT NULL,
  `assigned_by`          INT UNSIGNED DEFAULT NULL,
  `last_visit_at`        DATETIME     DEFAULT NULL,
  `visit_count`          INT UNSIGNED NOT NULL DEFAULT 0,
  `last_promise_id`      BIGINT UNSIGNED DEFAULT NULL,
  `next_followup_date`   DATE         DEFAULT NULL,
  `remarks`              VARCHAR(1000) DEFAULT NULL COMMENT 'remarks from the source Excel file',
  `import_id`            BIGINT UNSIGNED DEFAULT NULL,
  `closed_at`            DATETIME     DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loan_account_number` (`loan_account_number`),
  KEY `idx_loan_customer` (`customer_id`),
  KEY `idx_loan_agent_status` (`assigned_agent_id`, `current_status`),
  KEY `idx_loan_branch_status` (`branch_id`, `current_status`),
  KEY `idx_loan_branch_agent` (`branch_id`, `assigned_agent_id`),
  KEY `idx_loan_type` (`loan_type`),
  KEY `idx_loan_npa` (`is_npa`, `npa_date`),
  KEY `idx_loan_followup` (`next_followup_date`),
  KEY `idx_loan_import` (`import_id`),
  KEY `idx_loan_last_visit` (`last_visit_at`),
  CONSTRAINT `fk_loan_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_loan_branch`   FOREIGN KEY (`branch_id`)   REFERENCES `branches` (`id`),
  CONSTRAINT `fk_loan_agent`    FOREIGN KEY (`assigned_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_loan_import`   FOREIGN KEY (`import_id`)   REFERENCES `lead_imports` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. VISIT REPORTS  (Digital BC Field Visit Report - APPEND ONLY)
--    Borrower/loan values are snapshotted at submission time so a historical
--    report always renders exactly as it was signed.
-- ============================================================================

DROP TABLE IF EXISTS `visit_reports`;
CREATE TABLE `visit_reports` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_account_id`      BIGINT UNSIGNED NOT NULL,
  `customer_id`          BIGINT UNSIGNED NOT NULL,
  `agent_id`             INT UNSIGNED NOT NULL,
  `branch_id`            INT UNSIGNED NOT NULL,

  -- ---- General -----------------------------------------------------------
  `visit_date`           DATE         NOT NULL,
  `visit_time`           TIME         NOT NULL,
  `bc_code`              VARCHAR(40)  DEFAULT NULL,
  `agent_name`           VARCHAR(150) NOT NULL COMMENT 'snapshot',
  `branch_name`          VARCHAR(150) DEFAULT NULL COMMENT 'snapshot',
  `village`              VARCHAR(150) DEFAULT NULL,

  -- ---- Borrower details (snapshot) ---------------------------------------
  `customer_name`        VARCHAR(150) NOT NULL,
  `father_husband_name`  VARCHAR(150) DEFAULT NULL,
  `address`              VARCHAR(500) DEFAULT NULL,
  `mobile_enc`           VARBINARY(255) DEFAULT NULL,
  `mobile_hash`          CHAR(64)     DEFAULT NULL,
  `mobile_masked`        VARCHAR(20)  DEFAULT NULL,
  `aadhaar_enc`          VARBINARY(255) DEFAULT NULL,
  `aadhaar_hash`         CHAR(64)     DEFAULT NULL,
  `aadhaar_masked`       VARCHAR(20)  DEFAULT NULL,

  -- ---- Loan details (snapshot) -------------------------------------------
  `loan_account_number`  VARCHAR(60)  NOT NULL,
  `loan_type`            VARCHAR(80)  DEFAULT NULL,
  `outstanding_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `overdue_amount`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `npa_date`             DATE         DEFAULT NULL,
  `current_status`       VARCHAR(40)  DEFAULT NULL,

  -- ---- Customer contact --------------------------------------------------
  `customer_met`               TINYINT(1) NOT NULL DEFAULT 0,
  `family_member_met`          TINYINT(1) NOT NULL DEFAULT 0,
  `house_locked`               TINYINT(1) NOT NULL DEFAULT 0,
  `phone_contact`              TINYINT(1) NOT NULL DEFAULT 0,
  `phone_switched_off`         TINYINT(1) NOT NULL DEFAULT 0,
  `family_member_name`         VARCHAR(150) DEFAULT NULL,
  `family_member_relationship` VARCHAR(80)  DEFAULT NULL,

  -- ---- Physical verification ---------------------------------------------
  `borrower_alive` TINYINT(1) NOT NULL DEFAULT 1,
  `same_address`   TINYINT(1) NOT NULL DEFAULT 1,
  `shifted`        TINYINT(1) NOT NULL DEFAULT 0,
  `occupation`     ENUM('agriculture','dairy','business','job','labour','others') DEFAULT NULL,
  `occupation_other_text` VARCHAR(150) DEFAULT NULL,

  -- ---- Recovery possibility ----------------------------------------------
  `ready_to_pay`     TINYINT(1) NOT NULL DEFAULT 0,
  `not_ready`        TINYINT(1) NOT NULL DEFAULT 0,
  `interest_payment` TINYINT(1) NOT NULL DEFAULT 0,
  `ots`              TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'One Time Settlement offered',
  `promise_amount`   DECIMAL(15,2) DEFAULT NULL,
  `promise_date`     DATE          DEFAULT NULL,

  -- ---- Non-payment reason -------------------------------------------------
  `reason_financial_problem` TINYINT(1) NOT NULL DEFAULT 0,
  `reason_crop_loss`         TINYINT(1) NOT NULL DEFAULT 0,
  `reason_animal_loss`       TINYINT(1) NOT NULL DEFAULT 0,
  `reason_illness`           TINYINT(1) NOT NULL DEFAULT 0,
  `reason_unemployment`      TINYINT(1) NOT NULL DEFAULT 0,
  `reason_dispute`           TINYINT(1) NOT NULL DEFAULT 0,
  `reason_other_loan`        TINYINT(1) NOT NULL DEFAULT 0,
  `reason_others`            TINYINT(1) NOT NULL DEFAULT 0,
  `reason_other_text`        VARCHAR(255) DEFAULT NULL,

  -- ---- Agent recommendation ----------------------------------------------
  `rec_recovery_possible`  TINYINT(1) NOT NULL DEFAULT 0,
  `rec_regular_followup`   TINYINT(1) NOT NULL DEFAULT 0,
  `rec_legal_action`       TINYINT(1) NOT NULL DEFAULT 0,
  `rec_rc`                 TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Revenue Certificate',
  `rec_ots`                TINYINT(1) NOT NULL DEFAULT 0,
  `rec_others`             TINYINT(1) NOT NULL DEFAULT 0,
  `rec_other_text`         VARCHAR(255) DEFAULT NULL,

  -- ---- Remarks & meta -----------------------------------------------------
  `remarks`      TEXT         DEFAULT NULL,
  `source`       ENUM('android','web') NOT NULL DEFAULT 'android',
  `app_version`  VARCHAR(30)  DEFAULT NULL,
  `device_info`  VARCHAR(255) DEFAULT NULL,
  `client_uuid`  CHAR(36)     DEFAULT NULL COMMENT 'idempotency key from the app to stop double submits',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visit_client_uuid` (`client_uuid`),
  KEY `idx_visit_loan_created` (`loan_account_id`, `created_at`),
  KEY `idx_visit_agent_date` (`agent_id`, `visit_date`),
  KEY `idx_visit_branch_date` (`branch_id`, `visit_date`),
  KEY `idx_visit_date` (`visit_date`),
  KEY `idx_visit_village` (`village`),
  KEY `idx_visit_loan_type` (`loan_type`),
  KEY `idx_visit_customer` (`customer_id`),
  KEY `idx_visit_loan_number` (`loan_account_number`),
  CONSTRAINT `fk_visit_loan`     FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visit_customer` FOREIGN KEY (`customer_id`)     REFERENCES `customers` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_visit_agent`    FOREIGN KEY (`agent_id`)        REFERENCES `users` (`id`),
  CONSTRAINT `fk_visit_branch`   FOREIGN KEY (`branch_id`)       REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. PROMISES
-- ============================================================================

DROP TABLE IF EXISTS `promises`;
CREATE TABLE `promises` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_account_id`  BIGINT UNSIGNED NOT NULL,
  `customer_id`      BIGINT UNSIGNED NOT NULL,
  `visit_report_id`  BIGINT UNSIGNED DEFAULT NULL,
  `agent_id`         INT UNSIGNED NOT NULL,
  `branch_id`        INT UNSIGNED NOT NULL,
  `promise_amount`   DECIMAL(15,2) NOT NULL,
  `promise_date`     DATE         NOT NULL,
  `status`           ENUM('pending','kept','broken','cancelled') NOT NULL DEFAULT 'pending',
  `settled_at`       DATETIME     DEFAULT NULL,
  `settled_by`       INT UNSIGNED DEFAULT NULL,
  `notes`            VARCHAR(500) DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_promise_loan` (`loan_account_id`, `created_at`),
  KEY `idx_promise_status_date` (`status`, `promise_date`),
  KEY `idx_promise_branch_status` (`branch_id`, `status`),
  KEY `idx_promise_agent_status` (`agent_id`, `status`),
  KEY `idx_promise_visit` (`visit_report_id`),
  CONSTRAINT `fk_promise_loan`     FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_promise_customer` FOREIGN KEY (`customer_id`)     REFERENCES `customers` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_promise_visit`    FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_promise_agent`    FOREIGN KEY (`agent_id`)        REFERENCES `users` (`id`),
  CONSTRAINT `fk_promise_branch`   FOREIGN KEY (`branch_id`)       REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `loan_accounts`
  ADD CONSTRAINT `fk_loan_last_promise` FOREIGN KEY (`last_promise_id`) REFERENCES `promises` (`id`) ON DELETE SET NULL;

-- ============================================================================
-- 9. VISIT HISTORY  (append-only timeline for every state change)
-- ============================================================================

DROP TABLE IF EXISTS `visit_history`;
CREATE TABLE `visit_history` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_account_id` BIGINT UNSIGNED NOT NULL,
  `event_type`      ENUM(
                      'lead_imported','lead_updated','assigned','reassigned','transferred',
                      'visit','promise_created','promise_kept','promise_broken',
                      'status_changed','closed','reopened','note'
                    ) NOT NULL,
  `event_at`        DATETIME     NOT NULL,
  `actor_id`        INT UNSIGNED DEFAULT NULL COMMENT 'NULL = system',
  `actor_name`      VARCHAR(150) DEFAULT NULL COMMENT 'snapshot',
  `visit_report_id` BIGINT UNSIGNED DEFAULT NULL,
  `promise_id`      BIGINT UNSIGNED DEFAULT NULL,
  `title`           VARCHAR(180) NOT NULL,
  `description`     VARCHAR(1000) DEFAULT NULL,
  `meta`            JSON         DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_history_loan_event` (`loan_account_id`, `event_at`, `id`),
  KEY `idx_history_type_date` (`event_type`, `event_at`),
  KEY `idx_history_actor` (`actor_id`),
  KEY `idx_history_visit` (`visit_report_id`),
  CONSTRAINT `fk_history_loan`  FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_history_visit` FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_promise` FOREIGN KEY (`promise_id`)    REFERENCES `promises` (`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. MEDIA: photos / documents / signatures
-- ============================================================================

DROP TABLE IF EXISTS `photos`;
CREATE TABLE `photos` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_report_id` BIGINT UNSIGNED DEFAULT NULL,
  `loan_account_id` BIGINT UNSIGNED NOT NULL,
  `photo_type`      ENUM('customer','house','aadhaar','other') NOT NULL DEFAULT 'other',
  `file_path`       VARCHAR(500) NOT NULL COMMENT 'relative to uploads root',
  `original_name`   VARCHAR(255) DEFAULT NULL,
  `mime_type`       VARCHAR(100) DEFAULT NULL,
  `file_size`       INT UNSIGNED DEFAULT NULL,
  `width`           SMALLINT UNSIGNED DEFAULT NULL,
  `height`          SMALLINT UNSIGNED DEFAULT NULL,
  `uploaded_by`     INT UNSIGNED NOT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_photos_visit` (`visit_report_id`),
  KEY `idx_photos_loan_type` (`loan_account_id`, `photo_type`),
  CONSTRAINT `fk_photos_visit` FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photos_loan`  FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photos_user`  FOREIGN KEY (`uploaded_by`)     REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_report_id` BIGINT UNSIGNED DEFAULT NULL,
  `loan_account_id` BIGINT UNSIGNED NOT NULL,
  `doc_type`        VARCHAR(60)  NOT NULL DEFAULT 'other',
  `title`           VARCHAR(180) DEFAULT NULL,
  `file_path`       VARCHAR(500) NOT NULL,
  `original_name`   VARCHAR(255) DEFAULT NULL,
  `mime_type`       VARCHAR(100) DEFAULT NULL,
  `file_size`       INT UNSIGNED DEFAULT NULL,
  `uploaded_by`     INT UNSIGNED NOT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_docs_visit` (`visit_report_id`),
  KEY `idx_docs_loan` (`loan_account_id`, `doc_type`),
  CONSTRAINT `fk_docs_visit` FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docs_loan`  FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docs_user`  FOREIGN KEY (`uploaded_by`)     REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One customer signature + one agent signature per visit report.
DROP TABLE IF EXISTS `signatures`;
CREATE TABLE `signatures` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_report_id` BIGINT UNSIGNED NOT NULL,
  `loan_account_id` BIGINT UNSIGNED NOT NULL,
  `signature_type`  ENUM('customer','agent') NOT NULL,
  `file_path`       VARCHAR(500) NOT NULL COMMENT 'PNG',
  `signed_name`     VARCHAR(150) DEFAULT NULL,
  `file_size`       INT UNSIGNED DEFAULT NULL,
  `captured_at`     DATETIME     DEFAULT NULL,
  `uploaded_by`     INT UNSIGNED NOT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signature_visit_type` (`visit_report_id`, `signature_type`),
  KEY `idx_sign_loan` (`loan_account_id`),
  CONSTRAINT `fk_sign_visit` FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sign_loan`  FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sign_user`  FOREIGN KEY (`uploaded_by`)     REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. NOTIFICATIONS
-- ============================================================================

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED DEFAULT NULL COMMENT 'NULL = broadcast to all',
  `branch_id`       INT UNSIGNED DEFAULT NULL COMMENT 'scope a broadcast to one branch',
  `type`            ENUM('new_lead_assigned','followup_reminder','promise_reminder','broadcast') NOT NULL,
  `title`           VARCHAR(180) NOT NULL,
  `body`            VARCHAR(1000) DEFAULT NULL,
  `loan_account_id` BIGINT UNSIGNED DEFAULT NULL,
  `data`            JSON         DEFAULT NULL,
  `is_read`         TINYINT(1)   NOT NULL DEFAULT 0,
  `read_at`         DATETIME     DEFAULT NULL,
  `pushed_at`       DATETIME     DEFAULT NULL COMMENT 'FCM delivery timestamp',
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`, `is_read`, `created_at`),
  KEY `idx_notif_branch` (`branch_id`, `created_at`),
  KEY `idx_notif_loan` (`loan_account_id`),
  CONSTRAINT `fk_notif_user`   FOREIGN KEY (`user_id`)         REFERENCES `users` (`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_notif_branch` FOREIGN KEY (`branch_id`)       REFERENCES `branches` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_notif_loan`   FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Firebase device tokens for push (optional; only used when Firebase key is set).
DROP TABLE IF EXISTS `device_tokens`;
CREATE TABLE `device_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(255) NOT NULL,
  `platform`   ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  `app_version` VARCHAR(30) DEFAULT NULL,
  `last_seen_at` DATETIME   DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_token` (`token`),
  KEY `idx_device_user` (`user_id`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. LOGS
-- ============================================================================

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `user_name`   VARCHAR(150) DEFAULT NULL COMMENT 'snapshot',
  `action`      ENUM('create','update','delete','import','assign','reassign','transfer','restore','backup','login_reset') NOT NULL,
  `entity_type` VARCHAR(60)  NOT NULL COMMENT 'e.g. loan_account, user, branch',
  `entity_id`   VARCHAR(60)  DEFAULT NULL,
  `old_values`  JSON         DEFAULT NULL,
  `new_values`  JSON         DEFAULT NULL,
  `summary`     VARCHAR(500) DEFAULT NULL,
  `ip`          VARCHAR(45)  DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_user_date` (`user_id`, `created_at`),
  KEY `idx_audit_action_date` (`action`, `created_at`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `user_name`   VARCHAR(150) DEFAULT NULL,
  `activity`    VARCHAR(60)  NOT NULL COMMENT 'login | logout | export | view | failed_login ...',
  `module`      VARCHAR(60)  DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `method`      VARCHAR(10)  DEFAULT NULL,
  `url`         VARCHAR(500) DEFAULT NULL,
  `ip`          VARCHAR(45)  DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_user_date` (`user_id`, `created_at`),
  KEY `idx_activity_type_date` (`activity`, `created_at`),
  KEY `idx_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 13. SETTINGS  (DB-driven; no hardcoded secrets anywhere in the codebase)
-- ============================================================================

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`  VARCHAR(80)  NOT NULL,
  `setting_value` TEXT        DEFAULT NULL,
  `group_name`   VARCHAR(40)  NOT NULL DEFAULT 'general',
  `label`        VARCHAR(150) NOT NULL,
  `input_type`   ENUM('text','password','number','textarea','select','toggle') NOT NULL DEFAULT 'text',
  `options`      VARCHAR(500) DEFAULT NULL COMMENT 'comma separated, for select',
  `is_secret`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'masked in UI + never logged',
  `is_required`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'drives the Missing Configuration banner',
  `hint`         VARCHAR(255) DEFAULT NULL,
  `sort_order`   SMALLINT     NOT NULL DEFAULT 0,
  `updated_by`   INT UNSIGNED DEFAULT NULL,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`),
  KEY `idx_settings_group` (`group_name`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED: ROLES
-- ============================================================================

INSERT INTO `roles` (`id`, `slug`, `display_name`, `description`, `is_system`) VALUES
  (1, 'super_admin',    'Super Admin',    'Full system access across all branches',        1),
  (2, 'branch_manager', 'Branch Manager', 'Scoped to the assigned branch only',            1),
  (3, 'agent',          'BC/DC Agent',    'Android app only - assigned leads and visits',  1),
  (4, 'auditor',        'Auditor',        'Read-only access to reports and logs',          1);

-- ============================================================================
-- SEED: PERMISSIONS
-- ============================================================================

INSERT INTO `permissions` (`code`, `module`, `display_name`) VALUES
  ('dashboard.view',        'Dashboard',   'View dashboard'),
  ('dashboard.all_branches','Dashboard',   'View all branches on dashboard'),

  ('branches.view',         'Branches',    'View branches'),
  ('branches.create',       'Branches',    'Create branch'),
  ('branches.update',       'Branches',    'Update branch'),
  ('branches.delete',       'Branches',    'Delete branch'),

  ('users.view',            'Users',       'View managers and agents'),
  ('users.create',          'Users',       'Create user'),
  ('users.update',          'Users',       'Update user'),
  ('users.delete',          'Users',       'Delete user'),
  ('users.reset_password',  'Users',       'Reset user password'),
  ('users.toggle_status',   'Users',       'Activate or suspend user'),

  ('roles.view',            'Roles',       'View roles and permissions'),
  ('roles.manage',          'Roles',       'Manage roles and permission sets'),

  ('customers.view',        'Customers',   'View customers and leads'),
  ('customers.update',      'Customers',   'Update customer details'),
  ('customers.view_pii',    'Customers',   'View unmasked mobile and Aadhaar'),

  ('leads.assign',          'Leads',       'Assign leads to agents'),
  ('leads.reassign',        'Leads',       'Reassign leads between agents'),
  ('leads.transfer',        'Leads',       'Transfer leads between branches'),
  ('leads.close',           'Leads',       'Close a lead'),

  ('import.upload',         'Import',      'Upload Excel lead file'),
  ('import.view',           'Import',      'View import history'),

  ('visits.view',           'Visits',      'View visit reports and timeline'),
  ('visits.create',         'Visits',      'Submit a field visit report'),

  ('promises.view',         'Promises',    'View promise cases'),
  ('promises.update',       'Promises',    'Mark promise kept or broken'),

  ('reports.view',          'Reports',     'View reports'),
  ('reports.export',        'Reports',     'Export reports to Excel/PDF'),

  ('notifications.view',    'Notifications','View notifications'),
  ('notifications.send',    'Notifications','Send broadcast notification'),

  ('logs.audit',            'Logs',        'View audit logs'),
  ('logs.activity',         'Logs',        'View activity logs'),

  ('settings.view',         'Settings',    'View settings'),
  ('settings.update',       'Settings',    'Update settings'),

  ('backup.run',            'Backup',      'Run and download database backup');

-- Super Admin -> every permission
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 1, `id` FROM `permissions`;

-- Branch Manager -> branch-scoped operational set
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 2, `id` FROM `permissions` WHERE `code` IN (
    'dashboard.view','customers.view','customers.update','users.view',
    'leads.assign','leads.reassign','leads.close',
    'visits.view','promises.view','promises.update',
    'reports.view','reports.export','notifications.view','import.view'
  );

-- BC/DC Agent -> Android app surface only.
-- dashboard.view is granted because the app has an agent home screen with the
-- agent's own counters (assigned / pending / visits / promises). It is scoped to
-- that agent in code; it does not expose branch or all-branch figures.
-- Agents deliberately do NOT get promises.update: recording a promise during a
-- visit is theirs, but deciding it was kept or broken is a branch decision.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 3, `id` FROM `permissions` WHERE `code` IN (
    'dashboard.view','customers.view','customers.view_pii',
    'visits.view','visits.create','promises.view','notifications.view'
  );

-- Auditor -> read only
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 4, `id` FROM `permissions` WHERE `code` IN (
    'dashboard.view','dashboard.all_branches','customers.view','visits.view',
    'promises.view','reports.view','reports.export','logs.audit','logs.activity','branches.view','users.view'
  );

-- ============================================================================
-- SEED: SETTINGS
-- ============================================================================

INSERT INTO `settings` (`setting_key`, `setting_value`, `group_name`, `label`, `input_type`, `is_secret`, `is_required`, `hint`, `sort_order`) VALUES
  ('app_name',           'LRMS',            'general', 'Application name',        'text',     0, 0, 'Shown in the header and on exports', 1),
  ('bank_name',          '',                'general', 'Bank / institution name', 'text',     0, 1, 'Printed on report headers',          2),
  ('app_version',        '1.0.0',           'general', 'Android app version',     'text',     0, 1, 'Latest published APK version',       3),
  ('app_min_version',    '1.0.0',           'general', 'Minimum supported app version', 'text', 0, 0, 'Older apps are asked to update',  4),
  ('records_per_page',   '25',              'general', 'Records per page',        'number',   0, 0, 'Default pagination size',            5),
  ('timezone',           'Asia/Kolkata',    'general', 'Timezone',                'text',     0, 0, 'PHP timezone identifier',            6),

  ('smtp_host',          '',                'smtp',    'SMTP host',               'text',     0, 0, '', 1),
  ('smtp_port',          '587',             'smtp',    'SMTP port',               'number',   0, 0, '', 2),
  ('smtp_username',      '',                'smtp',    'SMTP username',           'text',     0, 0, '', 3),
  ('smtp_password',      '',                'smtp',    'SMTP password',           'password', 1, 0, '', 4),
  ('smtp_encryption',    'tls',             'smtp',    'SMTP encryption',         'select',   0, 0, '', 5),
  ('smtp_from_email',    '',                'smtp',    'From email',              'text',     0, 0, '', 6),
  ('smtp_from_name',     'LRMS',            'smtp',    'From name',               'text',     0, 0, '', 7),

  ('sms_provider',       '',                'sms',     'SMS provider',            'text',     0, 1, 'e.g. msg91, textlocal, custom', 1),
  ('sms_api_url',        '',                'sms',     'SMS API URL',             'text',     0, 1, 'Placeholders: {mobile} {message}', 2),
  ('sms_api_key',        '',                'sms',     'SMS API key',             'password', 1, 1, '', 3),
  ('sms_sender_id',      '',                'sms',     'SMS sender ID',           'text',     0, 0, '', 4),
  ('sms_otp_template',   'Your LRMS OTP is {otp}. Valid for 10 minutes.', 'sms', 'OTP template', 'textarea', 0, 0, 'Use {otp}', 5),

  ('google_maps_key',    '',                'integrations', 'Google Maps API key', 'password', 1, 0, 'Static map previews only - no tracking', 1),
  ('firebase_server_key','',                'integrations', 'Firebase server key', 'password', 1, 0, 'FCM push notifications', 2),
  ('firebase_project_id','',                'integrations', 'Firebase project ID', 'text',     0, 0, '', 3),

  ('otp_expiry_minutes', '10',              'security', 'OTP expiry (minutes)',    'number', 0, 0, '', 1),
  ('max_login_attempts', '5',               'security', 'Max failed login attempts','number', 0, 0, 'Then the account locks temporarily', 2),
  ('lockout_minutes',    '15',              'security', 'Lockout duration (minutes)','number',0, 0, '', 3),
  ('jwt_ttl_minutes',    '120',             'security', 'JWT access token TTL (minutes)','number',0, 0, '', 4),
  ('refresh_ttl_days',   '30',              'security', 'Refresh token TTL (days)','number',  0, 0, 'Drives "remember login"', 5),
  ('password_min_length','8',               'security', 'Minimum password length', 'number',  0, 0, '', 6),

  ('promise_reminder_days','1',             'notifications', 'Promise reminder lead time (days)', 'number', 0, 0, 'Notify this many days before the promise date', 1),
  ('followup_reminder_days','7',            'notifications', 'Follow-up reminder after (days)',   'number', 0, 0, 'Nudge if a lead has had no visit for N days', 2),

  ('backup_retention_days','14',            'backup',  'Backup retention (days)', 'number', 0, 0, 'Older .sql files are pruned', 1),
  ('mysqldump_path',     'mysqldump',       'backup',  'mysqldump binary path',   'text',   0, 0, 'Falls back to a pure-PHP dump if unavailable', 2);

UPDATE `settings` SET `options` = 'tls,ssl,none' WHERE `setting_key` = 'smtp_encryption';

-- ============================================================================
-- SEED: bootstrap super admin + demo branch
--   Employee code : ADMIN001
--   Password      : Admin@123   (must_change_password = 1 forces a reset)
--   Regenerate with: php -r "echo password_hash('YourPass', PASSWORD_BCRYPT);"
-- ============================================================================

INSERT INTO `branches` (`id`, `branch_code`, `name`, `district`, `state`, `pincode`, `status`) VALUES
  (1, 'HO001', 'Head Office', 'Central', 'Maharashtra', '400001', 'active');

INSERT INTO `users`
  (`id`, `employee_code`, `name`, `email`, `password_hash`, `role_id`, `branch_id`, `status`, `must_change_password`)
VALUES
  (1, 'ADMIN001', 'System Administrator', 'admin@example.com',
   '$2y$12$2q28FzDqMSbQH/rK66GwWOB7QhCplC4jBmkYwcQfEy6OR7R3sXB.G',
   1, NULL, 'active', 1);


-- ============================================================================
-- Restore foreign key enforcement for the remainder of this session.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;
