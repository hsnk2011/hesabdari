<?php
// /setup.php (نسخه هوشمند برای نصب و بروزرسانی)
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$step = $_GET['step'] ?? 1;
$config_file_path = __DIR__ . '/api/config.php';
$error_message = '';
$success_messages = [];

if (file_exists($config_file_path) && $step != 3) {
    $step = 3; 
}

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
        'activity_log' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`user_id` int(11) DEFAULT NULL',
                '`timestamp` timestamp NOT NULL DEFAULT current_timestamp()',
                '`username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`action_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => ['PRIMARY KEY (`id`)']
        ],
        'customers' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`nationalId` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`initial_balance` decimal(15,2) DEFAULT 0.00',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => ['PRIMARY KEY (`id`)']
        ],
        'suppliers' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`economicCode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`initial_balance` decimal(15,2) DEFAULT 0.00',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => ['PRIMARY KEY (`id`)']
        ],
        'products' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`type` enum(\'machine\',\'handmade\') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'machine\'',
                '`material` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`collection` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`design_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`colorway` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`shaneh` int(11) DEFAULT NULL',
                '`density` int(11) DEFAULT NULL',
                '`pile_height_mm` decimal(5,2) DEFAULT NULL',
                '`origin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`raj` int(11) DEFAULT NULL',
                '`length_cm` int(11) DEFAULT NULL',
                '`width_cm` int(11) DEFAULT NULL',
                '`parent_product_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `parent_product_id` (`parent_product_id`)'
            ]
        ],
        'partners' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`share` decimal(5,4) NOT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => ['PRIMARY KEY (`id`)']
        ],
        'accounts' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`bank_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`account_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`card_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`current_balance` decimal(15,2) NOT NULL DEFAULT 0.00',
                '`is_cash` tinyint(1) NOT NULL DEFAULT 0',
                '`partner_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `partner_id` (`partner_id`)',
                'CONSTRAINT `fk_account_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
            ]
        ],
        'product_stock' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`product_id` int(11) NOT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`quantity` int(11) NOT NULL DEFAULT 0',
                '`warehouse_id` int(11) DEFAULT NULL',
                '`bin_location` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_product_dimension_warehouse` (`product_id`,`dimensions`,`warehouse_id`)',
                'CONSTRAINT `product_stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE',
            ]
        ],
        'sales_invoices' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`customerId` int(11) NOT NULL',
                '`date` date NOT NULL',
                '`totalAmount` decimal(15,2) NOT NULL',
                '`discount` decimal(15,2) DEFAULT 0.00',
                '`paidAmount` decimal(15,2) DEFAULT 0.00',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_consignment` tinyint(1) DEFAULT 0',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `customerId` (`customerId`)',
                'KEY `date` (`date`)',
                'CONSTRAINT `sales_invoices_ibfk_1` FOREIGN KEY (`customerId`) REFERENCES `customers` (`id`) ON DELETE RESTRICT',
            ]
        ],
        'purchase_invoices' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`supplierId` int(11) NOT NULL',
                '`date` date NOT NULL',
                '`totalAmount` decimal(15,2) NOT NULL',
                '`discount` decimal(15,2) DEFAULT 0.00',
                '`paidAmount` decimal(15,2) DEFAULT 0.00',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`is_consignment` tinyint(1) DEFAULT 0',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `supplierId` (`supplierId`)',
                'KEY `date` (`date`)',
                'CONSTRAINT `purchase_invoices_ibfk_1` FOREIGN KEY (`supplierId`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT',
            ]
        ],
        'sales_invoice_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`invoiceId` int(11) NOT NULL',
                '`productId` int(11) NOT NULL',
                '`quantity` int(11) NOT NULL',
                '`unitPrice` decimal(15,2) NOT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_invoice_product_dims` (`invoiceId`,`productId`,`dimensions`)',
                'KEY `invoiceId` (`invoiceId`)',
                'CONSTRAINT `sales_invoice_items_ibfk_1` FOREIGN KEY (`invoiceId`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE',
            ]
        ],
        'purchase_invoice_items' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`invoiceId` int(11) NOT NULL',
                '`productId` int(11) NOT NULL',
                '`quantity` int(11) NOT NULL',
                '`unitPrice` decimal(15,2) NOT NULL',
                '`dimensions` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `uk_invoice_product_dims` (`invoiceId`,`productId`,`dimensions`)',
                'KEY `invoiceId` (`invoiceId`)',
                'CONSTRAINT `purchase_invoice_items_ibfk_1` FOREIGN KEY (`invoiceId`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE',
            ]
        ],
        'checks' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`type` enum(\'received\',\'payable\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`status` enum(\'in_hand\',\'endorsed\',\'cashed\',\'payable\',\'bounced\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`invoiceId` int(11) DEFAULT NULL',
                '`invoiceType` enum(\'sales\',\'purchase\') COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`checkNumber` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`dueDate` date NOT NULL',
                '`bankName` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`endorsedToInvoiceId` int(11) DEFAULT NULL',
                '`cashed_in_account_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `cashed_in_account_id` (`cashed_in_account_id`)',
                'KEY `dueDate` (`dueDate`)',
                'KEY `status` (`status`)',
                'CONSTRAINT `checks_ibfk_1` FOREIGN KEY (`cashed_in_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL',
            ]
        ],
        'payments' => [
            'columns' => [
                '`id` int(11) NOT NULL AUTO_INCREMENT',
                '`invoiceId` int(11) DEFAULT NULL',
                '`invoiceType` enum(\'sales\',\'purchase\') COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`person_id` int(11) DEFAULT NULL',
                '`person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`transaction_type` enum(\'receipt\',\'payment\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`type` enum(\'cash\',\'check\',\'endorse_check\') COLLATE utf8mb4_unicode_ci NOT NULL',
                '`amount` decimal(15,2) NOT NULL',
                '`date` date NOT NULL',
                '`description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL',
                '`checkId` int(11) DEFAULT NULL',
                '`account_id` int(11) DEFAULT NULL',
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `checkId` (`checkId`)',
                'KEY `account_id` (`account_id`)',
                'KEY `person_key` (`person_id`,`person_type`)',
                'KEY `date` (`date`)',
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
                '`entity_id` int(11) NOT NULL DEFAULT 1',
            ],
            'constraints' => [
                'PRIMARY KEY (`id`)',
                'KEY `account_id` (`account_id`)',
                'KEY `date` (`date`)',
                'CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT',
            ]
        ],
    ];
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
                // Check for missing columns
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
}


