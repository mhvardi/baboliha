<?php
// /public_html/config.php

// 0. فعال کردن نمایش خطاها در ابتدای همه چیز (فقط برای دیباگ)
// در محیط نهایی، این بخش باید برای امنیت بیشتر مدیریت شود.
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', true); // برای توسعه true، برای محصول نهایی false
}

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// 1. مسیرهای اصلی
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__); // __DIR__ به پوشه‌ای اشاره می‌کند که این فایل (config.php) در آن قرار دارد.
}

// 2. تنظیمات پایگاه داده
if (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }
if (!defined('DB_NAME')) { define('DB_NAME', 'baboliha_bargh'); }
if (!defined('DB_USER')) { define('DB_USER', 'baboliha_bargh'); }
if (!defined('DB_PASS')) { define('DB_PASS', 'Xgz6B0wqFQ1G3m4t'); } // لطفاً در محیط نهایی این را امن‌تر مدیریت کنید
if (!defined('DB_CHARSET')) { define('DB_CHARSET', 'utf8mb4'); }

// 3. URL پایه سایت
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'baboliha.ir'; // یک پیش‌فرض اگر HTTP_HOST ست نشده باشد
    define('BASE_URL', $protocol . $host);
}

// 4. تنظیمات سشن
if (!defined('SESSION_NAME_ADMIN')) { define('SESSION_NAME_ADMIN', 'BabolOutageAdminSID'); } // نامی متفاوت برای سشن ادمین
if (!defined('SESSION_NAME_USER')) { define('SESSION_NAME_USER', 'BabolOutageUserSID'); }   // نامی متفاوت برای سشن کاربر
if (!defined('SESSION_LIFETIME')) { define('SESSION_LIFETIME', 0); } // 0 = تا زمانی که مرورگر بسته شود
if (!defined('SESSION_PATH')) { define('SESSION_PATH', '/'); }
if (!defined('SESSION_DOMAIN')) { define('SESSION_DOMAIN', ''); }   // برای تمام زیردامنه‌ها اگر لازم است، دامنه را مشخص کنید
$is_https_config_check = (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
if (!defined('SESSION_SECURE')) { define('SESSION_SECURE', $is_https_config_check); } // فقط در HTTPS ارسال شود
if (!defined('SESSION_HTTPONLY')) { define('SESSION_HTTPONLY', true); } // جلوگیری از دسترسی جاوااسکریپت به کوکی سشن

// 5. تنظیمات منطقه زمانی
date_default_timezone_set('Asia/Tehran');

// 6. ثابت‌های مربوط به اسکرپر اطلاعات خاموشی
if (!defined('SCRAPER_BASE_URL')) { define('SCRAPER_BASE_URL', 'http://80.191.255.65/'); }
if (!defined('SCRAPER_USER_AGENT')) { define('SCRAPER_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 BabolihaScraper/1.0'); }
if (!defined('COOKIE_FILE')) { define('COOKIE_FILE', ROOT_PATH . '/cookie.txt'); } // فایل کوکی در ریشه پروژه
if (!defined('BABOL_CITY_CODE')) { define('BABOL_CITY_CODE', '990090345'); }     // کد شهر بابل برای اسکرپر
if (!defined('ALL_AREAS_CODE')) { define('ALL_AREAS_CODE', '-1'); }          // کد "همه امور" برای اسکرپر

// 7. کلید API برای سرویس cron-job.org
if (!defined('CRONJOBORG_API_KEY')) { define('CRONJOBORG_API_KEY', 'stlGeP7m4faYuQyFUDlPe6mTjDP0b/Udx6rzGaQjVnw='); }

// 8. تنظیمات پیش‌فرض پیامک (LimoSMS) - اینها باید توسط پنل ادمین قابل تغییر باشند و از دیتابیس خوانده شوند
// اما مقادیر پیش‌فرض یا fallback می‌توانند اینجا باشند.
if (!defined('LIMOSMS_API_KEY')) { define('LIMOSMS_API_KEY', 'کلید_API_لیمو_پیامک_شما'); } // <<<--- این را با کلید واقعی خودتان جایگزین کنید
if (!defined('LIMOSMS_SENDER_NUMBER')) { define('LIMOSMS_SENDER_NUMBER', 'شماره_فرستنده_شما'); } // <<<--- این را با شماره واقعی خودتان جایگزین کنید
if (!defined('LIMOSMS_DEFAULT_PATTERN_ID')) { define('LIMOSMS_DEFAULT_PATTERN_ID', '1330'); } // کد پترن پیش‌فرض شما
if (!defined('DEFAULT_SUBSCRIBER_SMS_TEMPLATE')) {
    define('DEFAULT_SUBSCRIBER_SMS_TEMPLATE', "قطعی برق امروز\nتاریخ: {outage_date}\nمحدوده ({address_title}): {outage_address}\nاز ساعت: {outage_start_time}\nتا ساعت: {outage_end_time}");
}
// تنظیمات مربوط به زمانبندی ارسال پیامک که بعداً از دیتابیس خوانده می‌شود
// if (!defined('SMS_ALLOWED_SEND_TIMES')) { define('SMS_ALLOWED_SEND_TIMES', '07:00,08:00,13:00,14:00,19:00,20:00'); }
// if (!defined('SMS_SEND_TYPE')) { define('SMS_SEND_TYPE', 'at_scheduled_times_for_active'); }
// if (!defined('SMS_MAX_SENDS_PER_OUTAGE_PER_DAY')) { define('SMS_MAX_SENDS_PER_OUTAGE_PER_DAY', 1); }



// 10. تابع کمکی site_url
if (!function_exists('site_url')) {
    function site_url($path = '') {
        // BASE_URL باید قبلاً تعریف شده باشد
        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }
}

// 11. تابع دیباگ پایه (فقط در صورت فعال بودن DEBUG_MODE خروجی دارد)
if (!function_exists('debug_echo')) {
    function debug_echo($message, $isHtml = true) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $timestamp = "[" . date('Y-m-d H:i:s') . "] ";
            if (PHP_SAPI === 'cli' || !$isHtml || !isset($_SERVER['REQUEST_METHOD']) || headers_sent()) {
                // برای CLI یا اگر هدرها ارسال شده، یا اگر خروجی غیر HTML است
                error_log($timestamp . preg_replace('/<br\s*\/?>/i', "\n", $message)); // همیشه در لاگ بنویس
                if(PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) echo $timestamp . preg_replace('/<br\s*\/?>/i', "\n", $message) . "\n";
                // برای وب اگر هدر ارسال شده، نمی‌توان echo کرد مگر اینکه در بافر باشد
            } else {
                // برای وب اگر هدرها هنوز ارسال نشده‌اند (کمتر محتمل برای استفاده مستقیم در config)
                echo $timestamp . $message . ($isHtml ? "<br>\n" : "\n");
            }
        }
    }
}

