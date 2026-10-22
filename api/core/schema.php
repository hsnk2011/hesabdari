<?php
// /api/core/schema.php
// Single source of truth for the database schema and idempotent data migrations.
// Used by setup.php (install / manual upgrade) and SchemaMigrator (automatic upgrade per APP_VERSION).

/**
 * تعریف کامل و صحیح ساختار دیتابیس برای آخرین نسخه برنامه
 * @return array
 */
function get_full_schema() {
    return [
        'business_entities' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
            ],
            'constraints' => ['PRIMARY KEY (`id`)']
        ],
        'settings' => [
            'columns' => [
                '`setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`setting_value` text COLLATE utf8mb4_unicode_ci NOT NULL',
            ],
            'constraints' => ['PRIMARY KEY (`setting_key`)']
        ],
        'product_attribute_types' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`applicable_to` enum(\'machine\',\'handmade\',\'both\') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'both\'',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_name_applicable` (`name`, `applicable_to`)'
            ]
        ],
        'product_collections' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`type` enum(\'machine\',\'handmade\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`price_per_meter` decimal(15,2) NOT NULL DEFAULT 0.00',
                '`entity_id` int(11) NOT NULL',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_name_entity` (`name`, `entity_id`)',
                'KEY `fk_collection_entity` (`entity_id`)',
                'CONSTRAINT `fk_collection_entity` FOREIGN KEY (`entity_id`) REFERENCES `business_entities` (`id`) ON DELETE CASCADE'
            ]
        ],
        'collection_dimensions' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`collection_id` int(11) NOT NULL',
                '`dimension` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`price_override` decimal(15,2) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_collection_dimension` (`collection_id`, `dimension`)',
                'CONSTRAINT `fk_dim_collection` FOREIGN KEY (`collection_id`) REFERENCES `product_collections` (`id`) ON DELETE CASCADE'
            ]
        ],
        'product_collection_attributes' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`collection_id` int(11) NOT NULL',
                '`attribute_type_id` int(11) NOT NULL',
                '`value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_collection_attribute` (`collection_id`, `attribute_type_id`)',
                'KEY `fk_coll_attr_collection` (`collection_id`)',
                'KEY `fk_coll_attr_type` (`attribute_type_id`)',
                'CONSTRAINT `fk_coll_attr_collection` FOREIGN KEY (`collection_id`) REFERENCES `product_collections` (`id`) ON DELETE CASCADE',
                'CONSTRAINT `fk_coll_attr_type` FOREIGN KEY (`attribute_type_id`) REFERENCES `product_attribute_types` (`id`) ON DELETE CASCADE'
            ]
        ],
        'audit_ignored_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`reference_table` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`reference_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`issue_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`ignored_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`ignored_by` int(11) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_ignore_item` (`entity_id`, `reference_table`, `reference_id`, `issue_type`)',
                'KEY `idx_ignore_fingerprint` (`entity_id`, `fingerprint`)'
            ]
        ],
        'audit_runs' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`started_at` datetime NOT NULL',
                '`finished_at` datetime DEFAULT NULL',
                "`status` enum('running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running'",
                '`duration_ms` int(11) DEFAULT NULL',
                '`summary_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_by` int(11) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_started` (`entity_id`, `started_at`)',
            ]
        ],
        'audit_run_issues' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`run_id` int(11) NOT NULL',
                '`rule_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`reference_table` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`reference_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`payload_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`status` enum('open','resolved','ignored') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open'",
                '`remediation_class` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'navigate_only\'',
                '`nav_json` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_run` (`run_id`)',
                'KEY `idx_fingerprint` (`fingerprint`)',
                'KEY `idx_run_rule` (`run_id`, `rule_id`)',
            ]
        ],
        'users' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`failed_login_attempts` int(11) NOT NULL DEFAULT 0',
                '`lockout_until` datetime DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `username` (`username`)',
            ]
        ],
        'roles' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`role_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_system` tinyint(1) NOT NULL DEFAULT 0',
            ],
            'constraints' => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `role_name` (`role_name`)']
        ],
        'permissions' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`permission_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `permission_key` (`permission_key`)']
        ],
        'role_permissions' => [
            'columns' => [
                '`role_id` int(11) NOT NULL',
                '`permission_id` int(11) NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`role_id`, `permission_id`)',
                'KEY `fk_role_permissions_role` (`role_id`)',
                'KEY `fk_role_permissions_permission` (`permission_id`)',
                'CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE',
                'CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE',
            ]
        ],
        'user_roles' => [
            'columns' => [
                '`user_id` int(11) NOT NULL',
                '`role_id` int(11) NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`user_id`, `role_id`)',
                'KEY `fk_user_roles_user` (`user_id`)',
                'KEY `fk_user_roles_role` (`role_id`)',
                'CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
                'CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE',
            ]
        ],
        'user_entity_access' => [
            'columns' => [
                '`user_id` int(11) NOT NULL',
                '`entity_id` int(11) NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`user_id`, `entity_id`)',
                'KEY `fk_user_entity_access_user` (`user_id`)',
                'KEY `fk_user_entity_access_entity` (`entity_id`)',
                'CONSTRAINT `fk_user_entity_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
                'CONSTRAINT `fk_user_entity_access_entity` FOREIGN KEY (`entity_id`) REFERENCES `business_entities` (`id`) ON DELETE CASCADE',
            ]
        ],
        'activity_log' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`user_id` int(11) DEFAULT NULL',
                '`timestamp` timestamp NOT NULL DEFAULT current_timestamp()',
                '`username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`action_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_timestamp` (`timestamp`)'
            ]
        ],
        'customers' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`job` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`nationalId` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`initial_balance` decimal(15,2) DEFAULT 0.00',
                '`credit_limit` decimal(15,2) DEFAULT NULL',
                '`group_id` int(11) DEFAULT NULL',
                '`linked_supplier_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                '`owner_user_id` int(11) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_name` (`entity_id`, `name`)',
                'UNIQUE KEY `uk_linked_supplier` (`linked_supplier_id`)'
            ]
        ],
        'suppliers' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`job` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`economicCode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`initial_balance` decimal(15,2) DEFAULT 0.00',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_name` (`entity_id`, `name`)'
            ]
        ],
        'contacts' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`job` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`nationalId` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`initial_balance` decimal(15,2) DEFAULT 0.00',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_name` (`entity_id`, `name`)'
            ]
        ],
        'product_categories' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`parent_id` int(11) DEFAULT NULL',
                '`sort_order` int(11) NOT NULL DEFAULT 0',
                '`icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT "bi-folder"',
                '`color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT "#6c757d"',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_category_name_entity` (`name`, `entity_id`)',
                'KEY `fk_category_entity` (`entity_id`)',
                'KEY `fk_category_parent` (`parent_id`)',
                'CONSTRAINT `fk_category_entity` FOREIGN KEY (`entity_id`) REFERENCES `business_entities` (`id`) ON DELETE CASCADE',
                'CONSTRAINT `fk_category_parent` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL'
            ]
        ],
        'products' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`collection_id` int(11) DEFAULT NULL',
                '`category_id` int(11) DEFAULT NULL',
                '`design_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`colorway` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`price` decimal(15,2) DEFAULT NULL',
                '`min_stock` int(11) DEFAULT NULL',
                '`max_stock` int(11) DEFAULT NULL',
                '`reorder_point` int(11) DEFAULT NULL',
                '`unit` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT "عدد"',
                '`sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`weight` decimal(10,3) DEFAULT NULL',
                '`is_active` tinyint(1) NOT NULL DEFAULT 1',
                "`fulfillment_mode` enum('stocked','mto') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stocked'",
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `fk_product_category` (`category_id`)',
                'CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL',
                'KEY `collection_id` (`collection_id`)',
                'CONSTRAINT `fk_product_collection` FOREIGN KEY (`collection_id`) REFERENCES `product_collections` (`id`) ON DELETE SET NULL',
                'KEY `idx_fulfillment_mode` (`fulfillment_mode`)'
            ]
        ],
        'product_images' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`product_id` int(11) NOT NULL',
                '`file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`thumb_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_primary` tinyint(1) NOT NULL DEFAULT 0',
                '`sort_order` int(11) NOT NULL DEFAULT 0',
                '`uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `fk_image_product` (`product_id`)',
                'CONSTRAINT `fk_image_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE'
            ]
        ],
        'warehouses' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_default` tinyint(1) NOT NULL DEFAULT 0',
                '`is_active` tinyint(1) NOT NULL DEFAULT 1',
                '`show_on_dashboard` tinyint(1) NOT NULL DEFAULT 0',
                "`warehouse_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'physical'",
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `fk_warehouse_entity` (`entity_id`)',
                'CONSTRAINT `fk_warehouse_entity` FOREIGN KEY (`entity_id`) REFERENCES `business_entities` (`id`) ON DELETE CASCADE'
            ]
        ],
        'partners' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`share` decimal(5,4) NOT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => ['PRIMARY KEY (`id`)']
        ],
        'account_closings' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`period_start_date` date NOT NULL',
                '`period_end_date` date NOT NULL',
                "`close_type` enum('annual','interim','partner_restructure') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'interim'",
                '`calculation_method` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'standard\'',
                "`status` enum('completed','reopened') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed'",
                '`net_profit` decimal(15,2) NOT NULL DEFAULT 0.00',
                '`gross_profit` decimal(15,2) NOT NULL DEFAULT 0.00',
                '`snapshot_json` longtext COLLATE utf8mb4_unicode_ci',
                '`partner_allocation_json` longtext COLLATE utf8mb4_unicode_ci',
                '`new_partners_config_json` longtext COLLATE utf8mb4_unicode_ci',
                '`profit_posted` tinyint(1) NOT NULL DEFAULT 0',
                '`notes` text COLLATE utf8mb4_unicode_ci',
                '`closed_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`closed_by` int(11) DEFAULT NULL',
                '`reopened_at` timestamp NULL DEFAULT NULL',
                '`reopened_by` int(11) DEFAULT NULL',
                // NULL for reopened rows so multiple reopen history of the same end date is allowed;
                // UNIQUE below only blocks two *completed* closings sharing the same period_end.
                "`completed_end_key` date GENERATED ALWAYS AS (IF(`status` = 'completed', `period_end_date`, NULL)) STORED",
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_closing_entity_status` (`entity_id`, `status`)',
                'KEY `idx_closing_entity_end` (`entity_id`, `period_end_date`)',
                'UNIQUE KEY `uq_closing_entity_completed_end` (`entity_id`, `completed_end_key`)',
            ]
        ],
        'partner_share_history' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`partner_id` int(11) DEFAULT NULL',
                '`partner_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`share` decimal(5,4) NOT NULL',
                '`effective_from` date NOT NULL',
                '`effective_to` date DEFAULT NULL',
                '`closing_id` int(11) DEFAULT NULL',
                "`action` enum('unchanged','modified','added','removed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unchanged'",
                '`notes` text COLLATE utf8mb4_unicode_ci',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_share_entity_dates` (`entity_id`, `effective_from`, `effective_to`)',
                'KEY `idx_share_partner` (`partner_id`)',
                'KEY `idx_share_closing` (`closing_id`)',
            ]
        ],
        'accounts' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`bank_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`account_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`card_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`initial_balance` decimal(15,2) NOT NULL DEFAULT 0.00',
                '`current_balance` decimal(15,2) NOT NULL DEFAULT 0.00',
                '`is_cash` tinyint(1) NOT NULL DEFAULT 0',
                '`include_in_cash_position` tinyint(1) NOT NULL DEFAULT 1',
                '`partner_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `partner_id` (`partner_id`)',
                'KEY `idx_entity_id` (`entity_id`)',
                'CONSTRAINT `fk_account_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
            ]
        ],
        'inventory_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`product_id` int(11) NOT NULL',
                '`entity_id` int(11) NOT NULL',
                '`warehouse_id` int(11) DEFAULT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`serial_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`serial_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`batch_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`cost_price` decimal(15,2) DEFAULT NULL',
                '`status` enum(\'in_stock\',\'sold\',\'consignment_out\',\'returned\',\'returned_to_supplier\',\'reserved\',\'damaged\') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'in_stock\'',
                '`purchase_invoice_item_id` int(11) DEFAULT NULL',
                '`sales_invoice_item_id` int(11) DEFAULT NULL',
                '`returned_from_sales_item_id` int(11) DEFAULT NULL',
                '`notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_virtual` tinyint(1) NOT NULL DEFAULT 0',
                '`entry_date` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `product_id` (`product_id`)',
                'KEY `idx_entity_status` (`entity_id`, `status`)',
                'KEY `idx_warehouse` (`warehouse_id`)',
                'KEY `status` (`status`)',
                'KEY `purchase_invoice_item_id` (`purchase_invoice_item_id`)',
                'KEY `sales_invoice_item_id` (`sales_invoice_item_id`)',
                'UNIQUE KEY `idx_serial_id_entity` (`entity_id`, `serial_id`)',
                'CONSTRAINT `fk_inv_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE',
                'CONSTRAINT `fk_inv_item_entity` FOREIGN KEY (`entity_id`) REFERENCES `business_entities` (`id`) ON DELETE CASCADE',
                'CONSTRAINT `fk_inv_item_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL'
            ]
        ],
        'sales_invoices' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`customerId` int(11) NOT NULL',
                '`date` date NOT NULL',
                '`due_date` date DEFAULT NULL',
                '`payment_terms` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`totalAmount` decimal(15,2) NOT NULL',
                '`discount` decimal(15,2) DEFAULT 0.00',
                '`tax_rate` decimal(5,2) DEFAULT 0.00',
                '`tax_amount` decimal(15,2) DEFAULT 0.00',
                '`paidAmount` decimal(15,2) DEFAULT 0.00',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_consignment` tinyint(1) DEFAULT 0',
                "`delivery_status` ENUM('preparing','ready','delivered') COLLATE utf8mb4_unicode_ci DEFAULT 'preparing'",
                '`invoice_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                '`source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`salesperson_id` int(11) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `customerId` (`customerId`)',
                'KEY `date` (`date`)',
                'KEY `idx_entity_date` (`entity_id`, `date`)',
                'KEY `idx_source` (`source`)',
                'CONSTRAINT `sales_invoices_ibfk_1` FOREIGN KEY (`customerId`) REFERENCES `customers` (`id`) ON DELETE RESTRICT',
            ]
        ],
        'purchase_invoices' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`supplierId` int(11) NOT NULL',
                '`date` date NOT NULL',
                '`due_date` date DEFAULT NULL',
                '`payment_terms` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`totalAmount` decimal(15,2) NOT NULL',
                '`discount` decimal(15,2) DEFAULT 0.00',
                '`tax_rate` decimal(5,2) DEFAULT 0.00',
                '`tax_amount` decimal(15,2) DEFAULT 0.00',
                '`paidAmount` decimal(15,2) DEFAULT 0.00',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_consignment` tinyint(1) DEFAULT 0',
                "`delivery_status` ENUM('preparing','ready','delivered') COLLATE utf8mb4_unicode_ci DEFAULT 'preparing'",
                '`invoice_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`warehouse_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `supplierId` (`supplierId`)',
                'KEY `date` (`date`)',
                'KEY `idx_entity_date` (`entity_id`, `date`)',
                'KEY `idx_purchase_warehouse` (`warehouse_id`)',
                'CONSTRAINT `purchase_invoices_ibfk_1` FOREIGN KEY (`supplierId`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT',
            ]
        ],
        'sales_invoice_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`invoiceId` int(11) NOT NULL',
                '`productId` int(11) DEFAULT NULL',
                '`quantity` int(11) NOT NULL',
                '`unitPrice` decimal(15,2) NOT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_service` tinyint(1) NOT NULL DEFAULT 0',
                '`item_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_invoice_product_dims` (`invoiceId`,`productId`,`dimensions`)',
                'KEY `invoiceId` (`invoiceId`)',
                'KEY `productId` (`productId`)',
                'CONSTRAINT `sales_invoice_items_ibfk_1` FOREIGN KEY (`invoiceId`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE',
            ]
        ],
        'purchase_invoice_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`invoiceId` int(11) NOT NULL',
                '`productId` int(11) DEFAULT NULL',
                '`quantity` int(11) NOT NULL',
                '`unitPrice` decimal(15,2) NOT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_service` tinyint(1) NOT NULL DEFAULT 0',
                '`item_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_invoice_product_dims` (`invoiceId`,`productId`,`dimensions`)',
                'KEY `invoiceId` (`invoiceId`)',
                'KEY `productId` (`productId`)',
                'CONSTRAINT `purchase_invoice_items_ibfk_1` FOREIGN KEY (`invoiceId`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE',
            ]
        ],
        'checks' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`type` enum(\'received\',\'payable\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`status` enum(\'in_hand\',\'endorsed\',\'cashed\',\'payable\',\'bounced\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`invoiceId` int(11) DEFAULT NULL',
                '`invoiceType` enum(\'sales\',\'purchase\',\'return\',\'asset_purchase\',\'asset_sale\') COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`checkNumber` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`dueDate` date NOT NULL',
                '`bankName` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`issuerName` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`endorsedToInvoiceId` int(11) DEFAULT NULL',
                '`cashed_in_account_id` int(11) DEFAULT NULL',
                '`cashed_date` date DEFAULT NULL',
                '`bounced_date` date DEFAULT NULL',
                '`bounce_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `cashed_in_account_id` (`cashed_in_account_id`)',
                'KEY `dueDate` (`dueDate`)',
                'KEY `cashed_date` (`cashed_date`)',
                'KEY `status` (`status`)',
                'KEY `idx_entity_status_due` (`entity_id`, `status`, `dueDate`)',
                'CONSTRAINT `checks_ibfk_1` FOREIGN KEY (`cashed_in_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL',
            ]
        ],
        'payments' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`invoiceId` int(11) DEFAULT NULL',
                '`invoiceType` enum(\'sales\',\'purchase\',\'return\',\'asset_purchase\',\'asset_sale\') COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`person_id` int(11) DEFAULT NULL',
                '`person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`transaction_type` enum(\'receipt\',\'payment\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`type` enum(\'cash\',\'check\',\'endorse_check\',\'check_bounce\',\'setoff\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`date` date NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`checkId` int(11) DEFAULT NULL',
                '`account_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                '`installment_id` int(11) DEFAULT NULL',
                '`reversal_of_payment_id` int(11) DEFAULT NULL',
                '`setoff_group_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`transfer_group_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `checkId` (`checkId`)',
                'KEY `account_id` (`account_id`)',
                'KEY `person_key` (`person_id`,`person_type`)',
                'KEY `date` (`date`)',
                'KEY `idx_entity_type_date` (`entity_id`, `type`, `date`)',
                'KEY `idx_invoice` (`invoiceType`, `invoiceId`)',
                'KEY `idx_setoff_group` (`setoff_group_id`)',
                'KEY `idx_transfer_group` (`transfer_group_id`)',
                'CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`checkId`) REFERENCES `checks` (`id`) ON DELETE SET NULL',
                'CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL',
            ]
        ],
        'expenses' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`date` date NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`account_id` int(11) NOT NULL',
                '`person_id` int(11) DEFAULT NULL',
                '`person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                // Optional project this cost belongs to (میز کار budget).
                '`project_id` int(11) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `account_id` (`account_id`)',
                'KEY `date` (`date`)',
                'KEY `idx_entity_date` (`entity_id`, `date`)',
                'KEY `idx_expense_person` (`entity_id`, `person_type`, `person_id`)',
                'KEY `idx_expense_project` (`project_id`)',
                'CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT',
            ]
        ],
        'incomes' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`date` date NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`account_id` int(11) NOT NULL',
                '`person_id` int(11) DEFAULT NULL',
                '`person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `account_id` (`account_id`)',
                'KEY `date` (`date`)',
                'KEY `idx_entity_date` (`entity_id`, `date`)',
                'KEY `idx_income_person` (`entity_id`, `person_type`, `person_id`)',
                'CONSTRAINT `incomes_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT',
            ]
        ],
        'return_invoices' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`type` enum(\'sales_return\',\'purchase_return\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`original_invoice_id` int(11) NOT NULL',
                '`original_invoice_type` enum(\'sales\',\'purchase\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`person_id` int(11) NOT NULL',
                '`person_type` enum(\'customer\',\'supplier\',\'partner\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`date` date NOT NULL',
                '`totalAmount` decimal(15,2) NOT NULL',
                '`discount` decimal(15,2) DEFAULT 0.00',
                '`paidAmount` decimal(15,2) DEFAULT 0.00',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `person_id` (`person_id`)',
                'KEY `person_type` (`person_type`)',
                'KEY `original_invoice_id` (`original_invoice_id`)',
                'KEY `date` (`date`)',
                'KEY `idx_entity_date` (`entity_id`, `date`)',
                'KEY `idx_type_date` (`type`, `date`)',
            ]
        ],
        'return_invoice_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`return_invoice_id` int(11) NOT NULL',
                '`productId` int(11) NOT NULL',
                '`quantity` int(11) NOT NULL',
                '`unitPrice` decimal(15,2) NOT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`original_invoice_item_id` int(11) DEFAULT NULL',
                '`inventory_item_ids` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `return_invoice_id` (`return_invoice_id`)',
                'KEY `productId` (`productId`)',
                'CONSTRAINT `return_invoice_items_ibfk_1` FOREIGN KEY (`return_invoice_id`) REFERENCES `return_invoices` (`id`) ON DELETE CASCADE',
            ]
        ],
        'quotations' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`customerId` int(11) NOT NULL',
                '`date` date NOT NULL',
                '`valid_until` date DEFAULT NULL',
                '`totalAmount` decimal(15,2) NOT NULL',
                '`discount` decimal(15,2) DEFAULT 0.00',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`status` enum(\'pending\',\'converted\',\'cancelled\',\'expired\') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'pending\'',
                '`converted_invoice_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `customerId` (`customerId`)',
                'KEY `date` (`date`)',
                'KEY `status` (`status`)',
                'KEY `idx_entity_date` (`entity_id`, `date`)',
                'KEY `idx_valid_until` (`valid_until`)',
                'KEY `idx_entity_status` (`entity_id`, `status`)',
                'CONSTRAINT `quotations_ibfk_1` FOREIGN KEY (`customerId`) REFERENCES `customers` (`id`) ON DELETE RESTRICT',
            ]
        ],
        'quotation_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`quotationId` int(11) NOT NULL',
                '`productId` int(11) NOT NULL',
                '`quantity` int(11) NOT NULL',
                '`unitPrice` decimal(15,2) NOT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `quotationId` (`quotationId`)',
                'KEY `productId` (`productId`)',
                'CONSTRAINT `quotation_items_ibfk_1` FOREIGN KEY (`quotationId`) REFERENCES `quotations` (`id`) ON DELETE CASCADE',
            ]
        ],
        'installments' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`invoice_id` int(11) NOT NULL',
                '`invoice_type` enum(\'sales\',\'purchase\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`installment_number` int(11) NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`due_date` date NOT NULL',
                '`paid_amount` decimal(15,2) DEFAULT 0.00',
                '`paid_date` date DEFAULT NULL',
                "`status` enum('pending','paid','overdue','partially_paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'",
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_invoice` (`invoice_type`, `invoice_id`)',
                'KEY `idx_entity_status` (`entity_id`, `status`)',
                'KEY `idx_due_date` (`due_date`)',
            ]
        ],
        'stocktaking' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`date` date NOT NULL',
                '`warehouse_id` int(11) DEFAULT NULL',
                "`status` enum('draft','in_progress','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft'",
                '`notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_by` int(11) DEFAULT NULL',
                '`completed_at` timestamp NULL DEFAULT NULL',
                '`surplus_value` decimal(15,2) NOT NULL DEFAULT 0.00',
                '`shrinkage_value` decimal(15,2) NOT NULL DEFAULT 0.00',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_date` (`entity_id`, `date`)',
                'KEY `idx_status` (`status`)',
            ]
        ],
        'stocktaking_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`stocktaking_id` int(11) NOT NULL',
                '`product_id` int(11) NOT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`warehouse_id` int(11) DEFAULT NULL',
                '`system_qty` int(11) NOT NULL DEFAULT 0',
                '`counted_qty` int(11) NOT NULL DEFAULT 0',
                '`difference` int(11) NOT NULL DEFAULT 0',
                '`notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_stocktaking` (`stocktaking_id`)',
                'KEY `idx_product` (`product_id`)',
                'KEY `idx_warehouse` (`warehouse_id`)',
                'CONSTRAINT `fk_stocktaking_item` FOREIGN KEY (`stocktaking_id`) REFERENCES `stocktaking` (`id`) ON DELETE CASCADE',
            ]
        ],
        'proforma_links' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`invoice_type` enum(\'sales\',\'purchase\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`invoice_id` int(11) NOT NULL',
                '`token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`expires_at` datetime NOT NULL',
                '`revoked_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `idx_token` (`token`)',
                'KEY `idx_invoice` (`invoice_type`, `invoice_id`)',
                'KEY `idx_expires` (`expires_at`)',
            ]
        ],
        'sms_logs' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
                '`user_id` int(11) DEFAULT NULL',
                '`username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`messageid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`receptor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`sender` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'simple\'',
                '`template_key` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`verify_template` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`invoice_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`invoice_id` int(11) DEFAULT NULL',
                '`cost` int(11) DEFAULT NULL',
                '`status` int(11) DEFAULT NULL',
                '`status_text` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'app\'',
                '`sent_at` datetime DEFAULT NULL',
                '`status_checked_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_created` (`entity_id`, `created_at`)',
                'KEY `idx_messageid` (`messageid`)',
                'KEY `idx_status` (`status`)',
                'KEY `idx_invoice` (`invoice_type`, `invoice_id`)',
            ]
        ],
        'notifications' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`reference_id` int(11) DEFAULT NULL',
                '`due_date` date DEFAULT NULL',
                '`is_read` tinyint(1) NOT NULL DEFAULT 0',
                '`is_dismissed` tinyint(1) NOT NULL DEFAULT 0',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                // NULL = the whole team; otherwise only this user sees it (CRM).
                '`user_id` int(11) DEFAULT NULL',
                // In-app deep link (e.g. ./index.php#work/project/3/task/9) opened from the bell.
                '`link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_read` (`entity_id`, `is_read`)',
                'KEY `idx_type` (`type`)',
                'KEY `idx_due_date` (`due_date`)',
                'KEY `idx_reference` (`reference_type`, `reference_id`)',
                'KEY `idx_user` (`user_id`)',
            ]
        ],
        // ===== CRM =====
        'team_members' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`user_id` int(11) DEFAULT NULL',
                '`partner_id` int(11) DEFAULT NULL',
                '`full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`mobile` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`kind` enum('partner','employee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'employee'",
                '`is_active` tinyint(1) NOT NULL DEFAULT 1',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_team_entity_user` (`entity_id`, `user_id`)',
                'KEY `idx_team_entity_active` (`entity_id`, `is_active`)',
            ]
        ],
        'push_subscriptions' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`user_id` int(11) NOT NULL',
                '`endpoint` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`p256dh` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`auth` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`last_success_at` datetime DEFAULT NULL',
                '`fail_count` int(11) NOT NULL DEFAULT 0',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_push_endpoint` (`endpoint`(255))',
                'KEY `idx_push_user` (`user_id`)',
            ]
        ],
        'crm_activities' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                "`person_type` enum('customer','lead','supplier','contact') COLLATE utf8mb4_unicode_ci NOT NULL",
                '`person_id` int(11) NOT NULL',
                '`user_id` int(11) DEFAULT NULL',
                "`kind` enum('call_out','call_in','meeting','visit','note') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'note'",
                '`outcome` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`body` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`promise_amount` decimal(15,2) DEFAULT NULL',
                '`promise_date` date DEFAULT NULL',
                "`promise_status` enum('open','kept','broken') COLLATE utf8mb4_unicode_ci DEFAULT NULL",
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_act_person` (`entity_id`, `person_type`, `person_id`, `created_at`)',
                'KEY `idx_act_promise` (`entity_id`, `promise_status`, `promise_date`)',
            ]
        ],
        'crm_tasks' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`person_type` enum('customer','lead','supplier','contact') COLLATE utf8mb4_unicode_ci DEFAULT NULL",
                '`person_id` int(11) DEFAULT NULL',
                '`assignee_user_id` int(11) NOT NULL',
                '`created_by` int(11) DEFAULT NULL',
                '`due_at` datetime NOT NULL',
                "`kind` enum('followup','collection','sales','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'followup'",
                "`status` enum('open','done','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open'",
                '`done_at` datetime DEFAULT NULL',
                '`done_by` int(11) DEFAULT NULL',
                "`source` enum('manual','promise_broken','check_due','check_bounced','installment_overdue','quotation_expiring') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual'",
                '`source_ref_id` int(11) DEFAULT NULL',
                '`reminded_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_task_assignee` (`entity_id`, `assignee_user_id`, `status`, `due_at`)',
                'KEY `idx_task_person` (`entity_id`, `person_type`, `person_id`)',
                'KEY `idx_task_remind` (`status`, `reminded_at`, `due_at`)',
                // Automatic tasks are created once per source row; manual tasks keep source_ref_id NULL.
                'UNIQUE KEY `uk_task_source` (`entity_id`, `source`, `source_ref_id`)',
            ]
        ],
        'crm_leads' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`source` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`interest` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`stage` enum('new','contacted','quoted','won','lost') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new'",
                '`lost_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`owner_user_id` int(11) DEFAULT NULL',
                '`converted_customer_id` int(11) DEFAULT NULL',
                '`notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_lead_stage` (`entity_id`, `stage`)',
            ]
        ],
        // ===== میز کار: پروژه، کار، جلسه =====
        'projects' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`status` enum('planning','active','on_hold','done','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planning'",
                "`priority` enum('low','medium','high','urgent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium'",
                '`start_date` date DEFAULT NULL',
                '`due_date` date DEFAULT NULL',
                '`budget` decimal(15,2) DEFAULT NULL',
                '`created_by` int(11) DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_project_entity_status` (`entity_id`, `status`, `updated_at`)',
            ]
        ],
        'project_members' => [
            'columns' => [
                '`project_id` int(11) NOT NULL',
                '`user_id` int(11) NOT NULL',
                "`role` enum('manager','member') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member'",
                '`added_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`project_id`, `user_id`)',
                'KEY `idx_pm_user` (`user_id`)',
            ]
        ],
        'project_tasks' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`project_id` int(11) NOT NULL',
                '`title` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`status` enum('todo','in_progress','review','done') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'todo'",
                '`assignee_user_id` int(11) DEFAULT NULL',
                '`due_date` date DEFAULT NULL',
                '`sort_order` int(11) NOT NULL DEFAULT 0',
                '`created_by` int(11) DEFAULT NULL',
                '`completed_at` datetime DEFAULT NULL',
                '`completed_by` int(11) DEFAULT NULL',
                '`completion_note` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`reviewed_by` int(11) DEFAULT NULL',
                '`review_note` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                // Day the due-date reminder was sent (one reminder per task per day).
                '`reminded_on` date DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_pt_project_status` (`project_id`, `status`)',
                'KEY `idx_pt_assignee` (`assignee_user_id`, `status`)',
            ]
        ],
        'project_task_checks' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`task_id` int(11) NOT NULL',
                '`text` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`done` tinyint(1) NOT NULL DEFAULT 0',
                '`sort_order` int(11) NOT NULL DEFAULT 0',
                '`done_by` int(11) DEFAULT NULL',
                '`done_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_ptc_task` (`task_id`, `sort_order`)',
            ]
        ],
        'project_task_deps' => [
            'columns' => [
                '`task_id` int(11) NOT NULL',
                '`depends_on_id` int(11) NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`task_id`, `depends_on_id`)',
                'KEY `idx_ptd_dep` (`depends_on_id`)',
            ]
        ],
        'project_comments' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`project_id` int(11) NOT NULL',
                '`author_user_id` int(11) NOT NULL',
                '`body` text COLLATE utf8mb4_unicode_ci NOT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`edited_at` datetime DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_pc_project` (`project_id`, `created_at`)',
            ]
        ],
        'meetings' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`project_id` int(11) DEFAULT NULL',
                '`starts_at` datetime NOT NULL',
                '`location` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`agenda` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`minutes` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_by` int(11) DEFAULT NULL',
                // 30-minute reminder already sent for this start time.
                '`reminded_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_meeting_entity_start` (`entity_id`, `starts_at`)',
                'KEY `idx_meeting_project` (`project_id`)',
            ]
        ],
        'meeting_attendees' => [
            'columns' => [
                '`meeting_id` int(11) NOT NULL',
                '`user_id` int(11) NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`meeting_id`, `user_id`)',
                'KEY `idx_ma_user` (`user_id`)',
            ]
        ],
        'meeting_actions' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`meeting_id` int(11) NOT NULL',
                '`text` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`assignee_user_id` int(11) DEFAULT NULL',
                '`due_date` date DEFAULT NULL',
                '`done` tinyint(1) NOT NULL DEFAULT 0',
                // Project task created from this decision (once).
                '`task_id` int(11) DEFAULT NULL',
                '`sort_order` int(11) NOT NULL DEFAULT 0',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_ma_task` (`task_id`)',
                'KEY `idx_mact_meeting` (`meeting_id`, `sort_order`)',
                'KEY `idx_mact_assignee` (`assignee_user_id`, `done`)',
            ]
        ],
        'work_files' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`owner_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`owner_id` int(11) NOT NULL',
                // Set when the file belongs to a project report.
                '`comment_id` int(11) DEFAULT NULL',
                '`title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`stored_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`size` int(11) NOT NULL',
                '`mime` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`kind` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`uploaded_by` int(11) DEFAULT NULL',
                // Voice note recorded in the messenger.
                '`is_voice` tinyint(1) NOT NULL DEFAULT 0',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_wf_owner` (`owner_type`, `owner_id`)',
                'KEY `idx_wf_comment` (`comment_id`)',
            ]
        ],
        // ===== میز کار: پیام‌رسان =====
        'chat_conversations' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                "`type` enum('direct','group') COLLATE utf8mb4_unicode_ci NOT NULL",
                '`title` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                // direct only: "<smaller user id>:<larger user id>" — one direct chat per pair
                '`direct_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_by` int(11) DEFAULT NULL',
                '`pinned_message_id` int(11) DEFAULT NULL',
                '`last_message_at` datetime NOT NULL DEFAULT current_timestamp()',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_chat_direct` (`entity_id`, `direct_key`)',
                'KEY `idx_chat_last` (`entity_id`, `last_message_at`)',
            ]
        ],
        'chat_members' => [
            'columns' => [
                '`conversation_id` int(11) NOT NULL',
                '`user_id` int(11) NOT NULL',
                '`is_admin` tinyint(1) NOT NULL DEFAULT 0',
                '`joined_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`last_read_message_id` int(11) NOT NULL DEFAULT 0',
                '`muted` tinyint(1) NOT NULL DEFAULT 0',
                '`pinned_at` datetime DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`conversation_id`, `user_id`)',
                'KEY `idx_cm_user` (`user_id`)',
            ]
        ],
        'chat_messages' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`conversation_id` int(11) NOT NULL',
                '`sender_id` int(11) NOT NULL',
                "`kind` enum('text','system') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text'",
                '`body` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`reply_to_id` int(11) DEFAULT NULL',
                '`forwarded_from` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`ref_type` enum('customer','project','project_task','meeting') COLLATE utf8mb4_unicode_ci DEFAULT NULL",
                '`ref_id` int(11) DEFAULT NULL',
                '`ref_title` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`edited_at` datetime DEFAULT NULL',
                '`deleted_at` datetime DEFAULT NULL',
                '`created_at` datetime NOT NULL DEFAULT current_timestamp()',
                // Bumped on edit / delete / reaction so pollers fetch only changes.
                '`updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3)',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_msg_conv` (`conversation_id`, `id`)',
                'KEY `idx_msg_updated` (`conversation_id`, `updated_at`)',
            ]
        ],
        'chat_reactions' => [
            'columns' => [
                '`message_id` int(11) NOT NULL',
                '`user_id` int(11) NOT NULL',
                '`emoji` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => ['PRIMARY KEY (`message_id`, `user_id`)']
        ],
        // ===== میز کار: کارتابل، پرسنل، ایده‌ها =====
        'work_requests' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`number` int(11) NOT NULL',
                "`kind` enum('payment','letter') COLLATE utf8mb4_unicode_ci NOT NULL",
                '`title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`body` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`category` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`amount` decimal(15,2) DEFAULT NULL',
                '`payee` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`supplier_id` int(11) DEFAULT NULL',
                '`personnel_id` int(11) DEFAULT NULL',
                '`project_id` int(11) DEFAULT NULL',
                "`status` enum('pending','approved','rejected','paid','done','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'",
                '`created_by` int(11) NOT NULL',
                '`assignee_user_id` int(11) DEFAULT NULL',
                '`due_date` date DEFAULT NULL',
                '`result` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                // Expense booked when the payment was made (once).
                '`expense_id` int(11) DEFAULT NULL',
                '`closed_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_request_number` (`entity_id`, `number`)',
                'KEY `idx_request_status` (`entity_id`, `kind`, `status`)',
                'KEY `idx_request_assignee` (`assignee_user_id`, `status`)',
            ]
        ],
        'work_request_events' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`request_id` int(11) NOT NULL',
                '`actor_user_id` int(11) NOT NULL',
                "`action` enum('create','approve','reject','pay','refer','comment','done','cancel','reopen') COLLATE utf8mb4_unicode_ci NOT NULL",
                '`note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`to_user_id` int(11) DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_wre_request` (`request_id`, `created_at`)',
                'KEY `idx_wre_to` (`to_user_id`)',
            ]
        ],
        'hr_personnel' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`user_id` int(11) DEFAULT NULL',
                '`job_title` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`national_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`hired_at` date DEFAULT NULL',
                '`is_active` tinyint(1) NOT NULL DEFAULT 1',
                '`note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_hrp_entity` (`entity_id`, `full_name`)',
            ]
        ],
        'hr_records' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`personnel_id` int(11) NOT NULL',
                "`kind` enum('warning','notice','fine','advance','bonus','commendation','other') COLLATE utf8mb4_unicode_ci NOT NULL",
                '`date` date NOT NULL',
                '`amount` decimal(15,2) DEFAULT NULL',
                '`note` text COLLATE utf8mb4_unicode_ci NOT NULL',
                // Paid payment request that created this record.
                '`request_id` int(11) DEFAULT NULL',
                '`created_by` int(11) DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_hrr_request` (`request_id`)',
                'KEY `idx_hrr_person` (`personnel_id`, `date`)',
            ]
        ],
        'ideas' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`author_user_id` int(11) NOT NULL',
                '`title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`body` text COLLATE utf8mb4_unicode_ci NOT NULL',
                '`is_anonymous` tinyint(1) NOT NULL DEFAULT 0',
                "`status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'",
                '`decided_by` int(11) DEFAULT NULL',
                '`decided_at` datetime DEFAULT NULL',
                '`decision_note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`project_id` int(11) DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_idea_project` (`project_id`)',
                'KEY `idx_idea_status` (`entity_id`, `status`, `created_at`)',
            ]
        ],
        'idea_comments' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`idea_id` int(11) NOT NULL',
                '`author_user_id` int(11) NOT NULL',
                '`body` text COLLATE utf8mb4_unicode_ci NOT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_ic_idea` (`idea_id`, `created_at`)',
            ]
        ],
        'idea_votes' => [
            'columns' => [
                '`idea_id` int(11) NOT NULL',
                '`user_id` int(11) NOT NULL',
                '`agree` tinyint(1) NOT NULL DEFAULT 1',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => ['PRIMARY KEY (`idea_id`, `user_id`)']
        ],
        'api_tokens' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`token_prefix` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`entity_id` int(11) NOT NULL',
                '`scopes` text COLLATE utf8mb4_unicode_ci NOT NULL',
                '`is_active` tinyint(1) NOT NULL DEFAULT 1',
                '`last_used_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_token_hash` (`token_hash`)',
                'KEY `idx_token_entity` (`entity_id`)',
                'CONSTRAINT `fk_api_token_entity` FOREIGN KEY (`entity_id`) REFERENCES `business_entities` (`id`) ON DELETE CASCADE'
            ]
        ],
        'integration_links' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`local_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`remote_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`remote_system` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'medusa\'',
                '`business_entity_id` int(11) NOT NULL DEFAULT 1',
                '`meta_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_link_remote` (`entity_type`, `remote_system`, `remote_id`)',
                'UNIQUE KEY `uk_link_local` (`entity_type`, `remote_system`, `business_entity_id`, `local_id`)',
                'KEY `idx_link_entity` (`business_entity_id`, `entity_type`)'
            ]
        ],
        'integration_outbox' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`payload_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL',
                '`idempotency_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`status` enum(\'pending\',\'sent\',\'failed\') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'pending\'',
                '`attempts` int(11) NOT NULL DEFAULT 0',
                '`last_error` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`next_attempt_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`sent_at` datetime DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_outbox_idempotency` (`idempotency_key`)',
                'KEY `idx_outbox_status` (`status`, `next_attempt_at`)'
            ]
        ],
        'integration_sso_tokens' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`user_id` int(11) NOT NULL',
                '`expires_at` datetime NOT NULL',
                '`used_at` datetime DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_sso_hash` (`token_hash`)',
                'KEY `idx_sso_expires` (`expires_at`)'
            ]
        ],
        'bank_imports' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`account_id` int(11) NOT NULL',
                '`file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`import_date` timestamp NOT NULL DEFAULT current_timestamp()',
                '`total_rows` int(11) DEFAULT 0',
                '`matched_rows` int(11) DEFAULT 0',
                "`bank_format` enum('mellat','dey','melli','blubank','generic') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generic'",
                '`period_start` date DEFAULT NULL',
                '`period_end` date DEFAULT NULL',
                '`opening_balance` decimal(15,2) DEFAULT NULL',
                '`closing_balance` decimal(15,2) DEFAULT NULL',
                '`credit_total` decimal(15,2) DEFAULT 0',
                '`debit_total` decimal(15,2) DEFAULT 0',
                "`status` enum('draft','in_progress','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress'",
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity` (`entity_id`)',
                'KEY `idx_account` (`account_id`)',
                'CONSTRAINT `fk_bank_import_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE',
            ]
        ],
        'bank_import_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`import_id` int(11) NOT NULL',
                '`transaction_date` date NOT NULL',
                '`transaction_time` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`balance_after` decimal(15,2) DEFAULT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`counterparty` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`reference_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`document_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`tracking_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`deposit_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`branch` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`match_status` enum('unmatched','matched','ignored') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unmatched'",
                '`matched_payment_id` int(11) DEFAULT NULL',
                "`matched_source` enum('payment','expense','income','check') COLLATE utf8mb4_unicode_ci DEFAULT NULL",
                '`matched_ref_id` int(11) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_import` (`import_id`)',
                'KEY `idx_match_status` (`match_status`)',
                'KEY `idx_matched_ref` (`matched_source`, `matched_ref_id`)',
                'CONSTRAINT `fk_bank_import_item` FOREIGN KEY (`import_id`) REFERENCES `bank_imports` (`id`) ON DELETE CASCADE',
            ]
        ],
        'bank_match_history' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`account_id` int(11) NOT NULL',
                '`item_fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`transaction_date` date NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`tracking_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`reference_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`document_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`counterparty` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                "`matched_source` enum('payment','expense','income','check') COLLATE utf8mb4_unicode_ci NOT NULL",
                '`matched_ref_id` int(11) NOT NULL',
                '`ledger_label` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`matched_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_bank_match_fp` (`account_id`, `item_fingerprint`)',
                'KEY `idx_bank_match_entity` (`entity_id`, `account_id`)',
                'KEY `idx_bank_match_ledger` (`matched_source`, `matched_ref_id`)',
            ]
        ],
        'check_events' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`check_id` int(11) NOT NULL',
                '`event_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`event_date` date NOT NULL',
                '`amount` decimal(15,2) DEFAULT NULL',
                '`account_id` int(11) DEFAULT NULL',
                '`payment_id` int(11) DEFAULT NULL',
                '`note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`user_id` int(11) DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_check` (`check_id`)',
                'KEY `idx_entity_date` (`entity_id`, `event_date`)',
            ]
        ],
        'document_sequences' => [
            'columns' => [
                '`entity_id` int(11) NOT NULL',
                '`doc_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`last_number` int(11) NOT NULL DEFAULT 0',
            ],
            'constraints' => [
                'PRIMARY KEY (`entity_id`, `doc_type`)',
            ]
        ],
        'idempotency_keys' => [
            'columns' => [
                '`key_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`user_id` int(11) DEFAULT NULL',
                '`action` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`status_code` int(11) DEFAULT NULL',
                '`response_json` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`key_hash`)',
                'KEY `idx_created` (`created_at`)',
            ]
        ],
        'document_trash' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`doc_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`doc_id` int(11) NOT NULL',
                '`title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`amount` decimal(15,2) DEFAULT NULL',
                '`doc_date` date DEFAULT NULL',
                '`snapshot_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL',
                '`deleted_by` int(11) DEFAULT NULL',
                '`deleted_by_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`deleted_at` timestamp NOT NULL DEFAULT current_timestamp()',
                '`restored_at` datetime DEFAULT NULL',
                '`restored_doc_id` int(11) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_type` (`entity_id`, `doc_type`)',
                'KEY `idx_deleted_at` (`deleted_at`)',
            ]
        ],
        'recurring_expenses' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`category` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`account_id` int(11) NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`person_id` int(11) DEFAULT NULL',
                "`frequency` enum('weekly','monthly','quarterly','yearly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly'",
                '`next_date` date NOT NULL',
                '`end_date` date DEFAULT NULL',
                "`mode` enum('auto','remind') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'remind'",
                '`is_active` tinyint(1) NOT NULL DEFAULT 1',
                '`last_run_date` date DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_next` (`entity_id`, `is_active`, `next_date`)',
            ]
        ],
        'budgets' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`category` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`period_label` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`period_start` date NOT NULL',
                '`period_end` date NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_period` (`entity_id`, `period_start`, `period_end`)',
            ]
        ],
        'assets' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'عدد\'',
                '`price_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`manual_value` decimal(18,2) DEFAULT NULL',
                '`manual_value_date` date DEFAULT NULL',
                '`notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_archived` tinyint(1) NOT NULL DEFAULT 0',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity` (`entity_id`, `is_archived`)',
            ]
        ],
        'asset_documents' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                "`doc_type` enum('purchase','sale') COLLATE utf8mb4_unicode_ci NOT NULL",
                '`doc_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`date` date NOT NULL',
                '`person_id` int(11) DEFAULT NULL',
                '`person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`total_amount` decimal(18,2) NOT NULL DEFAULT 0.00',
                '`paidAmount` decimal(18,2) NOT NULL DEFAULT 0.00',
                '`source_purchase_invoice_id` int(11) DEFAULT NULL',
                '`is_opening` tinyint(1) NOT NULL DEFAULT 0',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity_date` (`entity_id`, `date`)',
                'KEY `idx_person` (`person_type`, `person_id`)',
            ]
        ],
        'asset_document_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`document_id` int(11) NOT NULL',
                '`asset_id` int(11) NOT NULL',
                '`quantity` decimal(18,4) NOT NULL',
                '`unit_price` decimal(18,2) NOT NULL DEFAULT 0.00',
                '`amount` decimal(18,2) NOT NULL DEFAULT 0.00',
                '`cost_amount` decimal(18,2) DEFAULT NULL',
                '`gain_amount` decimal(18,2) DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_document` (`document_id`)',
                'KEY `idx_asset` (`asset_id`)',
                'CONSTRAINT `fk_asset_item_document` FOREIGN KEY (`document_id`) REFERENCES `asset_documents` (`id`) ON DELETE CASCADE',
                'CONSTRAINT `fk_asset_item_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`)',
            ]
        ],
        'asset_prices' => [
            'columns' => [
                '`price_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`title` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`price` decimal(20,2) NOT NULL DEFAULT 0.00',
                '`source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'tgju\'',
                '`fetched_at` datetime NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`price_key`)',
            ]
        ],
        'salespeople' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`commission_percent` decimal(5,2) NOT NULL DEFAULT 0.00',
                '`is_active` tinyint(1) NOT NULL DEFAULT 1',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity` (`entity_id`)',
            ]
        ],
        'customer_groups' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `idx_entity` (`entity_id`)',
            ]
        ],
        'reminder_log' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`entity_id` int(11) NOT NULL',
                '`kind` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`ref_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`ref_id` int(11) NOT NULL',
                '`sent_on` date NOT NULL',
                '`receptor` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`created_at` timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_reminder` (`kind`, `ref_type`, `ref_id`, `sent_on`)',
                'KEY `idx_entity` (`entity_id`)',
            ]
        ],
    ];
}

