<?php
// /public_html/banner_click.php

// 0. فعال کردن لاگ خطا و غیرفعال کردن نمایش خطا به کاربر در این اسکریپت حساس به هدر
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL); // همه خطاها لاگ شوند
ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); // مسیر لاگ خطای PHP خود را تنظیم کنید اگر لازم است

// 1. بارگذاری فایل تنظیمات اصلی (فقط برای BASE_URL و ROOT_PATH اگر لازم است)
// این فایل نباید هیچ خروجی تولید کند و session_start هم نباید در آن باشد مگر اینکه واقعا لازم باشد
$config_file_path_click = __DIR__ . '/config.php';
if (file_exists($config_file_path_click)) {
    require_once $config_file_path_click;
} else {
    error_log("BANNER_CLICK FATAL: config.php not found at " . $config_file_path_click);
    // اگر config نیست، یک ریدایرکت پایه انجام بده
    if (!headers_sent()) { header("Location: /?error=bc_cfg_missing"); exit; }
    echo "Config Error."; exit;
}

// 2. بارگذاری کلاس دیتابیس
$database_file_path_click = ROOT_PATH . '/database.php'; // ROOT_PATH از config.php
if (!class_exists('Database', false)) {
    if (file_exists($database_file_path_click)) {
        require_once $database_file_path_click;
        if (!class_exists('Database', false)) {
            error_log("BANNER_CLICK FATAL: Database class not defined in " . $database_file_path_click);
            if (!headers_sent()) { header("Location: " . (defined('BASE_URL') ? BASE_URL : '/') . "?error=bc_db_class_missing"); exit; }
            echo "DB Class Error."; exit;
        }
    } else {
        error_log("BANNER_CLICK FATAL: database.php not found at " . $database_file_path_click);
        if (!headers_sent()) { header("Location: " . (defined('BASE_URL') ? BASE_URL : '/') . "?error=bc_db_file_missing"); exit; }
        echo "DB File Error."; exit;
    }
}

// تعریف تابع site_url اگر در config.php به هر دلیلی تعریف نشده یا include نشده
if (!function_exists('site_url')) {
    function site_url($path = '') {
        $base_url_func_bc = defined('BASE_URL') ? BASE_URL : '';
        if(empty($base_url_func_bc) && isset($_SERVER['HTTP_HOST'])) {
            $protocol_func_bc = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://");
            $base_url_func_bc = $protocol_func_bc . $_SERVER['HTTP_HOST'];
        } elseif(empty($base_url_func_bc)) {
            $base_url_func_bc = '/'; // ریشه سایت
        }
        return rtrim($base_url_func_bc, '/') . '/' . ltrim($path, '/');
    }
}
$home_page_url = site_url('index.php'); // صفحه اصلی سایت شما

// 3. دریافت و اعتبارسنجی پارامترهای GET
$banner_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$target_url_encoded = $_GET['r'] ?? null;

if (!$banner_id || $target_url_encoded === null) {
    error_log("Banner click error: Missing 'id' or 'r' parameter. ID: " . ($banner_id ?? 'N/A') . ", Encoded URL: " . ($target_url_encoded ?? 'N/A'));
    if (!headers_sent()) { header("Location: " . $home_page_url); exit; }
    // اگر هدرها ارسال شده، یک خروجی ساده و خروج
    echo "خطا: پارامترهای کلیک بنر ناقص است."; exit;
}

$target_url = urldecode($target_url_encoded);

if (filter_var($target_url, FILTER_VALIDATE_URL) === FALSE) {
    error_log("Banner click error: Invalid target URL after decode: " . $target_url);
    if (!headers_sent()) { header("Location: " . $home_page_url . "?err=invalid_banner_target_url"); exit; }
    echo "خطا: آدرس مقصد بنر نامعتبر است."; exit;
}

if (strpos($target_url, basename(__FILE__)) !== false || $target_url === site_url(basename(__FILE__))) {
    error_log("Banner click error: Target URL points back to banner_click.php (loop). URL: " . $target_url);
    if (!headers_sent()) { header("Location: " . $home_page_url . "?err=banner_redirect_loop"); exit; }
    echo "خطا: حلقه در ریدایرکت بنر شناسایی شد."; exit;
}


