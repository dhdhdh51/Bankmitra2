-- ============================================================================
-- D2 Recovery Solutions & Services - Loan Recovery Management System
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
  -- Where the branch sits in the bank's own hierarchy. Held here, once, rather than
  -- asked of the agent: the printed visit report has a Regional Office and a Zone at
  -- the top of every page, and an agent retyping them at forty doorsteps produces
  -- forty spellings of the same office.
  `regional_office` VARCHAR(150) DEFAULT NULL,
  `zone`            VARCHAR(150) DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`branch_code`),
  KEY `idx_branches_status` (`status`),
  KEY `idx_branches_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. USERS  (super admins + branch managers + BC agents)
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
  `bc_code`              VARCHAR(40)  DEFAULT NULL COMMENT 'BC code for agents',
  `designation`          VARCHAR(100) DEFAULT NULL,

  -- BC Basic Details. Asked for on every BC agent account so the panel and the
  -- printed field visit report can carry the bank's own reporting hierarchy
  -- (SP/CBC, SSA, link branch, region) and the agent's own registration numbers,
  -- rather than only a name and an internal employee code. All are optional here
  -- because a super admin or branch manager account has none of them - they exist
  -- for the agent role only, enforced by the form, not by the column.
  `sp_cbc_name`          VARCHAR(150) DEFAULT NULL COMMENT 'SP / CBC the BC reports to',
  `bc_name`              VARCHAR(150) DEFAULT NULL COMMENT 'the BC point''s registered name; may differ from the login name',
  `bcbf_code`            VARCHAR(40)  DEFAULT NULL COMMENT 'BC/BF code issued by the bank, distinct from bc_code (the internal field-report code)',
  `ssa`                  VARCHAR(150) DEFAULT NULL COMMENT 'Sub Service Area covered',
  `link_branch`          VARCHAR(150) DEFAULT NULL COMMENT 'the branch this BC is linked to for banking transactions',
  `district`             VARCHAR(100) DEFAULT NULL,
  `region_ro`            VARCHAR(100) DEFAULT NULL COMMENT 'Region / Regional Office',
  `iibf_number`          VARCHAR(40)  DEFAULT NULL COMMENT 'IIBF certification number',
  `dra_name_id`          VARCHAR(150) DEFAULT NULL COMMENT 'the Direct Recovery Agent''s own name/ID, when the BC works through one',

  -- Aadhaar and PAN follow the same encrypted-at-rest pattern already used for
  -- customers.aadhaar and visit_reports.pan_number: the plaintext is never
  -- stored, only an AES-256-GCM ciphertext, a keyed HMAC for exact-match lookup,
  -- and a masked value for display. mobile_enc/mobile_hash/mobile_masked above
  -- already cover the Mobile No. field.
  `aadhaar_enc`          VARBINARY(255) DEFAULT NULL,
  `aadhaar_hash`         CHAR(64)     DEFAULT NULL,
  `aadhaar_masked`       VARCHAR(20)  DEFAULT NULL,
  `pan_enc`              VARBINARY(255) DEFAULT NULL,
  `pan_hash`             CHAR(64)     DEFAULT NULL,
  `pan_masked`           VARCHAR(20)  DEFAULT NULL,

  `status`               ENUM('active','suspended','inactive') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'force change on first login',
  `last_login_at`        DATETIME     DEFAULT NULL,
  `last_login_ip`        VARCHAR(45)  DEFAULT NULL,
  `failed_attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`         DATETIME     DEFAULT NULL,

  -- Performance standing, maintained by cron/bc-warning-check.php. Denormalised
  -- onto the user so a BC list or dashboard can show the badge without joining
  -- and aggregating bc_warnings for every row.
  `dashboard_status`     ENUM('normal','warning_1','warning_2','final_warning') NOT NULL DEFAULT 'normal',
  `escalation_flag`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'final warning unimproved past its window',
  `status_changed_at`    DATETIME     DEFAULT NULL,
  -- No portrait or signature is held against a person here, deliberately.
  --
  -- There were two, printed at the foot of every report the person filed. They were
  -- removed because a stored image says nothing about the visit it gets printed on: a
  -- portrait uploaded once in a branch office, captioned nothing, sitting on a
  -- document that geo-captions every other photograph, reads as field evidence. A
  -- photograph now belongs to the thing it evidences - the visit it was taken at
  -- (photos.photo_type = 'agent', with its own fix).
  --
  -- Signatures are not stored anywhere at all any more, against a person or against a
  -- report. A fingertip scrawl on a phone is not a signature anybody would accept
  -- across a counter, so the printed report carries empty ruled boxes instead and the
  -- paper is signed by hand.
  `created_by`           INT UNSIGNED DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_employee_code` (`employee_code`),
  -- Email is a login identifier, so it has to be unique or a sign-in could
  -- resolve to the wrong person. MySQL allows any number of NULLs under a UNIQUE
  -- key, so users without an email are unaffected.
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_mobile_hash` (`mobile_hash`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_branch_status` (`branch_id`, `status`),
  KEY `idx_users_bc_code` (`bc_code`),
  KEY `idx_users_bcbf_code` (`bcbf_code`),
  KEY `idx_users_aadhaar_hash` (`aadhaar_hash`),
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

  -- A second number, and whose it is.
  --
  -- The borrower's own phone is dead more often than not, and the number that actually
  -- reaches them belongs to a son, a brother or the shop at the crossroads. Without a
  -- place to put it, an agent standing at the door either overwrites the number on record
  -- - destroying the one the bank was given at sanction - or writes it in a remarks field
  -- where nothing can dial it.
  --
  -- The label is not decoration: a bare second number tells the next agent nothing about
  -- who will pick up, and "who am I speaking to" is the whole of a recovery call's first
  -- ten seconds. Encrypted and hashed like the primary, so it is searchable without being
  -- readable in the database.
  --
  -- No importer writes these columns, by construction rather than by an override flag:
  -- the bank's export has no such field, so what an agent collects at a doorstep cannot
  -- be flattened by tomorrow's file.
  `alt_mobile_enc`      VARBINARY(255) DEFAULT NULL,
  `alt_mobile_hash`     CHAR(64)     DEFAULT NULL,
  `alt_mobile_masked`   VARCHAR(20)  DEFAULT NULL,
  `alt_mobile_label`    VARCHAR(60)  DEFAULT NULL COMMENT 'whose number it is - son, brother, shop',

  `village`             VARCHAR(150) DEFAULT NULL,
  `address`             VARCHAR(500) DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customers_branch` (`branch_id`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_village` (`village`),
  KEY `idx_customers_mobile_hash` (`mobile_hash`),
  KEY `idx_customers_alt_mobile_hash` (`alt_mobile_hash`),
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
  -- Kept because the Excel import maps rows by it and the visit report snapshots
  -- it. It is no longer shown in the loan details panel, where the figure people
  -- actually need is what it costs to close the account.
  `bc_code`              VARCHAR(40)  DEFAULT NULL,
  -- What the borrower must pay to close this account outright. Distinct from
  -- ots_amount, which is a settlement the branch is willing to accept: a closure
  -- figure is the full number, and conflating the two would have an agent quoting a
  -- discount nobody approved.
  `closure_amount`       DECIMAL(15,2) DEFAULT NULL COMMENT 'full amount to close the account',
  -- Which columns a human has edited by hand.
  --
  -- Loan figures arrive from the core banking export, and the next import would
  -- silently overwrite a correction somebody made in the panel - which is why they
  -- were read-only. Recording the override lets the importer leave those columns
  -- alone and report that it did, so an edit survives without the import quietly
  -- becoming untrustworthy. Shape: {"column": {"by": userId, "at": "Y-m-d H:i:s"}}.
  `manual_overrides`     JSON         DEFAULT NULL COMMENT 'hand-edited columns the import must not clobber',
  `loan_type`            VARCHAR(80)  DEFAULT NULL,
  -- Which renewable facility this account is, so KCC and OD-2 can be worked as the two
  -- separate queues they actually are.
  --
  -- They were one queue. `ckcc_renewal_due_date` covers both and the renewal report
  -- covered both, so a branch with three hundred KCC renewals and forty OD-2 renewals got
  -- one undifferentiated list - which is not how the work is divided, done or reviewed.
  --
  -- Derived from `loan_type` on import rather than needing a new column in the sheet: the
  -- bank's own product / scheme / facility heading already lands there, and it already
  -- says "KCC" or "OD-2". Correctable by hand afterwards like any other figure, because a
  -- product name is not always a facility name.
  `facility_type`        ENUM('kcc','od2','other') DEFAULT NULL COMMENT 'NULL = the file did not say',
  `outstanding_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `overdue_amount`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `npa_date`             DATE         DEFAULT NULL,
  `is_npa`              TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'derived: npa_date IS NOT NULL',

  -- ---- CKCC / cash-credit attributes -------------------------------------
  -- Imported from the bank's file. A CKCC OD account has to be renewed before
  -- its due date or it slips into NPA, so these drive the renewal worklist and
  -- pre-fill the agent's renewal report instead of making them copy figures off
  -- a passbook in the field.
  `cif_number`             VARCHAR(40)   DEFAULT NULL,
  `sanction_date`          DATE          DEFAULT NULL,
  `sanction_limit`         DECIMAL(15,2) DEFAULT NULL,
  `drawing_power`          DECIMAL(15,2) DEFAULT NULL,
  `interest_overdue`       DECIMAL(15,2) DEFAULT NULL,
  `ckcc_renewal_due_date`  DATE          DEFAULT NULL COMMENT 'renewal deadline; NPA follows if missed',
  `current_status`       ENUM('pending','visited','promise','followup','legal','closed') NOT NULL DEFAULT 'pending',
  `assigned_agent_id`    INT UNSIGNED DEFAULT NULL,
  `assigned_at`          DATETIME     DEFAULT NULL,
  `assigned_by`          INT UNSIGNED DEFAULT NULL,
  `last_visit_at`        DATETIME     DEFAULT NULL,
  `visit_count`          INT UNSIGNED NOT NULL DEFAULT 0,
  `last_promise_id`      BIGINT UNSIGNED DEFAULT NULL,
  `next_followup_date`   DATE         DEFAULT NULL,
  -- ---- Settlement position as the BRANCH stated it -------------------------
  -- Filled from the imported file, not by an agent. The branch decides which
  -- accounts it will settle and at what figure; the agent needs to know that
  -- before knocking on the door, and the OTS section of a visit report is
  -- pre-filled from it.
  --
  -- Nullable on purpose: NULL means "the file did not say", which is a different
  -- thing from an explicit No, and only one of those should stop an agent
  -- offering a settlement.
  `ots_eligible`         TINYINT(1)    DEFAULT NULL COMMENT 'bank flag from the import; NULL = not stated',
  `krm_eligible`         TINYINT(1)    DEFAULT NULL COMMENT 'eligible under the KRM scheme specifically',
  `ots_amount`           DECIMAL(15,2) DEFAULT NULL COMMENT 'settlement figure proposed by the branch',
  `deposit_amount`       DECIMAL(15,2) DEFAULT NULL COMMENT 'initial deposit expected alongside the OTS',

  -- ---- The rest of what a core banking NPA / recovery statement carries --------
  --
  -- These exist so a real export lands whole instead of being trimmed to the handful
  -- of columns the panel happened to have. An agent standing at a door is asked "how
  -- much, since when, what did I last pay, what happens if I don't" - and every one of
  -- those answers was previously sitting in a spreadsheet column the importer dropped.
  --
  -- All nullable: NULL means the file did not carry it, which is a different fact from
  -- a zero. An overdue amount of 0.00 says the account is current; a NULL says nobody
  -- told us.
  --
  -- asset_classification is text, not an ENUM, on purpose. Banks write this column
  -- twenty different ways - "SS", "D-1", "DOUBTFUL 2", "Sub Standard", "LOSS ASSET" -
  -- and an ENUM would force every unrecognised spelling to NULL, silently discarding
  -- the single most important prioritisation field in an NPA statement. The importer
  -- normalises the spellings it recognises to a canonical label and otherwise stores
  -- what the file said, so nothing is ever lost.
  `asset_classification` VARCHAR(40)   DEFAULT NULL COMMENT 'Standard / SMA-1 / Doubtful 2 / Loss ...',
  `interest_rate`        DECIMAL(6,3)  DEFAULT NULL COMMENT 'percent per annum',
  `installment_amount`   DECIMAL(15,2) DEFAULT NULL COMMENT 'EMI / instalment due per period',
  `last_payment_date`    DATE          DEFAULT NULL,
  `last_payment_amount`  DECIMAL(15,2) DEFAULT NULL,
  -- Days past due as the BANK computed it. Not derived here: a bank's DPD follows its
  -- own product rules, and recomputing it from npa_date would quietly disagree with
  -- the statement the branch is working from.
  `days_past_due`        SMALLINT UNSIGNED DEFAULT NULL,
  `security_value`       DECIMAL(15,2) DEFAULT NULL COMMENT 'value of collateral held',
  `guarantor_name`       VARCHAR(150)  DEFAULT NULL,
  `maturity_date`        DATE          DEFAULT NULL,
  `purpose`              VARCHAR(150)  DEFAULT NULL COMMENT 'crop / activity the loan was for',

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
  -- Recovery work is prioritised by classification within a branch, so that ordering
  -- gets an index rather than a filesort over every lead the branch holds.
  KEY `idx_loan_classification` (`branch_id`, `asset_classification`),
  -- The two renewal worklists are exactly this: one facility, one branch, ordered by the
  -- date the renewal is due.
  KEY `idx_loan_facility_renewal` (`branch_id`, `facility_type`, `ckcc_renewal_due_date`),
  KEY `idx_loan_followup` (`next_followup_date`),
  KEY `idx_loan_ckcc_renewal` (`ckcc_renewal_due_date`),
  -- Drives the "which accounts can we settle" worklist.
  KEY `idx_loan_ots_eligible` (`ots_eligible`),
  KEY `idx_loan_cif` (`cif_number`),
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

  -- ---- 1. General information ---------------------------------------------
  `visit_date`           DATE         NOT NULL,
  `visit_time`           TIME         NOT NULL,
  `bc_code`              VARCHAR(40)  DEFAULT NULL COMMENT 'BC Code / DRA ID',
  `agent_name`           VARCHAR(150) NOT NULL COMMENT 'snapshot',
  `branch_name`          VARCHAR(150) DEFAULT NULL COMMENT 'snapshot',
  `village`              VARCHAR(150) DEFAULT NULL COMMENT 'where the visit happened',

  -- Where in the bank's own hierarchy this visit sits. Snapshotted as text rather
  -- than joined from `branches`, for the same reason every other figure on this row
  -- is: a report printed two years from now has to read the way it read on the day,
  -- even after the branch has been moved to a different region.
  `branch_code`          VARCHAR(40)  DEFAULT NULL,
  `regional_office`      VARCHAR(150) DEFAULT NULL,
  `zone`                 VARCHAR(150) DEFAULT NULL,
  `linked_branch`        VARCHAR(150) DEFAULT NULL COMMENT 'the branch the BC/DRA is attached to',
  `district`             VARCHAR(150) DEFAULT NULL COMMENT 'district the visit happened in',

  -- Which kind of case this is - the printed form calls it "Case Type". The
  -- sections common to every type live in this table; the extra ones live in
  -- visit_ots_details / visit_ckcc_details so that this row does not grow another
  -- fifty mostly-null columns.
  --
  -- 'pre_npa' and 'post_npa' are not settlement or renewal work: they are the same
  -- doorstep verification done before an account slips and after it has, and they
  -- were being filed as plain recovery calls because there was nothing else to pick.
  `report_type`          ENUM('recovery','ots','ckcc_renewal','pre_npa','post_npa','other')
                           NOT NULL DEFAULT 'recovery',
  `report_type_other_text` VARCHAR(150) DEFAULT NULL COMMENT 'when Case Type is Other',

  `sp_cbc_name`          VARCHAR(150) DEFAULT NULL COMMENT 'SP / CBC the BC agent reports to',

  -- ---- 2. Borrower information (snapshot) --------------------------------
  `customer_name`        VARCHAR(150) NOT NULL,
  `father_husband_name`  VARCHAR(150) DEFAULT NULL,
  `gender`               ENUM('male','female','other') DEFAULT NULL,
  `date_of_birth`        DATE         DEFAULT NULL,
  `address`              VARCHAR(500) DEFAULT NULL COMMENT 'complete residential address',
  `mobile_enc`           VARBINARY(255) DEFAULT NULL,
  `mobile_hash`          CHAR(64)     DEFAULT NULL,
  `mobile_masked`        VARCHAR(20)  DEFAULT NULL,
  -- The second number the agent got at the door. Snapshotted like the first one, so
  -- a report still shows the number that was current when somebody rang it.
  `alt_mobile_enc`       VARBINARY(255) DEFAULT NULL,
  `alt_mobile_hash`      CHAR(64)     DEFAULT NULL,
  `alt_mobile_masked`    VARCHAR(20)  DEFAULT NULL,
  `aadhaar_enc`          VARBINARY(255) DEFAULT NULL,
  `aadhaar_hash`         CHAR(64)     DEFAULT NULL,
  `aadhaar_masked`       VARCHAR(20)  DEFAULT NULL,
  -- Encrypted and masked like the other two, not stored in the clear. A PAN is a
  -- national tax identifier: it is exactly as good a key for joining a person's
  -- records together as an Aadhaar number, and the form marks it optional.
  `pan_enc`              VARBINARY(255) DEFAULT NULL,
  `pan_hash`             CHAR(64)     DEFAULT NULL,
  `pan_masked`           VARCHAR(20)  DEFAULT NULL,

  -- The borrower's registered address, broken up the way the form asks for it.
  -- Deliberately separate from `village` and `district` above: those say where the
  -- agent stood, and for a borrower who has shifted the two are different facts -
  -- which is the whole point of asking both.
  `addr_village`         VARCHAR(150) DEFAULT NULL,
  `gram_panchayat`       VARCHAR(150) DEFAULT NULL,
  `tehsil`               VARCHAR(150) DEFAULT NULL,
  `addr_district`        VARCHAR(150) DEFAULT NULL,
  `state`                VARCHAR(100) DEFAULT NULL,
  `pin_code`             VARCHAR(10)  DEFAULT NULL,

  -- ---- 3. Loan account details (snapshot) --------------------------------
  `loan_account_number`  VARCHAR(60)  NOT NULL,
  `cif_number`           VARCHAR(40)  DEFAULT NULL,
  `loan_type`            VARCHAR(80)  DEFAULT NULL,
  `loan_type_other_text` VARCHAR(150) DEFAULT NULL,
  `sanction_date`        DATE          DEFAULT NULL,
  `sanction_limit`       DECIMAL(15,2) DEFAULT NULL,
  `drawing_power`        DECIMAL(15,2) DEFAULT NULL,
  `outstanding_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `interest_overdue`     DECIMAL(15,2) DEFAULT NULL,
  `overdue_amount`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `npa_date`             DATE         DEFAULT NULL,
  -- An ENUM here, unlike loan_accounts.asset_classification which is free text on
  -- purpose. That column holds whatever the bank's export writes; this one is a row
  -- of five tick boxes on a printed form, and a form offers a closed list.
  `asset_classification` ENUM('standard','sma_0','sma_1','sma_2','npa') DEFAULT NULL,
  `current_status`       VARCHAR(40)  DEFAULT NULL,

  -- ---- 6. Physical verification: who was met ------------------------------
  `customer_met`               TINYINT(1) NOT NULL DEFAULT 0,
  `family_member_met`          TINYINT(1) NOT NULL DEFAULT 0,
  `house_locked`               TINYINT(1) NOT NULL DEFAULT 0,
  `phone_contact`              TINYINT(1) NOT NULL DEFAULT 0,
  `phone_switched_off`         TINYINT(1) NOT NULL DEFAULT 0,
  `family_member_name`         VARCHAR(150) DEFAULT NULL,
  `family_member_relationship` VARCHAR(80)  DEFAULT NULL,

  -- ---- Where the visit happened --------------------------------------------
  -- Captured on the device at the moment the agent's photo is taken, not when the
  -- form is submitted: submission can happen hours later from a different place,
  -- and a coordinate recorded then would be a lie told precisely.
  --
  -- Nullable because a visit recorded before this module existed has no fix, and
  -- because a genuine GPS failure inside a building must not cost the agent the
  -- whole report. gps_source records which of those it was.
  `gps_latitude`      DECIMAL(10,7) DEFAULT NULL,
  `gps_longitude`     DECIMAL(10,7) DEFAULT NULL,
  `gps_accuracy_m`    SMALLINT UNSIGNED DEFAULT NULL,
  `gps_captured_at`   DATETIME      DEFAULT NULL,
  `gps_address`       VARCHAR(400)  DEFAULT NULL COMMENT 'reverse-geocoded, best effort',
  `gps_source`        ENUM('device','unavailable','denied') DEFAULT NULL,

  -- ---- 6. Physical verification: what was seen ----------------------------
  `borrower_alive` TINYINT(1) NOT NULL DEFAULT 1,
  `same_address`   TINYINT(1) NOT NULL DEFAULT 1,
  `shifted`        TINYINT(1) NOT NULL DEFAULT 0,

  -- Two separate questions, and both nullable, because "not confirmed" and "nobody
  -- asked" are different answers. A report that recorded silence as a negative would
  -- accuse an agent of failing a check they were never asked to run.
  `residence_verified`     ENUM('confirmed','not_confirmed')   DEFAULT NULL,
  `neighbour_verification` ENUM('conducted','not_conducted')   DEFAULT NULL,

  -- 'service' rather than 'job', which is what the printed form says and what the
  -- word means in this context - salaried employment, as against labour.
  `occupation`     ENUM('agriculture','dairy','business','labour','service','others') DEFAULT NULL,
  `occupation_other_text` VARCHAR(150) DEFAULT NULL,

  -- ---- 7. Documents verified ----------------------------------------------
  -- What the borrower physically had with them at the door. Distinct from the
  -- `documents` and `photos` tables, which hold files somebody uploaded: an agent can
  -- confirm a passbook exists without photographing it, and the branch still needs to
  -- know that it does.
  --
  -- Asked on EVERY report type, not just a renewal. This list used to live on
  -- visit_ckcc_details, where a recovery visit had nowhere to record that the
  -- borrower produced an Aadhaar card.
  `doc_aadhaar`            TINYINT(1) NOT NULL DEFAULT 0,
  `doc_pan`                TINYINT(1) NOT NULL DEFAULT 0,
  `doc_passbook`           TINYINT(1) NOT NULL DEFAULT 0,
  `doc_land_record`        TINYINT(1) NOT NULL DEFAULT 0,
  `doc_khatauni`           TINYINT(1) NOT NULL DEFAULT 0,
  `doc_electricity_bill`   TINYINT(1) NOT NULL DEFAULT 0,
  `doc_photograph`         TINYINT(1) NOT NULL DEFAULT 0,
  `doc_mobile_verified`    TINYINT(1) NOT NULL DEFAULT 0,
  `doc_renewal_form`       TINYINT(1) NOT NULL DEFAULT 0,
  `doc_ots_consent_letter` TINYINT(1) NOT NULL DEFAULT 0,
  `doc_others`             TINYINT(1) NOT NULL DEFAULT 0,
  `doc_other_text`         VARCHAR(255) DEFAULT NULL,

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

  -- ---- 9. Recommendation --------------------------------------------------
  -- The recovery recommendations. The OTS-specific and renewal-specific ones live on
  -- their own detail rows, because they are only answerable on that kind of case.
  `rec_recovery_possible`  TINYINT(1) NOT NULL DEFAULT 0,
  `rec_regular_followup`   TINYINT(1) NOT NULL DEFAULT 0,
  `rec_legal_action`       TINYINT(1) NOT NULL DEFAULT 0,
  `rec_rc`                 TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Revenue Certificate',
  `rec_ots`                TINYINT(1) NOT NULL DEFAULT 0,
  `rec_others`             TINYINT(1) NOT NULL DEFAULT 0,
  `rec_other_text`         VARCHAR(255) DEFAULT NULL,
  -- Free prose, separate from the tick boxes above and from `remarks` below: the form
  -- asks for observations (what was seen) and a recommendation (what should be done)
  -- as two different questions, and collapsing them loses which one an answer was.
  `general_recommendation` TEXT         DEFAULT NULL,

  -- ---- 10. Evidence attached ----------------------------------------------
  -- What the agent says is attached, recorded separately from what actually arrived
  -- in `photos` / `documents`. The gap between the two is the point: a report that
  -- claims a passbook copy and carries none is a thing a reviewer needs to see.
  `ev_borrower_photo`  TINYINT(1) NOT NULL DEFAULT 0,
  `ev_house_photo`     TINYINT(1) NOT NULL DEFAULT 0,
  `ev_land_photo`      TINYINT(1) NOT NULL DEFAULT 0,
  `ev_aadhaar_copy`    TINYINT(1) NOT NULL DEFAULT 0,
  `ev_passbook_copy`   TINYINT(1) NOT NULL DEFAULT 0,
  `ev_gps_location`    TINYINT(1) NOT NULL DEFAULT 0,
  `ev_renewal_form`    TINYINT(1) NOT NULL DEFAULT 0,
  `ev_ots_consent`     TINYINT(1) NOT NULL DEFAULT 0,
  `ev_others`          TINYINT(1) NOT NULL DEFAULT 0,
  `ev_other_text`      VARCHAR(255) DEFAULT NULL,

  -- ---- 8. BC agent / DRA observations -------------------------------------
  `remarks`      TEXT         DEFAULT NULL COMMENT 'BC agent / DRA observations',

  -- ---- 11. Declaration ----------------------------------------------------
  -- The agent ticking the RBI / Fair Practices Code declaration before submitting.
  -- Stored rather than assumed: the declaration is printed on every copy, and a
  -- printed certification nobody agreed to is worth nothing.
  `declaration_accepted` TINYINT(1) NOT NULL DEFAULT 0,

  -- ---- 12. Certification --------------------------------------------------
  -- The agent's own contact number, not the borrower's. Stored in the clear on
  -- purpose: it is a staff contact printed on the form so the branch can ring back
  -- the person who filed the report, and encrypting it would only make the printed
  -- copy useless while protecting nothing the employee record does not already hold.
  `agent_mobile`           VARCHAR(20)  DEFAULT NULL,
  `supervisor_name`        VARCHAR(150) DEFAULT NULL,
  `supervisor_designation` VARCHAR(100) DEFAULT NULL,
  `supervisor_employee_id` VARCHAR(40)  DEFAULT NULL COMMENT 'Employee ID / DRA ID',
  `supervisor_verified_at` DATE         DEFAULT NULL,

  -- ---- Meta ---------------------------------------------------------------
  `source`       ENUM('android','web') NOT NULL DEFAULT 'android',
  `app_version`  VARCHAR(30)  DEFAULT NULL,
  `device_info`  VARCHAR(255) DEFAULT NULL,
  -- ---- Approval -----------------------------------------------------------
  -- An agent files the report; somebody senior signs it off. The approver's own
  -- position and photograph are recorded, not just their user id, because "I approved
  -- it from the branch" and "I approved forty of them from home at midnight" are
  -- different claims and only one of them is verification. Their signature goes on
  -- the printed page by hand: the printout carries a blank box for it.
  --
  -- This does NOT make the report editable in place. The submitted values stay in the
  -- row and every subsequent change is written to visit_report_revisions with its
  -- before and after, so the original is always reconstructible. A field visit report
  -- is evidence; making it silently overwritable would destroy the only thing that
  -- makes it worth having.
  `approval_status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by`            INT UNSIGNED DEFAULT NULL,
  `approver_name`          VARCHAR(150) DEFAULT NULL COMMENT 'snapshot, survives a renamed or deleted user',
  `approved_at`            DATETIME     DEFAULT NULL,
  `approval_remarks`       VARCHAR(1000) DEFAULT NULL,
  `approval_photo_path`    VARCHAR(500) DEFAULT NULL COMMENT 'the approver, at the moment of approval',
  `approval_gps_latitude`  DECIMAL(10,7) DEFAULT NULL,
  `approval_gps_longitude` DECIMAL(10,7) DEFAULT NULL,
  `approval_gps_accuracy_m` SMALLINT UNSIGNED DEFAULT NULL,
  `approval_gps_source`    ENUM('device','unavailable','denied') NOT NULL DEFAULT 'unavailable',
  `revision_count`         SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'entries in visit_report_revisions',
  `updated_at`             DATETIME     DEFAULT NULL COMMENT 'set only by an approval or a revision',

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
  KEY `idx_visit_approval` (`approval_status`, `visit_date`),
  CONSTRAINT `fk_visit_approver` FOREIGN KEY (`approved_by`)     REFERENCES `users` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_visit_loan`     FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visit_customer` FOREIGN KEY (`customer_id`)     REFERENCES `customers` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_visit_agent`    FOREIGN KEY (`agent_id`)        REFERENCES `users` (`id`),
  CONSTRAINT `fk_visit_branch`   FOREIGN KEY (`branch_id`)       REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. PROMISES
-- ============================================================================

-- ============================================================================
-- KRM / OTS settlement details  (report_type = 'ots')
--
-- One row per visit report, created only when the agent filled the OTS section.
-- Append-only, like its parent.
--
-- NO MONEY PASSES THROUGH THE AGENT OR THIS SYSTEM.
--   `deposit_*` records that the BORROWER paid the bank directly, evidenced by
--   the bank's own receipt or transaction id which the agent copies down. The
--   agent never collects cash and this app never processes a payment. The fields
--   exist so a branch can see, from the field report, whether the 10% condition
--   of an OTS offer has actually been met.
-- ============================================================================

DROP TABLE IF EXISTS `visit_ots_details`;
CREATE TABLE `visit_ots_details` (
  `visit_report_id`  BIGINT UNSIGNED NOT NULL,
  `loan_account_id`  BIGINT UNSIGNED NOT NULL,

  -- ---- Eligibility ---------------------------------------------------------
  `eligible_for_ots` TINYINT(1) NOT NULL DEFAULT 0,
  `scheme`           ENUM('krm_ots','general_ots','other') DEFAULT NULL,
  `scheme_other_text` VARCHAR(150) DEFAULT NULL,

  -- ---- Settlement arithmetic ----------------------------------------------
  -- Every figure the agent wrote down is stored as entered. The app suggests
  -- values (payable = rlb_amount x payable_percent) but never overwrites what
  -- the agent typed: the branch's sanction letter is the authority, not our
  -- arithmetic, and a silent recalculation would misstate a settlement.
  -- Snapshotted from the loan account, not entered by the agent: the NPA date is
  -- the bank's own classification date and an agent retyping it in the field is
  -- just a chance to get it wrong. It sits on the settlement record because an
  -- OTS offer is read and approved against it.
  `npa_date`                  DATE          DEFAULT NULL,
  `borrower_name`             VARCHAR(150)  DEFAULT NULL COMMENT 'snapshot, so the offer reads standalone',
  `outstanding_amount`        DECIMAL(15,2) DEFAULT NULL COMMENT 'snapshot at visit time',
  `relief_waiver_percent`     DECIMAL(5,2)  DEFAULT NULL,
  `rlb_amount`                DECIMAL(15,2) DEFAULT NULL COMMENT 'Residual Loan Balance the payable % applies to',
  `payable_percent`           DECIMAL(5,2)  DEFAULT 22.50 COMMENT 'scheme default; overridable per case',
  `borrower_payable_amount`   DECIMAL(15,2) DEFAULT NULL,
  `total_settlement_amount`   DECIMAL(15,2) DEFAULT NULL,

  -- ---- Initial deposit (paid by the borrower AT THE BANK) -------------------
  `initial_deposit_percent`   DECIMAL(5,2)  DEFAULT 10.00,
  `required_deposit_amount`   DECIMAL(15,2) DEFAULT NULL,
  `deposit_received`          TINYINT(1) NOT NULL DEFAULT 0,
  `deposit_amount`            DECIMAL(15,2) DEFAULT NULL,
  `deposit_date`              DATE          DEFAULT NULL,
  `deposit_reference`         VARCHAR(120)  DEFAULT NULL COMMENT "bank's receipt no. / transaction id",
  `balance_payable`           DECIMAL(15,2) DEFAULT NULL,
  `proposed_final_payment_date` DATE        DEFAULT NULL,

  -- ---- Approval and validity ----------------------------------------------
  `approval_status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `validity_from`          DATE DEFAULT NULL,
  `validity_to`            DATE DEFAULT NULL,
  `expected_closure_date`  DATE DEFAULT NULL,

  -- ---- Borrower's response -------------------------------------------------
  -- `borrower_accepted` is the yes/no the settlement turns on. `customer_response` is
  -- WHY, and the four ways of saying no are not interchangeable: a borrower who asked
  -- for time gets another visit, one who cannot pay gets a different scheme, and one
  -- who refused outright gets neither. Recording only the boolean threw that away.
  `borrower_accepted`      TINYINT(1) NOT NULL DEFAULT 0,
  `customer_response`      ENUM('agreed','requested_time','financial_difficulty',
                                'refused','not_eligible') DEFAULT NULL,
  `rejection_reason`       VARCHAR(500) DEFAULT NULL COMMENT 'why the borrower declined',
  -- When the borrower says they will pay the initial deposit. Distinct from
  -- `deposit_date`, which is when they actually did: the first is a promise and the
  -- second is evidence, and one column could not hold both.
  `expected_deposit_date`  DATE DEFAULT NULL,

  -- ---- Recommendation on the settlement ------------------------------------
  `rec_proposal_recommended` TINYINT(1) NOT NULL DEFAULT 0,
  `rec_followup_required`    TINYINT(1) NOT NULL DEFAULT 0,
  `rec_customer_refused`     TINYINT(1) NOT NULL DEFAULT 0,
  `rec_not_eligible`         TINYINT(1) NOT NULL DEFAULT 0,

  -- ---- Final report status -------------------------------------------------
  -- Where the settlement has actually got to, which `approval_status` does not say:
  -- an offer can be approved by the branch and still be waiting on the borrower's
  -- deposit, and those are the two different things a follow-up list is built from.
  `st_customer_contacted`       TINYINT(1) NOT NULL DEFAULT 0,
  `st_customer_verified`        TINYINT(1) NOT NULL DEFAULT 0,
  `st_ots_accepted`             TINYINT(1) NOT NULL DEFAULT 0,
  `st_ots_rejected`             TINYINT(1) NOT NULL DEFAULT 0,
  `st_initial_deposit_received` TINYINT(1) NOT NULL DEFAULT 0,
  `st_ots_closed`               TINYINT(1) NOT NULL DEFAULT 0,
  `st_followup_required`        TINYINT(1) NOT NULL DEFAULT 0,

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`visit_report_id`),
  KEY `idx_ots_loan` (`loan_account_id`),
  KEY `idx_ots_status` (`approval_status`),
  KEY `idx_ots_validity` (`validity_to`),
  CONSTRAINT `fk_ots_visit` FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ots_loan`  FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CKCC OD-2 renewal details  (report_type = 'ckcc_renewal')
--
-- One row per visit report. A CKCC cash-credit account must be renewed before
-- its due date; if it is not, it turns NPA. This report exists to catch that
-- window, so the account figures are snapshotted alongside the renewal
-- paperwork the agent collected.
--
-- Sections 5 (Customer Verification) and Occupation are NOT repeated here -
-- visit_reports already carries customer_met, family_member_met, house_locked,
-- phone_contact, borrower_alive, same_address, shifted and occupation, and
-- duplicating them would let one report disagree with itself.
--
-- Deliberately absent: any GPS or location field. This system captures no
-- location data of any kind.
-- ============================================================================

DROP TABLE IF EXISTS `visit_ckcc_details`;
CREATE TABLE `visit_ckcc_details` (
  `visit_report_id`  BIGINT UNSIGNED NOT NULL,
  `loan_account_id`  BIGINT UNSIGNED NOT NULL,

  -- ---- Account snapshot ----------------------------------------------------
  `cif_number`        VARCHAR(40)   DEFAULT NULL,
  `sanction_date`     DATE          DEFAULT NULL,
  `sanction_limit`    DECIMAL(15,2) DEFAULT NULL,
  `drawing_power`     DECIMAL(15,2) DEFAULT NULL,
  `outstanding_amount` DECIMAL(15,2) DEFAULT NULL,
  `interest_overdue`  DECIMAL(15,2) DEFAULT NULL,
  `renewal_due_date`  DATE          DEFAULT NULL,
  -- Computed by the server from renewal_due_date at submit time and stored, so
  -- the report reads the same in six months as it did on the day of the visit.
  `expected_npa_date` DATE          DEFAULT NULL COMMENT 'if the renewal is not completed',
  `days_remaining`    INT           DEFAULT NULL COMMENT 'negative once overdue',

  -- ---- Renewal eligibility -------------------------------------------------
  `eligible_for_renewal`     TINYINT(1) NOT NULL DEFAULT 0,
  `renewal_due_bucket`       ENUM('within_30','within_15','within_7','overdue') DEFAULT NULL,
  `kyc_status`               ENUM('complete','pending') DEFAULT NULL,
  `aadhaar_seeded`           TINYINT(1) NOT NULL DEFAULT 0,
  `mobile_linked`            TINYINT(1) NOT NULL DEFAULT 0,
  `aadhaar_auth_completed`   TINYINT(1) NOT NULL DEFAULT 0,

  -- ---- Documents the borrower actually had in hand -------------------------
  -- DELIBERATELY NOT HERE ANY MORE. The checklist moved to visit_reports, where the
  -- printed form has it: section 7, asked on every case type. Keeping a second copy
  -- on this row meant a renewal report carried the same eleven boxes twice and could
  -- disagree with itself, and a recovery visit had nowhere to record that the
  -- borrower produced an Aadhaar card at all.

  -- ---- Renewal consent -----------------------------------------------------
  `willing_to_renew`      TINYINT(1) NOT NULL DEFAULT 0,
  `documents_handed_over` TINYINT(1) NOT NULL DEFAULT 0,
  `renewal_form_signed`   TINYINT(1) NOT NULL DEFAULT 0,
  `ekyc_completed`        TINYINT(1) NOT NULL DEFAULT 0,
  `biometrics_completed`  TINYINT(1) NOT NULL DEFAULT 0,

  -- ---- Agent observation and recommendation --------------------------------
  `agent_observation`          TEXT DEFAULT NULL,
  `rec_renew_immediately`      TINYINT(1) NOT NULL DEFAULT 0,
  `rec_documents_submitted`    TINYINT(1) NOT NULL DEFAULT 0,
  -- The other half of "documents complete". A renewal waiting on one missing paper is
  -- a branch task; one with nothing outstanding is not, and an unticked "complete"
  -- box could not tell the two apart.
  `rec_pending_documents`      TINYINT(1) NOT NULL DEFAULT 0,
  `rec_followup_required`      TINYINT(1) NOT NULL DEFAULT 0,
  `rec_not_interested`         TINYINT(1) NOT NULL DEFAULT 0,
  `rec_branch_contact_urgent`  TINYINT(1) NOT NULL DEFAULT 0,
  `rec_others`                 TINYINT(1) NOT NULL DEFAULT 0,
  `rec_other_text`             VARCHAR(255) DEFAULT NULL,

  -- ---- Report status -------------------------------------------------------
  `st_customer_contacted`     TINYINT(1) NOT NULL DEFAULT 0,
  `st_customer_verified`      TINYINT(1) NOT NULL DEFAULT 0,
  `st_documents_collected`    TINYINT(1) NOT NULL DEFAULT 0,
  `st_application_submitted`  TINYINT(1) NOT NULL DEFAULT 0,
  `st_ckcc_renewed`           TINYINT(1) NOT NULL DEFAULT 0,
  `st_pending_at_branch`      TINYINT(1) NOT NULL DEFAULT 0,
  `st_followup_required`      TINYINT(1) NOT NULL DEFAULT 0,
  `st_became_npa`             TINYINT(1) NOT NULL DEFAULT 0,

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`visit_report_id`),
  KEY `idx_ckcc_loan` (`loan_account_id`),
  KEY `idx_ckcc_due` (`renewal_due_date`),
  KEY `idx_ckcc_bucket` (`renewal_due_bucket`),
  CONSTRAINT `fk_ckcc_visit` FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ckcc_loan`  FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
                      'status_changed','closed','reopened','note',
                      -- Extend rather than reuse. A value missing from this list throws
                      -- on insert, and Timeline::record() is called inside the same
                      -- transaction as the action it records.
                      'visit_approved','visit_rejected','visit_revised',
                      -- Typed in by hand in the panel, not read out of a bank export.
                      -- Deliberately not folded into 'lead_imported': "the core banking
                      -- system says this account exists" and "an agent typed it in" carry
                      -- very different weight when a figure is later disputed.
                      'lead_created'
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
-- 10. MEDIA: photos / documents
-- ============================================================================