// 12. شروع سشن (مهم: باید بعد از تعریف ثابت‌های سشن و قبل از هر خروجی باشد)
// این بخش حالا در فایل‌های ورودی هر بخش (ادمین و کاربر) به صورت جداگانه با نام سشن مربوطه انجام می‌شود
// تا از تداخل جلوگیری شود. پس این بخش را از config.php حذف می‌کنیم.
/*
if (session_status() === PHP_SESSION_NONE) {
    // نام سشن باید بر اساس اینکه کاربر ادمین است یا عادی، قبل از این تنظیم شود.
    // اینجا یک نام پیش‌فرض در نظر می‌گیریم اگر هیچ‌کدام ست نشده باشند.
    // $session_name_to_use = session_name(); // نام سشن فعلی را بگیر
    // if (empty($session_name_to_use) || $session_name_to_use === 'PHPSESSID') {
    //     session_name(SESSION_NAME_USER); // یا یک نام عمومی‌تر
    // }
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME, 'path' => SESSION_PATH,
        'domain' => SESSION_DOMAIN, 'secure' => SESSION_SECURE,
        'httponly' => SESSION_HTTPONLY, 'samesite' => 'Lax'
    ]);
    if (!headers_sent()) { // فقط اگر هدرها ارسال نشده‌اند
        @session_start();
    } else {
        error_log("CONFIG.PHP WARNING: Could not start session because headers already sent.");
    }
}
*/

// بدون تگ پایانی ?>