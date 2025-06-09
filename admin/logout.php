<?php
// /public_html/admin/logout.php

// 1. بارگذاری تنظیمات اصلی (که session_start() را هم انجام می‌دهد)
// chdir برای اطمینان از صحت مسیر، اگر logout.php مستقیماً فراخوانی شود
if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} else {
    // اگر config.php پیدا نشد، یک خطای پایه برای لاگ و خروج امن
    error_log("LOGOUT FATAL ERROR: config.php not found. Path: " . dirname(__DIR__) . '/config.php');
    die("خطای سیستمی: فایل تنظیمات یافت نشد.");
}

// 2. پاک کردن تمام متغیرهای سشن
$_SESSION = [];

// 3. اگر از کوکی سشن استفاده می‌شود، آن را هم پاک کن
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), // نام سشن (باید از config.php آمده باشد)
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 4. در نهایت، سشن را از بین ببر
// این باید بعد از پاک کردن $_SESSION و کوکی انجام شود.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// 5. هدایت کاربر به صفحه لاگین
// اطمینان از تعریف BASE_URL
$login_page_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/admin/login.php';

// قبل از header() نباید هیچ خروجی وجود داشته باشد
if (!headers_sent()) {
    header("Location: " . $login_page_url);
    exit;
} else {
    // اگر به هر دلیلی هدرها قبلاً ارسال شده‌اند (که نباید)، یک لینک برای کاربر نمایش بده
    echo "شما با موفقیت از سیستم خارج شدید. برای ورود مجدد <a href='" . htmlspecialchars($login_page_url) . "'>اینجا کلیک کنید</a>.";
    error_log("LOGOUT WARNING: Headers already sent before redirecting to login page.");
    exit;
}
// بدون تگ پایانی ?>