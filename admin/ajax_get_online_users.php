<?php
// /public_html/admin/ajax_get_online_users.php

header('Content-Type: application/json; charset=utf-8');
$response = ['success' => false, 'online_users' => 0, 'message' => 'خطای اولیه.'];

// بارگذاری فایل‌های ضروری با بررسی دقیق مسیر
$config_path = dirname(__DIR__) . '/config.php'; // از admin به ریشه
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    error_log("AJAX ONLINE USERS FATAL: config.php not found.");
    $response['message'] = 'خطای پیکربندی سرور (c_ajax_online).';
    echo json_encode($response);
    exit;
}

$database_path = ROOT_PATH . '/database.php'; // ROOT_PATH از config.php
if (!class_exists('Database', false)) {
    if (file_exists($database_path)) {
        require_once $database_path;
        if (!class_exists('Database', false)) {
            error_log("AJAX ONLINE USERS FATAL: Database class not defined.");
            $response['message'] = 'خطای پیکربندی سرور (db_c_ajax_online).'; echo json_encode($response); exit;
        }
    } else {
        error_log("AJAX ONLINE USERS FATAL: database.php not found.");
        $response['message'] = 'خطای پیکربندی سرور (db_f_ajax_online).'; echo json_encode($response); exit;
    }
}

// این اسکریپت ممکن است توسط ادمین لاگین شده یا حتی بدون لاگین (اگر آمار عمومی است) فراخوانی شود.
// برای این مورد خاص، نیازی به بررسی auth_check نیست چون فقط داده می‌خواند.

$db = null;
$online_threshold_minutes_ajax = 5; // می‌توانید این را از یک تنظیمات بخوانید

try {
    if (!class_exists('Database')) throw new Exception("کلاس Database در دسترس نیست.");
    $dbInstance = Database::getInstance();
    $db = $dbInstance->getConnection();
    if (!$db instanceof PDO) throw new Exception("اتصال به پایگاه داده ناموفق بود.");

    $time_ago = date('Y-m-d H:i:s', strtotime("-{$online_threshold_minutes_ajax} minutes"));
    $stmtOnline = $db->prepare("SELECT COUNT(DISTINCT ip_address) FROM page_views WHERE view_datetime >= ?");
    $stmtOnline->execute([$time_ago]);
    $online_count = (int)($stmtOnline->fetchColumn() ?? 0);

    $response = ['success' => true, 'online_users' => $online_count, 'message' => 'آمار کاربران آنلاین با موفقیت دریافت شد.'];

} catch (PDOException $e_pdo) {
    $response['message'] = 'خطای پایگاه داده هنگام دریافت کاربران آنلاین.';
    error_log("AJAX Online Users PDOException: " . $e_pdo->getMessage());
    if(defined('DEBUG_MODE') && DEBUG_MODE) { $response['debug_error'] = $e_pdo->getMessage(); }
} catch (Exception $e_gen) {
    $response['message'] = 'خطای سیستمی هنگام دریافت کاربران آنلاین.';
    error_log("AJAX Online Users Exception: " . $e_gen->getMessage());
    if(defined('DEBUG_MODE') && DEBUG_MODE) { $response['debug_error'] = $e_gen->getMessage(); }
}

echo json_encode($response);
exit;
?>