if (($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 2) || (file_exists($config_file_path) && $step == 3)) {
    
    if ($step == 2) {
        $db_host = $_POST['db_host'] ?? 'localhost';
        $db_name = $_POST['db_name'] ?? '';
        $db_user = $_POST['db_user'] ?? '';
        $db_pass = $_POST['db_pass'] ?? '';

        if (empty($db_name) || empty($db_user)) {
            $error_message = 'نام دیتابیس و نام کاربری الزامی است.';
        } else {
            $config_content = "<?php\n// /api/config.php - Generated by setup script\n\n";
            $config_content .= "define('DB_SERVER', '{$db_host}');\n";
            $config_content .= "define('DB_NAME', '{$db_name}');\n";
            $config_content .= "define('DB_USERNAME', '{$db_user}');\n";
            $config_content .= "define('DB_PASSWORD', '{$db_pass}');\n";

            if (file_put_contents($config_file_path, $config_content) === false) {
                $error_message = 'خطا در ایجاد فایل `config.php`. لطفاً از قابل نوشتن بودن پوشه `api/` اطمینان حاصل کنید.';
            }
        }
    } else {
        require_once $config_file_path;
        $db_host = DB_SERVER;
        $db_name = DB_NAME;
        $db_user = DB_USERNAME;
        $db_pass = DB_PASSWORD;
    }

    if (empty($error_message)) {
        try {
            $pdo = new PDO("mysql:host={$db_host}", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            if ($step == 2) {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $success_messages[] = "دیتابیس `{$db_name}` با موفقیت ایجاد (یا از قبل موجود) بود.";
            }
            $pdo->exec("USE `{$db_name}`");

            synchronize_schema($pdo, $db_name, $success_messages, $error_message);
            
            if (empty($error_message)) {
                $success_messages[] = "ساختار تمام جداول با موفقیت بررسی و بروزرسانی شد.";
                
                // Check and insert default admin user
                $stmt = $pdo->prepare("SELECT id FROM `users` WHERE username = 'admin'");
                $stmt->execute();
                if ($stmt->fetch() === false) {
                    $admin_user = 'admin';
                    $admin_pass = 'admin123';
                    $password_hash = password_hash($admin_pass, PASSWORD_DEFAULT);

                    $stmt_insert = $pdo->prepare("INSERT INTO `users` (username, password_hash) VALUES (?, ?)");
                    $stmt_insert->execute([$admin_user, $password_hash]);
                    $success_messages[] = "کاربر پیش‌فرض `admin` با رمز `admin123` ایجاد شد.";
                } else {
                    $success_messages[] = "کاربر `admin` از قبل موجود است.";
                }

                // Check and insert default business entity
                $stmt = $pdo->prepare("SELECT id FROM `business_entities` WHERE id = 1");
                $stmt->execute();
                if ($stmt->fetch() === false) {
                    $stmt_insert = $pdo->prepare("INSERT INTO `business_entities` (id, name) VALUES (1, 'مجموعه اصلی')");
                    $stmt_insert->execute();
                    $success_messages[] = "مجموعه تجاری پیش‌فرض با موفقیت ایجاد شد.";
                }

            }
           
            if ($step == 2) {
                header('Location: setup.php?step=3');
                exit;
            }

        } catch (PDOException $e) {
            $error_message = "خطا در اتصال یا اجرای دستورات دیتابیس: " . $e->getMessage();
            if($step == 2 && file_exists($config_file_path)) {
                unlink($config_file_path);
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نصب و راه‌اندازی سیستم حسابداری</title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body { background-color: #f0f2f5; }
        .setup-container { max-width: 700px; margin: 5rem auto; }
    </style>
</head>
<body>
    <div class="container setup-container">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h2 class="mb-0"><i class="bi bi-gear-fill me-2"></i>نصب و راه‌اندازی سیستم حسابداری</h2>
            </div>
            <div class="card-body p-4">
                <?php if ($step == 1): ?>
                    <h4 class="card-title">مرحله ۱: خوش آمدید!</h4>
                    <p class="card-text">این اسکریپت شما را در راه‌اندازی سیستم حسابداری یاری می‌کند. لطفاً اطلاعات اتصال به دیتابیس خود را در فرم زیر وارد کنید.</p>
                    <p class="text-muted small">اسکریپت به صورت خودکار فایل `config.php` را ایجاد کرده و جداول مورد نیاز را در دیتابیس شما نصب خواهد کرد.</p>
                    <hr>
                    <form action="setup.php?step=2" method="POST">
                        <div class="mb-3">
                            <label for="db_host" class="form-label">آدرس هاست دیتابیس</label>
                            <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                        </div>
                        <div class="mb-3">
                            <label for="db_name" class="form-label">نام دیتابیس</label>
                            <input type="text" class="form-control" id="db_name" name="db_name" required placeholder="hesabdari_db">
                        </div>
                        <div class="mb-3">
                            <label for="db_user" class="form-label">نام کاربری دیتابیس</label>
                            <input type="text" class="form-control" id="db_user" name="db_user" required>
                        </div>
                        <div class="mb-3">
                            <label for="db_pass" class="form-label">رمز عبور دیتابیس</label>
                            <input type="password" class="form-control" id="db_pass" name="db_pass">
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-caret-right-fill me-2"></i>شروع نصب</button>
                    </form>
                <?php elseif ($step == 2): ?>
                    <h4 class="card-title">مرحله ۲: در حال نصب...</h4>
                     <?php if ($error_message): ?>
                        <div class="alert alert-danger">
                            <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>خطا در نصب:</strong><br>
                            <?php echo $error_message; ?><br><br>
                            <a href="setup.php" class="btn btn-primary">تلاش مجدد</a>
                        </div>
                    <?php else: ?>
                        <div class="d-flex align-items-center">
                            <strong>لطفاً منتظر بمانید...</strong>
                            <div class="spinner-border ms-auto" role="status" aria-hidden="true"></div>
                        </div>
                    <?php endif; ?>
                <?php elseif ($step == 3): ?>
                    <h4 class="card-title text-success"><i class="bi bi-check-circle-fill me-2"></i>فرآیند با موفقیت انجام شد</h4>
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger">
                            <strong>خطا در بروزرسانی:</strong><br>
                            <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($success_messages)): ?>
                        <p>جزئیات عملیات انجام شده:</p>
                        <ul class="list-group mb-3" style="font-size: 0.9em; max-height: 200px; overflow-y: auto;">
                            <?php foreach ($success_messages as $msg): ?>
                                <li class="list-group-item list-group-item-light py-1"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <p>می‌توانید با اطلاعات زیر وارد سیستم شوید:</p>
                    <ul class="list-unstyled">
                        <li><strong>نام کاربری:</strong> <code>admin</code></li>
                        <li><strong>رمز عبور (پیش‌فرض):</strong> <code>admin123</code></li>
                    </ul>
                    <p class="text-muted">پس از ورود، حتماً از طریق بخش "تغییر رمز" رمز عبور خود را تغییر دهید.</p>
                    
                    <div class="alert alert-danger mt-4">
                        <strong><i class="bi bi-shield-lock-fill me-2"></i>هشدار امنیتی بسیار مهم:</strong><br>
                         برای حفظ امنیت برنامه، لطفاً **فوراً و به صورت دستی** این فایل (`setup.php`) را از روی سرور خود حذف کنید.
                    </div>
                    <a href="index.php" class="btn btn-success w-100"><i class="bi bi-box-arrow-in-right me-2"></i>ورود به برنامه</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>