<?php
// /public_html/user/register.php

// 0. فعال کردن نمایش خطاها برای دیباگ
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// 1. بارگذاری فایل تنظیمات اصلی
$config_path_register = dirname(__DIR__) . '/config.php';
if (file_exists($config_path_register)) { require_once $config_path_register; }
else { die("خطای سیستمی: فایل تنظیمات اصلی یافت نشد."); }

// 2. شروع سشن با نام صحیح کاربر
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME_USER);
    session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'path' => SESSION_PATH, 'domain' => SESSION_DOMAIN, 'secure' => SESSION_SECURE, 'httponly' => SESSION_HTTPONLY, 'samesite' => 'Lax']);
    session_start();
}

// 3. بارگذاری کلاس دیتابیس
$database_path_register = ROOT_PATH . '/database.php';
if (!class_exists('Database', false)) {
    if (file_exists($database_path_register)) { require_once $database_path_register;
        if (!class_exists('Database', false)) { die("خطای بحرانی: تعریف کلاس Database یافت نشد."); }
    } else { die("خطای بحرانی: فایل کلاس دیتابیس یافت نشد."); }
}

// بارگذاری jdf (اختیاری)
$jdf_loaded_for_user_register = false;
if (function_exists('jdate')) { $jdf_loaded_for_user_register = true; }
else { /* ... (کد بارگذاری jdf مانند قبل) ... */ }

$pageTitle = "ثبت‌نام مشترکین جدید";
$message = null;
$form_data = $_POST;
$db = null;

if (isset($_SESSION['user_id'])) { // اگر از قبل لاگین کرده
    $profile_page_url = site_url('profile.php');
    if (!headers_sent()) { header("Location: " . $profile_page_url); exit; }
    else { echo "<script>window.location.href='" . addslashes($profile_page_url) . "';</script>"; exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_user'])) {
    $name = trim($form_data['name'] ?? '');
    $phone = trim($form_data['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $form_validation_error = false;

    if (empty($name)) { $message = ['type' => 'error', 'text' => 'نام نمی‌تواند خالی باشد.']; $form_validation_error = true; }
    if (!$form_validation_error && empty($phone)) { $message = ['type' => 'error', 'text' => 'شماره تلفن نمی‌تواند خالی باشد.']; $form_validation_error = true; }
    if (!$form_validation_error && !preg_match('/^09[0-9]{9}$/', $phone)) { $message = ['type' => 'error', 'text' => 'فرمت شماره تلفن (09xxxxxxxxxx) صحیح نیست.']; $form_validation_error = true; }
    if (!$form_validation_error && empty($password)) { $message = ['type' => 'error', 'text' => 'رمز عبور نمی‌تواند خالی باشد.']; $form_validation_error = true; }
    if (!$form_validation_error && mb_strlen($password) < 6) { $message = ['type' => 'error', 'text' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']; $form_validation_error = true; }
    if (!$form_validation_error && $password !== $password_confirm) { $message = ['type' => 'error', 'text' => 'رمز عبور و تکرار آن یکسان نیستند.']; $form_validation_error = true; }

    if (!$form_validation_error) {
        try {
            $dbInstance = Database::getInstance();
            $db = $dbInstance->getConnection();
            if (!$db instanceof PDO) throw new Exception("اتصال به پایگاه داده ناموفق بود.");

            $stmtCheck = $db->prepare("SELECT id FROM users WHERE phone = :phone");
            $stmtCheck->bindParam(':phone', $phone, PDO::PARAM_STR);
            $stmtCheck->execute();
            if ($stmtCheck->fetch()) {
                $message = ['type' => 'error', 'text' => 'این شماره تلفن قبلاً ثبت شده. لطفاً <a href="'.site_url('login_user.php').'">وارد شوید</a>.'];
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $insertQuery = "INSERT INTO users (name, phone, password_hash, is_active, created_at, updated_at) VALUES (:name, :phone, :password_hash, 1, NOW(), NOW())";
                $stmt = $db->prepare($insertQuery);
                $stmt->bindParam(':name', $name, PDO::PARAM_STR);
                $stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
                $stmt->bindParam(':password_hash', $password_hash, PDO::PARAM_STR);
                
                if ($stmt->execute()) {
                    $_SESSION['user_id'] = $db->lastInsertId();
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_phone'] = $phone;
                    session_regenerate_id(true);

                    $profile_page_url = site_url('profile.php?registered=true');
                    if (!headers_sent()) { header("Location: " . $profile_page_url); exit; }
                    else { echo "<script>window.location.href='" . addslashes($profile_page_url) . "';</script>"; exit;}
                } else { $message = ['type' => 'error', 'text' => 'خطا در ثبت‌نام.']; error_log("User Reg DB Error: " . print_r($stmt->errorInfo(), true)); }
            }
        } catch (Exception $e) { $message = ['type' => 'error', 'text' => 'خطای سیستمی: ' . htmlspecialchars($e->getMessage())]; error_log("User Reg Exception: " . $e->getMessage()); }
    }
}
$form_name_value = htmlspecialchars($form_data['name'] ?? '');
$form_phone_value = htmlspecialchars($form_data['phone'] ?? '');

if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - سامانه خاموشی</title>
    <link rel="stylesheet" href="<?php echo site_url('assets/style.css'); ?>?v=<?php echo time(); ?>">
    <style> /* ... CSS فرم ثبت‌نام مانند قبل ... */ </style>
</head>
<body>
    <div class="form-container">
        <h1>ثبت‌نام مشترک جدید</h1>
        <?php if (isset($message) && is_array($message)): ?>
            <p class="message <?php echo htmlspecialchars($message['type']); ?>"><?php echo $message['text']; ?></p>
        <?php endif; ?>
        <form action="<?php echo site_url('register.php'); ?>" method="post">
            </form>
        </div>
</body>
</html>