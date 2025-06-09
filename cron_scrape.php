<?php
// /public_html/cron_scrape.php

// 0. تغییر مسیر فعلی و فعال کردن کامل خطاها برای دیباگ
chdir(__DIR__);
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}
ini_set('log_errors', 1);

// 1. بارگذاری فایل تنظیمات اصلی
$config_path_cron = __DIR__ . '/config.php';
if (file_exists($config_path_cron)) {
    require_once $config_path_cron;
} else {
    $timestamp_error = "[" . date('Y-m-d H:i:s') . "] ";
    error_log($timestamp_error . "CRON FATAL ERROR: config.php not found at " . $config_path_cron);
    die($timestamp_error . "FATAL ERROR: config.php not found.");
}

// 2. تعریف تابع cli_debug_echo
if (!function_exists('cli_debug_echo')) {
    function cli_debug_echo($message) {
        $is_debug_mode = (defined('DEBUG_MODE') && DEBUG_MODE === true);
        $log_message = "[" . date('Y-m-d H:i:s') . "] CRON_LOG: " . preg_replace('/<br\s*\/?>/i', "\n", $message);
        error_log($log_message);
        if ($is_debug_mode) {
            if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
                echo "[" . date('Y-m-d H:i:s') . "] CRON_DEBUG: " . preg_replace('/<br\s*\/?>/i', "\n", $message) . "\n";
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] CRON_DEBUG: " . $message . "<br>\n";
            }
        }
    }
}

cli_debug_echo("==================================================");
cli_debug_echo("شروع اسکریپت Cron (اسکرپ و ذخیره داده‌ها)");
cli_debug_echo("==================================================");

if (!extension_loaded('intl')) {
    cli_debug_echo("CRON FATAL ERROR: اکستنشن 'intl' PHP مورد نیاز است اما فعال نیست. اسکریپت متوقف شد.");
    exit;
}

