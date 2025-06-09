<?php
// /public_html/ajax_toggle_guest_pin.php

// 0. تنظیم اولیه خطاها و هدر پاسخ
ini_set('display_errors', 0); // خطاها در مرورگر نمایش داده نشوند، فقط لاگ شوند
ini_set('log_errors', 1);
// error_reporting(E_ALL); // برای لاگ کردن تمام خطاها و هشدارها در سرور

header('Content-Type: application/json; charset=utf-8');
$response = ['success' => false, 'message' => 'درخواست نامعتبر اولیه.', 'is_pinned' => false, 'debug' => []];

// 1. بارگذاری فایل‌های ضروری
$config_path = __DIR__ . '/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    error_log("AJAX_TOGGLE_GUEST_PIN FATAL: config.php not found at " . $config_path);
    $response['message'] = 'خطای پیکربندی سرور (c_ajax).';
    echo json_encode($response);
    exit;
}

$database_path = ROOT_PATH . '/database.php'; // ROOT_PATH از config.php
if (!class_exists('Database', false)) {
    if (file_exists($database_path)) {
        require_once $database_path;
        if (!class_exists('Database', false)) {
            error_log("AJAX_TOGGLE_GUEST_PIN FATAL: Database class not defined in " . $database_path);
            $response['message'] = 'خطای پیکربندی سرور (db_c_ajax).'; echo json_encode($response); exit;
        }
    } else {
        error_log("AJAX_TOGGLE_GUEST_PIN FATAL: database.php not found at " . $database_path);
        $response['message'] = 'خطای پیکربندی سرور (db_f_ajax).'; echo json_encode($response); exit;
    }
}

