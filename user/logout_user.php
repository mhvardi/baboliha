<?php
// /public_html/logout_user.php

// 1. بارگذاری config.php (برای دسترسی به تنظیمات سشن و BASE_URL)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    error_log("USER LOGOUT FATAL: config.php not found.");
    die("خطای سیستمی: فایل تنظیمات اصلی یافت نشد.");
}

// 2. شروع سشن با نام صحیح (اگر قبلاً در config.php شروع نشده)
if (session_status() === PHP_SESSION_NONE) {
    $user_session_name = defined('SESSION_NAME_USER') ? SESSION_NAME_USER : 'BabolOutageUserSession';
    session_name($user_session_name);
    @session_start();
}

// 3. پاک کردن تمام متغیرهای سشن کاربر
$_SESSION = [];

// 4. اگر از کوکی سشن استفاده می‌شود، آن را هم پاک کن
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), // نام سشن فعلی (باید SESSION_NAME_USER باشد)
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 5. در نهایت، سشن را از بین ببر
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// 6. هدایت کاربر به صفحه اصلی یا صفحه لاگین
$main_page_url = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/index.php' : 'index.php';
if (!headers_sent()) {
    header("Location: " . $main_page_url);
    exit;
} else {
    echo "<p>شما با موفقیت از حساب کاربری خود خارج شدید. <a href='" . htmlspecialchars($main_page_url) . "'>بازگشت به صفحه اصلی</a>.</p>";
    echo "<script>window.location.href='" . addslashes($main_page_url) . "';</script>";
    exit;
}
// بدون تگ پایانی ?>