$required_constants = ['ROOT_PATH', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ALL_AREAS_CODE', 'SCRAPER_BASE_URL', 'COOKIE_FILE'];
foreach ($required_constants as $const) {
    if (!defined($const)) {
        $error_msg = "CRON FATAL ERROR: ثابت ضروری '{$const}' در config.php تعریف نشده است. اسکریپت متوقف شد.";
        cli_debug_echo($error_msg);
        exit;
    }
}

$database_path_cron = ROOT_PATH . '/database.php';
// ... (سایر require ها و بررسی‌ها مانند قبل) ...
if (!file_exists($database_path_cron)) {
    cli_debug_echo("CRON FATAL ERROR: database.php not found at {$database_path_cron}.");
    exit;
}
require_once $database_path_cron;
if (!class_exists('Database')) {
    cli_debug_echo("CRON FATAL ERROR: Database class not defined.");
    exit;
}

$scraper_func_path = ROOT_PATH . '/scraper_functions.php';
if (!file_exists($scraper_func_path)) {
    cli_debug_echo("CRON FATAL ERROR: scraper_functions.php not found at {$scraper_func_path}.");
    exit;
}
require_once $scraper_func_path;
if (!function_exists('getInitialAspParams') || !function_exists('fetchAllOutageDataForCity') || !function_exists('generateOutageEventSignature') || !function_exists('generateNormalizedAddressHash')) {
    cli_debug_echo("CRON FATAL ERROR: یک یا چند تابع ضروری اسکرپر تعریف نشده‌اند (در scraper_functions.php بررسی شود).");
    exit;
}


set_time_limit(600);
$db = null;
$script_executed_successfully = false;

try {
    $dbInstance = Database::getInstance();
    $db = $dbInstance->getConnection();
    if (!$db instanceof PDO) {
        throw new Exception("ایجاد نمونه PDO از دیتابیس ناموفق بود.");
    }
    cli_debug_echo("اتصال به پایگاه داده برقرار شد.");

    $stmtAreas = $db->prepare("SELECT city_code, area_code, area_name FROM areas WHERE is_active = 1");
    $stmtAreas->execute();
    $activeAreas = $stmtAreas->fetchAll(PDO::FETCH_ASSOC);

    if (empty($activeAreas)) {
        throw new Exception("هیچ شهر فعال (is_active = 1) در جدول areas یافت نشد.");
    }

    $aspParams = getInitialAspParams();
    if ($aspParams === null) {
        throw new Exception("دریافت پارامترهای اولیه ASP.NET ناموفق بود.");
    }

    $jalaliTimeZone = 'Asia/Tehran';
    $datePattern = 'yyyy/MM/dd HH:mm';

    $jalaliFormatter = new IntlDateFormatter(
        'fa_IR@calendar=persian',
        IntlDateFormatter::FULL,
        IntlDateFormatter::FULL,
        $jalaliTimeZone,
        IntlDateFormatter::TRADITIONAL,
        $datePattern
    );

    if (!$jalaliFormatter) {
        throw new Exception("ایجاد IntlDateFormatter برای تبدیل تاریخ جلالی ناموفق بود: " . intl_get_error_message());
    }
    
    $currentTime = new DateTime('now', new DateTimeZone($jalaliTimeZone));
    cli_debug_echo("زمان فعلی برای مقایسه: " . $currentTime->format('Y-m-d H:i:s P'));

    foreach ($activeAreas as $area) {
        $cityCodeToScrape = $area['city_code'] ?? 'UNKNOWN_CITY_CODE';
        $areaCodeSourceDb = $area['area_code'] ?? 'UNKNOWN_AREA_CODE';
        $areaName = (isset($area['area_name']) && $area['area_name'] !== null && $area['area_name'] !== '') ? (string)$area['area_name'] : 'نام شهر نامشخص';

        cli_debug_echo("پردازش برای شهر: " . htmlspecialchars($areaName) . " (کد شهر: " . htmlspecialchars($cityCodeToScrape) . ", کد امور: " . htmlspecialchars($areaCodeSourceDb) . ")");

        $scrapedOutages = fetchAllOutageDataForCity($aspParams, $cityCodeToScrape);
        cli_debug_echo(count($scrapedOutages) . " رکورد خاموشی از سایت منبع برای شهر " . htmlspecialchars($areaName) . " دریافت شد.");

        $validOutagesToProcess = [];
        if (!empty($scrapedOutages)) {
            foreach ($scrapedOutages as $outage) {
                $tarikh = $outage['tarikh'] ?? '';
                $az_saat = $outage['az_saat'] ?? '';
                $ta_saat = $outage['ta_saat'] ?? '';
                $address = $outage['address'] ?? '';

                // تعریف متغیرهای امن برای لاگ در ابتدای هر تکرار حلقه داخلی
                $safeLogAreaName = (isset($areaName) && $areaName !== null) ? htmlspecialchars($areaName) : '[نام شهر نامشخص]';
                $safeLogAddress = isset($outage['address']) ? htmlspecialchars($outage['address']) : '[آدرس نامشخص]';

                if (empty($tarikh) || empty($az_saat) || empty($address)) {
                    cli_debug_echo("رد شد (اطلاعات پایه ناقص): خاموشی در شهر " . $safeLogAreaName . ". تاریخ: '$tarikh', از ساعت: '$az_saat', آدرس: '" . $safeLogAddress . "'");
                    continue;
                }
                if (empty($ta_saat)) {
                    cli_debug_echo("رد شد (ساعت پایان نامشخص): خاموشی در شهر " . $safeLogAreaName . " آدرس '" . $safeLogAddress . "' فاقد ساعت پایان است.");
                    continue;
                }

                $jalaliDateTimeString = $tarikh . ' ' . $ta_saat;
                $timestamp = $jalaliFormatter->parse($jalaliDateTimeString);

                if ($timestamp === false) {
                    $intl_error = intl_get_error_message();
                    cli_debug_echo("هشدار: تبدیل تاریخ/زمان جلالی ناموفق بود برای رشته '" . $jalaliDateTimeString . "' در شهر " . $safeLogAreaName . ". خطا: " . $intl_error . ". از این رکورد صرف نظر می شود.");
                    continue;
                }

                $outageEndDateTime = new DateTime("@{$timestamp}");
                $outageEndDateTime->setTimezone(new DateTimeZone($jalaliTimeZone));

                if ($outageEndDateTime < $currentTime) {
                    // ساخت پیام لاگ با الحاق رشته‌ها برای جلوگیری از خطای تفسیر متغیر
                    $logMessage = "رد شد (رویداد گذشته): خاموشی برای شهر " . $safeLogAreaName .
                                  "، آدرس: '" . $safeLogAddress . // اطمینان از چاپ مقدار آدرس
                                  "', زمان پایان: " . $outageEndDateTime->format('Y-m-d H:i:s P') .
                                  " قبل از زمان فعلی (" . $currentTime->format('Y-m-d H:i:s P') . ") است.";
                    cli_debug_echo($logMessage);
                    continue;
                }
                $validOutagesToProcess[] = $outage;
            }
        }
        cli_debug_echo(count($validOutagesToProcess) . " رکورد خاموشی معتبر (غیر گذشته) برای پردازش در شهر " . htmlspecialchars($areaName) . ".");

        $db->beginTransaction();
        try {
            $stmtMarkOldAsInactive = $db->prepare("UPDATE outage_events_log SET is_currently_active = 0 WHERE area_code_source = :source AND is_currently_active = 1");
            $stmtMarkOldAsInactive->bindParam(':source', $areaCodeSourceDb, PDO::PARAM_STR);
            $stmtMarkOldAsInactive->execute();
            cli_debug_echo($stmtMarkOldAsInactive->rowCount() . " خاموشی قبلی برای شهر " . htmlspecialchars($areaName) . " موقتاً غیرفعال شدند.");

            $newOutagesCount = 0;
            $reactivatedOrUpdatedOutagesCount = 0;

            if (!empty($validOutagesToProcess)) {
                foreach ($validOutagesToProcess as $outage) {
                    $tarikh = $outage['tarikh'] ?? '';
                    $az_saat = $outage['az_saat'] ?? '';
                    $ta_saat = $outage['ta_saat'] ?? '';
                    $address = $outage['address'] ?? '';
                    $specific_area = $outage['specific_area_info_from_scrape'] ?? null;
                    
                    $safeDbLogAreaName = (isset($areaName) && $areaName !== null) ? htmlspecialchars($areaName) : '[نام شهر نامشخص]';
                    if (empty($tarikh) || empty($az_saat) || empty($ta_saat) || empty($address)) {
                        cli_debug_echo("رد شد در مرحله دیتابیس (اطلاعات ناقص): خاموشی برای شهر " . $safeDbLogAreaName . ".");
                        continue;
                    }

                    $outage_event_signature = generateOutageEventSignature($tarikh, $az_saat, $ta_saat, $address);
                    $address_normalized_hash = generateNormalizedAddressHash($address);

                    $stmtExisting = $db->prepare("SELECT id, is_currently_active FROM outage_events_log WHERE outage_signature = :sig");
                    $stmtExisting->bindValue(':sig', $outage_event_signature, PDO::PARAM_STR);
                    $stmtExisting->execute();
                    $existingOutage = $stmtExisting->fetch();

                    if ($existingOutage) {
                        $sql = "UPDATE outage_events_log SET last_scraped_at = NOW(), is_currently_active = 1, tarikh = :tarikh, az_saat = :az_saat, ta_saat = :ta_saat, address_text = :address, specific_area_info_from_scrape = :specific_area, address_normalized_hash = :address_hash WHERE id = :id";
                        $stmt = $db->prepare($sql);
                        $stmt->bindValue(':id', $existingOutage['id'], PDO::PARAM_INT);
                    } else {
                        $sql = "INSERT INTO outage_events_log (outage_signature, area_code_source, tarikh, az_saat, ta_saat, address_text, specific_area_info_from_scrape, address_normalized_hash, first_scraped_at, last_scraped_at, is_currently_active) VALUES (:signature, :area_code_ref, :tarikh, :az_saat, :ta_saat, :address, :specific_area, :address_hash, NOW(), NOW(), 1)";
                        $stmt = $db->prepare($sql);
                        $stmt->bindValue(':signature', $outage_event_signature, PDO::PARAM_STR);
                        $stmt->bindValue(':area_code_ref', $areaCodeSourceDb, PDO::PARAM_STR);
                    }
                    $stmt->bindValue(':tarikh', $tarikh, PDO::PARAM_STR);
                    $stmt->bindValue(':az_saat', $az_saat, PDO::PARAM_STR);
                    $stmt->bindValue(':ta_saat', $ta_saat, PDO::PARAM_STR);
                    $stmt->bindValue(':address', $address, PDO::PARAM_STR);
                    $stmt->bindValue(':specific_area', $specific_area, $specific_area === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->bindValue(':address_hash', $address_normalized_hash, PDO::PARAM_STR);

                    if ($stmt->execute()) {
                        if ($existingOutage) {
                            $reactivatedOrUpdatedOutagesCount++;
                        } else {
                            $newOutagesCount++;
                        }
                    } else {
                        error_log("CRON DB " . ($existingOutage ? "UPDATE" : "INSERT") . " Error for sig {$outage_event_signature}: " . print_r($stmt->errorInfo(), true));
                    }
                }
            }
            $db->commit();
            cli_debug_echo("ذخیره/آپدیت خاموشی‌ها برای شهر " . htmlspecialchars($areaName) . ": جدید $newOutagesCount, فعال/آپدیت $reactivatedOrUpdatedOutagesCount");
        } catch (Exception $e_db_transaction) {
            if ($db->inTransaction()) $db->rollBack();
            $safeErrorLogAreaName = (isset($areaName) && $areaName !== null) ? htmlspecialchars($areaName) : '[نام شهر نامشخص در خطا]';
            cli_debug_echo("خطا در تراکنش دیتابیس برای شهر " . $safeErrorLogAreaName . ": " . $e_db_transaction->getMessage());
            error_log("CRON DB TRANSACTION ERROR for city " . $safeErrorLogAreaName . ": " . $e_db_transaction->getMessage());
        }
        sleep(1);
    }
    $script_executed_successfully = true;

} catch (PDOException $e) {
    $errorMessage = "CRON PDO EXCEPTION: " . $e->getMessage();
    error_log($errorMessage . "\n" . $e->getTraceAsString());
    cli_debug_echo($errorMessage);
    $script_executed_successfully = false;
} catch (Exception $e) {
    $errorMessage = "CRON GENERAL EXCEPTION: " . $e->getMessage();
    error_log($errorMessage . "\n" . $e->getTraceAsString());
    cli_debug_echo($errorMessage);
    $script_executed_successfully = false;
} finally {
    if (isset($db) && $db instanceof PDO && $script_executed_successfully) {
        $settingKeyLastCronRun = 'last_cron_successful_run_time';
        try {
            $stmtSetting = $db->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmtSetting->execute([$settingKeyLastCronRun, date('Y-m-d H:i:s')]);
            cli_debug_echo("زمان آخرین اجرای موفق کرون در دیتابیس ثبت شد.");
        } catch (PDOException $e_final) {
            error_log("CRON FINALLY PDOEx (save settings): " . $e_final->getMessage());
            cli_debug_echo("خطا در ثبت زمان نهایی اجرای موفق کرون: " . $e_final->getMessage());
        }
    }
    if (defined('COOKIE_FILE') && file_exists(COOKIE_FILE)) {
        if (unlink(COOKIE_FILE)) {
            cli_debug_echo("فایل کوکی (" . COOKIE_FILE . ") با موفقیت حذف شد.");
        } else {
            cli_debug_echo("خطا در حذف فایل کوکی (" . COOKIE_FILE . ").");
            error_log("CRON ERROR: Failed to delete cookie file: " . COOKIE_FILE);
        }
    }
    cli_debug_echo("==================================================");
    cli_debug_echo("پایان اسکریپت Cron: " . date('Y-m-d H:i:s'));
    cli_debug_echo("==================================================");
}
?>