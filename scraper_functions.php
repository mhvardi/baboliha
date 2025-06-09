<?php
require_once __DIR__ . '/config.php'; // برای دسترسی به ثابت‌های SCRAPER_ و COOKIE_FILE

/**
 * تابع برای دیباگ (اگر در config.php تعریف نشده باشد، اینجا تعریف می‌کنیم)
 */
if (!function_exists('debug_echo')) {
    function debug_echo($message, $isHtml = true) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            echo $message . ($isHtml ? "<br>\n" : "\n");
        }
    }
}

/**
 * یک امضای ثابت بر اساس تاریخ، ساعت و آدرس نرمال‌شده برای یک رویداد خاموشی ایجاد می‌کند.
 */
function generateOutageEventSignature(string $tarikh, string $azSaat, string $taSaat, string $address): string {
    $normalizedAddress = preg_replace('/\s+/', '', mb_strtolower(trim($address)));
    $dataToHash = trim($tarikh) . '|' . trim($azSaat) . '|' . trim($taSaat) . '|' . $normalizedAddress;
    return md5($dataToHash);
}

/**
 * یک هش ثابت فقط بر اساس آدرس نرمال‌شده ایجاد می‌کند.
 */
function generateNormalizedAddressHash(string $address): string {
    $normalizedAddress = preg_replace('/\s+/', '', mb_strtolower(trim($address)));
    return md5($normalizedAddress);
}

/**
 * دریافت پارامترهای اولیه ASP.NET از صفحه اصلی سایت منبع
 * @return array|null آرایه‌ای از پارامترها یا null در صورت خطا
 */
function getInitialAspParams(): ?array {
    debug_echo("مرحله ۱ (اسکرپر): ارسال GET اولیه برای پارامترهای ASP.NET...", false);
    $ch_get = curl_init();
    curl_setopt($ch_get, CURLOPT_URL, SCRAPER_BASE_URL);
    curl_setopt($ch_get, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_get, CURLOPT_USERAGENT, SCRAPER_USER_AGENT);
    curl_setopt($ch_get, CURLOPT_COOKIEJAR, COOKIE_FILE);
    curl_setopt($ch_get, CURLOPT_COOKIEFILE, COOKIE_FILE);
    curl_setopt($ch_get, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch_get, CURLOPT_TIMEOUT, 30);

    $initialResponse = curl_exec($ch_get);
    $httpCode_get = curl_getinfo($ch_get, CURLINFO_HTTP_CODE);
    $curlError_get = curl_error($ch_get);
    curl_close($ch_get);

    if ($curlError_get || $httpCode_get != 200 || empty($initialResponse)) {
        error_log("Scraper Error: Failed to get initial ASP.NET params. HTTP: $httpCode_get, cURL Error: $curlError_get");
        debug_echo("خطا جدی (اسکرپر): دریافت پارامترهای اولیه ASP.NET ناموفق بود. HTTP: $httpCode_get, cURL Error: $curlError_get", false);
        return null;
    }

    $dom_initial = new DOMDocument();
    @$dom_initial->loadHTML('<?xml encoding="UTF-8">' . $initialResponse);
    $xpath_initial = new DOMXPath($dom_initial);

    $aspParams = [
        'viewState' => $xpath_initial->query('//input[@name="__VIEWSTATE"]/@value')->item(0)->nodeValue ?? '',
        'viewStateGenerator' => $xpath_initial->query('//input[@name="__VIEWSTATEGENERATOR"]/@value')->item(0)->nodeValue ?? '',
        'eventValidation' => $xpath_initial->query('//input[@name="__EVENTVALIDATION"]/@value')->item(0)->nodeValue ?? ''
    ];

    if (empty($aspParams['viewState']) || empty($aspParams['viewStateGenerator']) || empty($aspParams['eventValidation'])) {
        if (empty($aspParams['viewState'])) $aspParams['viewState'] = $xpath_initial->query('//input[@id="__VIEWSTATE"]/@value')->item(0)->nodeValue ?? '';
        if (empty($aspParams['viewStateGenerator'])) $aspParams['viewStateGenerator'] = $xpath_initial->query('//input[@id="__VIEWSTATEGENERATOR"]/@value')->item(0)->nodeValue ?? '';
        if (empty($aspParams['eventValidation'])) $aspParams['eventValidation'] = $xpath_initial->query('//input[@id="__EVENTVALIDATION"]/@value')->item(0)->nodeValue ?? '';

        if (empty($aspParams['viewState']) || empty($aspParams['viewStateGenerator']) || empty($aspParams['eventValidation'])) {
            error_log("Scraper Error: Critical ASP.NET parameters not found in initial page.");
            debug_echo("خطا جدی (اسکرپر): پارامترهای حیاتی ASP.NET (__VIEWSTATE, __VIEWSTATEGENERATOR, __EVENTVALIDATION) از صفحه اولیه استخراج نشدند.", false);
            return null;
        }
    }
    debug_echo("پارامترهای اولیه ASP.NET با موفقیت استخراج شدند.", false);
    return $aspParams;
}

