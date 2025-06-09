<?php
// /public_html/user/profile.php

ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

$config_path_profile = dirname(__DIR__) . '/config.php';
if (file_exists($config_path_profile)) { require_once $config_path_profile; }
else { die("خطای سیستمی: فایل تنظیمات یافت نشد."); }

// auth_user_check.php حالا باید در همین پوشه user باشد
$auth_user_check_path = __DIR__ . '/auth_user_check.php';
if (file_exists($auth_user_check_path)) {
    require_once $auth_user_check_path;
} else {
    error_log("USER PROFILE FATAL: auth_user_check.php not found at " . $auth_user_check_path);
    die("خطای سیستمی: فایل احراز هویت کاربر یافت نشد. مسیر بررسی شده: " . htmlspecialchars($auth_user_check_path));
}
// $loggedInUserId, $loggedInUserName, $loggedInUserPhone از auth_user_check.php می‌آیند

$database_path_profile = ROOT_PATH . '/database.php';
if (!class_exists('Database', false)) {
    if (file_exists($database_path_profile)) { require_once $database_path_profile;
        if (!class_exists('Database', false)) { die("خطای بحرانی: تعریف کلاس Database یافت نشد."); }
    } else { die("خطای بحرانی: فایل دیتابیس یافت نشد."); }
}

// ... (بارگذاری jdf و تعریف site_url مانند قبل) ...

$pageTitle = "پروفایل کاربری - " . htmlspecialchars($loggedInUserName);
$message = null; $errorMessageForPage = null; $user_db_data = null; $db = null;

if (isset($_GET['registered']) && $_GET['registered'] === 'true') {
    $message = ['type' => 'success', 'text' => 'ثبت‌نام شما با موفقیت انجام شد. به سامانه خوش آمدید!'];
}

try {
    $dbInstance = Database::getInstance(); $db = $dbInstance->getConnection();
    if (!$db instanceof PDO) throw new Exception("اتصال به دیتابیس ناموفق بود.");

    if (isset($loggedInUserId)) {
        $stmtUser = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmtUser->execute([$loggedInUserId]);
        $user_db_data = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$user_db_data) { $errorMessageForPage = "اطلاعات کاربری یافت نشد."; }
    } else { $errorMessageForPage = "خطای احراز هویت: شناسه کاربر در دسترس نیست."; }

} catch (Exception $e) { $errorMessageForPage = "خطای سیستمی: " . htmlspecialchars($e->getMessage()); error_log("User Profile Ex: " . $e->getMessage()); }

