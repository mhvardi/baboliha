<?php
// /public_html/user/auth_user_check.php

// این فایل باید در ابتدای صفحاتی که نیاز به لاگین کاربر دارند، include شود.

// 1. بارگذاری config.php برای دسترسی به ثابت‌ها
if (file_exists(dirname(__DIR__) . '/config.php')) { // مسیر از user/ به public_html/
    require_once dirname(__DIR__) . '/config.php';
} else {
    error_log("AUTH_USER_CHECK FATAL: config.php not found.");
    die("خطای پیکربندی.");
}

// 2. شروع/ادامه سشن با نام صحیح برای کاربران
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME_USER); // از config.php
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME, 'path' => SESSION_PATH,
        'domain' => SESSION_DOMAIN, 'secure' => SESSION_SECURE,
        'httponly' => SESSION_HTTPONLY, 'samesite' => 'Lax'
    ]);
    session_start();
}

// 3. بررسی وضعیت لاگین
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    // ذخیره URL درخواستی فعلی برای بازگشت پس از لاگین
    if (isset($_SERVER['REQUEST_URI'])) {
        $_SESSION['redirect_url_after_user_login'] = $_SERVER['REQUEST_URI'];
    }

    // login_user.php باید توسط .htaccess به user/login_user.php مپ شود
    $login_page_user_url = site_url('login_user.php');

    if (!headers_sent()) {
        header("Location: " . $login_page_user_url);
        exit;
    } else {
        error_log("AUTH_USER_CHECK: Headers already sent before redirecting to user login page: " . $login_page_user_url);
        echo "<p style='text-align:center; font-family:Tahoma, sans-serif; padding:20px; border:1px solid red; background:#ffebeb;'>برای دسترسی به این صفحه، لطفاً ابتدا <a href='" . htmlspecialchars($login_page_user_url) . "'>وارد شوید</a>.</p>";
        exit;
    }
}

// اگر کاربر لاگین کرده، متغیرها را تنظیم کن
$loggedInUserId = $_SESSION['user_id'];
$loggedInUserName = $_SESSION['user_name'] ?? 'کاربر';
$loggedInUserPhone = $_SESSION['user_phone'] ?? '';
// بدون تگ پایانی ?>