/**
 * دریافت تمام اطلاعات خاموشی برای یک شهر خاص
 * @param array $aspParams پارامترهای ASP.NET
 * @param string $cityCode کد شهر
 * @return array آرایه‌ای از خاموشی‌ها یا خالی در صورت خطا
 */
function fetchAllOutageDataForCity(array $aspParams, string $cityCode): array {
    debug_echo("----------", false);
    debug_echo("شروع دریافت اطلاعات یکجا برای شهر با کد: " . htmlspecialchars($cityCode) . ", کد امور: " . ALL_AREAS_CODE, false);

    $postData = [
        'ctl00$ScriptManager1' => 'ctl00$ContentPlaceHolder1$upOutage|ctl00$ContentPlaceHolder1$btnSearchOutage',
        'ctl00$ContentPlaceHolder1$txtSubscriberCode' => '',
        'ctl00$ContentPlaceHolder1$outage' => 'rbIsAddress',
        'ctl00$ContentPlaceHolder1$ddlCity' => $cityCode,
        'ctl00$ContentPlaceHolder1$ddlArea' => ALL_AREAS_CODE,
        'ctl00$ContentPlaceHolder1$txtPDateFrom' => '',
        'ctl00$ContentPlaceHolder1$txtPDateTo' => '',
        'ctl00$ContentPlaceHolder1$txtAddress' => '',
        '__EVENTTARGET' => '', '__EVENTARGUMENT' => '', '__LASTFOCUS' => '',
        '__VIEWSTATE' => $aspParams['viewState'],
        '__VIEWSTATEGENERATOR' => $aspParams['viewStateGenerator'],
        '__EVENTVALIDATION' => $aspParams['eventValidation'],
        '__ASYNCPOST' => 'true',
        'ctl00$ContentPlaceHolder1$btnSearchOutage' => 'جستجو'
    ];

    $ch_post = curl_init();
    curl_setopt($ch_post, CURLOPT_URL, SCRAPER_BASE_URL);
    curl_setopt($ch_post, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_post, CURLOPT_USERAGENT, SCRAPER_USER_AGENT);
    curl_setopt($ch_post, CURLOPT_COOKIEFILE, COOKIE_FILE);
    curl_setopt($ch_post, CURLOPT_COOKIEJAR, COOKIE_FILE);
    curl_setopt($ch_post, CURLOPT_POST, true);
    curl_setopt($ch_post, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch_post, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With: XMLHttpRequest', 'Origin: ' . rtrim(SCRAPER_BASE_URL, '/'), 'Referer: ' . SCRAPER_BASE_URL]);
    curl_setopt($ch_post, CURLOPT_TIMEOUT, 60);

    $ajaxResponse = curl_exec($ch_post);
    $httpCode_post = curl_getinfo($ch_post, CURLINFO_HTTP_CODE);
    $curlError_post = curl_error($ch_post);
    curl_close($ch_post);

    if ($curlError_post || $httpCode_post != 200 || empty($ajaxResponse) || strpos($ajaxResponse, 'error|500') !== false) {
        error_log("Scraper Error: Failed to fetch outage data for city $cityCode. HTTP: $httpCode_post, cURL Error: $curlError_post.");
        debug_echo("خطا در دریافت پاسخ یکجا برای شهر $cityCode. کد HTTP: $httpCode_post. خطا: $curlError_post", false);
        return [];
    }

    $htmlContent = '';
    $parts = explode('|', $ajaxResponse);
    foreach ($parts as $part) {
        if (strpos($part, 'ContentPlaceHolder1_grdOutage') !== false) {
            $htmlContent = $part;
            break;
        }
    }
    if (empty($htmlContent)) {
        $maxLength = 0;
        foreach ($parts as $part) {
            if (strlen($part) > $maxLength && (strpos($part, '<table') !== false || strpos($part, '<div') !== false)) {
                $maxLength = strlen($part);
                $htmlContent = $part;
            }
        }
    }

    if (empty($htmlContent)) {
        error_log("Scraper Error: Could not extract HTML table from AJAX response for city $cityCode.");
        debug_echo("محتوای HTML برای جدول در پاسخ شهر $cityCode یافت نشد.", false);
        return [];
    }

    $allResults = [];
    $dom_results = new DOMDocument();
    @$dom_results->loadHTML('<?xml encoding="UTF-8">' . $htmlContent);
    $xpath_results = new DOMXPath($dom_results);
    $query = '//table[contains(@id, "grdOutage")]/tr';
    $rows = $xpath_results->query($query);

    if ($rows && $rows->length > 0) {
        debug_echo("تعداد ردیف‌های خام یافت شده در جدول HTML برای شهر $cityCode: " . $rows->length, false);
        foreach ($rows as $rowIndex => $row) {
            if ($xpath_results->query('./th', $row)->length > 0) {
                debug_echo("ردیف $rowIndex هدر است، نادیده گرفته شد.", false);
                continue;
            }
            $dataCells = $xpath_results->query('./td', $row);

            if ($dataCells->length >= 5) {
                $tarikh = trim($dataCells->item(0)->nodeValue ?? '');
                $az_saat = trim($dataCells->item(1)->nodeValue ?? '');
                $ta_saat = trim($dataCells->item(2)->nodeValue ?? '');
                $address_part1 = trim($dataCells->item(3)->nodeValue ?? '');
                $address_part2 = trim($dataCells->item(4)->nodeValue ?? '');
                $address = !empty($address_part2) ? $address_part2 : $address_part1;

                $signature = generateOutageEventSignature($tarikh, $az_saat, $ta_saat, $address);

                $rowData = [
                    'tarikh' => $tarikh,
                    'az_saat' => $az_saat,
                    'ta_saat' => $ta_saat,
                    'address' => $address,
                    'specific_area_info_from_scrape' => $address_part1,
                    'signature' => $signature,
                    'city_code' => $cityCode
                ];
                $allResults[] = $rowData;
                debug_echo("رکورد استخراجی با امضا " . $signature . ": آدرس - " . htmlspecialchars($address), false);
            } else {
                debug_echo("ردیف $rowIndex در شهر $cityCode تعداد سلول کافی نداشت (" . $dataCells->length . " سلول).", false);
            }
        }
    } else {
        debug_echo("هیچ ردیفی در جدول HTML برای شهر $cityCode یافت نشد.", false);
    }
    debug_echo("تعداد کل رکوردهای معتبر استخراج شده برای شهر $cityCode (یکجا): " . count($allResults), false);
    return $allResults;
}

/**
 * دریافت اطلاعات خاموشی برای چندین شهر
 * @param array $cityCodes لیستی از کدهای شهرها
 * @return array آرایه‌ای از خاموشی‌ها برای همه شهرها
 */
function fetchAllOutageDataForCities(array $cityCodes): array {
    $allOutages = [];
    $aspParams = getInitialAspParams();
    if ($aspParams === null) {
        debug_echo("خطا در دریافت پارامترهای ASP.NET. اسکرپ متوقف شد.", false);
        return $allOutages;
    }

    foreach ($cityCodes as $cityCode) {
        debug_echo("اسکرپ کردن برای شهر: " . htmlspecialchars($cityCode), false);
        $outages = fetchAllOutageDataForCity($aspParams, $cityCode);
        if (!empty($outages)) {
            $allOutages[$cityCode] = $outages;
        }
        sleep(1); // تأخیر برای جلوگیری از فشار به سرور
    }

    if (file_exists(COOKIE_FILE)) {
        unlink(COOKIE_FILE);
    }
    return $allOutages;
}
?>