<?php
// /public_html/lib/LimoSmsHelper.php

// تعریف تابع دیباگ در ابتدای فایل و در سطح سراسری
if (!function_exists('limoSmsCliDebugEcho')) {
    function limoSmsCliDebugEcho($message, $is_error_type = false) {
        // اطمینان از تعریف DEBUG_MODE (باید از config.php آمده باشد)
        $is_debug_mode_active = (defined('DEBUG_MODE') && DEBUG_MODE === true);
        $timestamp = "[" . date('Y-m-d H:i:s') . "] ";
        $log_prefix = $is_error_type ? "LIMO_SMS_ERROR: " : "LIMO_SMS_LOG: ";
        $cli_prefix = $is_error_type ? "ERROR: " : "INFO: ";

        error_log($timestamp . $log_prefix . preg_replace('/<br\s*\/?>/i', "\n", $message));
        
        if ($is_debug_mode_active) {
            if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']) || headers_sent()) {
                echo $timestamp . $cli_prefix . preg_replace('/<br\s*\/?>/i', "\n", $message) . "\n";
            } else {
                echo "<div style='color:" . ($is_error_type ? "red" : "navy") . "; border-bottom:1px dotted #eee; padding:1px; font-size:0.9em;'>" . $timestamp . $cli_prefix . $message . "</div>\n";
            }
            if(PHP_SAPI !== 'cli') flush();
        }
    }
}


/**
 * تابع داخلی برای اجرای درخواست cURL به LimoSMS
 * @param string $apiUrl URL کامل API
 * @param array|null $postDataArray آرایه داده‌ها برای ارسال به صورت JSON در بدنه POST.
 * اگر null باشد، درخواست POST بدون بدنه ارسال می‌شود.
 * اگر آرایه خالی [] باشد، بدنه JSON به صورت "{}" ارسال می‌شود.
 * @param string $apiKey کلید API
 * @param bool $isPost آیا درخواست از نوع POST است (پیش‌فرض true)
 * @return array پاسخ API به صورت آرایه، شامل کلیدهای 'IsSuccessful' (boolean) و 'Message' (string) و 'OriginalResponse' و 'HttpCode'.
 * در صورت موفقیت ممکن است کلیدهای دیگری مانند 'Credit' یا 'MessageId' هم داشته باشد.
 */