/**
 * لیست دسترسی‌های پیش‌فرض برنامه
 * @return array
 */
function get_default_permissions() {
    return [
        'view_dashboard' => 'مشاهده داشبورد',
        'manage_sales_invoices' => 'مدیریت فاکتورهای فروش',
        'manage_purchase_invoices' => 'مدیریت فاکتورهای خرید',
        'manage_return_invoices' => 'مدیریت فاکتورهای مرجوعی',
        'manage_quotations' => 'مدیریت پیش فاکتورها',
        'manage_products' => 'مدیریت انبار و محصولات',
        'manage_collections' => 'مدیریت کلکسیون‌ها و مشخصات',
        'view_price_list' => 'مشاهده و چاپ لیست قیمت',
        'manage_consignment' => 'مدیریت محصولات امانی',
        'manage_transactions' => 'مدیریت مرکز تراکنشات',
        'manage_checks' => 'مدیریت چک‌ها',
        'manage_accounts' => 'مدیریت بانک‌ها و صندوق',
        'manage_customers' => 'مدیریت مشتریان',
        'manage_suppliers' => 'مدیریت تامین‌کنندگان',
        'manage_contacts' => 'مدیریت سایر اشخاص',
        'manage_assets' => 'مدیریت دارایی‌ها',
        'view_reports' => 'مشاهده گزارشات',
        'manage_settings' => 'دسترسی کامل به تنظیمات',
        'manage_stocktaking' => 'مدیریت شمارش فیزیکی انبار',
        'manage_installments' => 'مدیریت اقساط',
        'manage_bank_imports' => 'مدیریت ورود صورتحساب بانکی',
        'import_export_data' => 'ورود و خروج اطلاعات',
        'use_crm' => 'استفاده از CRM',
        'manage_crm_team' => 'مدیریت اعضای تیم CRM',
        'use_projects' => 'پروژه‌ها و جلسات',
        'manage_projects' => 'مدیریت همه پروژه‌ها و جلسات',
        'use_messenger' => 'پیام‌رسان داخلی',
        'use_cartable' => 'کارتابل (درخواست و نامه)',
        'approve_payments' => 'تأیید درخواست پرداخت',
        'pay_payments' => 'پرداخت درخواست‌ها',
        'manage_hr' => 'پرونده پرسنلی',
        'use_ideas' => 'ایده‌ها',
    ];
}

