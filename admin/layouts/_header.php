<?php

// /public_html/admin/layouts/_header.php

// 1. بارگذاری config.php در ابتدای همه چیز
// این فایل باید DEBUG_MODE, ثابت‌های دیتابیس, BASE_URL, ROOT_PATH, SESSION_NAME_ADMIN و session_start() را تعریف کند.
if (file_exists(dirname(dirname(__DIR__)) . '/config.php')) { // مسیر به ریشه public_html
    require_once dirname(dirname(__DIR__)) . '/config.php';
} else {
    // این یک خطای بحرانی است، چون بدون config هیچ چیز کار نمی‌کند.
    $fatal_error_message = "FATAL ERROR IN ADMIN HEADER: config.php not found at " . dirname(dirname(__DIR__)) . '/config.php';
    error_log($fatal_error_message);
    die("خطای سیستمی بسیار جدی: فایل تنظیمات اصلی یافت نشد. لطفاً با مدیر سیستم تماس بگیرید.");
}

// 2. بارگذاری کلاس Database
// این فایل فقط باید شامل تعریف کلاس Database باشد و هیچ کد اجرایی دیگری نداشته باشد.
$db_class_path = dirname(dirname(__DIR__)) . '/database.php'; // مسیر به ریشه
if (!class_exists('Database', false)) { // false یعنی autoloader را صدا نزن اگر داریم
    if (file_exists($db_class_path)) {
        require_once $db_class_path;
    } else {
        error_log("ADMIN LAYOUT FATAL ERROR: database.php not found at " . $db_class_path);
        die("خطای سیستمی: فایل کلاس دیتابیس یافت نشد.");
    }
}

// 3. ایجاد نمونه سراسری $db (بسیار مهم)
global $db; // اعلام $db به عنوان متغیر سراسری یا حداقل در این scope قابل دسترس برای فایل‌های بعدی
$db = null;  // مقداردهی اولیه

if (class_exists('Database')) {
    try {
        $dbInstance = Database::getInstance();
        $db = $dbInstance->getConnection(); // <<<<----- متغیر $db اینجا باید نمونه PDO را بگیرد
        if (!$db instanceof PDO) {
            error_log("ADMIN LAYOUT WARNING: \$db did not become a valid PDO object after Database instantiation in _header.php. Possible issue in Database class or DB connection params.");
            $db = null; // اطمینان از null بودن در صورت بروز هرگونه خطا در اتصال
        } else {
            // برای تست می‌توانید اینجا یک کوئری ساده بزنید اگر DEBUG_MODE فعال است
            // if (defined('DEBUG_MODE') && DEBUG_MODE) {
            //     $stmt_test = $db->query("SELECT 1");
            //     if ($stmt_test) { /* echo "DB connection in header successful."; */ }
            // }
        }
    } catch (Exception $e) {
        error_log("ADMIN LAYOUT DB CONNECTION EXCEPTION in _header.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $db = null;
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            // این پیام ممکن است باعث خطای headers already sent شود اگر auth_check.php ریدایرکت کند
            // بهتر است فقط لاگ شود.
            // echo "<p style='color:red;'>خطای بحرانی در اتصال به دیتابیس در هدر اصلی: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
} else {
    error_log("ADMIN LAYOUT FATAL ERROR: Database class does not exist even after requiring database.php. Check database.php for errors.");
    $db = null;
}

// 4. بررسی وضعیت لاگین ادمین
// auth_check.php باید بعد از تعریف $db باشد اگر به آن نیاز دارد (که فعلاً ندارد)
// و همچنین بعد از session_start (که در config.php است).
if (file_exists(__DIR__ . '/../auth_check.php')) { // مسیر از layouts به admin
    require_once __DIR__ . '/../auth_check.php';
} else {
    error_log("ADMIN LAYOUT FATAL ERROR: auth_check.php not found.");
    die("خطای سیستمی: فایل احراز هویت یافت نشد.");
}
// از اینجا به بعد، $loggedInAdminId و $loggedInAdminUsername از auth_check.php در دسترس هستند.

// 5. بارگذاری کتابخانه jdf
$jdf_loaded_for_admin_layout = false;
if (function_exists('jdate') && function_exists('jalali_to_gregorian')) {
    $jdf_loaded_for_admin_layout = true;
} else {
    $jdf_path_layout = dirname(dirname(__DIR__)) . '/lib/jdf.php';
    if (file_exists($jdf_path_layout)) {
        require_once $jdf_path_layout;
        if (function_exists('jdate') && function_exists('jalali_to_gregorian') && function_exists('jcheckdate')) {
            $jdf_loaded_for_admin_layout = true;
        } else { error_log("ADMIN LAYOUT WARNING: jdf.php included, but key functions not found."); }
    } else { error_log("ADMIN LAYOUT WARNING: jdf.php not found at " . $jdf_path_layout); }
}
if (!$jdf_loaded_for_admin_layout) {
    if (!function_exists('jdate')) { function jdate($f,$t=''){return date($f,($t===''?time():$t));} }
    if (!function_exists('jalali_to_gregorian')) { function jalali_to_gregorian($jy,$jm,$jd,$mod=''){ return array($jy,$jm,$jd); } }
    if (!function_exists('jcheckdate')) { function jcheckdate($m,$d,$y){ return checkdate((int)$m,(int)$d,(int)$y); } }
}

// 6. تابع site_url
if (!function_exists('site_url')) {
    function site_url($path = '') { /* ... مانند قبل ... */ return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/'); }
}

$current_page_admin = basename($_SERVER['PHP_SELF']);
$pageTitle = isset($pageTitle) ? $pageTitle : 'پنل مدیریت';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - سامانه خاموشی برق</title>
    <link rel="stylesheet" href="<?php echo site_url('assets/admin_style.css'); ?>?v=<?php echo defined('ROOT_PATH') && file_exists(ROOT_PATH . '/assets/admin_style.css') ? filemtime(ROOT_PATH . '/assets/admin_style.css') : time(); ?>">
    <style> @font-face { font-family: 'IRANSansX'; /* ... تعریف فونت ... */ } </style>
    <?php if (isset($page_specific_css) && is_array($page_specific_css)): /* ... */ endif; ?>
</head>
<body class="admin-body">
    <div class="admin-layout">
        <?php require_once __DIR__ . '/_sidebar.php'; ?>
        <main class="admin-main-content">
            <?php require_once __DIR__ . '/_topbar.php'; ?>
            <div class="admin-container-main">

<script>
document.addEventListener('DOMContentLoaded', function() {
    window.toggleAdminSidebar = function() {
        const sidebar = document.querySelector('.admin-sidebar');
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
    };
});
</script>

<?php if (isset($page_specific_js) && is_array($page_specific_js)): ?>
    <?php foreach ($page_specific_js as $jsPath): ?>
        <script src="<?php echo site_url($jsPath); ?>?v=<?php echo time(); ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
