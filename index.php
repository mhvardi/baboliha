<?php
// /public_html/index.php (صفحه عمومی نمایش خاموشی‌ها)

// 0. تنظیمات اولیه و بارگذاری فایل‌های ضروری
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    error_log("PUBLIC INDEX FATAL: config.php not found.");
    die("خطای پیکربندی اساسی سایت. لطفاً با مدیر تماس بگیرید.");
}

if (file_exists(__DIR__ . '/database.php')) {
    require_once __DIR__ . '/database.php';
} else {
    error_log("PUBLIC INDEX FATAL: database.php not found.");
    die("خطای پیکربندی پایگاه داده.");
}

$jdf_loaded_successfully = false;
$jdf_load_message = '';
if (defined('ROOT_PATH')) {
    $jdf_path = ROOT_PATH . '/lib/jdf.php';
    if (file_exists($jdf_path)) {
        require_once $jdf_path;
        if (function_exists('jdate') && function_exists('jalali_to_gregorian') && function_exists('jcheckdate')) {
            $jdf_loaded_successfully = true;
        } else {
            $jdf_load_message = (defined('DEBUG_MODE') && DEBUG_MODE) ? "<p style='color:orange;text-align:center;background:#fff1c6;padding:10px;border:1px solid #ffc107;'><b>هشدار:</b> فایل jdf.php بارگذاری شد اما توابع کلیدی آن یافت نشدند.</p>" : "";
            error_log("PUBLIC INDEX WARNING: jdf.php loaded but key functions are missing from " . $jdf_path);
        }
    } else {
        $jdf_load_message = (defined('DEBUG_MODE') && DEBUG_MODE) ? "<p style='color:red; background:yellow; padding:10px; text-align:center;'><b>خطا:</b> فایل کتابخانه jdf.php در مسیر '".htmlspecialchars($jdf_path)."' یافت نشد.</p>" : "";
        error_log("PUBLIC INDEX ERROR: jdf.php not found at " . $jdf_path);
    }
} else {
    $jdf_load_message = (defined('DEBUG_MODE') && DEBUG_MODE) ? "<p style='color:red; background:orange; padding:10px; text-align:center;'><b>خطای پیکربندی:</b> ثابت ROOT_PATH تعریف نشده است.</p>" : "";
    error_log("PUBLIC INDEX ERROR: ROOT_PATH constant not defined in config.php.");
}
if (!$jdf_loaded_successfully) {
    if (!function_exists('jdate')) { function jdate($f,$t='',$n='',$tz='Asia/Tehran',$tn='fa'){return date($f,($t===''?time():$t));} }
    if (!function_exists('jalali_to_gregorian')) { function jalali_to_gregorian($j_y, $j_m, $j_d, $mod = '') { return [$j_y, $j_m, $j_d]; } }
    if (!function_exists('jcheckdate')) { function jcheckdate($m, $d, $y) { return checkdate((int)$m, (int)$d, (int)$y); } }
}

if (!function_exists('site_url')) {
    function site_url($path = '') {
        $base_url_func = defined('BASE_URL') ? BASE_URL : '';
        if(empty($base_url_func) && isset($_SERVER['HTTP_HOST'])) {
            $protocol_func = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://");
            $base_url_func = $protocol_func . $_SERVER['HTTP_HOST'];
        } elseif(empty($base_url_func)) { $base_url_func = '.'; }
        return rtrim($base_url_func, '/') . '/' . ltrim($path, '/');
    }
}

// --- مدیریت شناسه کاربر مهمان با کوکی ---
$guest_cookie_name = "guest_uid_babolbargh_v3"; // یک نام جدید برای اطمینان از تازگی
$guest_identifier = null;
$cookie_expiry_timestamp = time() + (86400 * 365); // 1 سال

if (isset($_COOKIE[$guest_cookie_name])) {
    $guest_identifier = $_COOKIE[$guest_cookie_name];
    if (!preg_match('/^[a-f0-9]{32}$/', $guest_identifier)) {
        $guest_identifier = null; 
    }
}