DROP TABLE IF EXISTS `photos`;
CREATE TABLE `photos` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_report_id` BIGINT UNSIGNED DEFAULT NULL,
  `loan_account_id` BIGINT UNSIGNED NOT NULL,
  -- 'agent' is the agent's own photograph, taken at the door. It lives here rather
  -- than in a column on visit_reports so it inherits everything a photograph
  -- already has: its own fix, its own capture_source, branch-scoped media
  -- authorisation and a place in the galleries. It is the only photograph of an
  -- agent the system holds - there is no stored portrait to fall back on, because a
  -- portrait taken in a branch office is not evidence of a doorstep.
  `photo_type`      ENUM('customer','house','land','aadhaar','passbook','renewal_form','agent','other') NOT NULL DEFAULT 'other',
  `file_path`       VARCHAR(500) NOT NULL COMMENT 'relative to uploads root',
  `original_name`   VARCHAR(255) DEFAULT NULL,
  `mime_type`       VARCHAR(100) DEFAULT NULL,
  `file_size`       INT UNSIGNED DEFAULT NULL,
  `width`           SMALLINT UNSIGNED DEFAULT NULL,
  `height`          SMALLINT UNSIGNED DEFAULT NULL,
  `uploaded_by`     INT UNSIGNED NOT NULL,

  -- Fix taken at the moment of capture. capture_source = 'camera' is what makes a
  -- coordinate mean anything: a gallery image could have been taken anywhere, on
  -- any day, by anyone, so the app blocks the picker for visit photos and the
  -- server records which path a file arrived by.
  `gps_latitude`    DECIMAL(10,7) DEFAULT NULL,
  `gps_longitude`   DECIMAL(10,7) DEFAULT NULL,
  `gps_accuracy_m`  SMALLINT UNSIGNED DEFAULT NULL,
  `captured_at`     DATETIME     DEFAULT NULL COMMENT 'device clock at capture',
  `capture_source`  ENUM('camera','gallery','unknown') NOT NULL DEFAULT 'unknown',

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

-- ============================================================================
-- 10a. VISIT REPORT REVISIONS  (how "editable" and "append-only" coexist)
--
-- A field visit report is evidence. It is what an agent asserts about standing at a
-- borrower's door, it gets signed, and it may be read years later by somebody
-- deciding whether a recovery action was justified. So the report is not overwritten.
--
-- But corrections are real: a misheard name, a transposed digit, a figure the
-- approver can see is wrong. Refusing them just moves the correction off-system into
-- a phone call, which is worse. So an edit updates the row AND writes what it
-- changed here, with the value before and after, who did it and why. The submitted
-- original is always reconstructible by replaying these backwards.
--
-- Append-only in the strict sense: nothing updates or deletes a row in this table.
-- ============================================================================

DROP TABLE IF EXISTS `visit_report_revisions`;
CREATE TABLE `visit_report_revisions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_report_id` BIGINT UNSIGNED NOT NULL,
  `revision_no`     SMALLINT UNSIGNED NOT NULL COMMENT '1 for the first correction',
  `changed_by`      INT UNSIGNED DEFAULT NULL,
  `changed_by_name` VARCHAR(150) DEFAULT NULL COMMENT 'snapshot',
  `changed_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- {"column": {"from": <old>, "to": <new>}} - only the columns that actually moved.
  `changes`         JSON         NOT NULL,
  `reason`          VARCHAR(500) DEFAULT NULL COMMENT 'why, in the editor''s words',
  `ip`              VARCHAR(45)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_revision_report_no` (`visit_report_id`, `revision_no`),
  KEY `idx_revision_report` (`visit_report_id`, `changed_at`),
  KEY `idx_revision_actor` (`changed_by`),
  CONSTRAINT `fk_revision_report` FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_revision_actor`  FOREIGN KEY (`changed_by`)      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10b. CUSTOM FIELDS  (fields the operator adds without a code change)