// 2. دریافت داده‌های POST و شناسه کاربر مهمان
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['address_hash'], $_POST['action'], $_POST['guest_id'])) {
    $address_hash_from_js = trim($_POST['address_hash']);
    $action = trim($_POST['action']);
    $guest_identifier_ajax = trim($_POST['guest_id']);

    if (empty($address_hash_from_js) || !preg_match('/^[a-f0-9]{32}$/', $address_hash_from_js)) {
        $response['message'] = 'شناسه آدرس ارسالی نامعتبر است.';
        echo json_encode($response);
        exit;
    }
    if (empty($guest_identifier_ajax) || !preg_match('/^[a-zA-Z0-9\-_=]{20,}$/', $guest_identifier_ajax)) {
        $response['message'] = 'شناسه کاربر مهمان نامعتبر است.';
        error_log("AJAX_TOGGLE_GUEST_PIN: Invalid guest_identifier received: " . $guest_identifier_ajax);
        echo json_encode($response);
        exit;
    }
    if ($action !== 'pin' && $action !== 'unpin') {
        $response['message'] = 'عملیات درخواستی نامعتبر است.';
        echo json_encode($response);
        exit;
    }

    $db = null;
    try {
        if (!class_exists('Database')) { throw new Exception("کلاس Database در دسترس نیست (ajax_toggle_guest_pin)."); }
        $dbInstance = Database::getInstance();
        $db = $dbInstance->getConnection();
        if (!$db instanceof PDO) { throw new Exception("اتصال به پایگاه داده ناموفق بود (ajax_toggle_guest_pin).");}

        $response['debug']['action'] = $action;
        $response['debug']['guest_id'] = $guest_identifier_ajax;
        $response['debug']['address_hash'] = $address_hash_from_js;

        if ($action === 'pin') {
            $sqlCheck = "SELECT id FROM guest_pinned_outages WHERE guest_identifier = :gid AND address_hash = :addr_hash";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->bindValue(':gid', $guest_identifier_ajax, PDO::PARAM_STR);
            $stmtCheck->bindValue(':addr_hash', $address_hash_from_js, PDO::PARAM_STR);
            $stmtCheck->execute();

            if ($stmtCheck->fetch()) {
                $response = ['success' => true, 'is_pinned' => true, 'message' => 'این آدرس قبلاً برای شما پین شده بود.'];
            } else {
                $sqlInsert = "INSERT INTO guest_pinned_outages (guest_identifier, address_hash, pinned_at) VALUES (:gid, :addr_hash, NOW())";
                $stmtInsert = $db->prepare($sqlInsert);
                $stmtInsert->bindValue(':gid', $guest_identifier_ajax, PDO::PARAM_STR);
                $stmtInsert->bindValue(':addr_hash', $address_hash_from_js, PDO::PARAM_STR);
                if ($stmtInsert->execute()) {
                    $response = ['success' => true, 'is_pinned' => true, 'message' => 'آدرس با موفقیت به علاقه‌مندی‌ها اضافه شد.'];
                } else {
                    $errorInfo = $stmtInsert->errorInfo();
                    $response['message'] = 'خطا در پین کردن آدرس در دیتابیس.';
                    $response['debug']['db_error'] = $errorInfo;
                    error_log("AJAX Guest Pin (pin) DB Execute Error: " . ($errorInfo[2] ?? 'Unknown DB error') . " - SQL: " . $sqlInsert . " - PARAMS: GID=" . $guest_identifier_ajax . " HASH=" . $address_hash_from_js);
                }
            }
        } elseif ($action === 'unpin') {
            $sqlDelete = "DELETE FROM guest_pinned_outages WHERE guest_identifier = :gid AND address_hash = :addr_hash";
            $stmtDelete = $db->prepare($sqlDelete);
            $stmtDelete->bindValue(':gid', $guest_identifier_ajax, PDO::PARAM_STR);
            $stmtDelete->bindValue(':addr_hash', $address_hash_from_js, PDO::PARAM_STR);
            if ($stmtDelete->execute()) {
                $response = ['success' => true, 'is_pinned' => false, 'message' => 'پین آدرس با موفقیت برداشته شد.'];
            } else {
                $errorInfo = $stmtDelete->errorInfo();
                $response['message'] = 'خطا در برداشتن پین آدرس از دیتابیس.';
                $response['debug']['db_error'] = $errorInfo;
                error_log("AJAX Guest Pin (unpin) DB Execute Error: " . ($errorInfo[2] ?? 'Unknown DB error') . " - SQL: " . $sqlDelete . " - PARAMS: GID=" . $guest_identifier_ajax . " HASH=" . $address_hash_from_js);
            }
        }

    } catch (PDOException $e_pdo) {
        $response['message'] = 'خطای پایگاه داده هنگام عملیات پین.';
        error_log("AJAX Guest Pin PDOException: " . $e_pdo->getMessage() . "\n" . $e_pdo->getTraceAsString());
        if(defined('DEBUG_MODE') && DEBUG_MODE) { $response['debug']['pdo_exception'] = $e_pdo->getMessage(); }
    } catch (Exception $e_gen) {
        $response['message'] = 'خطای سیستمی هنگام عملیات پین.';
        error_log("AJAX Guest Pin Exception: " . $e_gen->getMessage() . "\n" . $e_gen->getTraceAsString());
        if(defined('DEBUG_MODE') && DEBUG_MODE) { $response['debug']['general_exception'] = $e_gen->getMessage(); }
    }
} else {
    $response['message'] = 'داده‌های ارسالی ناقص یا متد درخواست اشتباه است.';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $response['message'] = 'متد درخواست باید POST باشد.';
    // اضافه کردن جزئیات بیشتر برای دیباگ پارامترهای گمشده
    $missing_params = [];
    if (!isset($_POST['address_hash'])) $missing_params[] = 'address_hash';
    if (!isset($_POST['action'])) $missing_params[] = 'action';
    if (!isset($_POST['guest_id'])) $missing_params[] = 'guest_id';
    if (!empty($missing_params)) {
        $response['message'] .= ' پارامتر(های) ارسال نشده: ' . implode(', ', $missing_params);
        $response['debug']['missing_params'] = $missing_params;
    }
    error_log("AJAX_TOGGLE_GUEST_PIN: Invalid request. METHOD: " . $_SERVER['REQUEST_METHOD'] . " POST_DATA: " . print_r($_POST, true));
}

// اطمینان از اینکه هیچ خروجی دیگری قبل از این ارسال نشده
if (headers_sent($file, $line) && (defined('DEBUG_MODE') && DEBUG_MODE) ) {
    error_log("AJAX_TOGGLE_GUEST_PIN WARNING: Headers already sent before final json_encode. Output started at {$file}:{$line}");
    // اگر هدرها ارسال شده و در حالت دیباگ هستیم، سعی می‌کنیم پاسخ JSON را هم بفرستیم
    // اما ممکن است کلاینت آن را به درستی دریافت نکند.
    if(!isset($response['debug']['headers_sent_error'])) {
        $response['debug']['headers_sent_error'] = "Output started at {$file}:{$line}";
    }
}
echo json_encode($response);
exit;
// بدون تگ پایانی ?>