/**
 * انواع مشخصات پیش‌فرض
 * @return array
 */
function get_default_attribute_types() {
    return [
        ['name' => 'شانه', 'applicable_to' => 'machine'],
        ['name' => 'تراکم', 'applicable_to' => 'machine'],
        ['name' => 'جنس نخ', 'applicable_to' => 'both'],
        ['name' => 'ارتفاع خاب (میلی‌متر)', 'applicable_to' => 'machine'],
        ['name' => 'رج', 'applicable_to' => 'handmade'],
        ['name' => 'محل بافت', 'applicable_to' => 'handmade'],
    ];
}

/**
 * Migrates old aggregate stock quantities from product_stock to new serialized inventory_items table.
 * After migration, the old table is dropped.
 */
function migrate_stock_data($pdo, $db_name, &$success_messages, &$error_message) {
    try {
        // Check 1: Does the old 'product_stock' table exist at all?
        $stmt_check_table = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'product_stock'");
        $stmt_check_table->execute([$db_name]);
        if (!$stmt_check_table->fetchColumn()) {
            return; // No old table, no migration needed.
        }

        // Check 2: Does the old 'quantity' column exist? If not, migration has already run.
        $stmt_check_col = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'product_stock' AND COLUMN_NAME = 'quantity'");
        $stmt_check_col->execute([$db_name]);
        if (!$stmt_check_col->fetchColumn()) {
            // The column is gone, but the table might still exist. Let's drop it for cleanup.
            $pdo->exec("DROP TABLE `product_stock`");
            $success_messages[] = "جدول قدیمی و منسوخ `product_stock` پاکسازی شد.";
            return;
        }

        $success_messages[] = "تشخیص داده شد که داده‌های موجودی قدیمی نیاز به مهاجرت دارند. شروع فرآیند...";

        $pdo->beginTransaction();

        $old_stock = $pdo->query("SELECT product_id, dimensions, quantity FROM product_stock WHERE quantity > 0")->fetchAll(PDO::FETCH_ASSOC);

        $stmt_insert_item = $pdo->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status) VALUES (?, ?, ?, 'in_stock')");
        $product_entities = $pdo->query("SELECT id, entity_id FROM products")->fetchAll(PDO::FETCH_KEY_PAIR);

        $total_migrated = 0;
        foreach ($old_stock as $stock_item) {
            $entityId = $product_entities[$stock_item['product_id']] ?? 1;
            for ($i = 0; $i < $stock_item['quantity']; $i++) {
                $stmt_insert_item->execute([$stock_item['product_id'], $entityId, $stock_item['dimensions']]);
                $total_migrated++;
            }
        }

        // After successful migration, drop the entire old table.
        $pdo->exec("DROP TABLE `product_stock`");

        $pdo->commit();
        $success_messages[] = "مهاجرت با موفقیت انجام شد. تعداد {$total_migrated} آیتم به انبار سریالیزه شده اضافه شد و جدول قدیمی `product_stock` حذف گردید.";

    } catch (PDOException $e) {
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message .= "خطا در حین مهاجرت داده‌های موجودی: " . $e->getMessage() . "<br>";
    }
}

