<?php
// /scan.php - صفحه اسکن بارکد محصول با نمایش تصویر
error_reporting(0);
require_once __DIR__ . '/api/core/constants.php';

// جلوگیری از کش شدن ریدایرکت در مرورگر
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// بررسی لاگین بودن کاربر
require_once __DIR__ . '/api/core/session.php';
app_session_start();

require_once __DIR__ . '/api/core/helpers.php';

// Same idle timeout as the main app.
$is_logged_in = !empty($_SESSION['user_id']) && enforce_session_timeout();
if ($is_logged_in) {
    touch_session_activity();
}

// --- پردازش لاگین از فرم POST ---
// Uses the same User::login as the app (failed-attempt counter + lockout)
// plus a per-IP limit, so this form cannot be used for password guessing.
if (!$is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_login'])) {
    require_once __DIR__ . '/api/config.php';
    require_once __DIR__ . '/api/core/RateLimiter.php';
    require_once __DIR__ . '/api/models/User.php';
    $login_username = trim($_POST['username'] ?? '');
    $login_password = $_POST['password'] ?? '';
    $login_error = '';

    $scan_limiter = new RateLimiter(MAX_LOGIN_ATTEMPTS * 4, LOCKOUT_TIME_MINUTES * 60);
    $scan_rate = $scan_limiter->check('scan_login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if (!$scan_rate['allowed']) {
        $login_error = 'تعداد تلاش‌های ورود بیش از حد مجاز است. چند دقیقه بعد دوباره امتحان کنید.';
    } elseif ($login_username && $login_password) {
        try {
            $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
            $conn->set_charset("utf8mb4");
            db_sync_timezone($conn);
            $userModel = new User($conn);
            $result = $userModel->login(['username' => $login_username, 'password' => $login_password]);

            if (!empty($result['success'])) {
                $user = $result['user'];
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $access = $userModel->getUserAccessData($user['id']);
                $_SESSION['permissions'] = $access['permissions'];
                $_SESSION['allowed_entities'] = array_map('intval', $access['entities']);
                $_SESSION['current_entity_id'] = !empty($_SESSION['allowed_entities']) ? $_SESSION['allowed_entities'][0] : 0;
                $_SESSION['access_checked_at'] = time();

                $conn->close();
                // ریدایرکت به همین صفحه بعد از لاگین موفق
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
            $login_error = $result['error'] ?? 'نام کاربری یا رمز عبور اشتباه است.';
            $conn->close();
        } catch (Throwable $e) {
            error_log('scan.php login: ' . $e->getMessage());
            $login_error = 'خطا در ورود. لطفاً دوباره تلاش کنید.';
        }
    } else {
        $login_error = 'نام کاربری و رمز عبور را وارد کنید.';
    }
}

// --- اگر لاگین نیست، فرم لاگین نمایش بده ---
if (!$is_logged_in) {
    // Brand/contact info for the login footer comes from settings, not code.
    require_once __DIR__ . '/api/config.php';
    require_once __DIR__ . '/api/core/helpers.php';
    $scan_brand = '';
    $scan_phones = [];
    $scan_support_url = '';
    try {
        $conn = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
        if ($conn && !$conn->connect_error) {
            $conn->set_charset('utf8mb4');
            $scan_brand = trim((string)get_app_setting($conn, 'print_company_name', ''));
            if ($scan_brand === '') {
                $scan_brand = trim((string)get_app_setting($conn, 'app_title', ''));
            }
            $scan_phones = array_values(array_filter(array_map('trim', preg_split('/[،,]/', (string)get_app_setting($conn, 'print_company_phone', '')))));
            $scan_support_url = trim((string)get_app_setting($conn, 'support_url', ''));
            $conn->close();
        }
    } catch (Throwable $e) { /* render without brand info */ }
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <title>ورود به سیستم</title>
        <link rel="manifest" href="manifest.php">
        <meta name="theme-color" content="#4f46e5">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="حسابداری فرش">
        <link rel="icon" href="favicon.ico" sizes="any">
        <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
        <meta name="msapplication-TileColor" content="#4f46e5">
        <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
        <link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
        <style>
            body {
                font-family: 'Vazirmatn', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .login-card {
                max-width: 400px;
                width: 100%;
                border-radius: 20px;
                overflow: hidden;
                box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            }
            .login-header {
                background: linear-gradient(135deg, #6366f1, #4f46e5);
                color: white;
                padding: 30px 20px;
                text-align: center;
            }
            .login-header i {
                font-size: 3rem;
                margin-bottom: 10px;
            }
            .login-body {
                padding: 30px;
            }
            .btn-login {
                background: linear-gradient(135deg, #6366f1, #4f46e5);
                border: none;
                color: white;
                padding: 12px;
                font-size: 1.1rem;
                font-weight: 600;
                border-radius: 12px;
            }
            .btn-login:hover {
                background: linear-gradient(135deg, #4f46e5, #4338ca);
                color: white;
            }
            .info-section {
                text-align: center;
                margin-top: 20px;
                color: rgba(255,255,255,0.9);
            }
            .info-section .no-account {
                font-size: 0.85rem;
                margin-bottom: 8px;
                color: rgba(255,255,255,0.7);
            }
            .info-section a {
                color: #ffd700;
                text-decoration: none;
                font-weight: 600;
            }
            .info-section a:hover {
                color: #fff;
                text-decoration: underline;
            }
            .info-section .contact-numbers {
                margin-top: 15px;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 6px;
                font-size: 0.85rem;
            }
            .info-section .contact-numbers a {
                color: rgba(255,255,255,0.85);
                font-weight: 400;
                direction: ltr;
            }
            .info-section .contact-numbers a:hover {
                color: #ffd700;
            }
        </style>
    </head>
    <body>
        <div>
            <div class="card login-card">
                <div class="login-header">
                    <i class="bi bi-upc-scan d-block"></i>
                    <h4 class="mb-1">مشاهده مشخصات کالا</h4>
                    <p class="mb-0 opacity-75">برای مشاهده اطلاعات، وارد شوید</p>
                </div>
                <div class="login-body">
                    <?php if (!empty($login_error)): ?>
                        <div class="alert alert-danger py-2">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            <?php echo htmlspecialchars($login_error); ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                        <input type="hidden" name="scan_login" value="1">
                        <div class="mb-3">
                            <label for="username" class="form-label">نام کاربری</label>
                            <input type="text" class="form-control form-control-lg" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($login_username ?? ''); ?>" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">رمز عبور</label>
                            <input type="password" class="form-control form-control-lg" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-login w-100">
                            <i class="bi bi-box-arrow-in-right me-2"></i>ورود
                        </button>
                    </form>
                </div>
            </div>
            <div class="info-section">
                <?php if ($scan_support_url !== ''): ?>
                <div class="no-account">
                    <a href="<?php echo htmlspecialchars($scan_support_url); ?>">نام کاربری و رمز عبور ندارید؟</a>
                    <br>
                    <a href="<?php echo htmlspecialchars($scan_support_url); ?>">
                        مشاهده سایت <?php echo htmlspecialchars($scan_brand !== '' ? $scan_brand : 'فروشگاه'); ?>
                    </a>
                </div>
                <?php endif; ?>
                <div class="contact-numbers">
                    <?php foreach ($scan_phones as $phone): ?>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $phone)); ?>"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($phone); ?></a>
                    <?php endforeach; ?>
                    <?php if ($scan_support_url !== ''): ?>
                        <a href="<?php echo htmlspecialchars($scan_support_url); ?>"><i class="bi bi-globe me-1"></i><?php echo htmlspecialchars(preg_replace('#^https?://#', '', $scan_support_url)); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <script>window.__APP_VERSION__ = <?php echo json_encode(defined('APP_VERSION') ? APP_VERSION : ''); ?>;</script>
    <script src="js/pwa.js?v=<?php echo rawurlencode(defined('APP_VERSION') ? APP_VERSION : ''); ?>"></script>
    </body>
    </html>
    <?php
    exit;
}

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/helpers.php';

// Product details and prices: only for roles that work with products or sales.
$scan_allowed = array_intersect(
    ['manage_products', 'view_price_list', 'manage_sales_invoices', 'manage_purchase_invoices', 'manage_quotations', 'manage_stocktaking'],
    (array)($_SESSION['permissions'] ?? [])
);
if (empty($scan_allowed)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>دسترسی ندارید</title></head>'
        . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:3rem 1rem">'
        . '<h3>دسترسی ندارید</h3><p>برای دیدن مشخصات و قیمت کالا، دسترسی «انبار و محصولات» یا «لیست قیمت» لازم است.</p>'
        . '<p><a href="index.php">بازگشت به برنامه</a></p></body></html>';
    exit;
}

function format_currency_public($num, $conn = null) {
    $val = number_format(floatval($num), 0);
    $label = 'تومان';
    if ($conn instanceof mysqli) {
        $label = (string)get_app_setting($conn, 'currency_label', 'تومان');
    }
    return "{$val} {$label}";
}

$itemId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$item_data = null;
$product_image = null;
$error = '';

if ($itemId > 0) {
    try {
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
        if ($conn->connect_error) {
            throw new Exception("Connection failed");
        }
        $conn->set_charset("utf8mb4");

        // Step 1: Fetch the core inventory item (scoped to the user's active entity)
        $current_entity_id = intval($_SESSION['current_entity_id'] ?? 0);
        $stmt_item = $conn->prepare("SELECT * FROM inventory_items WHERE id = ? AND entity_id = ?");
        $stmt_item->bind_param("ii", $itemId, $current_entity_id);
        $stmt_item->execute();
        $result_item = db_stmt_to_assoc_array($stmt_item);
        $item_data = $result_item[0] ?? null;

        if ($item_data) {
            // Step 2: Fetch product and collection details
            $product_id = $item_data['product_id'];
            $stmt_product = $conn->prepare("
                SELECT p.name as product_name, p.description, p.design_code, 
                       p.colorway, p.collection_id, p.price as single_product_price_per_meter,
                       c.name as collection_name, c.type as collection_type
                FROM products p
                LEFT JOIN product_collections c ON p.collection_id = c.id
                WHERE p.id = ?
            ");
            $stmt_product->bind_param("i", $product_id);
            $stmt_product->execute();
            $product_details = db_stmt_to_assoc_array($stmt_product)[0] ?? [];
            $item_data = array_merge($item_data, $product_details);

            // Step 3: Fetch product primary image
            $stmt_img = $conn->prepare("
                SELECT file_path FROM product_images 
                WHERE product_id = ? AND is_primary = 1 
                LIMIT 1
            ");
            $stmt_img->bind_param("i", $product_id);
            $stmt_img->execute();
            $img_result = db_stmt_to_assoc_array($stmt_img);
            if (!empty($img_result)) {
                $product_image = $img_result[0]['file_path'];
            } else {
                // اگر تصویر اصلی نبود، اولین تصویر را بگیر
                $stmt_img2 = $conn->prepare("SELECT file_path FROM product_images WHERE product_id = ? LIMIT 1");
                $stmt_img2->bind_param("i", $product_id);
                $stmt_img2->execute();
                $img_result2 = db_stmt_to_assoc_array($stmt_img2);
                if (!empty($img_result2)) {
                    $product_image = $img_result2[0]['file_path'];
                }
            }

            // Step 4: Fetch dynamic attributes for the collection
            $item_data['attributes'] = [];
            if (!empty($item_data['collection_id'])) {
                $stmt_attrs = $conn->prepare("
                    SELECT at.name, pca.value
                    FROM product_collection_attributes pca
                    JOIN product_attribute_types at ON pca.attribute_type_id = at.id
                    WHERE pca.collection_id = ?
                ");
                $stmt_attrs->bind_param("i", $item_data['collection_id']);
                $stmt_attrs->execute();
                $attrs_result = db_stmt_to_assoc_array($stmt_attrs);
                foreach ($attrs_result as $attr) {
                    $item_data['attributes'][$attr['name']] = $attr['value'];
                }
            }
            
            // Step 5: Calculate Official Price
            $item_data['official_price'] = 0;
            $price_per_meter = 0;
            $price_override = null;

            if (!empty($item_data['dimensions'])) {
                // Case 1: Product is in a collection
                if (!empty($item_data['collection_id'])) {
                    $stmt_coll_price = $conn->prepare("
                        SELECT pc.price_per_meter, cd.price_override
                        FROM product_collections pc
                        LEFT JOIN collection_dimensions cd ON pc.id = cd.collection_id AND cd.dimension = ?
                        WHERE pc.id = ?
                    ");
                    $stmt_coll_price->bind_param("si", $item_data['dimensions'], $item_data['collection_id']);
                    $stmt_coll_price->execute();
                    $coll_price_result = db_stmt_to_assoc_array($stmt_coll_price)[0] ?? null;

                    if ($coll_price_result) {
                        $price_override = $coll_price_result['price_override'];
                        if ($price_override !== null) {
                            $item_data['official_price'] = floatval($price_override);
                        } else {
                            $price_per_meter = $coll_price_result['price_per_meter'] ?? 0;
                        }
                    }
                }
                // Case 2: Product is standalone
                else if (isset($item_data['single_product_price_per_meter'])) {
                    $price_per_meter = floatval($item_data['single_product_price_per_meter']);
                }

                // Calculate price based on area
                if ($price_per_meter > 0 && $price_override === null) {
                    // Same area rules as the price list / invoices (settings-driven
                    // named sizes and cm threshold) so scanned prices match.
                    $area = dimension_area($conn, $item_data['dimensions']);
                    $item_data['official_price'] = $area * $price_per_meter;
                }
            }

        } else {
            $error = 'آیتم با این شناسه یافت نشد.';
        }
  
        $conn->close();
    } catch (Exception $e) {
        $error = 'خطا در برقراری ارتباط با سرور.';
    }
} else {
    $error = 'شناسه آیتم نامعتبر است.';
}

$status_map = [
    'in_stock' => ['text' => 'موجود در انبار', 'class' => 'success', 'icon' => 'check-circle'],
    'sold' => ['text' => 'فروخته شده', 'class' => 'danger', 'icon' => 'x-circle'],
    'consignment_out' => ['text' => 'امانی', 'class' => 'warning', 'icon' => 'clock'],
    'returned' => ['text' => 'مرجوع شده', 'class' => 'info', 'icon' => 'arrow-return-left'],
    'returned_to_supplier' => ['text' => 'مرجوع به تامین‌کننده', 'class' => 'dark', 'icon' => 'box-arrow-left'],
    'reserved' => ['text' => 'رزرو شده', 'class' => 'primary', 'icon' => 'bookmark'],
    'damaged' => ['text' => 'آسیب دیده', 'class' => 'dark', 'icon' => 'exclamation-triangle']
];
$current_status = $item_data['status'] ?? 'unknown';
$status_info = $status_map[$current_status] ?? ['text' => $current_status, 'class' => 'secondary', 'icon' => 'question-circle'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>مشخصات کالا <?php echo $item_data ? '- ' . htmlspecialchars($item_data['product_name'] ?? '') : ''; ?></title>
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="حسابداری فرش">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <meta name="msapplication-TileColor" content="#4f46e5">
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #6366f1;
            --secondary-color: #4f46e5;
        }
        body { 
            font-family: 'Vazirmatn', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .product-card { 
            max-width: 500px; 
            margin: 0 auto;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .product-image-container {
            position: relative;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            height: 280px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .product-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .no-image-placeholder {
            color: #adb5bd;
            text-align: center;
        }
        .no-image-placeholder i {
            font-size: 4rem;
            margin-bottom: 10px;
        }
        .status-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: bold;
            font-size: 0.85rem;
        }
        .serial-badge {
            position: absolute;
            bottom: 15px;
            left: 15px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
        }
        .product-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
        }
        .collection-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .collection-machine { background: #e0f2fe; color: #0369a1; }
        .collection-handmade { background: #fef3c7; color: #b45309; }
        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .info-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-list li:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #64748b;
            font-size: 0.9rem;
        }
        .info-value {
            font-weight: 600;
            color: #334155;
        }
        .price-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 15px;
            margin: 15px;
        }
        .price-label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        .price-value {
            font-size: 1.8rem;
            font-weight: 700;
        }
        .error-container {
            text-align: center;
            padding: 60px 20px;
        }
        .error-icon {
            font-size: 4rem;
            color: #ef4444;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card product-card">
            <?php if ($error): ?>
                <div class="error-container">
                    <div class="error-icon"><i class="bi bi-exclamation-triangle"></i></div>
                    <h4 class="text-danger mb-2">خطا</h4>
                    <p class="text-muted"><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php elseif ($item_data): ?>
                <!-- تصویر محصول -->
                <div class="product-image-container">
                    <?php if ($product_image): ?>
                        <img src="<?php echo htmlspecialchars($product_image); ?>" alt="<?php echo htmlspecialchars($item_data['product_name'] ?? ''); ?>" class="product-image">
                    <?php else: ?>
                        <div class="no-image-placeholder">
                            <i class="bi bi-image"></i>
                            <p class="mb-0">بدون تصویر</p>
                        </div>
                    <?php endif; ?>
                    
                    <span class="status-badge bg-<?php echo $status_info['class']; ?>">
                        <i class="bi bi-<?php echo $status_info['icon']; ?> me-1"></i>
                        <?php echo $status_info['text']; ?>
                    </span>
                    
                    <span class="serial-badge">
                        <i class="bi bi-upc-scan me-1"></i>
                        <?php echo htmlspecialchars($item_data['serial_id'] ?? $item_data['id']); ?>
                    </span>
                </div>
                
                <div class="card-body">
                    <!-- نام محصول -->
                    <h2 class="product-title"><?php echo htmlspecialchars($item_data['product_name'] ?? 'نام محصول'); ?></h2>
                    
                    <!-- کلکسیون -->
                    <?php if (!empty($item_data['collection_name'])): ?>
                        <?php $type_class = ($item_data['collection_type'] ?? '') === 'machine' ? 'collection-machine' : 'collection-handmade'; ?>
                        <span class="collection-badge <?php echo $type_class; ?>">
                            <?php echo htmlspecialchars($item_data['collection_name']); ?>
                            <?php if ($item_data['collection_type'] === 'machine'): ?>
                                (ماشینی)
                            <?php elseif ($item_data['collection_type'] === 'handmade'): ?>
                                (دستبافت)
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    
                    <!-- توضیحات -->
                    <?php if (!empty($item_data['description'])): ?>
                        <p class="text-muted mt-2 mb-3"><?php echo htmlspecialchars($item_data['description']); ?></p>
                    <?php endif; ?>
                    
                    <!-- مشخصات -->
                    <ul class="info-list mt-3">
                        <li>
                            <span class="info-label"><i class="bi bi-rulers me-2"></i>ابعاد</span>
                            <span class="info-value"><?php echo htmlspecialchars($item_data['dimensions'] ?? '-'); ?></span>
                        </li>
                        <?php if (!empty($item_data['design_code'])): ?>
                        <li>
                            <span class="info-label"><i class="bi bi-palette me-2"></i>کد طرح</span>
                            <span class="info-value"><?php echo htmlspecialchars($item_data['design_code']); ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($item_data['colorway'])): ?>
                        <li>
                            <span class="info-label"><i class="bi bi-brush me-2"></i>کد رنگ</span>
                            <span class="info-value"><?php echo htmlspecialchars($item_data['colorway']); ?></span>
                        </li>
                        <?php endif; ?>
                        
                        <?php if(!empty($item_data['attributes'])): ?>
                            <?php foreach($item_data['attributes'] as $name => $value): ?>
                            <?php if(!empty($value)): ?>
                            <li>
                                <span class="info-label"><?php echo htmlspecialchars($name); ?></span>
                                <span class="info-value"><?php echo htmlspecialchars($value); ?></span>
                            </li>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <!-- قیمت -->
                <?php if ($item_data['official_price'] > 0): ?>
                <div class="price-section">
                    <div class="price-label">قیمت فروش رسمی</div>
                    <div class="price-value"><?php echo format_currency_public($item_data['official_price'], $conn ?? null); ?></div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        
        <div class="text-center mt-4">
            <small class="text-white opacity-75">
                <i class="bi bi-shop me-1"></i> سیستم مدیریت فروشگاه فرش
            </small>
        </div>
    </div>
    <script>window.__APP_VERSION__ = <?php echo json_encode(defined('APP_VERSION') ? APP_VERSION : ''); ?>;</script>
    <script src="js/pwa.js?v=<?php echo rawurlencode(defined('APP_VERSION') ? APP_VERSION : ''); ?>"></script>
</body>
</html>