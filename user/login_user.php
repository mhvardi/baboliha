<?php
// /public_html/user/login_user.php

ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

$config_path_login = dirname(__DIR__) . '/config.php';
if (file_exists($config_path_login)) { require_once $config_path_login; }
else { die("خطای سیستمی: فایل تنظیمات یافت نشد."); }

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME_USER);
    session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'path' => SESSION_PATH, 'domain' => SESSION_DOMAIN, 'secure' => SESSION_SECURE, 'httponly' => SESSION_HTTPONLY, 'samesite' => 'Lax']);
    session_start();
}

$database_path_login = ROOT_PATH . '/database.php';
if (!class_exists('Database', false)) {
    if (file_exists($database_path_login)) { require_once $database_path_login;
        if (!class_exists('Database', false)) { die("خطای بحرانی: تعریف کلاس Database یافت نشد."); }
    } else { die("خطای بحرانی: فایل دیتابیس یافت نشد."); }
}

$pageTitle = "ورود مشترکین";
$message = null;
$form_data = $_POST;
$db = null;

if (isset($_SESSION['user_id'])) {
    $redirect_url = $_SESSION['redirect_url_after_user_login'] ?? site_url('profile.php');
    unset($_SESSION['redirect_url_after_user_login']);
    if (!headers_sent()) { header("Location: " . $redirect_url); exit; }
    else { echo "<script>window.location.href='" . addslashes($redirect_url) . "';</script>"; exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'])) {
    $phone = trim($form_data['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $form_validation_error = false;

    if (empty($phone)) { $message = ['type' => 'error', 'text' => 'شماره تلفن نمی‌تواند خالی باشد.']; $form_validation_error = true; }
    if (!$form_validation_error && empty($password)) { $message = ['type' => 'error', 'text' => 'رمز عبور نمی‌تواند خالی باشد.']; $form_validation_error = true; }
    if (!$form_validation_error && !preg_match('/^09[0-9]{9}$/', $phone)) { $message = ['type' => 'error', 'text' => 'فرمت شماره تلفن صحیح نیست.']; $form_validation_error = true; }

    if (!$form_validation_error) {
        try {
            $dbInstance = Database::getInstance(); $db = $dbInstance->getConnection();
            if (!$db instanceof PDO) throw new Exception("اتصال به دیتابیس ناموفق بود.");

            $stmt = $db->prepare("SELECT id, name, phone, password_hash, is_active FROM users WHERE phone = :phone LIMIT 1");
            $stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['is_active'] == 1) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_phone'] = $user['phone'];
                    session_regenerate_id(true);
                    $redirect_url_after_login = $_SESSION['redirect_url_after_user_login'] ?? site_url('profile.php');
                    unset($_SESSION['redirect_url_after_user_login']);
                    if (!headers_sent()) { header("Location: " . $redirect_url_after_login); exit; }
                    else { echo "<script>window.location.href='" . addslashes($redirect_url_after_login) . "';</script>"; exit;}
                } else { $message = ['type' => 'error', 'text' => 'حساب کاربری شما غیرفعال است.']; }
            } else { $message = ['type' => 'error', 'text' => 'شماره تلفن یا رمز عبور اشتباه است.']; }
        } catch (Exception $e) { $message = ['type' => 'error', 'text' => 'خطای سیستمی: ' . htmlspecialchars($e->getMessage())]; error_log("User Login Ex: " . $e->getMessage()); }
    }
}
$form_phone_value = htmlspecialchars($form_data['phone'] ?? '');

if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - سامانه خاموشی برق</title>
    <link rel="stylesheet" href="<?php echo site_url('assets/style.css'); ?>?v=<?php echo defined('ROOT_PATH') && file_exists(ROOT_PATH . '/assets/style.css') ? filemtime(ROOT_PATH . '/assets/style.css') : time(); ?>">
    <style>
        body { font-family: 'IRANSansX', Tahoma, Arial, sans-serif; background-color: #f4f7f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .form-container { background-color: #fff; padding: 30px 40px; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); width: 100%; max-width: 450px; text-align: center; }
        .form-container h1 { color: #2c3e50; margin-bottom: 25px; font-size: 1.7em; font-weight: 600;}
        .form-group { margin-bottom: 20px; text-align: right; }
        .form-group label { display: block; margin-bottom: 7px; font-weight: 500; color: #495057; font-size: 0.9em; }
        .form-group input[type="tel"], .form-group input[type="password"] {
            width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; box-sizing: border-box; font-size: 1em;
            font-family: 'IRANSansX', Tahoma, Arial, sans-serif;
        }
        .form-group input:focus { border-color: #28a745; box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, .20); outline: none; }
        .btn-submit-user {
            width: 100%; padding: 12px; background-image: linear-gradient(to right, #28a745, #218838); /* سبز */
            color: white; border: none; border-radius: 6px; font-size: 1.05em; font-weight: 500; cursor: pointer;
            font-family: 'IRANSansX', Tahoma, sans-serif; transition: background-image 0.2s ease;
        }
        .btn-submit-user:hover { background-image: linear-gradient(to right, #218838, #1e7e34); }
        .message { padding: 12px; margin-bottom: 20px; border-radius: 5px; border: 1px solid transparent; font-size: 0.9em;}
        .message.success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .register-link { margin-top: 20px; font-size: 0.9em; }
        .register-link a { color: #007bff; text-decoration: none; font-weight: 500; }
        .register-link a:hover { text-decoration: underline; }
         @font-face {
            font-family: 'IRANSansX';
            src: url('<?php echo site_url('assets/fonts/IRANSansXVF.ttf'); ?>') format('truetype-variations'),
                 url('<?php echo site_url('assets/fonts/IRANSansXVF.ttf'); ?>') format('truetype');
            font-weight: 100 900;
            font-display: swap;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h1>ورود به حساب کاربری</h1>
        <?php if (isset($message) && is_array($message)): ?>
            <p class="message <?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></p>
        <?php endif; ?>

        <form action="<?php echo site_url('user/login_user.php'); // یا login_user.php اگر .htaccess کار می‌کند ?>" method="post">
            <div class="form-group">
                <label for="phone">شماره تلفن همراه:</label>
                <input type="tel" id="phone" name="phone" value="<?php echo $form_phone_value; ?>" pattern="^09[0-9]{9}$" title="مثال: 09123456789" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">رمز عبور:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" name="login_user" class="btn-submit-user">ورود</button>
        </form>
        <p class="register-link">هنوز حساب کاربری ندارید؟ <a href="<?php echo site_url('register.php'); // با فرض اینکه .htaccess register.php را به user/register.php مپ می‌کند ?>">ثبت‌نام کنید</a></p>
        <p class="register-link" style="margin-top:5px;"><a href="<?php echo site_url('index.php'); ?>">بازگشت به صفحه اصلی</a></p>
    </div>
</body>
</html>