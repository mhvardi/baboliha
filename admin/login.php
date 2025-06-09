<?php
// /public_html/admin/login.php

// 0. فعال کردن نمایش تمام خطاها برای دیباگ این فایل
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. بارگذاری فایل تنظیمات اصلی (از ریشه public_html)
$config_path_login = dirname(__DIR__) . '/config.php'; // مسیر به ریشه public_html
if (file_exists($config_path_login)) {
    require_once $config_path_login;
} else {
    error_log("ADMIN LOGIN FATAL: config.php not found at " . $config_path_login);
    die("خطای سیستمی: فایل تنظیمات اصلی (config.php) یافت نشد.");
}

// 2. شروع سشن با نام صحیح برای ادمین
// این باید قبل از هرگونه بررسی یا تنظیم متغیرهای $_SESSION باشد
if (session_status() === PHP_SESSION_NONE) {
    if (!defined('SESSION_NAME_ADMIN')) {
        error_log("ADMIN LOGIN FATAL: SESSION_NAME_ADMIN not defined in config.php.");
        die("خطای پیکربندی سشن ادمین.");
    }
    session_name(SESSION_NAME_ADMIN);
    session_set_cookie_params([
        'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 0,
        'path'     => defined('SESSION_PATH') ? SESSION_PATH : '/',
        'domain'   => defined('SESSION_DOMAIN') ? SESSION_DOMAIN : '',
        'secure'   => defined('SESSION_SECURE') ? SESSION_SECURE : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        'httponly' => defined('SESSION_HTTPONLY') ? SESSION_HTTPONLY : true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 3. بارگذاری کلاس دیتابیس (از ریشه public_html)
$database_path_login = ROOT_PATH . '/database.php'; // ROOT_PATH از config.php
if (!class_exists('Database', false)) {
    if (file_exists($database_path_login)) {
        require_once $database_path_login;
        if (!class_exists('Database', false)) {
            error_log("ADMIN LOGIN FATAL: Database class not defined in " . $database_path_login);
            die("خطای بحرانی: تعریف کلاس Database یافت نشد.");
        }
    } else {
        error_log("ADMIN LOGIN FATAL: database.php not found at " . $database_path_login);
        die("خطای بحرانی: فایل کلاس دیتابیس (database.php) یافت نشد.");
    }
}

// تابع site_url اگر در config.php تعریف نشده
if (!function_exists('site_url')) {
    function site_url($path = '') {
        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }
}


$pageTitle = "ورود به پنل مدیریت";
$loginError = null;
$form_data = $_POST; // برای حفظ نام کاربری در صورت خطا
$db = null;

// اگر کاربر از قبل لاگین کرده، به داشبورد هدایت کن
if (isset($_SESSION['admin_user_id'])) {
    $dashboard_page_url = site_url('admin/index.php'); // مسیر به داشبورد ادمین
    if (!headers_sent()) {
        header("Location: " . $dashboard_page_url);
        exit;
    } else {
        // اگر هدرها قبلاً ارسال شده‌اند (که در این مرحله نباید اتفاق بیفتد)
        echo "<p>شما قبلاً وارد شده‌اید. در حال انتقال به <a href='" . htmlspecialchars($dashboard_page_url) . "'>داشبورد</a>...</p>";
        echo "<script>window.location.href='" . addslashes($dashboard_page_url) . "';</script>";
        exit;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login_submit'])) {
    $username = trim($form_data['username'] ?? '');
    $password = $_POST['password'] ?? ''; // پسورد را از $_POST مستقیم بخوانیم

    if (empty($username) || empty($password)) {
        $loginError = 'نام کاربری و رمز عبور نمی‌توانند خالی باشند.';
    } else {
        try {
            if (!class_exists('Database')) {throw new Exception("کلاس Database در دسترس نیست.");}
            $dbInstance = Database::getInstance();
            $db = $dbInstance->getConnection();
            if (!$db instanceof PDO) {throw new Exception("اتصال به پایگاه داده ناموفق بود.");}

            $stmt = $db->prepare("SELECT id, username, password_hash FROM admins WHERE username = :username LIMIT 1");
            $stmt->bindParam(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin && password_verify($password, $admin['password_hash'])) {
                // لاگین موفقیت آمیز، قبل از تنظیم سشن جدید، سشن قبلی را (اگر وجود دارد) بازسازی کن
                // session_start() باید در ابتدای فایل و با نام صحیح انجام شده باشد
                if (session_status() === PHP_SESSION_ACTIVE) { // اطمینان از فعال بودن سشن
                    session_regenerate_id(true); // <<< این خط حالا باید درست کار کند
                } else {
                    // این حالت بعید است چون در ابتدا session_start داریم
                    error_log("ADMIN LOGIN WARNING: Session not active before regenerate_id.");
                    // اگر سشن فعال نبود، دوباره با نام صحیح استارت کن
                    session_name(SESSION_NAME_ADMIN);
                    session_start();
                    session_regenerate_id(true);
                }

                $_SESSION['admin_user_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];

                $updateStmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = :id");
                $updateStmt->bindParam(':id', $admin['id'], PDO::PARAM_INT);
                $updateStmt->execute();

                $dashboard_page_url = site_url('admin/index.php');
                if (!headers_sent()) { // این خط 51 شما بود
                    header("Location: " . $dashboard_page_url);
                    exit;
                } else {
                    // این اتفاق نباید بیفتد اگر هیچ خروجی قبل از این نبوده
                    error_log("ADMIN LOGIN ERROR: Headers already sent before redirect to dashboard. Output started somewhere.");
                    echo "<p>ورود موفق بود. در حال انتقال به <a href='" . htmlspecialchars($dashboard_page_url) . "'>داشبورد</a>...</p>";
                    echo "<script>window.location.href='" . addslashes($dashboard_page_url) . "';</script>";
                    exit;
                }
            } else {
                $loginError = 'نام کاربری یا رمز عبور اشتباه است.';
            }
        } catch (PDOException $e) {
            $loginError = "خطای پایگاه داده هنگام بررسی اطلاعات ورود.";
            error_log("Admin Login Auth DB Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        } catch (Exception $e) {
            $loginError = 'خطای سیستمی: ' . htmlspecialchars($e->getMessage());
            error_log("Admin Login General Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }
}

$form_username_value = htmlspecialchars($form_data['username'] ?? '');

// ارسال هدر Content-Type اگر قبلاً ارسال نشده باشد
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - سامانه خاموشی برق</title>
    <link rel="stylesheet" href="<?php echo site_url('assets/admin_style.css'); ?>?v=<?php echo time(); ?>"> <style>
        body { font-family: 'IRANSansX', Tahoma, Arial, sans-serif; background-color: #f4f7f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .login-container { background-color: #fff; padding: 35px 45px; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.12); width: 100%; max-width: 420px; text-align: center; }
        .login-container h1 { color: #2c3e50; margin-bottom: 30px; font-size: 1.8em; font-weight: 600;}
        .form-group { margin-bottom: 25px; text-align: right; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #495057; font-size: 0.95em; }
        .form-group input[type="text"], .form-group input[type="password"] {
            width: 100%; padding: 14px; border: 1px solid #ced4da; border-radius: 6px; box-sizing: border-box; font-size: 1em;
            font-family: 'IRANSansX', Tahoma, Arial, sans-serif;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-group input[type="text"]:focus, .form-group input[type="password"]:focus {
            border-color: #1abc9c; /* سبز آبی */
            box-shadow: 0 0 0 0.2rem rgba(26, 188, 156, .25);
            outline: none;
        }
        .login-button {
            width: 100%; padding: 14px; background-image: linear-gradient(to right, #1abc9c, #16a085); /* سبز آبی */
            color: white; border: none; border-radius: 6px;
            font-size: 1.1em; font-weight: 500; cursor: pointer; transition: background-image 0.2s ease, transform 0.1s ease;
            font-family: 'IRANSansX', Tahoma, Arial, sans-serif;
        }
        .login-button:hover { background-image: linear-gradient(to right, #16a085, #138671); }
        .login-button:active { transform: translateY(1px); }
        .error-message {
            background-color: #ffebee; color: #c62828; border: 1px solid #ef9a9a;
            padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center; font-size: 0.95em;
        }
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
    <div class="login-container">
        <h1>ورود به پنل مدیریت</h1>
        <?php if (isset($loginError) && !empty($loginError)): ?>
            <p class="error-message"><?php echo htmlspecialchars($loginError); ?></p>
        <?php endif; ?>

        <form action="<?php echo site_url('admin/login.php'); ?>" method="post">
            <div class="form-group">
                <label for="username">نام کاربری:</label>
                <input type="text" id="username" name="username" value="<?php echo $form_username_value; ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">رمز عبور:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" name="admin_login_submit" class="login-button">ورود</button>
        </form>
    </div>
</body>
</html>