/**
 * اصلاح effective_to خالی در تاریخچه سهم شرکا (ناشی از bind نادرست NULL در نسخه‌های قبلی).
 */
function repair_partner_share_history($pdo, $db_name, &$success_messages) {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'partner_share_history'"
        );
        $stmt->execute([$db_name]);
        if (!$stmt->fetchColumn()) {
            return;
        }
        $pdo->exec('SET @hesabdari_old_sql_mode = @@SESSION.sql_mode');
        $pdo->exec(
            "SET SESSION sql_mode = TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(
                @@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', ''), 'STRICT_TRANS_TABLES', ''))"
        );
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ALLOW_INVALID_DATES')");
        $fixed = $pdo->exec(
            "UPDATE partner_share_history SET effective_to = NULL
             WHERE effective_to IS NOT NULL
               AND (effective_to = '0000-00-00' OR effective_to < '1980-01-01')"
        );
        $pdo->exec('SET SESSION sql_mode = @hesabdari_old_sql_mode');
        if ($fixed) {
            $success_messages[] = "تاریخچه سهم شرکا: {$fixed} رکورد اصلاح شد.";
        }
    } catch (PDOException $e) {
        // Non-fatal repair
    }
}


/**
 * ساختار دیتابیس را با اسکما مقایسه و بروزرسانی می‌کند
 */
