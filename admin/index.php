<?php
// /public_html/admin/index.php (داشبورد ادمین)

$pageTitle = "داشبورد مدیریت";

// 1. لود کردن هدر ادمین (این فایل $db و سایر موارد را آماده می‌کند)
if (file_exists(__DIR__ . '/layouts/_header.php')) {
    require_once __DIR__ . '/layouts/_header.php';
} else {
    error_log("ADMIN DASHBOARD FATAL ERROR: Admin header layout file not found.");
    die("خطای سیستمی: فایل لایوت هدر ادمین یافت نشد.");
}
// از اینجا به بعد، متغیر $db (که باید سراسری شده باشد یا در همین scope از هدر آمده باشد),
// $loggedInAdminUsername, $jdf_loaded_for_admin_layout, site_url(), $current_page_admin
// باید در دسترس باشند.

// --- مقداردهی اولیه متغیرهای این صفحه ---
$lastCronRunDisplay = "نامشخص (داده‌ای ثبت نشده)";
$nextServerCronRunDisplay = "نامشخص";
$nextServerCronTimestamp = null;
$manualScrapeMessageArray = $_SESSION['manual_scrape_status'] ?? null;
if ($manualScrapeMessageArray) unset($_SESSION['manual_scrape_status']);
$cronJobOrgJobsData = [];
$cronJobOrgErrorMessage = null;
$cronJobHistories = [];
$errorMessageForPage = null;

// تابع کمکی برای فرمت کردن زمانبندی Cron-Job.org
if (!function_exists('formatCronJobOrgSchedule')) {
    function formatCronJobOrgSchedule($scheduleArray) { /* ... مانند قبل ... */ }
}

// --- پردازش درخواست به‌روزرسانی دستی (باید قبل از هر خروجی HTML اصلی باشد) ---
if (isset($_GET['action']) && $_GET['action'] === 'manual_scrape' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // اطمینان از وجود $db و اینکه یک آبجکت PDO معتبر است
    if (isset($db) && $db instanceof PDO) {
        // ... (منطق کامل manual_scrape مانند قبل، با استفاده از $db و ROOT_PATH) ...
        // ... اطمینان حاصل کنید که از $db که از _header.php آمده استفاده می‌کنید ...
        // ... و نیازی به Database::getInstance() مجدد نیست ...
        ob_start();
        // ... (بقیه کد اجرای دستی کرون) ...
        $_SESSION['manual_scrape_status'] = ['type' => 'success', 'text' => "عملیات به‌روزرسانی دستی با موفقیت خاتمه یافت."]; // یا پیام خطا
    } else {
        $_SESSION['manual_scrape_status'] = ['type' => 'error', 'text' => "خطا: اتصال به دیتابیس برای اجرای بروزرسانی دستی برقرار نیست (از داشبورد)."];
        error_log("Admin Manual Scrape Error: Database connection (\$db) not available or not PDO in admin/index.php.");
    }
    // ریدایرکت به همین صفحه برای نمایش پیام از سشن
    $dashboard_url = site_url('admin/index.php');
    if (!headers_sent()) { header("Location: " . $dashboard_url); exit; }
    else { echo "<script>window.location.href='" . addslashes($dashboard_url) . "';</script>"; exit; }
}