if ($guest_identifier === null) {
    try {
        $guest_identifier = bin2hex(random_bytes(16));
    } catch (Exception $e) { 
        $guest_identifier = md5(uniqid('guest_', true) . microtime(true) . ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
    if (!headers_sent()) {
        setcookie($guest_cookie_name, $guest_identifier, $cookie_expiry_timestamp, "/", "", (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'), true);
    } else { error_log("INDEX.PHP WARNING: Cannot set guest cookie, headers already sent."); }
}
// --- پایان مدیریت شناسه کاربر مهمان ---

$pageTitle = "لیست خاموشی های برق بابل"; // این متغیر توسط بخش‌های دیگر استفاده می‌شود
$finalResultsDataForHtml = [];
$allOutagesFromDb = []; 
$lastCronRunTime = "نامشخص";
$errorMessage = null;
$db = null;
$top_banner_html = '';
$bottom_banner_html = '';
$timestamp_last_run = null; // برای استفاده در JSON-LD

try {
    if (!class_exists('Database')) { throw new Exception("کلاس Database یافت نشد."); }
    $dbInstance = Database::getInstance();
    $db = $dbInstance->getConnection();
    if (!$db instanceof PDO) { throw new Exception("اتصال به پایگاه داده ناموفق بود."); }

    // --- شروع بخش ثبت آمار بازدید ---
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $page_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . ($_SERVER['HTTP_HOST'] ?? 'UNKNOWN_HOST') . ($_SERVER['REQUEST_URI'] ?? '/UNKNOWN_URI');
    $referrer_url = $_SERVER['HTTP_REFERER'] ?? null;
    $view_date = date('Y-m-d');
    $bot_keywords = ['bot', 'spider', 'crawler', 'slurp', 'mediapartners', 'adsbot', 'bingpreview', 'wget', 'curl', '검색엔진', 'python-requests', 'linkpad', 'pingdom', 'ahrefsbot', 'semrushbot', 'googlebot', 'yandexbot', 'mj12bot', 'dotbot'];
    $is_bot = false;
    if ($user_agent) { foreach ($bot_keywords as $keyword) { if (stripos($user_agent, $keyword) !== false) { $is_bot = true; break; } } }
    if (!$is_bot) {
        $sql_page_view = "INSERT INTO page_views (ip_address, user_agent, page_url, referrer_url, view_date, view_datetime) VALUES (:ip, :ua, :purl, :rurl, :vdate, NOW())";
        $stmt_page_view = $db->prepare($sql_page_view);
        $stmt_page_view->bindValue(':ip', $ip_address, PDO::PARAM_STR);
        $stmt_page_view->bindValue(':ua', $user_agent, $user_agent === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_page_view->bindValue(':purl', mb_substr($page_url, 0, 2080), PDO::PARAM_STR);
        $stmt_page_view->bindValue(':rurl', $referrer_url === null ? null : mb_substr($referrer_url, 0, 2080), $referrer_url === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_page_view->bindValue(':vdate', $view_date, PDO::PARAM_STR);
        try { $stmt_page_view->execute(); } catch (PDOException $e_pv) { error_log("Error inserting page view: " . $e_pv->getMessage()); }
    }
    // --- پایان بخش ثبت آمار بازدید ---

    $stmtSettings = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmtSettings->execute(['last_cron_successful_run_time']);
    $lastRunDbValue = $stmtSettings->fetchColumn();
    if ($lastRunDbValue) {
        $timestamp_last_run = strtotime($lastRunDbValue); // ذخیره timestamp برای JSON-LD
        if ($jdf_loaded_successfully && $timestamp_last_run) { $lastCronRunTime = jdate('l، j F Y ساعت H:i:s', $timestamp_last_run); }
        elseif($timestamp_last_run) { $lastCronRunTime = date('Y-m-d H:i:s', $timestamp_last_run) . " (میلادی)"; }
    }

    $stmtOutages = $db->query("SELECT id, outage_signature, tarikh, az_saat, ta_saat, address_text, address_normalized_hash FROM outage_events_log WHERE is_currently_active = 1");
    if ($stmtOutages) {
        $allOutagesFromDb = $stmtOutages->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("Public Index: Failed to execute query for active outages. DB error: " . print_r($db->errorInfo(), true));
        $errorMessage = "خطا در دریافت لیست خاموشی‌ها از پایگاه داده.";
    }

    if (!empty($allOutagesFromDb)) {
        usort($allOutagesFromDb, function ($a, $b) {
            $val_a_str = '999999999999'; $val_b_str = '999999999999'; 
            $tarikh_a = $a['tarikh'] ?? ''; $az_saat_a = $a['az_saat'] ?? '';
            $parts_a = explode('/', (string)$tarikh_a);
            if (is_array($parts_a) && count($parts_a) === 3) {
                $y_a = (int)$parts_a[0]; $m_a = (int)$parts_a[1]; $d_a = (int)$parts_a[2];
                if ($y_a < 100 && $y_a > 0) $y_a += 1400;
                if ($y_a >=1300 && $y_a <=1500 && $m_a >=1 && $m_a <=12 && $d_a >=1 && $d_a <=31) {
                    $time_parts_a = explode(':', (string)$az_saat_a);
                    $h_a = (is_array($time_parts_a) && isset($time_parts_a[0]) && is_numeric($time_parts_a[0])) ? (int)$time_parts_a[0] : 0;
                    $min_a = (is_array($time_parts_a) && isset($time_parts_a[1]) && is_numeric($time_parts_a[1])) ? (int)$time_parts_a[1] : 0;
                    if ($h_a >= 0 && $h_a <= 23 && $min_a >= 0 && $min_a <= 59) {
                        $val_a_str = sprintf('%04d%02d%02d%02d%02d', $y_a, $m_a, $d_a, $h_a, $min_a);
                    }
                }
            }
            $tarikh_b = $b['tarikh'] ?? ''; $az_saat_b = $b['az_saat'] ?? '';
            $parts_b = explode('/', (string)$tarikh_b);
            if (is_array($parts_b) && count($parts_b) === 3) {
                $y_b = (int)$parts_b[0]; $m_b = (int)$parts_b[1]; $d_b = (int)$parts_b[2];
                if ($y_b < 100 && $y_b > 0) $y_b += 1400;
                 if ($y_b >=1300 && $y_b <=1500 && $m_b >=1 && $m_b <=12 && $d_b >=1 && $d_b <=31) {
                    $time_parts_b = explode(':', (string)$az_saat_b);
                    $h_b = (is_array($time_parts_b) && isset($time_parts_b[0]) && is_numeric($time_parts_b[0])) ? (int)$time_parts_b[0] : 0;
                    $min_b = (is_array($time_parts_b) && isset($time_parts_b[1]) && is_numeric($time_parts_b[1])) ? (int)$time_parts_b[1] : 0;
                    if ($h_b >= 0 && $h_b <= 23 && $min_b >= 0 && $min_b <= 59) {
                        $val_b_str = sprintf('%04d%02d%02d%02d%02d', $y_b, $m_b, $d_b, $h_b, $min_b);
                    }
                }
            }
            return strcmp($val_a_str, $val_b_str); 
         });
    }

    $guestPinnedAddressHashesMap = [];
    if ($guest_identifier && $db instanceof PDO) {
        try {
            $stmtGuestPinned = $db->prepare("SELECT address_hash FROM guest_pinned_outages WHERE guest_identifier = :guest_id");
            $stmtGuestPinned->bindParam(':guest_id', $guest_identifier, PDO::PARAM_STR);
            $stmtGuestPinned->execute();
            foreach ($stmtGuestPinned->fetchAll(PDO::FETCH_COLUMN) as $hash) {
                $guestPinnedAddressHashesMap[$hash] = true;
            }
        } catch (PDOException $e_star) { error_log("Error fetching guest pinned items for guest_id {$guest_identifier}: " . $e_star->getMessage()); }
    }

    global $pinnedAddressKeywords;
    $guestPinnedDisplayRows = [];
    $phpPinnedDisplayRows = [];
    $regularDisplayRows = [];

    if (!empty($allOutagesFromDb)) {
        foreach ($allOutagesFromDb as $row) {
            $current_address_hash = $row['address_normalized_hash'] ?? null;
            $current_outage_signature = $row['outage_signature'] ?? md5(rand().time().($row['address_text'] ?? ''));
            $isGuestPinned = ($current_address_hash && isset($guestPinnedAddressHashesMap[$current_address_hash]));
            $isPhpKeyPinned = false;

            if (!$isGuestPinned && isset($row['address_text']) && !empty($pinnedAddressKeywords) && is_array($pinnedAddressKeywords)) {
                foreach ($pinnedAddressKeywords as $pinKeyword) {
                    if (mb_strpos(preg_replace('/\s+/', '', mb_strtolower($row['address_text'])), preg_replace('/\s+/', '', mb_strtolower($pinKeyword))) !== false) {
                        $isPhpKeyPinned = true; break;
                    }
                }
            }
            $displayRow = [
                'tarikh' => $row['tarikh'] ?? '', 'az_saat' => $row['az_saat'] ?? '', 'ta_saat' => $row['ta_saat'] ?? '',
                'address' => $row['address_text'] ?? 'آدرس نامشخص', 'signature' => $current_outage_signature,
                'address_hash' => $current_address_hash,
                'is_guest_pinned' => $isGuestPinned,
                'is_php_pinned' => $isPhpKeyPinned
            ];
            if ($isGuestPinned) { $guestPinnedDisplayRows[] = $displayRow; }
            elseif ($isPhpKeyPinned) { $phpPinnedDisplayRows[] = $displayRow; }
            else { $regularDisplayRows[] = $displayRow; }
        }
    }
    $finalResultsDataForHtml = array_merge($guestPinnedDisplayRows, $phpPinnedDisplayRows, $regularDisplayRows);

    if ($db instanceof PDO) {
        $today_for_banner_query = date('Y-m-d');
        $stmtBanners = $db->prepare("SELECT id, image_url, target_url, name FROM banners WHERE is_active = 1 AND (start_date IS NULL OR start_date <= :today1) AND (end_date IS NULL OR end_date >= :today2) AND position = :position ORDER BY RAND() LIMIT 1");
        $stmtBanners->execute([':today1' => $today_for_banner_query, ':today2' => $today_for_banner_query, ':position' => 'top_banner']);
        $top_banner = $stmtBanners->fetch(PDO::FETCH_ASSOC);
        if ($top_banner && !empty($top_banner['image_url'])) {
            $valid_target_url_top = filter_var($top_banner['target_url'], FILTER_VALIDATE_URL) ? $top_banner['target_url'] : '#';
            $banner_redirect_url = site_url('banner_click.php?id=' . $top_banner['id'] . '&r=' . urlencode($valid_target_url_top));
            $image_actual_url = (stripos($top_banner['image_url'], 'http') === 0) ? $top_banner['image_url'] : site_url($top_banner['image_url']);
            $top_banner_html = '<a href="' . htmlspecialchars($banner_redirect_url) . '" target="_blank" rel="noopener sponsored nofollow" class="banner-link"><img src="' . htmlspecialchars($image_actual_url) . '" alt="' . htmlspecialchars($top_banner['name']) . '"></a>';
        }
        $stmtBanners->execute([':today1' => $today_for_banner_query, ':today2' => $today_for_banner_query, ':position' => 'bottom_banner']);
        $bottom_banner = $stmtBanners->fetch(PDO::FETCH_ASSOC);
        if ($bottom_banner && !empty($bottom_banner['image_url'])) {
            $valid_target_url_bottom = filter_var($bottom_banner['target_url'], FILTER_VALIDATE_URL) ? $bottom_banner['target_url'] : '#';
            $banner_redirect_url_bottom = site_url('banner_click.php?id=' . $bottom_banner['id'] . '&r=' . urlencode($valid_target_url_bottom));
            $image_actual_url_bottom = (stripos($bottom_banner['image_url'], 'http') === 0) ? $bottom_banner['image_url'] : site_url($bottom_banner['image_url']);
            $bottom_banner_html = '<a href="' . htmlspecialchars($banner_redirect_url_bottom) . '" target="_blank" rel="noopener sponsored nofollow" class="banner-link"><img src="' . htmlspecialchars($image_actual_url_bottom) . '" alt="' . htmlspecialchars($bottom_banner['name']) . '"></a>';
        }
    }

} catch (PDOException $e) { $errorMessage = "خطای پایگاه داده."; error_log("Public Index PDOException: " . $e->getMessage() . "\n" . $e->getTraceAsString()); if(defined('DEBUG_MODE') && DEBUG_MODE) { $errorMessage .= " (جزئیات در لاگ)";} }
  catch (Exception $e) { $errorMessage = "خطای عمومی سیستم: ". htmlspecialchars($e->getMessage()); error_log("Public Index Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString()); }


// --- آماده سازی متغیرهای مورد نیاز برای بخش <head> ---
$last_update_iso_format = date(DATE_ATOM, time()); // پیش‌فرض
if ($timestamp_last_run) { // $timestamp_last_run از بالا مقداردهی شده
    $last_update_iso_format = date(DATE_ATOM, $timestamp_last_run);
}

$current_page_url = site_url(($_SERVER['REQUEST_URI'] ?? ''));
$json_ld_page_title = isset($pageTitle) ? $pageTitle : 'لیست خاموشی های برق بابل'; // $pageTitle بالاتر تعریف شده
$base_site_url = defined('BASE_URL') && BASE_URL ? rtrim(BASE_URL, '/') : ( (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . ($_SERVER['HTTP_HOST'] ?? 'localhost') );
// --- پایان آماده سازی متغیرهای <head> ---


if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'لیست خاموشی‌ها'; ?> | اطلاع رسانی قطعی برق بابل</title>
    
    <meta name="description" content="آخرین لیست و برنامه زمانبندی قطعی برق بابل. مشاهده جدول خاموشی های برق مناطق مختلف بابل و اطلاع از ساعات و مدت زمان قطعی. بروزرسانی منظم.">
    <meta name="keywords" content="قطعی برق بابل, لیست قطعی برق بابل, خاموشی برق بابل, برنامه قطعی برق بابل, جدول قطعی برق بابل, ساعت قطعی برق بابل, اداره برق بابل, قطعی برق امروز بابل">
    <link rel="canonical" href="<?php echo htmlspecialchars($current_page_url); ?>" />

    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($current_page_url); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($json_ld_page_title); ?> | برنامه خاموشی بابل">
    <meta property="og:description" content="جدول و لیست ساعات قطعی برق در مناطق مختلف بابل. برنامه خاموشی‌های امروز و روزهای آینده را اینجا ببینید.">
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo htmlspecialchars($current_page_url); ?>">
    <meta property="twitter:title" content="<?php echo htmlspecialchars($json_ld_page_title); ?> | برنامه خاموشی بابل">
    <meta property="twitter:description" content="جدول و لیست ساعات قطعی برق در مناطق مختلف بابل. برنامه خاموشی‌های امروز و روزهای آینده را اینجا ببینید.">
    <link rel="stylesheet" href="<?php echo site_url('assets/style.css'); ?>?v=<?php echo defined('ROOT_PATH') && file_exists(ROOT_PATH . '/assets/style.css') ? filemtime(ROOT_PATH . '/assets/style.css') : time(); ?>">
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      "name": "<?php echo htmlspecialchars($json_ld_page_title); ?> - برنامه و لیست قطعی برق",
      "description": "آخرین لیست و برنامه زمانبندی قطعی برق بابل. مشاهده جدول خاموشی های برق مناطق مختلف بابل و اطلاع از ساعات و مدت زمان قطعی.",
      "url": "<?php echo htmlspecialchars($current_page_url); ?>",
      "isPartOf": {
        "@type": "WebSite",
        "name": "اطلاع رسانی قطعی برق بابل",
        "url": "<?php echo htmlspecialchars($base_site_url); ?>"
      },
      "keywords": "قطعی برق بابل, لیست قطعی برق بابل, خاموشی برق بابل, برنامه قطعی برق بابل, جدول قطعی برق بابل, ساعت قطعی برق بابل, اطلاع رسانی قطعی برق بابل",
      "datePublished": "<?php echo date(DATE_ATOM, @filemtime(__FILE__)); /* @ برای جلوگیری از خطا اگر فایل در محیط خاصی قابل دسترس نباشد */ ?>",
      "dateModified": "<?php echo htmlspecialchars($last_update_iso_format); ?>"
    }
    </script>
</head>
<body>
    <?php if (defined('DEBUG_MODE') && DEBUG_MODE && !empty($jdf_load_message) ): ?>
        <?php echo $jdf_load_message; ?>
    <?php endif; ?>

    <div class="container">
        <h1 class="page-title"><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'جدول خاموشی‌های برق'; ?></h1>
                <p class="footer-note">آخرین بروزرسانی : <span id="last-update-time"><?php echo htmlspecialchars($lastCronRunTime); ?></span></p>


        <div class="banner-ad-slot-top" id="bannerAdSlotTop">
            <?php if (!empty($top_banner_html)) { echo $top_banner_html; } ?>
        </div>


        <input type="text" id="searchInput" placeholder="آدرس / منطقه خود را وارد کنید..." 
               onkeypress="return restrictToPersian(event);" 
               onpaste="handlePersianPaste(event);">
        

        <?php if (isset($errorMessage)): ?>
            <p class="error-message-box"><?php echo htmlspecialchars($errorMessage); ?></p>
        <?php endif; ?>

        <?php if (!empty($finalResultsDataForHtml)): ?>
		<?php
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = -1; // تعداد آیتم‌ها در هر صفحه
$totalItems = count($finalResultsDataForHtml);
$totalPages = ceil($totalItems / $perPage);
$offset = ($currentPage - 1) * $perPage;

$pagedResults = array_slice($finalResultsDataForHtml, $offset, $perPage);
?>
   <div class="list-responsive-wrapper">
    <ul class="outage-card-list">
<?php foreach ($pagedResults as $row): ?>
            <li class="outage-card <?php 
                $rowClasses = [];
                if ($row['is_guest_pinned']) $rowClasses[] = 'user-starred-row';
                elseif ($row['is_php_pinned']) $rowClasses[] = 'php-pinned-row';
                echo implode(' ', $rowClasses);
            ?>" data-address-hash="<?php echo htmlspecialchars($row['address_hash'] ?? ''); ?>"
                data-signature="<?php echo htmlspecialchars($row['signature']); ?>">
                
                <!-- ستاره بالا سمت راست -->
                <div class="card-star">
    <span class="star-icon <?php echo $row['is_guest_pinned'] ? 'starred' : 'not-starred'; ?>" 
          onclick="toggleGuestStar(this, '<?php echo htmlspecialchars($row['address_hash'] ?? ''); ?>')">
        <span class="star-char"><?php echo $row['is_guest_pinned'] ? '★' : '☆'; ?></span>
        <span class="star-label"><?php echo $row['is_guest_pinned'] ? 'ذخیره شده' : 'ذخیره'; ?></span>
    </span>
                </div>

                <!-- تاریخ وسط -->
                <div class="card-date">
                    <?php
                        $display_tarikh = $row['tarikh'] ?? 'نامشخص';
                        if ($jdf_loaded_successfully && !empty($row['tarikh']) && strpos((string)$row['tarikh'], '/') !== false) {
                            $parts = explode('/', (string)$row['tarikh']);
                            if (is_array($parts) && count($parts) === 3) {
                                $y = (int)$parts[0]; $m = (int)$parts[1]; $d = (int)$parts[2];
                                if ($y < 100 && $y > 0) $y += 1400;
                                if (function_exists('jcheckdate') && jcheckdate($m, $d, $y)) {
                                    $g_parts = jalali_to_gregorian($y, $m, $d);
                                    if ($g_parts && is_array($g_parts) && count($g_parts) === 3) {
                                        $timestamp = mktime(0,0,0, (int)$g_parts[1], (int)$g_parts[2], (int)$g_parts[0]);
                                        $display_tarikh = jdate("Y/m/d", $timestamp);
                                    } else { $display_tarikh .= ' <small>(خ.ت.م)</small>'; }
                                } else { $display_tarikh .= ' <small>(ن.ش)</small>'; }
                            } else { $display_tarikh .= ' <small>(ف.ن)</small>'; }
                        }
                        echo htmlspecialchars($display_tarikh);
                    ?>
                </div>

                <!-- ساعت شروع، پایان و شهر -->
                <div class="card-meta">
                    <span>از ساعت: <?php echo htmlspecialchars($row['az_saat'] ?? ''); ?></span>
                    <span>تا ساعت: <?php echo htmlspecialchars($row['ta_saat'] ?? ''); ?></span>
                    <span>شهر: بابل</span>
                </div>

                <!-- آدرس -->
                <div class="card-address">
				<span> آدرس:  <?php echo nl2br(htmlspecialchars($row['address'] ?? 'نامشخص')); ?></span>

                   
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="pagination">
    <?php if ($currentPage > 1): ?>
        <a href="?page=<?php echo $currentPage - 1; ?>">&laquo; قبلی</a>
    <?php endif; ?>

    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?page=<?php echo $i; ?>" class="<?php echo $i === $currentPage ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
    <?php endfor; ?>

    <?php if ($currentPage < $totalPages): ?>
        <a href="?page=<?php echo $currentPage + 1; ?>">بعدی &raquo;</a>
    <?php endif; ?>
</div>


        <?php elseif (empty($errorMessage)): // فقط اگر خطای دیتابیس نداشتیم این پیام را نشان بده ?>
             <p class="no-data">در حال حاضر هیچ برنامه قطعی برق برای بابل در این سامانه ثبت نشده است یا تمامی خاموشی‌های اعلام شده به پایان رسیده‌اند. لطفاً برای اطلاع از آخرین تغییرات و لیست جدید قطعی برق بابل، صفحه را در زمان دیگری بررسی نمایید. اطلاعات این صفحه به طور منظم بروزرسانی می‌شود.</p>
        <?php endif; ?>

        <div class="banner-ad-slot-bottom" id="bannerAdSlotBottom">
             <?php if (!empty($bottom_banner_html)) { echo $bottom_banner_html; } ?>
        </div>


<div class="faq-section">
    <h3>پرسش های متداول درباره قطعی برق بابل</h3>

    <div class="faq-item">
        <div class="faq-question">چگونه از برنامه قطعی برق منطقه خود در بابل مطلع شوم؟</div>
        <div class="faq-answer">
            شما می‌توانید با وارد کردن نام خیابان، کوچه یا منطقه خود در کادر جستجوی موجود در بالای همین صفحه، از زمانبندی احتمالی قطعی برق مطلع شوید. همچنین، لیست کامل خاموشی‌های فعال و برنامه‌ریزی شده برای بابل در جدول نمایش داده می‌شود.
        </div>
    </div>

    <div class="faq-item">
        <div class="faq-question">اطلاعات قطعی برق بابل هر چند وقت یکبار بروز می‌شود؟</div>
        <div class="faq-answer">
            اطلاعات این سامانه بر اساس جدیدترین داده‌های دریافتی از منابع رسمی شرکت توزیع برق بروزرسانی می‌شود. زمان دقیق آخرین بروزرسانی اطلاعات در بالای صفحه ذکر شده است.
        </div>
    </div>

    <div class="faq-item">
        <div class="faq-question">آیا این سایت مرجع رسمی قطعی برق بابل است؟</div>
        <div class="faq-answer">
            خیر، این وبسایت یک منبع کمکی برای اطلاع‌رسانی آسان‌تر به شهروندان بابلی است و اطلاعات خود را از منابع رسمی شرکت توزیع نیروی برق دریافت می‌کند.
        </div>
    </div>

    <div class="faq-item">
        <div class="faq-question">قطعی برق بابل امروز شامل کدام مناطق است؟</div>
        <div class="faq-answer">
            لیست مناطقی که امروز در بابل با قطعی برق مواجه هستند یا طبق برنامه خواهند شد، در جدول خاموشی‌ها در بالای همین صفحه قابل مشاهده است.
        </div>
    </div>

    <div class="faq-item">
        <div class="faq-question">اگر برق منطقه ما خارج از برنامه اعلام شده قطع شد چه کار کنیم؟</div>
        <div class="faq-answer">
            در صورتی که قطعی برق شما در لیست اعلام شده موجود نیست یا به صورت ناگهانی رخ داده است، لطفاً مستقیماً با شماره اضطراری اداره برق تماس بگیرید.
        </div>
    </div>
	    <div class="faq-item">
        <div class="faq-question">سلب مسئولیت</div>
        <div class="faq-answer">
 تمامی اطلاعات این سایت از منابع رسمی شرکت توزیع نیروی برق مازندران استخراج شده و «بابلیها» صرفاً بازنشرکننده است. این سایت مسئولیتی در قبال صحت، دقت یا به روزرسانی لحظه ای اطلاعات ندارد
        </div>
    </div>
</div>

<script>
const questions = document.querySelectorAll('.faq-question');

questions.forEach(question => {
    question.addEventListener('click', () => {
        const isActive = question.classList.contains('active');
        
        // بستن همه موارد
        document.querySelectorAll('.faq-question').forEach(q => q.classList.remove('active'));
        document.querySelectorAll('.faq-answer').forEach(a => {
            a.style.maxHeight = null;
            a.classList.remove('open');
        });

        // اگر مورد کلیک شده فعال نبود، بازش کن
        if (!isActive) {
            question.classList.add('active');
            const answer = question.nextElementSibling;
            answer.style.maxHeight = answer.scrollHeight + 'px';
            answer.classList.add('open');
        }
    });
});
</script>




        <p class="footer-note" style="margin-top: 5px;">طراحی شده با <span style="color: #e74c3c;">❤️</span> - <a href="https://vardi.ir/" target="_blank" rel="noopener noreferrer">آژانس تبلیغاتی وردی</a></p>
    </div>



    <button id="scrollTopBtn" title="بازگشت به بالا">▲</button>

    <script>
        const currentGuestIdentifier = <?php echo json_encode($guest_identifier); ?>;
        const ajaxToggleGuestPinUrl = '<?php echo addslashes(site_url("ajax_toggle_guest_pin.php")); ?>';

        function restrictToPersian(event) {
            const persianRegex = /^[\u0600-\u06FF\s]+$/; 
            const allowedKeys = [8, 9, 13, 16, 17, 18, 20, 35, 36, 37, 38, 39, 40, 46]; 
            if (allowedKeys.includes(event.keyCode)) {
                return true;
            }
            if (!persianRegex.test(event.key) && event.key !== ' ') { 
                event.preventDefault();
                return false;
            }
            return true;
        }

        function handlePersianPaste(event) {
            const clipboardData = event.clipboardData || window.clipboardData;
            const pastedData = clipboardData.getData('Text');
            const persianRegex = /^[\u0600-\u06FF\s]*$/; 

            if (!persianRegex.test(pastedData)) {
                event.preventDefault();
                alert("لطفاً فقط حروف فارسی وارد کنید.");
                return false;
            }
        }
        // افزودن قابلیت باز و بسته شدن سوالات متداول با کلیک روی عنوان سوال
        document.addEventListener('DOMContentLoaded', function () {
            const faqItems = document.querySelectorAll('.faq-item h3');
            faqItems.forEach(item => {
                item.addEventListener('click', function () {
                    const answer = this.nextElementSibling; // پاراگراف p بعدی
                    if (answer && answer.tagName === 'P') {
                         answer.style.display = answer.style.display === 'none' || answer.style.display === '' ? 'block' : 'none';
                    }
                });
                 // به صورت پیش فرض پاسخ ها پنهان باشند (مگر اینکه بخواهید باز باشند)
                const firstAnswer = item.nextElementSibling;
                if (firstAnswer && firstAnswer.tagName === 'P') {
                   // firstAnswer.style.display = 'none'; // اگر میخواهید در ابتدا بسته باشند این خط را از کامنت خارج کنید
                }
            });
        });
    </script>
	
    <script src="<?php echo site_url('assets/bargh.js'); ?>?v=<?php echo defined('ROOT_PATH') && file_exists(ROOT_PATH . '/assets/bargh.js') ? filemtime(ROOT_PATH . '/assets/bargh.js') : time(); ?>"></script>
</body>
</html>