function synchronize_schema($pdo, $db_name, &$success_messages, &$error_message) {
    $schema = get_full_schema();
    
    foreach ($schema as $tableName => $tableDef) {
        try {
            $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
            $stmt_check->execute([$db_name, $tableName]);
            $tableExists = $stmt_check->fetchColumn();

            if (!$tableExists) {
                $columnsAndConstraints = array_merge($tableDef['columns'], $tableDef['constraints']);
                $createQuery = "CREATE TABLE `{$tableName}` (" . implode(', ', $columnsAndConstraints) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
                $pdo->exec($createQuery);
                $success_messages[] = "جدول `{$tableName}` با موفقیت ایجاد شد.";
            } else {
                $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
                $stmt->execute([$db_name, $tableName]);
                $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($tableDef['columns'] as $columnDef) {
                    preg_match('/`([^`]+)`/', $columnDef, $matches);
                    $columnName = $matches[1];
                    
                    if (!in_array($columnName, $existingColumns)) {
                        $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN {$columnDef}");
                        $success_messages[] = "ستون `{$columnName}` به جدول `{$tableName}` اضافه شد.";
                    }
                }
            }
        } catch (PDOException $e) {
            $error_message .= "خطا در پردازش جدول `{$tableName}`: " . $e->getMessage() . "<br>";
        }
    }
    
    // CRM: index for per-user notifications on databases created before the column existed.
    try {
        $stmt_idx = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_user'");
        $stmt_idx->execute([$db_name]);
        if (!$stmt_idx->fetchColumn()) {
            $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_user` (`user_id`)");
            $success_messages[] = "ایندکس `idx_user` به جدول `notifications` اضافه شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن ایندکس idx_user: " . $e->getMessage() . "<br>";
    }
    // میز کار: index for project budgets on existing expenses tables.
    try {
        $stmt_idx = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'expenses' AND INDEX_NAME = 'idx_expense_project'");
        $stmt_idx->execute([$db_name]);
        if (!$stmt_idx->fetchColumn()) {
            $pdo->exec("ALTER TABLE `expenses` ADD INDEX `idx_expense_project` (`project_id`)");
            $success_messages[] = "ایندکس `idx_expense_project` به جدول `expenses` اضافه شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن ایندکس idx_expense_project: " . $e->getMessage() . "<br>";
    }

    // ========== بروزرسانی‌های نسخه 2.0 ==========

    // 0. Widen person phone columns for multiple numbers (joined with " / ")
    foreach (['customers', 'suppliers', 'contacts'] as $phoneTable) {
        try {
            $stmt_phone = $pdo->prepare(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'phone'"
            );
            $stmt_phone->execute([$db_name, $phoneTable]);
            $phoneLen = $stmt_phone->fetchColumn();
            if ($phoneLen !== false && (int)$phoneLen < 255) {
                $pdo->exec(
                    "ALTER TABLE `{$phoneTable}` MODIFY COLUMN `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL"
                );
                $success_messages[] = "ستون `phone` در جدول `{$phoneTable}` به varchar(255) ارتقا یافت.";
            }
        } catch (PDOException $e) {
            $error_message .= "خطا در ارتقای phone جدول `{$phoneTable}`: " . $e->getMessage() . "<br>";
        }
    }
    
    // 1. اضافه کردن entry_date به inventory_items اگر وجود ندارد
    try {
        $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'entry_date'");
        $stmt_check->execute([$db_name]);
        if (!$stmt_check->fetchColumn()) {
            $pdo->exec("ALTER TABLE `inventory_items` ADD COLUMN `entry_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $success_messages[] = "ستون `entry_date` به جدول `inventory_items` اضافه شد (بهبود FIFO).";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن entry_date: " . $e->getMessage() . "<br>";
    }

    // 1-b. Ensure inventory_items has entity ownership information
    try {
        $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'entity_id'");
        $stmt_check->execute([$db_name]);
        if (!$stmt_check->fetchColumn()) {
            $pdo->exec("ALTER TABLE `inventory_items` ADD COLUMN `entity_id` INT(11) NOT NULL DEFAULT 1 AFTER `product_id`");
            $pdo->exec("UPDATE inventory_items ii JOIN products p ON ii.product_id = p.id SET ii.entity_id = p.entity_id");
            $pdo->exec("ALTER TABLE `inventory_items` ADD INDEX `idx_entity_status` (`entity_id`, `status`)");
            $pdo->exec("ALTER TABLE `inventory_items` ADD CONSTRAINT `fk_inv_item_entity` FOREIGN KEY (`entity_id`) REFERENCES `business_entities`(`id`) ON DELETE CASCADE");
            $success_messages[] = "ستون `entity_id` به جدول `inventory_items` اضافه و داده‌ها با مجموعه‌های تجاری تطبیق داده شد.";
        } else {
            $pdo->exec("UPDATE inventory_items ii JOIN products p ON ii.product_id = p.id SET ii.entity_id = p.entity_id WHERE ii.entity_id IS NULL OR ii.entity_id <> p.entity_id");
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن یا همگام‌سازی entity_id در inventory_items: " . $e->getMessage() . "<br>";
    }
    
    // 1-c. Add serial_id column to inventory_items for custom identifiers
    try {
        $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'serial_id'");
        $stmt_check->execute([$db_name]);
        if (!$stmt_check->fetchColumn()) {
            $pdo->exec("ALTER TABLE `inventory_items` ADD COLUMN `serial_id` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `dimensions`");
            // Initialize serial_id with auto-generated values based on id
            $pdo->exec("UPDATE inventory_items SET serial_id = CONCAT('INV-', LPAD(id, 6, '0')) WHERE serial_id IS NULL");
            $pdo->exec("ALTER TABLE `inventory_items` ADD UNIQUE INDEX `idx_serial_id_entity` (`entity_id`, `serial_id`)");
            $success_messages[] = "ستون `serial_id` به جدول `inventory_items` اضافه شد (شناسه قابل ویرایش).";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن serial_id به inventory_items: " . $e->getMessage() . "<br>";
    }
    
    // 2. اضافه کردن indexهای performance - تمام ایندکس‌ها در یک بلاک
    $all_indexes = [
        // Sales Invoices
        ['sales_invoices', 'idx_entity_date', '(`entity_id`, `date`)'],
        ['sales_invoices', 'idx_customer', '(`customerId`)'],
        ['sales_invoices', 'idx_consignment', '(`is_consignment`)'],
        // Purchase Invoices
        ['purchase_invoices', 'idx_entity_date', '(`entity_id`, `date`)'],
        ['purchase_invoices', 'idx_supplier', '(`supplierId`)'],
        ['purchase_invoices', 'idx_consignment', '(`is_consignment`)'],
        // Inventory Items
        ['inventory_items', 'idx_product_status_date', '(`product_id`, `status`, `entry_date`)'],
        ['inventory_items', 'idx_purchase_item', '(`purchase_invoice_item_id`)'],
        ['inventory_items', 'idx_sales_item', '(`sales_invoice_item_id`)'],
        ['inventory_items', 'idx_returned_from_sales', '(`returned_from_sales_item_id`)'],
        ['inventory_items', 'idx_warehouse', '(`warehouse_id`)'],
        // Payments
        ['payments', 'idx_entity_date', '(`entity_id`, `date`)'],
        ['payments', 'idx_invoice', '(`invoiceType`, `invoiceId`)'],
        ['payments', 'idx_person', '(`person_id`, `person_type`)'],
        ['payments', 'idx_account', '(`account_id`)'],
        // Checks
        ['checks', 'idx_entity_status_due', '(`entity_id`, `status`, `dueDate`)'],
        ['checks', 'idx_due_date', '(`dueDate`)'],
        ['checks', 'idx_type', '(`type`)'],
        // Expenses
        ['expenses', 'idx_entity_date', '(`entity_id`, `date`)'],
        ['expenses', 'idx_account', '(`account_id`)'],
        ['incomes', 'idx_entity_date', '(`entity_id`, `date`)'],
        ['incomes', 'idx_account', '(`account_id`)'],
        // Products
        ['products', 'idx_entity_name', '(`entity_id`, `name`)'],
        ['products', 'idx_collection', '(`collection_id`)'],
        // Accounts
        ['accounts', 'idx_entity_id', '(`entity_id`)'],
        ['accounts', 'idx_partner', '(`partner_id`)'],
        // Customers
        ['customers', 'idx_entity_name', '(`entity_id`, `name`)'],
        // Suppliers
        ['suppliers', 'idx_entity_name', '(`entity_id`, `name`)'],
        // Contacts (سایر اشخاص)
        ['contacts', 'idx_entity_name', '(`entity_id`, `name`)'],
        // Activity Log
        ['activity_log', 'idx_timestamp', '(`timestamp`)'],
        ['activity_log', 'idx_user', '(`user_id`)'],
        // Quotations
        ['quotations', 'idx_valid_until', '(`valid_until`)'],
        ['quotations', 'idx_entity_status', '(`entity_id`, `status`)'],
        // Return Invoices
        ['return_invoices', 'idx_entity_date', '(`entity_id`, `date`)'],
        ['return_invoices', 'idx_type_date', '(`type`, `date`)'],
        ['return_invoices', 'idx_original_invoice', '(`original_invoice_id`)'],
        ['return_invoices', 'idx_person', '(`person_id`, `person_type`)'],
    ];
    
    $index_count = 0;
    foreach ($all_indexes as $idx) {
        try {
            $pdo->exec("ALTER TABLE `{$idx[0]}` ADD INDEX `{$idx[1]}` {$idx[2]}");
            $index_count++;
        } catch (PDOException $e) {
            // Index از قبل وجود دارد یا ستون وجود ندارد - مشکلی نیست
        }
    }
    
    if ($index_count > 0) {
        $success_messages[] = "تعداد {$index_count} index جدید برای بهبود عملکرد اضافه شد.";
    }
    
    // ========== بروزرسانی‌های سازگاری با سرور (v2.1) ==========
    
    // 3. اضافه کردن 'partner' به person_type در return_invoices
    try {
        $stmt_check = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'return_invoices' AND COLUMN_NAME = 'person_type'");
        $stmt_check->execute([$db_name]);
        $column_type = $stmt_check->fetchColumn();
        
        if ($column_type && strpos($column_type, 'partner') === false) {
            $pdo->exec("ALTER TABLE `return_invoices` MODIFY COLUMN `person_type` ENUM('customer','supplier','partner') COLLATE utf8mb4_unicode_ci NOT NULL");
            $success_messages[] = "ستون `person_type` در جدول `return_invoices` بروزرسانی شد (افزودن 'partner').";
        }
    } catch (PDOException $e) {
        // اگر جدول وجود ندارد یا قبلاً به‌روز شده، خطایی نیست
    }
    
    // 4. پاکسازی داده‌های ناسازگار (orphaned records) - فقط ردیف‌های محصولی (غیر خدماتی)
    try {
        $pdo->exec("DELETE ii FROM inventory_items ii LEFT JOIN products p ON ii.product_id = p.id WHERE p.id IS NULL");
        // NOTE: We intentionally no longer delete orphaned sales_invoice_items /
        // purchase_invoice_items rows here. Removing invoice line items silently
        // corrupts invoice totals (totalAmount/paidAmount no longer match the
        // remaining lines) without any recalculation. Only inventory_items
        // orphans (which carry no monetary total) are cleaned up.
    } catch (PDOException $e) {
        // foreign key constraint وجود دارد - داده‌ها سازگار هستند
    }

    // 4-b. بروزرسانی ستون productId برای پشتیبانی از آیتم‌های خدماتی
    try {
        foreach (['sales_invoice_items', 'purchase_invoice_items'] as $tbl) {
            $stmt_check = $pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'productId'");
            $stmt_check->execute([$db_name, $tbl]);
            $nullable = $stmt_check->fetchColumn();
            if ($nullable === 'NO') {
                $pdo->exec("ALTER TABLE `{$tbl}` MODIFY COLUMN `productId` int(11) DEFAULT NULL");
                $success_messages[] = "ستون productId در جدول {$tbl} قابل NULL شد (پشتیبانی آیتم خدماتی).";
            }
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در بروزرسانی productId: " . $e->getMessage() . "<br>";
    }

    // 4-c. بروزرسانی ستون dimensions برای پشتیبانی از آیتم‌های خدماتی
    try {
        foreach (['sales_invoice_items', 'purchase_invoice_items'] as $tbl) {
            $stmt_check = $pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'dimensions'");
            $stmt_check->execute([$db_name, $tbl]);
            $nullable = $stmt_check->fetchColumn();
            if ($nullable === 'NO') {
                $pdo->exec("ALTER TABLE `{$tbl}` MODIFY COLUMN `dimensions` varchar(50) DEFAULT NULL");
                $success_messages[] = "ستون dimensions در جدول {$tbl} قابل NULL شد (پشتیبانی آیتم خدماتی).";
            }
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در بروزرسانی dimensions: " . $e->getMessage() . "<br>";
    }

    // 5. تنظیم AUTO_INCREMENT برای جداول خالی
    try {
        $tables_to_optimize = ['return_invoices', 'return_invoice_items', 'checks', 'inventory_items'];
        foreach ($tables_to_optimize as $table) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}`");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            if ($count == 0) {
                $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            }
        }
    } catch (PDOException $e) {
        // برخی جداول ممکن است وجود نداشته باشند
    }

    // 6. اضافه کردن delivery_status به فاکتورهای فروش و خرید
    try {
        foreach (['sales_invoices', 'purchase_invoices'] as $tbl) {
            $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'delivery_status'");
            $stmt_check->execute([$db_name, $tbl]);
            if (!$stmt_check->fetchColumn()) {
                $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `delivery_status` ENUM('preparing','ready','delivered') COLLATE utf8mb4_unicode_ci DEFAULT 'preparing'");
                $success_messages[] = "ستون `delivery_status` به جدول `{$tbl}` اضافه شد (وضعیت تحویل).";
            }
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن delivery_status: " . $e->getMessage() . "<br>";
    }

    // 6-a2. نمایش انبار روی داشبورد
    try {
        $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'warehouses' AND COLUMN_NAME = 'show_on_dashboard'");
        $stmt_check->execute([$db_name]);
        if (!$stmt_check->fetchColumn()) {
            $pdo->exec("ALTER TABLE `warehouses` ADD COLUMN `show_on_dashboard` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`");
            $success_messages[] = "ستون show_on_dashboard به جدول warehouses اضافه شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن show_on_dashboard به warehouses: " . $e->getMessage() . "<br>";
    }

    // 6-a3. نوع انبار: فیزیکی یا سفارش
    try {
        $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'warehouses' AND COLUMN_NAME = 'warehouse_type'");
        $stmt_check->execute([$db_name]);
        if (!$stmt_check->fetchColumn()) {
            $pdo->exec("ALTER TABLE `warehouses` ADD COLUMN `warehouse_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'physical' AFTER `show_on_dashboard`");
            $success_messages[] = "ستون warehouse_type به جدول warehouses اضافه شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن warehouse_type به warehouses: " . $e->getMessage() . "<br>";
    }

    // 6-b. انبار مقصد روی فاکتور خرید
    try {
        $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'purchase_invoices' AND COLUMN_NAME = 'warehouse_id'");
        $stmt_check->execute([$db_name]);
        if (!$stmt_check->fetchColumn()) {
            $pdo->exec("ALTER TABLE `purchase_invoices` ADD COLUMN `warehouse_id` int(11) DEFAULT NULL AFTER `invoice_number`");
            $pdo->exec("ALTER TABLE `purchase_invoices` ADD KEY `idx_purchase_warehouse` (`warehouse_id`)");
            $success_messages[] = "ستون warehouse_id به جدول purchase_invoices اضافه شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن warehouse_id به purchase_invoices: " . $e->getMessage() . "<br>";
    }

    // 6-c. انبار در ردیف‌های انبارگردانی
    try {
        $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'stocktaking_items' AND COLUMN_NAME = 'warehouse_id'");
        $stmt_check->execute([$db_name]);
        if (!$stmt_check->fetchColumn()) {
            $pdo->exec("ALTER TABLE `stocktaking_items` ADD COLUMN `warehouse_id` int(11) DEFAULT NULL AFTER `dimensions`");
            $pdo->exec("ALTER TABLE `stocktaking_items` ADD KEY `idx_warehouse` (`warehouse_id`)");
            $success_messages[] = "ستون warehouse_id به جدول stocktaking_items اضافه شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن warehouse_id به stocktaking_items: " . $e->getMessage() . "<br>";
    }

    // 7. پر کردن شناسه انبار (serial_id) برای آیتم‌هایی که ندارند
    try {
        $stmt_null = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE serial_id IS NULL");
        $null_count = $stmt_null->fetchColumn();
        if ($null_count > 0) {
            $pdo->exec("UPDATE inventory_items SET serial_id = CONCAT('INV-', LPAD(id, 6, '0')) WHERE serial_id IS NULL");
            $success_messages[] = "شناسه انبار (serial_id) برای {$null_count} آیتم موجودی به صورت خودکار تولید شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در تولید serial_id: " . $e->getMessage() . "<br>";
    }

    // 9. فیلدهای جدید چک: صادرکننده و تاریخ وصول/پاس
    try {
        $checkCols = [
            'issuerName' => "ALTER TABLE `checks` ADD COLUMN `issuerName` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `bankName`",
            'cashed_date' => "ALTER TABLE `checks` ADD COLUMN `cashed_date` date DEFAULT NULL AFTER `cashed_in_account_id`",
        ];
        foreach ($checkCols as $col => $sql) {
            $stmt_check = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'checks' AND COLUMN_NAME = ?");
            $stmt_check->execute([$db_name, $col]);
            if (!$stmt_check->fetchColumn()) {
                $pdo->exec($sql);
                $success_messages[] = "ستون `{$col}` به جدول `checks` اضافه شد.";
            }
        }
        try {
            $pdo->exec("ALTER TABLE `checks` ADD INDEX `cashed_date` (`cashed_date`)");
        } catch (PDOException $e) { /* index may exist */ }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن فیلدهای چک: " . $e->getMessage() . "<br>";
    }

    // 9b. طرف حساب روی هزینه و درآمد (لیست تراکنش‌ها به این ستون‌ها وابسته است)
    try {
        $partyCols = [
            'expenses' => [
                'person_id' => "ALTER TABLE `expenses` ADD COLUMN `person_id` int(11) DEFAULT NULL AFTER `account_id`",
                'person_type' => "ALTER TABLE `expenses` ADD COLUMN `person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `person_id`",
            ],
            'incomes' => [
                'person_id' => "ALTER TABLE `incomes` ADD COLUMN `person_id` int(11) DEFAULT NULL AFTER `account_id`",
                'person_type' => "ALTER TABLE `incomes` ADD COLUMN `person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `person_id`",
            ],
        ];
        foreach ($partyCols as $table => $cols) {
            foreach ($cols as $col => $sql) {
                $stmt_col = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $stmt_col->execute([$db_name, $table, $col]);
                if (!$stmt_col->fetchColumn()) {
                    $pdo->exec($sql);
                    $success_messages[] = "ستون `{$col}` به جدول `{$table}` اضافه شد.";
                }
            }
            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `idx_{$table}_person` (`entity_id`, `person_type`, `person_id`)");
            } catch (PDOException $e) { /* index may exist */ }
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن طرف حساب هزینه/درآمد: " . $e->getMessage() . "<br>";
    }

    // 10. رفع دوباره‌کاهی مانده حساب شخصی شریک هنگام وصول چک
    // فقط برای وصول‌هایی که در activity_log ثبت شده‌اند (مسیر cashCheck با آپدیت مانده شریک).
    try {
        $stmt_flag = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'fix_partner_check_cash_double_count_v1' LIMIT 1");
        $stmt_flag->execute();
        $alreadyFixed = $stmt_flag->fetchColumn();
        if (!$alreadyFixed) {
            $fixedCount = 0;
            $logs = $pdo->query("SELECT description FROM activity_log WHERE action_type = 'CASH_CHECK'")->fetchAll(PDO::FETCH_COLUMN);
            $upd = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ? AND entity_id = ?");

            foreach ($logs as $desc) {
                if (!preg_match('/مبلغ\s+([0-9]+(?:\.[0-9]+)?).*حساب\s+(\d+)/u', $desc, $m)) {
                    continue;
                }
                $amount = (float)$m[1];
                $bankAccountId = (int)$m[2];

                $stmt = $pdo->prepare("
                    SELECT c.id, c.amount, p.person_id, c.entity_id, a.id AS partner_account_id
                    FROM checks c
                    JOIN payments p ON p.checkId = c.id AND p.type = 'check' AND p.entity_id = c.entity_id
                    JOIN accounts a ON a.partner_id = p.person_id AND a.entity_id = c.entity_id
                    WHERE c.type = 'received' AND c.status = 'cashed'
                      AND c.cashed_in_account_id = ?
                      AND c.amount = ?
                      AND p.person_type = 'partner' AND p.person_id IS NOT NULL
                    LIMIT 1
                ");
                $stmt->execute([$bankAccountId, $amount]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $upd->execute([(float)$row['amount'], (int)$row['partner_account_id'], (int)$row['entity_id']]);
                    $fixedCount++;
                }
            }

            $clearLogs = $pdo->query("SELECT description FROM activity_log WHERE action_type = 'CLEAR_CHECK'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($clearLogs as $desc) {
                if (!preg_match('/مبلغ\s+([0-9]+(?:\.[0-9]+)?).*حساب\s+(\d+)/u', $desc, $m)) {
                    continue;
                }
                $amount = (float)$m[1];
                $bankAccountId = (int)$m[2];
                $stmt = $pdo->prepare("
                    SELECT c.id, c.amount, p.person_id, c.entity_id, a.id AS partner_account_id
                    FROM checks c
                    JOIN payments p ON p.checkId = c.id AND p.type = 'check' AND p.entity_id = c.entity_id
                    JOIN accounts a ON a.partner_id = p.person_id AND a.entity_id = c.entity_id
                    WHERE c.type = 'payable' AND c.status = 'cashed'
                      AND c.cashed_in_account_id = ?
                      AND c.amount = ?
                      AND p.person_type = 'partner' AND p.person_id IS NOT NULL
                    LIMIT 1
                ");
                $stmt->execute([$bankAccountId, $amount]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $upd->execute([-(float)$row['amount'], (int)$row['partner_account_id'], (int)$row['entity_id']]);
                    $fixedCount++;
                }
            }

            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('fix_partner_check_cash_double_count_v1', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([date('Y-m-d H:i:s') . "|count={$fixedCount}"]);
            if ($fixedCount > 0) {
                $success_messages[] = "مغایرت مانده حساب شخصی بابت وصول/پاس چک شریک برای {$fixedCount} مورد اصلاح شد.";
            }
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در اصلاح مغایرت چک شریک: " . $e->getMessage() . "<br>";
    }

    // 8. رفع تکراری بودن serial_id و افزودن ایندکس یکتا
    try {
        $pdo->exec("
            UPDATE inventory_items ii
            JOIN (
                SELECT entity_id, serial_id, MIN(id) AS keep_id
                FROM inventory_items
                WHERE serial_id IS NOT NULL AND serial_id != ''
                GROUP BY entity_id, serial_id
                HAVING COUNT(*) > 1
            ) dups ON ii.entity_id = dups.entity_id AND ii.serial_id = dups.serial_id AND ii.id != dups.keep_id
            SET ii.serial_id = CONCAT('INV-', LPAD(ii.id, 6, '0'))
        ");
        $stmt_dup = $pdo->query("SELECT COUNT(*) FROM (
            SELECT entity_id, serial_id FROM inventory_items
            WHERE serial_id IS NOT NULL AND serial_id != ''
            GROUP BY entity_id, serial_id HAVING COUNT(*) > 1
        ) t");
        if ((int)$stmt_dup->fetchColumn() === 0) {
            $stmt_idx = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'inventory_items' AND INDEX_NAME = 'idx_serial_id_entity'");
            $stmt_idx->execute([$db_name]);
            if (!$stmt_idx->fetchColumn()) {
                $pdo->exec("ALTER TABLE `inventory_items` ADD UNIQUE INDEX `idx_serial_id_entity` (`entity_id`, `serial_id`)");
                $success_messages[] = "ایندکس یکتای شناسه انبار (serial_id) اضافه شد.";
            }
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در یکتا کردن serial_id: " . $e->getMessage() . "<br>";
    }

    // 9. بستن حساب: UNIQUE فقط روی completed تا close→reopen→close→reopen همان پایان‌دوره ممکن باشد
    try {
        $stmt_ac = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'account_closings'"
        );
        $stmt_ac->execute([$db_name]);
        if ($stmt_ac->fetchColumn()) {
            $stmt_col = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'account_closings' AND COLUMN_NAME = 'completed_end_key'"
            );
            $stmt_col->execute([$db_name]);
            $hasGeneratedKey = (bool)$stmt_col->fetchColumn();

            // Detect legacy unique on (entity_id, period_end_date, status)
            $stmt_legacy = $pdo->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'account_closings'
                   AND INDEX_NAME = 'uq_closing_entity_completed_end'
                 ORDER BY SEQ_IN_INDEX"
            );
            $stmt_legacy->execute([$db_name]);
            $uqCols = $stmt_legacy->fetchAll(PDO::FETCH_COLUMN);
            $isLegacyUnique = $uqCols === ['entity_id', 'period_end_date', 'status'];
            $hasCorrectUnique = $uqCols === ['entity_id', 'completed_end_key'];

            if ($isLegacyUnique || (!$hasCorrectUnique && !empty($uqCols))) {
                $pdo->exec("ALTER TABLE `account_closings` DROP INDEX `uq_closing_entity_completed_end`");
                $uqCols = [];
            }

            if (!$hasGeneratedKey) {
                $pdo->exec(
                    "ALTER TABLE `account_closings`
                     ADD COLUMN `completed_end_key` date
                     GENERATED ALWAYS AS (IF(`status` = 'completed', `period_end_date`, NULL)) STORED"
                );
                $success_messages[] = "ستون `completed_end_key` به جدول `account_closings` اضافه شد.";
            }

            if (empty($uqCols) || !$hasCorrectUnique) {
                $stmt_recheck = $pdo->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'account_closings'
                       AND INDEX_NAME = 'uq_closing_entity_completed_end' LIMIT 1"
                );
                $stmt_recheck->execute([$db_name]);
                if (!$stmt_recheck->fetchColumn()) {
                    $pdo->exec(
                        "ALTER TABLE `account_closings`
                         ADD UNIQUE KEY `uq_closing_entity_completed_end` (`entity_id`, `completed_end_key`)"
                    );
                    $success_messages[] = "ایندکس یکتای بستن حساب اصلاح شد (فقط دوره‌های completed).";
                }
            }
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در اصلاح ایندکس بستن حساب: " . $e->getMessage() . "<br>";
    }

    // 10. پر کردن cashed_date خالی برای چک‌های وصول/پاس‌شده (داده قدیمی قبل از افزودن ستون)
    try {
        $stmt_chk = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'checks' AND COLUMN_NAME = 'cashed_date'"
        );
        $stmt_chk->execute([$db_name]);
        if ($stmt_chk->fetchColumn()) {
            $fixedCashDates = $pdo->exec(
                "UPDATE checks
                 SET cashed_date = dueDate
                 WHERE status = 'cashed'
                   AND cashed_date IS NULL
                   AND dueDate IS NOT NULL"
            );
            if ($fixedCashDates) {
                $success_messages[] = "تاریخ وصول {$fixedCashDates} چک خالی با تاریخ سررسید پر شد.";
            }
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در پر کردن cashed_date: " . $e->getMessage() . "<br>";
    }

    apply_schema_patches($pdo, $db_name, $success_messages, $error_message);
}

/**
 * Idempotent structural patches that synchronize_schema cannot express
 * (enum extensions, extra indexes). Safe to run repeatedly.
 */
function apply_schema_patches($pdo, $db_name, &$success_messages, &$error_message) {
    $colType = function ($table, $col) use ($pdo, $db_name) {
        $st = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$db_name, $table, $col]);
        return $st->fetchColumn();
    };
    try {
        $t = $colType('payments', 'type');
        if ($t && strpos($t, 'setoff') === false) {
            $pdo->exec("ALTER TABLE `payments` MODIFY COLUMN `type` enum('cash','check','endorse_check','check_bounce','setoff') COLLATE utf8mb4_unicode_ci NOT NULL");
            $success_messages[] = "نوع تراکنش «تهاتر / پرداخت به جای» به جدول payments افزوده شد.";
        } elseif ($t && strpos($t, 'check_bounce') === false) {
            $pdo->exec("ALTER TABLE `payments` MODIFY COLUMN `type` enum('cash','check','endorse_check','check_bounce') COLLATE utf8mb4_unicode_ci NOT NULL");
            $success_messages[] = "نوع تراکنش «برگشت چک» به جدول payments افزوده شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن نوع تهاتر/برگشت چک: " . $e->getMessage() . "<br>";
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'setoff_group_id'");
        $st->execute([$db_name]);
        if ((int)$st->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `payments` ADD COLUMN `setoff_group_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `reversal_of_payment_id`");
            $pdo->exec("ALTER TABLE `payments` ADD INDEX `idx_setoff_group` (`setoff_group_id`)");
            $success_messages[] = "ستون setoff_group_id برای تهاتر افزوده شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن setoff_group_id: " . $e->getMessage() . "<br>";
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'transfer_group_id'");
        $st->execute([$db_name]);
        if ((int)$st->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `payments` ADD COLUMN `transfer_group_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `setoff_group_id`");
            $pdo->exec("ALTER TABLE `payments` ADD INDEX `idx_transfer_group` (`transfer_group_id`)");
            $success_messages[] = "ستون transfer_group_id برای راس‌گیری/انتقال گروهی چک افزوده شد.";
        }
    } catch (PDOException $e) {
        $error_message .= "خطا در افزودن transfer_group_id: " . $e->getMessage() . "<br>";
    }
    foreach (['payments', 'checks'] as $tbl) {
        try {
            $t = $colType($tbl, 'invoiceType');
            if ($t && strpos($t, 'asset_sale') === false) {
                $pdo->exec("ALTER TABLE `{$tbl}` MODIFY COLUMN `invoiceType` enum('sales','purchase','return','asset_purchase','asset_sale') COLLATE utf8mb4_unicode_ci DEFAULT NULL");
                $success_messages[] = "نوع سند دارایی به ستون invoiceType جدول {$tbl} افزوده شد.";
            }
        } catch (PDOException $e) {
            $error_message .= "خطا در افزودن نوع سند دارایی به {$tbl}: " . $e->getMessage() . "<br>";
        }
    }
    foreach ([
        ['payments', 'idx_installment', '(`installment_id`)'],
        ['payments', 'idx_reversal', '(`reversal_of_payment_id`)'],
        ['payments', 'idx_setoff_group', '(`setoff_group_id`)'],
        ['payments', 'idx_transfer_group', '(`transfer_group_id`)'],
        ['inventory_items', 'idx_virtual', '(`is_virtual`)'],
        ['sales_invoices', 'idx_salesperson', '(`salesperson_id`)'],
        ['sales_invoices', 'idx_entity_invoice_number', '(`entity_id`, `invoice_number`)'],
        ['purchase_invoices', 'idx_entity_invoice_number', '(`entity_id`, `invoice_number`)'],
    ] as $idx) {
        try {
            $pdo->exec("ALTER TABLE `{$idx[0]}` ADD INDEX `{$idx[1]}` {$idx[2]}");
        } catch (PDOException $e) { /* already exists */ }
    }
    // A supplier is the twin of at most one customer (person both buys and sells).
    try {
        $pdo->exec("ALTER TABLE `customers` ADD UNIQUE KEY `uk_linked_supplier` (`linked_supplier_id`)");
    } catch (PDOException $e) { /* already exists */ }
}

/**
 * دسترسی‌ها و مشخصات پیش‌فرض را در دیتابیس ثبت می‌کند
 */
function seed_default_data($pdo, &$success_messages) {
    // Permissions (remember which keys already existed: related-role grants
    // below apply only to permissions introduced by this upgrade)
    $existingPermissionKeys = [];
    try {
        $existingPermissionKeys = $pdo->query("SELECT permission_key FROM `permissions`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        // fresh install: table not there yet
    }
    $permissions = get_default_permissions();
    $stmt = $pdo->prepare("INSERT INTO `permissions` (permission_key, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description)");
    foreach ($permissions as $key => $description) {
        $stmt->execute([$key, $description]);
    }
    $success_messages[] = "دسترسی‌های پیش‌فرض برنامه ثبت و بروزرسانی شدند.";
    // System roles (مدیر کل) always hold every permission, including ones added later.
    try {
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.is_system = 1");
    } catch (PDOException $e) {
        // roles.is_system missing on very old databases: nothing to do
    }

    // Attribute Types
    $attribute_types = get_default_attribute_types();
    $stmt_attr = $pdo->prepare("INSERT IGNORE INTO `product_attribute_types` (name, applicable_to) VALUES (?, ?)");
    foreach ($attribute_types as $attr) {
        $stmt_attr->execute([$attr['name'], $attr['applicable_to']]);
    }
    $success_messages[] = "انواع مشخصات پیش‌فرض برای محصولات ثبت شدند.";

    // Seed default settings if they don't exist
    $default_settings = [
        'standard_dimensions' => 'پادری,1x1.5,1.5x2,1x2,1x3,1x4,1.5x2.25,1.7x2.4,2x3,2.5x3.5,3x4',
        'expense_categories' => 'اجاره,حقوق و دستمزد,قبوض (آب، برق، گاز، تلفن),حمل و نقل,ملزومات اداری و فروشگاه,تبلیغات و بازاریابی,پذیرایی و تشریفات,تعمیر و نگهداری,نظافت و خدمات,متفرقه',
        'income_categories' => 'سود سپرده بانکی,برگشت کارمزد,اجاره دریافتی,سایر درآمدها,متفرقه',
        // Localization / business-rule defaults (match historical behavior)
        'currency_label' => 'تومان',
        'currency_rial_divisor' => '10',
        'default_unit' => 'عدد',
        'dimension_cm_threshold' => '15',
        'dimension_area_map' => '{"پادری":0.4}',
        'ar_aging_buckets' => '30,60,90',
        'check_discount_days_per_month' => '30',
        'share_link_ttl_hours' => '168',
        'invoice_price_history_enabled' => '1',
        'invoice_price_history_similar' => '1',
        'invoice_price_history_person_prefer' => '1',
        'invoice_price_history_limit' => '3',
        'print_template' => 'classic',
    ];

    $stmt_insert_setting = $pdo->prepare("INSERT IGNORE INTO `settings` (setting_key, setting_value) VALUES (?, ?)");
    foreach ($default_settings as $key => $value) {
        $stmt_insert_setting->execute([$key, $value]);
    }
     $success_messages[] = "تنظیمات پیش‌فرض (ابعاد و دسته‌بندی هزینه‌ها) ثبت شدند.";

    // Grant manage_contacts to roles that have manage_customers — only while the
    // permission is new (no ordinary role holds it yet). Running it on every
    // upgrade used to undo an administrator who removed it from a role on purpose.
    try {
        $granted = grant_new_permission_like($pdo, 'manage_contacts', 'manage_customers', $existingPermissionKeys);
        if ($granted) {
            $success_messages[] = "مجوز مدیریت سایر اشخاص به نقش‌های دارای مدیریت مشتریان اعطا شد.";
        }
    } catch (PDOException $e) {
        // non-fatal
    }

    // Grant manage_assets to roles that manage accounts — same "only while new" rule.
    try {
        $granted = grant_new_permission_like($pdo, 'manage_assets', 'manage_accounts', $existingPermissionKeys);
        if ($granted) {
            $success_messages[] = "مجوز مدیریت دارایی‌ها به نقش‌های دارای مدیریت حساب‌ها اعطا شد.";
        }
    } catch (PDOException $e) {
        // non-fatal
    }

    // CRM: whoever manages customers uses the CRM; whoever manages settings manages the team.
    try {
        $granted = grant_new_permission_like($pdo, 'use_crm', 'manage_customers', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'manage_crm_team', 'manage_settings', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'use_projects', 'use_crm', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'manage_projects', 'manage_settings', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'use_messenger', 'use_projects', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'use_cartable', 'use_projects', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'use_ideas', 'use_projects', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'approve_payments', 'manage_settings', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'pay_payments', 'manage_settings', $existingPermissionKeys)
            + grant_new_permission_like($pdo, 'manage_hr', 'manage_settings', $existingPermissionKeys);
        if ($granted) {
            $success_messages[] = "مجوزهای CRM به نقش‌های مرتبط اعطا شد.";
        }
    } catch (PDOException $e) {
        // non-fatal
    }
}

/**
 * Give a newly introduced permission to the roles that hold a related one.
 * Only on the upgrade that creates the permission; afterwards the roles are
 * whatever the administrator set in «کنترل دسترسی» (an upgrade must never
 * re-add a permission that was removed on purpose).
 * Returns the number of grants.
 */
function grant_new_permission_like($pdo, $newKey, $likeKey, array $existingKeys) {
    if (in_array($newKey, $existingKeys, true)) {
        return 0;
    }
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT rp.role_id, p_new.id
         FROM role_permissions rp
         JOIN permissions p_old ON p_old.id = rp.permission_id AND p_old.permission_key = ?
         JOIN permissions p_new ON p_new.permission_key = ?"
    );
    $ins->execute([$likeKey, $newKey]);
    return $ins->rowCount();
}

/**
 * One-off data migrations that must run exactly once per database.
 * Each step is guarded by a settings flag so re-running is a no-op.
 */
function run_versioned_data_migrations($pdo, $db_name, &$success_messages) {
    $steps = get_versioned_data_migrations();
    $flagStmt = $pdo->prepare("SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1");
    $setFlag = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    foreach ($steps as $key => $fn) {
        $flag = 'data_migration_' . $key;
        try {
            $flagStmt->execute([$flag]);
            if ($flagStmt->fetchColumn()) {
                continue;
            }
            $note = $fn($pdo, $db_name);
            $setFlag->execute([$flag, date('Y-m-d H:i:s') . ($note ? ' | ' . $note : '')]);
            if ($note) {
                $success_messages[] = $note;
            }
        } catch (Throwable $e) {
            error_log("Data migration {$key} failed: " . $e->getMessage());
        }
    }
}

/**
 * @return array<string, callable(PDO, string): ?string>
 */
function get_versioned_data_migrations() {
    return [
        // Virtual items are the placeholders created when a sale exceeds stock.
        // Before is_virtual existed they were only recognizable heuristically:
        // no purchase link, no stocktaking note, auto serial, created on/after
        // the sale date. Flagging them lets invoice edits delete them instead of
        // turning them into phantom in-stock items.
        'flag_virtual_inventory_v1' => function ($pdo, $db_name) {
            $n = $pdo->exec(
                "UPDATE inventory_items ii
                 JOIN sales_invoice_items sii ON ii.sales_invoice_item_id = sii.id
                 JOIN sales_invoices si ON sii.invoiceId = si.id
                 SET ii.is_virtual = 1
                 WHERE ii.purchase_invoice_item_id IS NULL
                   AND (ii.notes IS NULL OR ii.notes = '')
                   AND ii.status IN ('sold', 'consignment_out')
                   AND ii.returned_from_sales_item_id IS NULL
                   AND ii.serial_id = CONCAT('INV-', LPAD(ii.id, 6, '0'))
                   AND DATE(ii.entry_date) >= si.date"
            );
            return $n ? "{$n} کالای مجازی (فروش بیش از موجودی) علامت‌گذاری شد." : null;
        },
        // The per-entity invoice sequence (v2.1.0) gave invoices numbers 1, 2, 3...
        // while the lists show their own number (id). Those generated numbers are
        // cleared so every screen and printout shows the same number again.
        // Only digit-only numbers within each entity's issued range are touched;
        // numbers typed by people (text, or beyond the sequence) stay.
        'invoice_numbers_back_to_id_v1' => function ($pdo, $db_name) {
            $has = $pdo->query("SHOW TABLES LIKE 'document_sequences'")->fetch();
            if (!$has) {
                return null;
            }
            $total = 0;
            foreach (['sales' => 'sales_invoices', 'purchase' => 'purchase_invoices'] as $type => $table) {
                $seqs = $pdo->prepare("SELECT entity_id, last_number FROM document_sequences WHERE doc_type = ?");
                $seqs->execute([$type . '_invoice']);
                foreach ($seqs->fetchAll(PDO::FETCH_ASSOC) as $seq) {
                    $upd = $pdo->prepare(
                        "UPDATE `{$table}` SET invoice_number = NULL
                         WHERE entity_id = ? AND invoice_number REGEXP '^[0-9]+$'
                           AND CAST(invoice_number AS UNSIGNED) BETWEEN 1 AND ?
                           AND CAST(invoice_number AS UNSIGNED) <> id"
                    );
                    $upd->execute([(int)$seq['entity_id'], (int)$seq['last_number']]);
                    $total += $upd->rowCount();
                }
            }
            $pdo->exec("DELETE FROM document_sequences WHERE doc_type IN ('sales_invoice', 'purchase_invoice')");
            return $total ? "{$total} شماره فاکتورِ خودکار پاک شد؛ این فاکتورها دوباره با شماره خودشان نمایش داده می‌شوند." : null;
        },
        // New input has < and > replaced by ‹ › (stored-XSS guard). Apply the
        // same to display text already in the database.
        'neutralize_markup_text_v1' => function ($pdo, $db_name) {
            $cols = $pdo->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns
                 WHERE table_schema = ? AND DATA_TYPE IN ('varchar','char','text','tinytext','mediumtext')
                   AND COLUMN_NAME REGEXP 'name|description|notes?$|address|title|reason|memo|comment|label|dimensions|serial|city|job|bank|branch|phone|email|category|reference'
                   AND TABLE_NAME NOT IN ('api_tokens','app_settings','integration_settings')"
            );
            $cols->execute([$db_name]);
            $total = 0;
            foreach ($cols->fetchAll(PDO::FETCH_NUM) as [$table, $col]) {
                $total += (int)$pdo->exec(
                    "UPDATE `{$table}` SET `{$col}` = REPLACE(REPLACE(`{$col}`, '<', '‹'), '>', '›')
                     WHERE `{$col}` LIKE '%<%' OR `{$col}` LIKE '%>%'"
                );
            }
            return $total ? "{$total} مقدار متنی دارای < یا > ایمن‌سازی شد." : null;
        },
    ];
}