// --- شروع منطق اصلی نمایش داشبورد ---
try {
    // **مهم:** بررسی کنید که $db در اینجا یک آبجکت PDO معتبر است
    if (!isset($db) || !$db instanceof PDO) {
        throw new Exception("اتصال به پایگاه داده برای نمایش اطلاعات داشبورد در دسترس نیست. فایل admin/layouts/_header.php را برای مقداردهی اولیه \$db بررسی کنید.");
    }

    // 1. خواندن زمان آخرین اجرای موفق کرون
    $stmtSettings = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmtSettings->execute(['last_cron_successful_run_time']);
    $lastRunDbValue = $stmtSettings->fetchColumn();
    if ($lastRunDbValue) {
        // ... (محاسبه $lastCronRunDisplay و $nextServerCronRunDisplay مانند قبل با استفاده از $jdf_loaded_for_admin_layout) ...
        $timestamp_last_run = strtotime($lastRunDbValue);
        if ($jdf_loaded_for_admin_layout) {
            $lastCronRunDisplay = jdate('l، j F Y ساعت H:i:s', $timestamp_last_run);
            $next_run_interval_seconds = 15 * 60;
            $nextServerCronTimestamp = ceil((time() + 1) / $next_run_interval_seconds) * $next_run_interval_seconds;
            if ($nextServerCronTimestamp <= time()) { $nextServerCronTimestamp += $next_run_interval_seconds; }
            $nextServerCronRunDisplay = jdate('H:i (Y/m/d)', $nextServerCronTimestamp);
        } else { /* ... میلادی ... */ }
    }

    // 2. خواندن اطلاعات از cron-job.org API
    $cronJobHelperPath = ROOT_PATH . '/lib/CronJobOrgHelper.php'; // ROOT_PATH از config.php
    if (file_exists($cronJobHelperPath)) {
        if(!function_exists('getCronJobOrgJobs')) require_once $cronJobHelperPath;
        // ... (بقیه منطق فراخوانی API cron-job.org و پردازش پاسخ مانند قبل) ...
    } else { $cronJobOrgErrorMessage = "فایل CronJobOrgHelper.php یافت نشد."; }

} catch (PDOException $e) {
    $errorMessageForPage = "خطای پایگاه داده در داشبورد.";
    error_log("Admin Dashboard PDOException (from index itself): " . $e->getMessage() . "\n" . $e->getTraceAsString());
    if(defined('DEBUG_MODE') && DEBUG_MODE) { $errorMessageForPage .= " (جزئیات در لاگ)";}
} catch (Exception $e) {
    $errorMessageForPage = "خطای عمومی در داشبورد: " . htmlspecialchars($e->getMessage());
    error_log("Admin Dashboard Exception (from index itself): " . $e->getMessage() . "\n" . $e->getTraceAsString());
}

?>

<div class="admin-page-content">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>

    <?php if (isset($errorMessageForPage)): ?>
        <div class="message error"><?php echo $errorMessageForPage; // پیام خطا htmlspecialchars شده است ?></div>
    <?php endif; ?>

    <?php if (isset($manualScrapeMessageArray) && is_array($manualScrapeMessageArray)): ?>
        <div class="message <?php echo htmlspecialchars($manualScrapeMessageArray['type']); ?>" style="margin-top:15px;">
            <?php echo $manualScrapeMessageArray['text']; // این می‌تواند حاوی <pre> باشد، پس با دقت استفاده شود ?>
        </div>
    <?php endif; ?>

    <div class="status-box">
        <p>آخرین بروزرسانی خودکار (توسط کرون سرور شما): <strong class="cron-time-display"><?php echo htmlspecialchars($lastCronRunDisplay); ?></strong></p>
        <p>زمان تقریبی بروزرسانی بعدی (توسط کرون سرور شما): <strong class="cron-time-display" id="next-update-display"><?php echo htmlspecialchars($nextServerCronRunDisplay); ?></strong> <span id="countdown-timer-server" style="font-weight:normal; color:#555;"></span></p>
    </div>

    <form id="manualScrapeForm" action="<?php echo site_url('admin/index.php?action=manual_scrape'); ?>" method="post" style="margin-bottom:20px;">
<button type="button" id="manualScrapeBtn" class="btn-submit manual-update-btn-style">
    بروزرسانی لیست خاموشی
</button>

<script>
document.getElementById('manualScrapeBtn').addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'در حال بروزرسانی...';

    fetch('https://baboliha.ir/cron_scrape.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('خطا در ارتباط با سرور');
            }
            return response.text();
        })
        .then(data => {
            alert('✅ بروزرسانی با موفقیت انجام شد');
        })
        .catch(error => {
            console.error(error);
            alert('❌ مشکلی در بروزرسانی به وجود آمد');
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'بروزرسانی لیست خاموشی';
        });
});
</script>

    </form>
    


<script>
const nextRunTimestampFromServer = <?php echo isset($nextServerCronTimestamp) && is_numeric($nextServerCronTimestamp) ? $nextServerCronTimestamp * 1000 : 'null'; ?>;
// کد JavaScript برای تایمر و دکمه بروزرسانی دستی باید در فایل assets/admin_script.js شما باشد
</script>

<?php
require_once __DIR__ . '/layouts/_footer.php';
?>