--
-- Two tables rather than a JSON bag on each row. A JSON column is quicker to build
-- and then you cannot answer "which borrowers have no PAN recorded" without scanning
-- every row, cannot put a unique key on anything, and cannot tell a field that was
-- deleted from one that was never filled. Definitions and values kept apart also
-- mean renaming a label does not touch a single stored answer.
--
-- Deliberately NOT a way to add loan figures. Anything the core banking export owns
-- belongs in a real column that the importer knows about; a custom field holding an
-- outstanding balance would be a second answer to the question the whole system is
-- built to answer once.
-- ============================================================================

DROP TABLE IF EXISTS `custom_field_definitions`;
CREATE TABLE `custom_field_definitions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity`         ENUM('customer','loan_account','visit_report') NOT NULL,
  -- Machine name. Immutable once created: it is what stored values point at through
  -- definition_id, and renaming the label is the supported way to change wording.
  `field_key`      VARCHAR(60)  NOT NULL,
  `label`          VARCHAR(120) NOT NULL,
  `field_type`     ENUM('text','textarea','number','money','date','select','toggle') NOT NULL DEFAULT 'text',
  `options`        VARCHAR(500) DEFAULT NULL COMMENT 'comma separated, for select',
  `hint`           VARCHAR(255) DEFAULT NULL,
  `is_required`    TINYINT(1)   NOT NULL DEFAULT 0,
  -- Whether it appears on the printed visit report. Off by default: a field somebody
  -- added to track an internal note should not silently start appearing on a document
  -- handed to a borrower.
  `show_in_report` TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`     SMALLINT     NOT NULL DEFAULT 0,
  -- Retired rather than deleted, so existing answers keep their meaning. Deleting a
  -- definition cascades its values away, which is correct only when the field was a
  -- mistake - and that is a different decision from "we stopped collecting this".
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_field_key` (`entity`, `field_key`),
  KEY `idx_custom_field_entity` (`entity`, `status`, `sort_order`),
  CONSTRAINT `fk_custom_field_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `custom_field_values`;
CREATE TABLE `custom_field_values` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `definition_id` INT UNSIGNED NOT NULL,
  -- Denormalised from the definition so a value can be filtered by entity without a
  -- join, and so a mismatch is detectable rather than invisible.
  `entity`        ENUM('customer','loan_account','visit_report') NOT NULL,
  `entity_id`     BIGINT UNSIGNED NOT NULL,
  -- One text column for every type. The definition says how to read it; storing a
  -- typed column per type would mean seven mostly-null columns and a CASE in every
  -- query. Dates are ISO, money is a decimal string, toggles are '1' or '0'.
  `value`         TEXT         DEFAULT NULL,
  `updated_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One answer per field per record. Without this a double-submitted form gives a
  -- field two values and every read has to pick one.
  UNIQUE KEY `uq_custom_value` (`definition_id`, `entity_id`),
  KEY `idx_custom_value_entity` (`entity`, `entity_id`),
  CONSTRAINT `fk_custom_value_definition` FOREIGN KEY (`definition_id`) REFERENCES `custom_field_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. NOTIFICATIONS
-- ============================================================================

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED DEFAULT NULL COMMENT 'NULL = broadcast to all',
  `branch_id`       INT UNSIGNED DEFAULT NULL COMMENT 'scope a broadcast to one branch',
  -- Extend this list rather than reusing a near-enough value. A type missing from
  -- here throws on insert, and the nightly jobs insert in a loop - one absent value
  -- once took out an entire warning run before the first agent was notified.
  `type`            ENUM('new_lead_assigned','followup_reminder','promise_reminder','broadcast','target_warning','sss_pending','ckcc_renewal_due') NOT NULL,
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
  -- Extend this list rather than reusing a near-enough value. Logger::audit()
  -- deliberately swallows its own failures so a logging problem cannot break the
  -- action being logged - which means an action name missing from this ENUM does
  -- not raise anything, it just silently never records. The customer-sheet export
  -- was audited that way for a while: the code called it, the row never appeared.
  `action`      ENUM('create','update','delete','import','assign','reassign','transfer',
                     'restore','backup','login_reset','export','consent','view_location','purge') NOT NULL,
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

-- ============================================================================
-- 14. LOCATION CAPTURE AND STAFF TRACKING
--
-- This system tracks where its agents are. That was a deliberate decision taken
-- by the operator, reversing an earlier design that captured no location at all,
-- and it carries obligations that are implemented here rather than left to
-- policy:
--
--   * An agent cannot be tracked until they have been shown a written notice and
--     acknowledged it. `tracking_consents` records which version of the notice,
--     when, and from which device. The API refuses location writes without it, so
--     the obligation is enforced by the schema and the code rather than by a
--     promise in a handbook.
--   * Location is retained for a bounded period and then purged
--     (cron/purge-location-logs.php). An unbounded trail of somebody's movements
--     is a liability, not an asset.
--   * Reading another person's location trail is audited like any other PII
--     access, because that is what it is.
--
-- The agent-facing app states plainly that location is recorded while on duty.
-- Any change here has to keep that statement true.
-- ============================================================================

-- The notice text is versioned so that a change forces a fresh acknowledgement
-- rather than silently applying old consent to new collection.
DROP TABLE IF EXISTS `tracking_consents`;
CREATE TABLE `tracking_consents` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `notice_version`  VARCHAR(20)  NOT NULL COMMENT 'e.g. 2026-08-01; bump to force re-consent',
  `acknowledged_at` DATETIME     NOT NULL,
  `device_info`     VARCHAR(255) DEFAULT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `withdrawn_at`    DATETIME     DEFAULT NULL COMMENT 'set when an agent revokes; stops collection',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tracking_consent` (`user_id`, `notice_version`),
  KEY `idx_tracking_consent_user` (`user_id`, `withdrawn_at`),
  CONSTRAINT `fk_tracking_consent_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Continuous location trail while an agent is on duty.
--
-- Deliberately NOT indexed for open-ended history queries: the only supported
-- reads are "this agent, this day" and the purge. Making a year of somebody's
-- movements cheap to scan is a design choice, and not one worth making.
DROP TABLE IF EXISTS `bc_location_logs`;
CREATE TABLE `bc_location_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`     INT UNSIGNED NOT NULL,
  `latitude`     DECIMAL(10,7) NOT NULL,
  `longitude`    DECIMAL(10,7) NOT NULL,
  `accuracy_m`   SMALLINT UNSIGNED DEFAULT NULL COMMENT 'reported accuracy radius in metres',
  `logged_at`    DATETIME     NOT NULL COMMENT 'device clock at the fix',
  `received_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'server clock; the trustworthy one',
  `on_duty`      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_location_agent_day` (`agent_id`, `logged_at`),
  KEY `idx_location_purge` (`logged_at`),
  CONSTRAINT `fk_location_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reverse-geocoding cache: coordinate -> human-readable address.