if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="<?php echo site_url('assets/style.css'); ?>?v=<?php echo defined('ROOT_PATH') && file_exists(ROOT_PATH . '/assets/style.css') ? filemtime(ROOT_PATH . '/assets/style.css') : time(); ?>">
    <style>
        /* استایل‌های پایه برای صفحه پروفایل */
        body { font-family: 'IRANSansX', Tahoma, Arial, sans-serif; background-color: #f4f7f9; margin: 0; padding: 20px; }
        .profile-container { background-color: #fff; padding: 25px 30px; border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.1); max-width: 700px; margin: 30px auto; }
        .profile-container h1 { color: #2c3e50; margin-bottom: 20px; font-size: 1.8em; text-align: center; border-bottom: 1px solid #eee; padding-bottom: 15px;}
        .profile-info p { font-size: 1.05em; margin: 12px 0; color: #333; line-height: 1.8; }
        .profile-info strong { color: #0056b3; margin-left: 8px; }
        .message { padding: 12px 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid transparent; font-size: 0.95em; text-align: center;}
        .message.success { background-color: #d1e7dd; color: #0f5132; border-color: #badbcc; }
        .message.error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .actions-menu { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center;}
        .actions-menu a { display: inline-block; margin: 8px 5px; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-size: 0.95em; transition: background-color 0.2s ease, transform 0.1s ease; font-weight: 500; }
        .actions-menu a:hover { transform: translateY(-1px); }
        .btn-view-outages { background-color: #28a745; color: white; }
        .btn-view-outages:hover { background-color: #218838; }
        .btn-logout-user { background-color: #dc3545; color: white; }
        .btn-logout-user:hover { background-color: #c82333; }
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
    <div class="profile-container">
        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>

        <?php if (isset($message) && is_array($message)): ?>
            <p class="message <?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></p>
        <?php endif; ?>
        <?php if (isset($errorMessageForPage)): ?>
            <p class="message error"><?php echo htmlspecialchars($errorMessageForPage); ?></p>
        <?php endif; ?>

        <?php if ($user_db_data): ?>
            <div class="profile-info">
                <p><strong>نام شما:</strong> <?php echo htmlspecialchars($user_db_data['name']); ?></p>
                <p><strong>شماره تماس:</strong> <?php echo htmlspecialchars($user_db_data['phone']); ?></p>
                <p><strong>وضعیت حساب:</strong> <?php echo ($user_db_data['is_active'] ?? 0) ? '<span style="color:green; font-weight:bold;">فعال</span>' : '<span style="color:red; font-weight:bold;">غیرفعال</span>'; ?></p>
                <p><strong>تاریخ عضویت:</strong>
                    <?php
                    $createdAtDisplay = "-";
                    if (!empty($user_db_data['created_at'])) {
                        try { $dt = new DateTime($user_db_data['created_at']);
                              $createdAtDisplay = ($jdf_loaded_for_profile && function_exists('jdate')) ? jdate('l، j F Y', $dt->getTimestamp()) : $dt->format('Y-m-d');
                        } catch (Exception $e) {$createdAtDisplay = $user_db_data['created_at'];}
                    } echo htmlspecialchars($createdAtDisplay);
                    ?>
                </p>
                <p><strong>پکیج عضویت:</strong>
                    <?php
                    $packageDisplay = "<span style='color:#777;'>پکیجی فعال نشده است.</span>";
                    if (!empty($user_db_data['package_expiry_date'])) {
                        try {
                            $expiry_dt = new DateTime($user_db_data['package_expiry_date']);
                            $today_dt = new DateTime();
                            $today_start_of_day = new DateTime($today_dt->format('Y-m-d'));

                            if ($expiry_dt < $today_start_of_day) {
                                $packageDisplay = "<span style='color:red;font-weight:bold;'>منقضی شده در " . (($jdf_loaded_for_profile && function_exists('jdate')) ? jdate('Y/m/d', $expiry_dt->getTimestamp()) : $expiry_dt->format('Y-m-d')) . "</span>";
                            } else {
                                $interval = $expiry_dt->diff($today_start_of_day);
                                $days_left = $interval->days;
                                if ($today_start_of_day > $expiry_dt) $days_left = 0;
                                $packageDisplay = "<span style='color:green;'>فعال تا " . (($jdf_loaded_for_profile && function_exists('jdate')) ? jdate('Y/m/d', $expiry_dt->getTimestamp()) : $expiry_dt->format('Y-m-d')) . " (" . $days_left . " روز باقیمانده)</span>";
                            }
                        } catch (Exception $e) { $packageDisplay = "<span style='color:orange;'>تاریخ انقضا نامعتبر</span>";}
                    }
                    echo $packageDisplay;
                    ?>
                </p>
            </div>
        <?php elseif (!$errorMessageForPage) : // فقط اگر خطای اصلی صفحه نداشتیم این پیام را نشان بده ?>
             <p class="no-data">اطلاعات کاربری برای نمایش یافت نشد.</p>
        <?php endif; ?>

        <div class="actions-menu">
            <p><a href="<?php echo site_url('index.php'); ?>" class="btn-view-outages">مشاهده لیست خاموشی‌ها</a></p>
            <p><a href="<?php echo site_url('logout_user.php'); ?>" class="btn-logout-user">خروج از حساب کاربری</a></p>
        </div>
    </div>
</body>
</html>