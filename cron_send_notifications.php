<?php
// /public_html/cron_send_notifications.php
// ... (بخش‌های ابتدایی، require_once ها، اتصال به دیتابیس $db_notif، مانند قبل) ...

try {
    // ... (اتصال به دیتابیس $db_notif) ...
    notification_debug_echo("اتصال به دیتابیس برای نوتیفیکیشن موفق.");

    // 1. خواندن تمام تنظیمات پیامک از دیتابیس (جدول settings)
    $sms_settings_notif = [];
    $stmtSettingsNotif = $db_notif->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'limosms_%' OR setting_key LIKE 'sms_%'");
    if ($stmtSettingsNotif) {
        foreach ($stmtSettingsNotif->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sms_settings_notif[$row['setting_key']] = $row['setting_value'];
        }
    }
    notification_debug_echo("تنظیمات پیامک از دیتابیس خوانده شد: " . count($sms_settings_notif) . " مورد.");

    // دریافت مقادیر تنظیمات با fallback به config.php یا مقادیر hardcode شده
    $apiKeyNotif = $sms_settings_notif['limosms_api_key'] ?? (defined('LIMOSMS_API_KEY_FALLBACK') ? LIMOSMS_API_KEY_FALLBACK : null);
    $senderNumberNotif = $sms_settings_notif['limosms_sender_number'] ?? (defined('LIMOSMS_SENDER_NUMBER_FALLBACK') ? LIMOSMS_SENDER_NUMBER_FALLBACK : null);
    
    // **این دو خط مهم هستند**
    $defaultPatternIdFromSettings = $sms_settings_notif['limosms_default_pattern_id'] ?? (defined('LIMOSMS_DEFAULT_PATTERN_ID_FALLBACK') ? LIMOSMS_DEFAULT_PATTERN_ID_FALLBACK : null);
    $defaultNormalTemplateFromSettings = $sms_settings_notif['sms_default_normal_template'] ?? (defined('DEFAULT_SUBSCRIBER_SMS_TEMPLATE_FALLBACK') ? DEFAULT_SUBSCRIBER_SMS_TEMPLATE_FALLBACK : "قطعی برق ({address_title}): {outage_address} تاریخ {outage_date} از {outage_start_time} تا {outage_end_time}");
    
    $allowedSendTimesConfigNotif = $sms_settings_notif['sms_allowed_send_times'] ?? '08:00,13:00,18:00';
    $smsSendTypeConfigNotif = $sms_settings_notif['sms_send_type'] ?? 'always_on_new';
    $smsMaxSendsPerDayConfigNotif = isset($sms_settings_notif['sms_max_sends_per_outage_per_day']) ? (int)$sms_settings_notif['sms_max_sends_per_outage_per_day'] : 1;

    if (!$limo_sms_helper_loaded_notif) { throw new Exception("LimoSmsHelper بارگذاری نشده. ارسال پیامک متوقف شد.");}
    if (empty($apiKeyNotif) || empty($senderNumberNotif)) { throw new Exception("کلید API یا شماره فرستنده پیامک در تنظیمات یافت نشد. ارسال پیامک متوقف شد.");}

    // ... (منطق canSendSmsThisRunNotif مانند قبل) ...
    
    if (!$canSendSmsThisRunNotif) { /* ... لاگ ... */ }
    else {
        notification_debug_echo("ارسال پیامک مجاز است. خواندن کاربران واجد شرایط...");
        // ... (خواندن $active_users_for_sms از جدول users مانند قبل) ...
        // ... (خواندن $outagesToNotify مانند قبل) ...

        if (!empty($outagesToNotify) && !empty($active_users_for_sms)) {
            notification_debug_echo(count($outagesToNotify) . " خاموشی برای ارسال به " . count($active_users_for_sms) . " کاربر.");
            foreach ($active_users_for_sms as $user) {
                // ... (خواندن $subscribed_addresses برای کاربر مانند قبل) ...
                if (empty($subscribed_addresses)) continue;

                foreach ($outagesToNotify as $outage_event) {
                    // ... (منطق تطابق آدرس و $matchedAddressTitle مانند قبل) ...

                    if ($addressMatchedNotif) {
                        // ... (بررسی سقف ارسال روزانه $alreadySentTodayCountNotif مانند قبل) ...

                        if ($alreadySentTodayCountNotif < $smsMaxSendsPerDayConfigNotif) {
                            // ... (آماده‌سازی $sms_tarikh_formatted و $full_time_range مانند قبل) ...
                            $messageContentOrPatternIdForLog = ''; $messageTypeForLog = ''; $limoSmsResponse = null;

                            if (isset($user['use_pattern_sms']) && $user['use_pattern_sms'] == 1) { // کاربر می‌خواهد از پترن استفاده کند
                                // اولویت با پترن اختصاصی کاربر، سپس پترن پیش‌فرض از تنظیمات
                                $patternIdToSend = !empty($user['pattern_id_override']) ? $user['pattern_id_override'] : $defaultPatternIdFromSettings;
                                
                                if ($patternIdToSend) {
                                    // توکن‌ها: 0=عنوان آدرس, 1=متن آدرس خاموشی, 2=تاریخ, 3=بازه زمانی
                                    $tokens = [
                                        $matchedAddressTitleNotif,
                                        mb_substr($outage_event['address_text'], 0, 60, "UTF-8"), // خلاصه کردن آدرس برای پترن
                                        $sms_tarikh_formatted,
                                        $full_time_range
                                    ];
                                    $limoSmsResponse = sendLimoPatternSms($user['phone'], $patternIdToSend, $tokens, $apiKeyNotif);
                                    $messageTypeForLog = 'pattern'; 
                                    $messageContentOrPatternIdForLog = 'ID:' . $patternIdToSend . ' Tokens:' . implode('|',array_map('htmlspecialchars', $tokens));
                                    notification_debug_echo("تلاش ارسال پترن (ID: {$patternIdToSend}) به " . $user['phone'] . " برای: " . $matchedAddressTitleNotif);
                                } else {
                                    $messageTypeForLog = 'pattern_error'; 
                                    $messageContentOrPatternIdForLog = 'کد پترن (اختصاصی یا پیش‌فرض) تنظیم نشده است.';
                                    notification_debug_echo("خطا: کد پترن برای " . htmlspecialchars($user['name']) . " یا پترن پیش‌فرض از تنظیمات، یافت نشد.", true);
                                }
                            } else { // ارسال پیامک عادی
                                // استفاده از تمپلیت پیش‌فرض خوانده شده از تنظیمات
                                $sms_body = str_replace(
                                    ['{subscriber_name}', '{address_title}', '{outage_address}', '{outage_date}', '{outage_start_time}', '{outage_end_time}'],
                                    [htmlspecialchars($user['name']), htmlspecialchars($matchedAddressTitleNotif), htmlspecialchars($outage_event['address_text']), $sms_tarikh_formatted, htmlspecialchars($outage_event['az_saat']), htmlspecialchars($outage_event['ta_saat'])],
                                    $defaultNormalTemplateFromSettings // استفاده از تمپلیت خوانده شده
                                );
                                $limoSmsResponse = sendLimoNormalSms([$user['phone']], $sms_body, $apiKeyNotif, $senderNumberNotif);
                                $messageTypeForLog = 'normal'; $messageContentOrPatternIdForLog = $sms_body;
                                notification_debug_echo("تلاش ارسال پیامک عادی به " . $user['phone'] . " برای: " . $matchedAddressTitleNotif);
                            }
                            
                            // ... (لاگ کردن نتیجه ارسال در sms_log مانند قبل) ...
                        } // پایان if alreadySentTodayCount
                        // break; // برای هر کاربر، فقط برای اولین آدرس منطبق پیامک ارسال شود (اگر این منطق را می‌خواهید)
                    } // پایان if addressMatchedNotif
                } // پایان foreach outagesToNotify
            } // پایان foreach active_users_for_sms
        } // پایان else (اگر کاربری یافت شد)
    } // پایان else (اگر ارسال مجاز بود)
} // پایان else (اگر کلید و شماره فرستنده معتبر بود)

    $notification_run_status_message = "اسکریپت نوتیفیکیشن با موفقیت اجرا شد.";

} catch (Exception $e) { /* ... مدیریت خطا ... */ }
finally { /* ... بخش finally ... */ }
// بدون تگ پایانی ?>