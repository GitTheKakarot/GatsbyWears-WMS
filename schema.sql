

SET NAMES utf8mb4;
SET time_zone = '+06:00';
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `branches` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(191) NOT NULL,
  `code`       VARCHAR(20)  NOT NULL,
  `type`       ENUM('mother','sub') NOT NULL DEFAULT 'sub',
  `city`       VARCHAR(100) NULL,
  `area`       VARCHAR(100) NULL,
  `address`    TEXT NULL,
  `capacity`   INT UNSIGNED DEFAULT 0,
  `phone`      VARCHAR(30)  NULL,
  `email`      VARCHAR(190) NULL,
  `latitude`   DECIMAL(10,7) NULL,
  `longitude`  DECIMAL(10,7) NULL,
  `hours`      TEXT NULL,                 -- JSON: [{day,open,close,closed}]
  `notes`      TEXT NULL,
  `deleted_at` DATETIME     NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_branches_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `branch_images` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id`  INT UNSIGNED NOT NULL,
  `path`       VARCHAR(255) NOT NULL,
  `is_primary` TINYINT(1)   NOT NULL DEFAULT 0,
  `sort`       INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_branch_images_branch` (`branch_id`),
  CONSTRAINT `fk_branch_images_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `users` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`                 VARCHAR(191) NOT NULL,
  `email`                VARCHAR(191) NOT NULL,
  `phone`                VARCHAR(30)  NULL,
  `password`             VARCHAR(255) NOT NULL,
  `role`                 ENUM('super_admin','manager','worker') NOT NULL DEFAULT 'worker',
  `branch_id`            INT UNSIGNED NULL,
  `pos_access`           TINYINT(1)   NOT NULL DEFAULT 0,
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,

  `photo`                VARCHAR(255) NULL,
  `nid`                  VARCHAR(50)  NULL,
  `dob`                  DATE         NULL,
  `gender`               ENUM('male','female','other') NULL,
  `blood_group`          VARCHAR(5)   NULL,
  `address`              TEXT         NULL,
  `emergency_name`       VARCHAR(120) NULL,
  `emergency_phone`      VARCHAR(30)  NULL,
  `emergency_relation`   VARCHAR(60)  NULL,
  `designation`          VARCHAR(120) NULL,
  `join_date`            DATE         NULL,
  `employment_type`      ENUM('full_time','part_time','contract') NULL,
  `salary`               DECIMAL(12,2) NULL,    
  `created_by`           INT UNSIGNED NULL,
  `deleted_at`           DATETIME     NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_users_email`  (`email`),
  UNIQUE KEY `uq_users_phone`  (`phone`),
  KEY `idx_users_branch`       (`branch_id`),
  KEY `idx_users_created_by`   (`created_by`),
  CONSTRAINT `fk_users_branch`      FOREIGN KEY (`branch_id`)  REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_created_by`  FOREIGN KEY (`created_by`) REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`        VARCHAR(191) NULL,
  `ip`           VARCHAR(45)  NOT NULL,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_la_email`        (`email`),
  KEY `idx_la_ip`           (`ip`),
  KEY `idx_la_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `products` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(191)    NOT NULL,
  `sku`           VARCHAR(100)    NOT NULL,
  `category`      VARCHAR(100)    NULL,
  `price`         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `cost`          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `color`         VARCHAR(100)    NULL,
  `reorder_point` INT UNSIGNED    NOT NULL DEFAULT 10,
  `status`        ENUM('active','low','inactive') NOT NULL DEFAULT 'active',
  `emoji`         VARCHAR(10)     NULL,
  `description`   TEXT            NULL,
  `deleted_at`    DATETIME        NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_products_sku` (`sku`),
  KEY `idx_products_status`   (`status`),
  KEY `idx_products_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `product_images` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `path`       VARCHAR(500) NOT NULL,
  `is_primary` TINYINT(1)   NOT NULL DEFAULT 0,
  `sort`       INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pi_product` (`product_id`),
  KEY `idx_pi_primary` (`product_id`, `is_primary`),
  CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_variants` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id`  INT UNSIGNED  NOT NULL,
  `size`        VARCHAR(50)   NOT NULL,
  `variant_sku` VARCHAR(120)  NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pv_product` (`product_id`),
  UNIQUE KEY `uq_pv_sku`          (`variant_sku`),
  UNIQUE KEY `uq_pv_product_size` (`product_id`, `size`),
  CONSTRAINT `fk_pv_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `branch_stock` (
  `branch_id`  INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `qty`        INT          NOT NULL DEFAULT 0,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`branch_id`, `variant_id`),
  KEY `idx_bs_variant` (`variant_id`),
  CONSTRAINT `fk_bs_branch`  FOREIGN KEY (`branch_id`)  REFERENCES `branches`         (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bs_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `transfers` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `from_branch_id` INT UNSIGNED NOT NULL,
  `to_branch_id`   INT UNSIGNED NOT NULL,
  `variant_id`     INT UNSIGNED NOT NULL,
  `qty`            INT          NOT NULL,
  `status`         ENUM('pending','approved','delivered','rejected') NOT NULL DEFAULT 'pending',
  `requested_by`   INT UNSIGNED NOT NULL,
  `notes`          TEXT         NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_tr_from`      (`from_branch_id`),
  KEY `idx_tr_to`        (`to_branch_id`),
  KEY `idx_tr_variant`   (`variant_id`),
  KEY `idx_tr_requester` (`requested_by`),
  KEY `idx_tr_status`    (`status`),
  CONSTRAINT `fk_tr_from`      FOREIGN KEY (`from_branch_id`) REFERENCES `branches`         (`id`),
  CONSTRAINT `fk_tr_to`        FOREIGN KEY (`to_branch_id`)   REFERENCES `branches`         (`id`),
  CONSTRAINT `fk_tr_variant`   FOREIGN KEY (`variant_id`)     REFERENCES `product_variants` (`id`),
  CONSTRAINT `fk_tr_requester` FOREIGN KEY (`requested_by`)   REFERENCES `users`            (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `customers` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(120)  NOT NULL,
  `phone`      VARCHAR(30)   NULL,
  `email`      VARCHAR(190)  NULL,
  `notes`      TEXT          NULL,
  `created_by` INT UNSIGNED  NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_customers_phone` (`phone`),
  KEY `idx_customers_name` (`name`),
  CONSTRAINT `fk_customers_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `sales` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_no`     VARCHAR(30)    NOT NULL,
  `branch_id`      INT UNSIGNED   NOT NULL,
  `worker_id`      INT UNSIGNED   NOT NULL,
  `customer_id`    INT UNSIGNED   NULL,
  `total_amount`   DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `discount`       DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `net_amount`     DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','card','bkash','nagad','other') NOT NULL DEFAULT 'cash',
  `notes`          TEXT           NULL,
  `cancelled`      TINYINT(1)     NOT NULL DEFAULT 0,
  `cancelled_at`   DATETIME       NULL,
  `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sales_invoice` (`invoice_no`),
  KEY `idx_sales_branch`     (`branch_id`),
  KEY `idx_sales_worker`     (`worker_id`),
  KEY `idx_sales_customer`   (`customer_id`),
  KEY `idx_sales_created_at` (`created_at`),
  KEY `idx_sales_cancelled`  (`cancelled`),
  CONSTRAINT `fk_sales_branch`   FOREIGN KEY (`branch_id`)   REFERENCES `branches`  (`id`),
  CONSTRAINT `fk_sales_worker`   FOREIGN KEY (`worker_id`)   REFERENCES `users`     (`id`),
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sale_id`     INT UNSIGNED  NOT NULL,
  `variant_id`  INT UNSIGNED  NOT NULL,
  `product_id`  INT UNSIGNED  NOT NULL,
  `qty`         INT           NOT NULL,
  `unit_price`  DECIMAL(12,2) NOT NULL,
  `line_total`  DECIMAL(12,2) NOT NULL,
  `unit_cost`   DECIMAL(12,2) NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_si_sale`    (`sale_id`),
  KEY `idx_si_variant` (`variant_id`),
  KEY `idx_si_product` (`product_id`),
  CONSTRAINT `fk_si_sale`    FOREIGN KEY (`sale_id`)    REFERENCES `sales`            (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_si_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`),
  CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products`         (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `invites` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `token`      VARCHAR(64)  NOT NULL,
  `email`      VARCHAR(191) NULL,
  `name`       VARCHAR(191) NULL,
  `role`       ENUM('manager','worker') NOT NULL DEFAULT 'worker',
  `branch_id`  INT UNSIGNED NULL,
  `max_uses`   INT          NOT NULL DEFAULT 1,
  `uses`       INT          NOT NULL DEFAULT 0,
  `expires_at` DATETIME     NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `used_at`    DATETIME     NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_invites_token` (`token`),
  KEY `idx_inv_branch`     (`branch_id`),
  KEY `idx_inv_created_by` (`created_by`),
  KEY `idx_inv_expires_at` (`expires_at`),
  CONSTRAINT `fk_inv_branch`     FOREIGN KEY (`branch_id`)  REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`    (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `action_type` ENUM('manual_edit','pos_sale','transfer_deduct','transfer_add','adjustment','auth') NOT NULL,
  `entity_type` ENUM('branch_stock','inventory','transfer','sales','auth') NOT NULL,
  `entity_id`   INT UNSIGNED  NULL,
  `branch_id`   INT UNSIGNED  NULL,
  `product_id`  INT UNSIGNED  NULL,
  `old_qty`     INT           NULL,
  `new_qty`     INT           NULL,
  `changed_by`  INT UNSIGNED  NULL,
  `reason`      VARCHAR(500)  NULL,
  `metadata`    JSON          NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_al_action`     (`action_type`),
  KEY `idx_al_entity`     (`entity_type`, `entity_id`),
  KEY `idx_al_branch`     (`branch_id`),
  KEY `idx_al_product`    (`product_id`),
  KEY `idx_al_changed_by` (`changed_by`),
  KEY `idx_al_created_at` (`created_at`),
  CONSTRAINT `fk_al_branch`      FOREIGN KEY (`branch_id`)  REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_al_product`     FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_al_changed_by`  FOREIGN KEY (`changed_by`) REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `performance_targets` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED  NOT NULL,
  `branch_id`      INT UNSIGNED  NOT NULL,
  `month`          TINYINT       NOT NULL CHECK (`month` BETWEEN 1 AND 12),
  `year`           SMALLINT      NOT NULL,
  `target_amount`  DECIMAL(12,2) NULL,
  `target_units`   INT           NULL,
  `target_type`    ENUM('daily','monthly') NOT NULL DEFAULT 'monthly',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pt_user_period` (`user_id`, `month`, `year`, `target_type`),
  KEY `idx_pt_branch` (`branch_id`),
  CONSTRAINT `fk_pt_user`   FOREIGN KEY (`user_id`)   REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(100) NOT NULL,
  `value`      TEXT         NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;


INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('app_version',       '2.0.0'),
  ('currency_symbol',   '৳'),
  ('low_stock_days',    '30');