// 4. اتصال به دیتابیس و ثبت کلیک
$db = null; // تعریف اولیه
try {
    if (!class_exists('Database')) throw new Exception("کلاس Database در دسترس نیست.");
    $dbInstance = Database::getInstance();
    $db = $dbInstance->getConnection();
    if (!$db instanceof PDO) throw new Exception("اتصال به پایگاه داده ناموفق بود.");

    // الف. ثبت جزئیات کلیک در جدول banner_clicks
    $ip_address_click = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_agent_click = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmtClick = $db->prepare("INSERT INTO banner_clicks (banner_id, ip_address, user_agent, clicked_at) VALUES (:banner_id, :ip_address, :user_agent, NOW())");
    $stmtClick->bindValue(':banner_id', $banner_id, PDO::PARAM_INT);
    $stmtClick->bindValue(':ip_address', $ip_address_click, PDO::PARAM_STR);
    $stmtClick->bindValue(':user_agent', $user_agent_click, $user_agent_click === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmtClick->execute();

    // ب. افزایش شمارنده کلیک در جدول banners
    $stmtUpdateBanner = $db->prepare("UPDATE banners SET clicks = clicks + 1 WHERE id = :banner_id");
    $stmtUpdateBanner->bindValue(':banner_id', $banner_id, PDO::PARAM_INT);
    $stmtUpdateBanner->execute();

} catch (PDOException $e_db) {
    error_log("Banner click DB PDOException for banner ID {$banner_id}: " . $e_db->getMessage() . "\n" . $e_db->getTraceAsString());
} catch (Exception $e_gen) {
    error_log("Banner click General Exception for banner ID {$banner_id}: " . $e_gen->getMessage() . "\n" . $e_gen->getTraceAsString());
}
// مهم: حتی اگر در ثبت کلیک در دیتابیس خطا رخ داد، کاربر باید به مقصد هدایت شود.

// 5. هدایت کاربر به لینک مقصد
// این بخش باید آخرین چیزی باشد که اجرا می‌شود و اطمینان حاصل کنید هیچ خروجی قبل از آن نیست.
if (!headers_sent()) {
    header("Location: " . $target_url, true, 302);
    exit; // بسیار مهم
} else {
    // این حالت فقط زمانی رخ می‌دهد که به دلیلی خروجی قبل از این بلاک ارسال شده باشد.
    $output_started_info = "نامشخص (error_get_last در دسترس نیست یا خطای قبلی نبوده)";
    if (function_exists('error_get_last')) {
        $last_error = error_get_last();
        if ($last_error && isset($last_error['message']) && stripos($last_error['message'], 'output started at') !== false) {
            if (preg_match('/output started at\s*(.*?):(\d+)/', $last_error['message'], $matches)) {
                $output_started_info = htmlspecialchars($matches[1] . ' line ' . $matches[2]);
            }
        } elseif ($last_error) {
             $output_started_info = "آخرین خطای PHP: " . htmlspecialchars($last_error['message']);
        }
    }
    error_log("BANNER_CLICK CRITICAL WARNING: Headers already sent BEFORE redirecting to target URL: " . $target_url . ". Output might have started at: " . $output_started_info);
    
    // نمایش یک صفحه ساده با ریدایرکت جاوااسکریپت به عنوان fallback نهایی
    echo "<!DOCTYPE html><html lang='fa' dir='rtl'><head><meta charset='UTF-8'><title>در حال انتقال...</title>";
    echo "<script type='text/javascript'>window.setTimeout(function() { window.location.href = '" . addslashes($target_url) . "'; }, 500);</script></head>"; // تاخیر کم برای نمایش پیام
    echo "<body><p style='text-align:center; font-family:Tahoma, sans-serif; padding:20px;'>در حال انتقال به مقصد... لطفاً چند لحظه صبر کنید.</p>";
    echo "<p style='text-align:center; font-family:Tahoma, sans-serif;'><a href='" . htmlspecialchars($target_url) . "'>اگر به صورت خودکار منتقل نشدید، اینجا کلیک کنید.</a></p>";
    echo "";
    echo "</body></html>";
    exit;
}
// بدون تگ پایانی ?>