--
-- Coordinates are what get stored on a visit or a location point; an address is
-- only ever derived for display. That ordering matters: a name resolved once and
-- frozen into the record would quietly become the record, and a free service
-- getting a village wrong would be indistinguishable from the agent being there.
--
-- The cache exists because the free provider (OpenStreetMap Nominatim) asks for
-- at most one request per second and no bulk reverse-geocoding. An agent's day is
-- hundreds of points inside a few hundred metres, so rounding the key to ~4
-- decimal places (about 11 m) collapses almost all of them onto one lookup.
--
-- `failed_at` is remembered too. Without it, a coordinate the provider cannot
-- name is retried on every single page view, which is precisely the behaviour
-- that gets an IP blocked.
DROP TABLE IF EXISTS `geocode_cache`;
CREATE TABLE `geocode_cache` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grid_key`    VARCHAR(32)  NOT NULL COMMENT 'lat,lng rounded to 4dp - the cache key',
  `latitude`    DECIMAL(10,7) NOT NULL,
  `longitude`   DECIMAL(10,7) NOT NULL,
  `address`     VARCHAR(400) DEFAULT NULL COMMENT 'NULL when the lookup failed',
  `village`     VARCHAR(150) DEFAULT NULL,
  `district`    VARCHAR(150) DEFAULT NULL,
  `state`       VARCHAR(150) DEFAULT NULL,
  `postcode`    VARCHAR(20)  DEFAULT NULL,
  `provider`    VARCHAR(40)  NOT NULL DEFAULT 'nominatim',
  `failed_at`   DATETIME     DEFAULT NULL COMMENT 'set when the provider could not name it',
  `attempts`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_geocode_grid` (`grid_key`),
  KEY `idx_geocode_retry` (`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 15. BC PERFORMANCE: targets, daily achievement, warnings, SSS enrolment
--
-- The four tables below are the only genuinely new storage this module needs.
-- Everything else the module specification asked for already exists and is
-- deliberately NOT duplicated:
--
--   borrower KYC          -> `customers` (with encrypted mobile/Aadhaar). A second
--                            plaintext KYC table would fork the borrower record and
--                            put unencrypted Aadhaar numbers back in the database.
--   CKCC / OD loan detail -> `loan_accounts` already carries cif_number,
--                            sanction_date, sanction_limit, drawing_power,
--                            interest_overdue and ckcc_renewal_due_date, and
--                            `visit_ckcc_details` carries the per-visit renewal
--                            checklist. Two parallel side tables keyed on the same
--                            loan account would mean three places to ask "what is
--                            the outstanding?" and three answers.
--   lead distribution     -> `loan_accounts.assigned_agent_id` / `assigned_at` /
--                            `assigned_by`, with `visit_history` as the audit trail.
--   import batches        -> `lead_imports`.
--   field visit reports   -> `visit_reports` plus `photos` / `documents`.
-- ============================================================================

DROP TABLE IF EXISTS `bc_targets`;
CREATE TABLE `bc_targets` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`            INT UNSIGNED NOT NULL COMMENT 'users.id with the agent role',
  `target_month`        DATE         NOT NULL COMMENT 'always the 1st of the month',

  `apy_target`          INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjjby_target`       INT UNSIGNED NOT NULL DEFAULT 0,
  `pmsby_target`        INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjdy_target`        INT UNSIGNED NOT NULL DEFAULT 0,
  `npa_recovery_target` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `od2_renewal_target`  INT UNSIGNED NOT NULL DEFAULT 0,
  `daily_visit_target`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'per working day, not per month',

  `set_by`              INT UNSIGNED DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- One target row per agent per month. Without this a second row silently halves
  -- or doubles every gap the warning cron computes.
  UNIQUE KEY `uq_bc_target_agent_month` (`agent_id`, `target_month`),
  KEY `idx_bc_target_month` (`target_month`),
  CONSTRAINT `fk_bc_target_agent`  FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bc_target_setter` FOREIGN KEY (`set_by`)   REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nightly rollup, one row per agent per day. Derived entirely from visit_reports,
-- promises and sss_enrollment, so it can be rebuilt from scratch at any time - it
-- is a cache for dashboard and streak queries, never a source of truth.
DROP TABLE IF EXISTS `bc_daily_achievement`;
CREATE TABLE `bc_daily_achievement` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`          INT UNSIGNED NOT NULL,
  `achievement_date`  DATE         NOT NULL,

  `apy_done`          INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjjby_done`       INT UNSIGNED NOT NULL DEFAULT 0,
  `pmsby_done`        INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjdy_done`        INT UNSIGNED NOT NULL DEFAULT 0,
  `npa_recovery_done` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `od2_renewal_done`  INT UNSIGNED NOT NULL DEFAULT 0,
  `visits_done`       INT UNSIGNED NOT NULL DEFAULT 0,
  `contacts_done`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'visits where the borrower was actually met',
  `ptp_done`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'promises to pay taken',
  `report_submitted`  TINYINT(1)   NOT NULL DEFAULT 0,

  `computed_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bc_achievement_agent_date` (`agent_id`, `achievement_date`),
  KEY `idx_bc_achievement_date` (`achievement_date`),
  CONSTRAINT `fk_bc_achievement_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only escalation log. A warning is a statement about someone's employment,
-- so rows are never edited or deleted; a withdrawn warning is recorded as a new row
-- with status 'withdrawn' and a reason.
DROP TABLE IF EXISTS `bc_warnings`;
CREATE TABLE `bc_warnings` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`       INT UNSIGNED NOT NULL,
  `warning_level`  ENUM('L1','L2','L3') NOT NULL,
  `target_type`    ENUM('apy','pmjjby','pmsby','pmjdy','npa_recovery','od2_renewal','visit','report') NOT NULL,

  `target_value`   VARCHAR(40)  DEFAULT NULL,
  `achieved_value` VARCHAR(40)  DEFAULT NULL,
  `gap_value`      VARCHAR(40)  DEFAULT NULL,
  `miss_streak`    SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'consecutive working days missed',

  `triggered_date` DATE         NOT NULL,
  `email_sent`     TINYINT(1)   NOT NULL DEFAULT 0,
  `sms_sent`       TINYINT(1)   NOT NULL DEFAULT 0,
  `notified_at`    DATETIME     DEFAULT NULL,
  `delivery_note`  VARCHAR(255) DEFAULT NULL COMMENT 'why a notification did not go out',

  `status`         ENUM('open','acknowledged','resolved','withdrawn') NOT NULL DEFAULT 'open',
  `resolved_at`    DATETIME     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- The cron runs daily and may be re-run after a failure; without this it would
  -- issue the same warning twice and mail the supervisor twice.
  UNIQUE KEY `uq_bc_warning_daily` (`agent_id`, `target_type`, `triggered_date`),
  KEY `idx_bc_warning_agent_date` (`agent_id`, `triggered_date`),
  KEY `idx_bc_warning_level` (`warning_level`, `status`),
  CONSTRAINT `fk_bc_warning_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily SSS quick entry by the agent. Separate from visit_reports because these
-- enrolments happen at the CSP point, not on a field visit.
DROP TABLE IF EXISTS `sss_enrollment`;
CREATE TABLE `sss_enrollment` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`        INT UNSIGNED NOT NULL,
  `branch_id`       INT UNSIGNED NOT NULL,
  `enrollment_date` DATE         NOT NULL,

  `apy_count`       INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjjby_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `pmsby_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjdy_count`     INT UNSIGNED NOT NULL DEFAULT 0,

  `remarks`         VARCHAR(500) DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- One row per agent per day, edited rather than appended, so the day's figure
  -- cannot be double counted by an agent who submits twice.
  UNIQUE KEY `uq_sss_agent_date` (`agent_id`, `enrollment_date`),
  KEY `idx_sss_date` (`enrollment_date`),
  KEY `idx_sss_branch_date` (`branch_id`, `enrollment_date`),
  CONSTRAINT `fk_sss_agent`  FOREIGN KEY (`agent_id`)  REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sss_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scorecard weights, editable rather than compiled in, so a region can weight
-- recovery over enrolment without a deploy.
DROP TABLE IF EXISTS `score_weights`;
CREATE TABLE `score_weights` (
  `metric`      VARCHAR(40)   NOT NULL,
  `weight`      DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  `label`       VARCHAR(100)  NOT NULL,
  `divisor`     DECIMAL(12,2) NOT NULL DEFAULT 1.00 COMMENT 'rupee metrics score per this many rupees',
  `sort_order`  SMALLINT      NOT NULL DEFAULT 0,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`metric`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where a warning escalates to, per branch. One fixed regional address across a
-- multi-region deployment is how escalations end up going to the wrong office.
DROP TABLE IF EXISTS `branch_escalation_emails`;
CREATE TABLE `branch_escalation_emails` (
  `branch_id`             INT UNSIGNED NOT NULL,
  `supervisor_email`      VARCHAR(190) DEFAULT NULL,
  `service_provider_email` VARCHAR(190) DEFAULT NULL COMMENT 'CBC / corporate BC',
  `regional_office_email` VARCHAR(190) DEFAULT NULL,
  `updated_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`branch_id`),
  CONSTRAINT `fk_branch_escalation` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED: ROLES
-- ============================================================================

INSERT INTO `roles` (`id`, `slug`, `display_name`, `description`, `is_system`) VALUES
  (1, 'super_admin',    'Super Admin',    'Full system access across all branches',        1),
  (2, 'branch_manager', 'Branch Manager', 'Scoped to the assigned branch only',            1),
  (3, 'agent',          'BC Agent',       'Android app only - assigned leads and visits',  1),
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

  ('tracking.view',         'Tracking',    "View an agent's location trail on a map"),

  ('customers.view',        'Customers',   'View customers and leads'),
  ('customers.create',      'Customers',   'Add a borrower and loan account by hand'),
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

  ('visits.approve',        'Visits',      'Approve or reject a field visit report'),
  ('visits.revise',         'Visits',      'Correct a submitted visit report (recorded as a revision)'),
  ('custom_fields.manage',  'Settings',    'Add and edit custom fields'),

  ('bc_targets.view',       'BC performance','View monthly BC targets'),
  ('bc_targets.manage',     'BC performance','Set and update monthly BC targets'),
  ('sss.view',              'BC performance','View SSS enrolment entries'),
  ('sss.manage',            'BC performance','Record and correct SSS enrolment'),
  ('scorecard.view',        'BC performance','View the BC summary scorecard'),

  ('backup.run',            'Backup',      'Run and download database backup');

-- Super Admin -> every permission
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 1, `id` FROM `permissions`;

-- Branch Manager -> branch-scoped operational set
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 2, `id` FROM `permissions` WHERE `code` IN (
    'dashboard.view','customers.view','customers.create','customers.update','users.view',
    'tracking.view',
    'leads.assign','leads.reassign','leads.close',
    'visits.view','promises.view','promises.update',
    'reports.view','reports.export','notifications.view','import.view',
    'visits.approve','visits.revise',
    -- A branch manager sets their own agents' targets and sees their scorecard.
    -- Both are scoped to the manager's branch in code, not by this grant.
    'bc_targets.view','bc_targets.manage','sss.view','sss.manage','scorecard.view'
  );

-- BC Agent -> Android app surface only.
-- dashboard.view is granted because the app has an agent home screen with the
-- agent's own counters (assigned / pending / visits / promises). It is scoped to
-- that agent in code; it does not expose branch or all-branch figures.
-- Agents deliberately do NOT get promises.update: recording a promise during a
-- visit is theirs, but deciding it was kept or broken is a branch decision.
-- customers.update and custom_fields.manage are granted deliberately. An agent who
-- spots a wrong father's name or a dead phone number at a doorstep is the only person
-- who will ever be standing there; the alternative to letting them fix it is a phone
-- call to the branch, or the record staying wrong. Every figure they change is stamped
-- into loan_accounts.manual_overrides, so the correction is attributed and the next
-- import leaves it alone. The panel restricts them to leads assigned to them - branch
-- scope is not enough, because a branch holds several agents.
--
-- customers.create is granted for the same reason and is a separate permission rather
-- than part of customers.update, because adding a borrower is not correcting one: an
-- auditor holds customers.view and must never be able to invent an account. A branch
-- hands an agent accounts on paper that the Excel export has not reached - a new NPA, a
-- takeover, an account opened at a different branch - and until now the only way in was
-- a spreadsheet somebody at head office had to build. The lead an agent creates is
-- assigned to them and stamped 'lead_created', so a typed account is never mistaken for
-- one the core banking system produced.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 3, `id` FROM `permissions` WHERE `code` IN (
    'dashboard.view','customers.view','customers.view_pii','customers.create','customers.update',
    'custom_fields.manage',
    -- Their own trail, and only their own - the page pins an agent to themselves. Somebody
    -- whose movements are recorded should be able to see what was recorded, and
    -- TrackingService::trailFor() already skips the audit entry when the viewer is the
    -- subject, which is the same judgement made in code long before this screen existed.
    'tracking.view',
    'visits.view','visits.create','promises.view','notifications.view'
  );

-- Auditor -> read only
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 4, `id` FROM `permissions` WHERE `code` IN (
    'dashboard.view','dashboard.all_branches','customers.view','visits.view',
    'promises.view','reports.view','reports.export','logs.audit','logs.activity','branches.view','users.view',
    -- Read, never manage: an auditor checking whether a warning was fair must be
    -- able to see the target it was measured against.
    'bc_targets.view','sss.view','scorecard.view'
  );

-- ============================================================================
-- SEED: SETTINGS
-- ============================================================================

INSERT INTO `settings` (`setting_key`, `setting_value`, `group_name`, `label`, `input_type`, `options`, `is_secret`, `is_required`, `hint`, `sort_order`) VALUES
  ('app_name',           'D2 Recovery Solutions & Services', 'general', 'Application name',        'text', NULL,     0, 0, 'Shown in the header and on exports', 1),
  ('bank_name',          '',                'general', 'Bank / institution name', 'text', NULL,     0, 1, 'Printed on report headers',          2),
  -- The masthead of the Field Visit Verification Report. Deliberately NOT bank_name:
  -- the form is the recovery agency's own document, filed WITH a bank, and printing the
  -- bank's name at the top of it claimed the bank had issued it. A separate key so an
  -- agency running this can put its own name there without touching either of the above.
  ('report_org_name',    'D2 Recovery Solutions & Services', 'general', 'Organisation name on printed forms', 'text', NULL, 0, 0, 'Masthead of the Field Visit Verification Report', 3),
  ('app_version',        '1.0.0',           'general', 'Android app version',     'text', NULL,     0, 1, 'Latest published APK version',       3),
  ('app_min_version',    '1.0.0',           'general', 'Minimum supported app version', 'text', NULL, 0, 0, 'Older apps are asked to update',  4),
  ('records_per_page',   '25',              'general', 'Records per page',        'number', NULL,   0, 0, 'Default pagination size',            5),
  ('timezone',           'Asia/Kolkata',    'general', 'Timezone',                'text', NULL,     0, 0, 'PHP timezone identifier',            6),

  ('smtp_host',          '',                'smtp',    'SMTP host',               'text', NULL,     0, 0, '', 1),
  ('smtp_port',          '587',             'smtp',    'SMTP port',               'number', NULL,   0, 0, '', 2),
  ('smtp_username',      '',                'smtp',    'SMTP username',           'text', NULL,     0, 0, '', 3),
  ('smtp_password',      '',                'smtp',    'SMTP password',           'password', NULL, 1, 0, '', 4),
  ('smtp_encryption',    'tls',             'smtp',    'SMTP encryption',         'select', 'tls,ssl,none',   0, 0, '', 5),
  ('smtp_from_email',    '',                'smtp',    'From email',              'text', NULL,     0, 0, '', 6),
  ('smtp_from_name',     'D2 Recovery Solutions & Services', 'smtp', 'From name',               'text', NULL,     0, 0, '', 7),

  ('sms_provider',       '',                'sms',     'SMS provider',            'text', NULL,     0, 1, 'e.g. msg91, textlocal, custom', 1),
  ('sms_api_url',        '',                'sms',     'SMS API URL',             'text', NULL,     0, 1, 'Placeholders: {mobile} {message}', 2),
  ('sms_api_key',        '',                'sms',     'SMS API key',             'password', NULL, 1, 1, '', 3),
  ('sms_sender_id',      '',                'sms',     'SMS sender ID',           'text', NULL,     0, 0, '', 4),
  ('sms_otp_template',   'Your D2 Recovery Solutions & Services OTP is {otp}. Valid for 10 minutes.', 'sms', 'OTP template', 'textarea', NULL, 0, 0, 'Use {otp}', 5),

  ('firebase_server_key','',                'integrations', 'Firebase server key', 'password', NULL, 1, 0, 'FCM push notifications', 2),
  ('firebase_project_id','',                'integrations', 'Firebase project ID', 'text', NULL,     0, 0, '', 3),

  ('otp_expiry_minutes', '10',              'security', 'OTP expiry (minutes)',    'number', NULL, 0, 0, '', 1),
  ('max_login_attempts', '5',               'security', 'Max failed login attempts','number', NULL, 0, 0, 'Then the account locks temporarily', 2),
  ('lockout_minutes',    '15',              'security', 'Lockout duration (minutes)','number', NULL,0, 0, '', 3),
  ('jwt_ttl_minutes',    '120',             'security', 'JWT access token TTL (minutes)','number', NULL,0, 0, '', 4),
  ('refresh_ttl_days',   '30',              'security', 'Refresh token TTL (days)','number', NULL,  0, 0, 'Drives "remember login"', 5),
  ('password_min_length','8',               'security', 'Minimum password length', 'number', NULL,  0, 0, '', 6),

  ('promise_reminder_days','1',             'notifications', 'Promise reminder lead time (days)', 'number', NULL, 0, 0, 'Notify this many days before the promise date', 1),
  ('followup_reminder_days','7',            'notifications', 'Follow-up reminder after (days)',   'number', NULL, 0, 0, 'Nudge if a lead has had no visit for N days', 2),

  -- The daily report deadline. The app schedules a local alarm from this, so it is
  -- the bank's policy in one place rather than a time typed into each phone.
  -- Stored as HH:MM in 24-hour form; anything unparseable falls back to 17:00 in
  -- code, because the settings screen has no per-type validation and a blank here
  -- must not mean "no deadline".
  --
  -- A select with an explicit list rather than free text: a typo in a typed time
  -- silently becomes the 17:00 fallback, and the operator has no way to tell their
  -- change did nothing. Half-hour steps across the plausible range instead.
  ('daily_report_due_time','17:00',         'notifications', 'Daily report due by',               'select', '15:00,15:30,16:00,16:30,17:00,17:30,18:00,18:30,19:00,19:30,20:00', 0, 0, 'The app reminds agents at this time. Keep the sss-reminder --final cron entry on the same hour.', 3),
  ('daily_report_reminder_enabled','1',     'notifications', 'Remind agents to submit',           'toggle', NULL, 0, 0, 'Turns the in-app daily alarm off for everyone. An agent cannot switch off their own.', 4),
  -- The alarm repeats until the report is in, and both numbers are the bank's, not the
  -- agent's. A single notification at the deadline is dismissed in one swipe and then it
  -- is nobody's problem until tomorrow; repeating it makes the deadline mean something.
  --
  -- The cutoff exists because "until it is submitted" cannot literally mean all night. An
  -- alarm at 2 am is not a reminder, it is a reason to turn notifications off for the app
  -- entirely - which would take the useful reminders with it. It resumes at the deadline
  -- on the next working day, so an unfiled report is not forgotten either.
  ('daily_report_reminder_repeat_minutes','15','notifications','Repeat the alarm every (minutes)','number', NULL, 0, 0, 'The alarm re-fires this often until the agent submits. 0 turns repeating off and leaves one reminder at the deadline.', 5),
  ('daily_report_reminder_until_hour','22',  'notifications', 'Stop repeating at (hour, 0-23)',    'number', NULL, 0, 0, 'Repeats stop at this hour and resume at the deadline on the next working day. An alarm through the night gets the app silenced.', 6),

  ('backup_retention_days','14',            'backup',  'Backup retention (days)', 'number', NULL, 0, 0, 'Older .sql files are pruned', 1),
  ('mysqldump_path',     'mysqldump',       'backup',  'mysqldump binary path',   'text', NULL,   0, 0, 'Falls back to a pure-PHP dump if unavailable', 2),

  -- Location. These were read from code with hard-coded fallbacks before they had
  -- rows here, which meant the retention window could not actually be changed by
  -- the operator who is accountable for it.
  ('location_retention_days','90',          'location','Location retention (days)','number', NULL, 0, 0, 'Location points older than this are deleted by the purge cron', 1),
  ('geocode_enabled',    '1',               'location','Resolve addresses from coordinates','toggle', NULL, 0, 0, 'Uses the free OpenStreetMap service. Turn off to store coordinates only.', 2),
  ('geocode_contact_email','',              'location','Contact email for the map service','text', NULL, 0, 0, 'OpenStreetMap asks who is calling. Without it, lookups are skipped rather than sent anonymously.', 3),
  ('ckcc_renewal_alert_days','30',          'location','CKCC renewal alert window (days)','number', NULL, 0, 0, 'Agents are alerted as a renewal crosses this many days, then 15, 7 and overdue', 4);

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
-- SEED: scorecard weights
--
-- Starting values only; they are meant to be tuned from Settings. Recovery is
-- scored per 1,000 rupees (divisor) so a single large recovery cannot swamp a
-- month of field work, and enrolments are worth more than a visit because they
-- are a completed outcome rather than an activity.
-- ============================================================================

INSERT INTO `score_weights` (`metric`, `weight`, `label`, `divisor`, `sort_order`) VALUES
  ('visits',       1.0000, 'Per visit',                      1.00, 1),
  ('contacts',     1.5000, 'Per borrower actually met',      1.00, 2),
  ('ptp',          2.0000, 'Per promise to pay',             1.00, 3),
  ('npa_recovery', 1.0000, 'Per Rs 1,000 recovered',      1000.00, 4),
  ('od2_renewal',  3.0000, 'Per OD-2 / CKCC renewal',        1.00, 5),
  ('apy',          5.0000, 'Per APY enrolment',              1.00, 6),
  ('pmjjby',       5.0000, 'Per PMJJBY enrolment',           1.00, 7),
  ('pmsby',        5.0000, 'Per PMSBY enrolment',            1.00, 8),
  ('pmjdy',        3.0000, 'Per PMJDY account',              1.00, 9);

-- ============================================================================
-- Restore foreign key enforcement for the remainder of this session.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;
