<?php
// /public_html/admin/auth_check.php

// config.php باید قبل از این فایل توسط صفحه فراخواننده include شده باشد
// و session_start() را انجام داده باشد.

if (!defined('BASE_URL')) { // یک بررسی ساده که config.php لود شده
    error_log("AUTH_CHECK FATAL ERROR: config.php (or BASE_URL constant) not loaded before auth_check.php");
    die("خطای پیکربندی سیستم.");
}

if (session_status() === PHP_SESSION_NONE) {
    // این نباید اتفاق بیفتد اگر config.php سشن را استارت کرده
    error_log("AUTH_CHECK WARNING: Session was not started prior to auth_check.php. Starting now.");
    $session_name = defined('SESSION_NAME_ADMIN') ? SESSION_NAME_ADMIN : 'BabolOutageAdminSession';
    session_name($session_name);
    @session_start(); // @ برای جلوگیری از وارنینگ اگر به هر دلیلی قبلا استارت شده بود
}

if (!isset($_SESSION['admin_user_id'])) {
    $login_page_url = rtrim(BASE_URL, '/') . '/admin/login.php';
    if (!headers_sent()) {
        header("Location: " . $login_page_url);
        exit;
    } else {
        // این حالت بسیار بعید است چون auth_check باید اولین include در صفحات ادمین باشد
        error_log("AUTH_CHECK FAILED TO REDIRECT: Headers already sent before redirecting to login page.");
        echo "دسترسی غیرمجاز. لطفاً ابتدا وارد شوید. <a href='" . htmlspecialchars($login_page_url) . "'>صفحه ورود</a>";
        exit;
    }
}

$loggedInAdminId = $_SESSION['admin_user_id'];
$loggedInAdminUsername = $_SESSION['admin_username'] ?? 'ادمین';
// بدون تگ پایانی ?>