function _executeLimoSmsApiRequest(string $apiUrl, ?array $postDataArray, string $apiKey, bool $isPost = true): array {
    if (empty($apiKey)) {
        limoSmsCliDebugEcho("کلید API لیمو پیامک برای اجرای درخواست ارائه نشده است.", true);
        return ['IsSuccessful' => false, 'Message' => 'کلید API ارائه نشده است.', 'HttpCode' => 0, 'OriginalResponse' => null];
    }

    $ch = curl_init();
    if (!$ch) {
        $errorMsg = "خطای بحرانی: تابع curl_init() برای URL [{$apiUrl}] ناموفق بود.";
        limoSmsCliDebugEcho($errorMsg, true);
        error_log("LimoSmsHelper cURL FATAL: curl_init() failed for " . $apiUrl);
        return ['IsSuccessful' => false, 'Message' => 'خطای داخلی سرور (cURL init).', 'HttpCode' => 0, 'OriginalResponse' => null];
    }

    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45); // افزایش تایم‌اوت
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'BabolBarghNotifier/1.1 (+https://baboliha.ir)'); // User agent مناسب

    $headers = [
        'ApiKey: ' . $apiKey,
        'Accept: application/json' // درخواست پاسخ JSON
    ];

    if ($isPost) {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($postDataArray !== null) { // فقط اگر null نیست، بدنه JSON ارسال کن
            $jsonData = json_encode($postDataArray);
            if (json_last_error() !== JSON_ERROR_NONE) {
                limoSmsCliDebugEcho("خطا در تبدیل داده‌ها به JSON: " . json_last_error_msg() . " برای داده: " . print_r($postDataArray, true), true);
                curl_close($ch);
                return ['IsSuccessful' => false, 'Message' => 'خطای داخلی سرور (JSON encode).', 'HttpCode' => 0, 'OriginalResponse' => null];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = 'Content-Type: application/json; charset=utf-8'; // ارسال با charset
            // Content-Length توسط cURL خودکار تنظیم می‌شود وقتی CURLOPT_POSTFIELDS ست شده باشد.
        } else {
            // برای POST بدون بدنه (اگر API نیاز دارد)
            curl_setopt($ch, CURLOPT_POSTFIELDS, ""); // یا حذف کنید اگر نباید هیچ بدنه ای ارسال شود
            $headers[] = 'Content-Length: 0';
        }
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlErrMsg = curl_error($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    limoSmsCliDebugEcho("درخواست به: " . $apiUrl . " | کد HTTP: " . $httpCode . " | نوع محتوا: " . ($contentType ?? 'N/A'));
    // limoSmsCliDebugEcho("پاسخ خام (۵۰۰ کاراکتر اول): " . substr($responseBody ?? '', 0, 500));

    if ($curlErrNo) {
        limoSmsCliDebugEcho("خطای cURL (#{$curlErrNo}): " . $curlErrMsg, true);
        return ['IsSuccessful' => false, 'Message' => "cURL Error (#{$curlErrNo}): " . $curlErrMsg, 'HttpCode' => $httpCode, 'OriginalResponse' => null];
    }

    $decodedResponse = json_decode($responseBody, true);

    if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) {
        limoSmsCliDebugEcho("خطا در دیکود کردن پاسخ JSON. کد HTTP: {$httpCode}. پاسخ خام: " . substr($responseBody ?? '', 0, 200), true);
        // اگر کد HTTP موفقیت آمیز بود ولی JSON مشکل داشت، ممکن است پاسخ غیر JSON موفقیت آمیز باشد
        if ($httpCode >= 200 && $httpCode < 300 && !empty(trim($responseBody ?? ''))) {
             return ['IsSuccessful' => true, 'Message' => 'پاسخ غیر JSON موفق از سرور دریافت شد.', 'Data' => $responseBody, 'HttpCode' => $httpCode, 'OriginalResponse' => $responseBody];
        }
        return ['IsSuccessful' => false, 'Message' => 'پاسخ دریافتی از سرور فرمت JSON معتبر ندارد.', 'HttpCode' => $httpCode, 'OriginalResponse' => $responseBody];
    }
    
    // ساختاردهی پاسخ نهایی
    $finalResponse = [
        'IsSuccessful' => false, // پیش‌فرض
        'Message' => 'وضعیت نامشخص از API.',
        'HttpCode' => $httpCode,
        'OriginalResponse' => $decodedResponse ?? $responseBody // اگر دیکود نشد، پاسخ خام را نگه دار
    ];

    if (is_array($decodedResponse)) {
        // بررسی هر دو حالت success (کوچک) و IsSuccessful (بزرگ)
        if ((isset($decodedResponse['success']) && $decodedResponse['success'] === true) || 
            (isset($decodedResponse['IsSuccessful']) && $decodedResponse['IsSuccessful'] === true)) {
            $finalResponse['IsSuccessful'] = true;
        }
        $finalResponse['Message'] = $decodedResponse['message'] ?? ($decodedResponse['Message'] ?? ($finalResponse['IsSuccessful'] ? 'عملیات موفق' : 'خطا در عملیات API'));

        // اضافه کردن سایر فیلدهای مفید از پاسخ API
        if (isset($decodedResponse['Credit'])) $finalResponse['Credit'] = $decodedResponse['Credit'];
        if (isset($decodedResponse['MessageId'])) $finalResponse['MessageId'] = $decodedResponse['MessageId'];
        if (isset($decodedResponse['Messages'])) $finalResponse['Messages'] = $decodedResponse['Messages']; // برای getstatus
        if (isset($decodedResponse['Data'])) $finalResponse['Data'] = $decodedResponse['Data'];
        if (isset($decodedResponse['result'])) $finalResponse['result'] = $decodedResponse['result'];
    } elseif ($httpCode >= 200 && $httpCode < 300) { // اگر پاسخ JSON نبود ولی کد HTTP موفق بود
        $finalResponse['IsSuccessful'] = true;
        $finalResponse['Message'] = 'پاسخ موفقیت آمیز غیر JSON دریافت شد.';
    } else { // اگر پاسخ JSON نبود و کد HTTP هم خطا بود
         $finalResponse['Message'] = 'خطای HTTP از سرور پیامک: ' . $httpCode;
    }
    
    if ($finalResponse['IsSuccessful']) {
        limoSmsCliDebugEcho("API لیمو پیامک عملیات موفق گزارش کرد. پیام: " . $finalResponse['Message']);
    } else {
        limoSmsCliDebugEcho("API لیمو پیامک عملیات ناموفق گزارش کرد. پیام: " . $finalResponse['Message'] . " | کد HTTP: " . $httpCode, true);
    }
    return $finalResponse;
}


/**
 * ارسال پیامک عادی
 * @param array $receivers آرایه‌ای از شماره‌های گیرنده
 * @param string $message متن پیام
 * @param string $apiKey کلید API
 * @param string $senderNumber شماره فرستنده
 * @return array پاسخ API
 */
function sendLimoNormalSms(array $receivers, string $message, string $apiKey, string $senderNumber): array {
    if (empty($senderNumber)) { 
        return ['IsSuccessful' => false, 'Message' => 'شماره فرستنده برای پیامک عادی ارائه نشده است.'];
    }
    $apiUrl = 'https://api.limosms.com/api/sendsms'; // اندپوینت صحیح
    $postData = [ 
        'Message' => $message, 
        'SenderNumber' => $senderNumber, 
        'MobileNumber' => $receivers, 
    ];
    return _executeLimoSmsApiRequest($apiUrl, $postData, $apiKey, true);
}

/**
 * ارسال پیامک بر اساس پترن
 * @param string $mobileNumber شماره گیرنده (لیمو پیامک برای پترن معمولا تکی می‌پذیرد)
 * @param string $patternId کد پترن
 * @param array $tokens آرایه‌ای از مقادیر برای جایگزینی در پترن (توکن‌ها باید رشته باشند)
 * @param string $apiKey کلید API
 * @return array پاسخ API
 */
function sendLimoPatternSms(string $mobileNumber, string $patternId, array $tokens, string $apiKey): array {
    // اطمینان از اینکه توکن‌ها رشته هستند
    $stringTokens = array_map('strval', $tokens);
    $apiUrl = 'https://api.limosms.com/api/sendpatternmessage'; // اندپوینت صحیح
    $postData = [ 
        'OtpId' => $patternId,          // نام پارامتر در LimoSMS معمولا OtpId یا PatternCode است
        'ReplaceToken' => $stringTokens, // نام پارامتر در LimoSMS معمولا ReplaceToken یا Parameters است
        'MobileNumber' => $mobileNumber, 
    ];
    return _executeLimoSmsApiRequest($apiUrl, $postData, $apiKey, true);
}

/**
 * دریافت اعتبار فعلی سامانه پیامک
 * @param string $apiKey کلید API
 * @return array پاسخ API (شامل کلید Credit در صورت موفقیت)
 */
function getLimoSmsCredit(string $apiKey): array {
    $apiUrl = 'https://api.limosms.com/api/getcurrentcredit'; // اندپوینت صحیح
    // طبق مستندات نمونه شما، getcurrentcredit با POST فراخوانی می‌شود.
    // معمولاً API های دریافت اعتبار نیاز به بدنه خالی JSON دارند.
    return _executeLimoSmsApiRequest($apiUrl, [] , $apiKey, true);
}

/**
 * دریافت وضعیت پیامک‌های ارسالی از LimoSMS
 * @param array $messageIds آرایه‌ای از شناسه‌های پیامک
 * @param string $apiKey کلید API شما
 * @return array پاسخ API شامل آرایه‌ای از وضعیت‌ها در کلید 'Messages'
 */
function getLimoSmsStatus(array $messageIds, string $apiKey): array {
    if (empty($messageIds)) {
        return ['IsSuccessful' => false, 'Message' => 'شناسه پیامک برای بررسی وضعیت ارائه نشده است.'];
    }
    $apiUrl = 'https://api.limosms.com/api/getstatus'; // اندپوینت صحیح
    $postData = ['MessageId' => $messageIds]; // ارسال آرایه از شناسه‌ها
    
    $response = _executeLimoSmsApiRequest($apiUrl, $postData, $apiKey, true);
    
    // ممکن است LimoSMS پاسخ موفقیت‌آمیز کلی بدهد و وضعیت هر پیامک در آرایه Messages باشد
    // این تابع خود پاسخ کامل را برمی‌گرداند، پردازش بیشتر در محل فراخوانی انجام شود.
    return $response;
}

// بدون تگ پایانی ?>