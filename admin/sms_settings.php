<?php
// /public_html/admin/sms_settings.php

$pageTitle = "تنظیمات سامانه پیامک";

// 1. لود کردن هدر ادمین
if (file_exists(__DIR__ . '/layouts/_header.php')) {
    require_once __DIR__ . '/layouts/_header.php';
} else {
    error_log("SMS SETTINGS FATAL ERROR: Admin header layout file not found.");
    die("خطای سیستمی: فایل لایوت هدر ادمین یافت نشد.");
}
// $db, $jdf_loaded_for_admin_layout, $loggedInAdminUsername, site_url() باید در دسترس باشند

// بارگذاری LimoSmsHelper برای تابع getLimoSmsCredit (اگر در هدر لود نشده)
$limo_sms_helper_loaded_settings = false;
if (function_exists('getLimoSmsCredit')) { // فرض می‌کنیم LimoSmsHelper.php قبلاً include شده (مثلاً در _header.php)
    $limo_sms_helper_loaded_settings = true;
} else {
    $limo_helper_path_settings = ROOT_PATH . '/lib/LimoSmsHelper.php';
    if (file_exists($limo_helper_path_settings)) {
        require_once $limo_helper_path_settings;
        if (function_exists('getLimoSmsCredit')) {
            $limo_sms_helper_loaded_settings = true;
        } else {
             error_log("SMS_SETTINGS WARNING: LimoSmsHelper.php loaded but getLimoSmsCredit function not found.");
        }
    } else {
        error_log("SMS_SETTINGS WARNING: LimoSmsHelper.php not found at {$limo_helper_path_settings}. Cannot get credit.");
    }
}


$message = $_SESSION['settings_message'] ?? null;
if ($message) unset($_SESSION['settings_message']);

$settings_from_db = [];
$errorMessageForPage = null;
$current_credit_display = "کلید API وارد نشده یا نامعتبر است."; // مقدار پیش‌فرض

try {
    if (!isset($db) || !$db instanceof PDO) {
        throw new Exception("اتصال به پایگاه داده برای تنظیمات پیامک در دسترس نیست.");
    }

    $setting_keys_to_manage = [
        'limosms_api_key', 'limosms_sender_number', 'limosms_default_pattern_id',
        'sms_default_normal_template', 'sms_allowed_send_times', 'sms_send_type',
        'sms_max_sends_per_outage_per_day'
    ];

    // خواندن تنظیمات فعلی از دیتابیس
    $placeholders = implode(',', array_fill(0, count($setting_keys_to_manage), '?'));
    $stmtGetSettings = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmtGetSettings->execute($setting_keys_to_manage);
    
    foreach ($stmtGetSettings->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings_from_db[$row['setting_key']] = $row['setting_value'];
    }

    // مقادیر پیش‌فرض برای فرم اگر تنظیمات در دیتابیس نیست
    $default_settings_values = [
        'limosms_api_key' => '',
        'limosms_sender_number' => '',
        'limosms_default_pattern_id' => '1330',
        'sms_default_normal_template' => "قطعی برق امروز\nتاریخ: {outage_date}\nمحدوده ({address_title}): {outage_address}\nاز ساعت: {outage_start_time}\nتا ساعت: {outage_end_time}\nلغو۱۱",
        'sms_allowed_send_times' => '08:00,14:00,20:00',
        'sms_send_type' => 'at_scheduled_times_for_active',
        'sms_max_sends_per_outage_per_day' => 1
    ];

    // ادغام مقادیر دیتابیس با پیش‌فرض‌ها
    $current_settings_form = array_merge($default_settings_values, $settings_from_db);


    // پردازش فرم اگر ارسال شده باشد
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sms_settings'])) {
        $new_settings_to_save = [];
        foreach ($setting_keys_to_manage as $key) {
            if ($key === 'sms_max_sends_per_outage_per_day') {
                $new_settings_to_save[$key] = (int)($_POST[$key] ?? $default_settings_values[$key]);
            } else {
                $new_settings_to_save[$key] = trim($_POST[$key] ?? $default_settings_values[$key]);
            }
        }

        if (empty($new_settings_to_save['limosms_api_key'])) {
            $message = ['type' => 'error', 'text' => 'کلید API لیمو پیامک نمی‌تواند خالی باشد.'];
        } elseif (empty($new_settings_to_save['limosms_sender_number'])) {
            $message = ['type' => 'error', 'text' => 'شماره فرستنده پیش‌فرض لیمو پیامک نمی‌تواند خالی باشد.'];
        } else {
            $db->beginTransaction();
            try {
                $stmtReplace = $db->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (:key, :value)");
                foreach ($new_settings_to_save as $key => $value) {
                    $stmtReplace->bindValue(':key', $key, PDO::PARAM_STR);
                    $stmtReplace->bindValue(':value', $value, PDO::PARAM_STR); // تمام مقادیر به عنوان رشته ذخیره می‌شوند
                    $stmtReplace->execute();
                }
                $db->commit();
                $current_settings_form = $new_settings_to_save; // به‌روزرسانی برای نمایش مقادیر جدید
                $_SESSION['settings_message'] = ['type' => 'success', 'text' => 'تنظیمات پیامک با موفقیت ذخیره شد.'];
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $_SESSION['settings_message'] = ['type' => 'error', 'text' => 'خطا در ذخیره تنظیمات پیامک: ' . $e->getMessage()];
                error_log("SMS Settings Save Error: " . $e->getMessage());
            }
            if (!headers_sent()) { header("Location: sms_settings.php"); exit; }
            else { echo "<script>window.location.href='sms_settings.php';</script>"; exit;}
        }
    }

    // دریافت اعتبار فعلی
    $apiKeyForCreditCheck = $current_settings_form['limosms_api_key'] ?? null;
    if (!empty($apiKeyForCreditCheck) && $limo_sms_helper_loaded_settings && function_exists('getLimoSmsCredit')) {
        $credit_response = getLimoSmsCredit($apiKeyForCreditCheck); // تابع باید apiKey را بپذیرد
        if ($credit_response && isset($credit_response['IsSuccessful']) && $credit_response['IsSuccessful'] === true && isset($credit_response['Credit'])) {
            $current_credit_display = number_format((float)$credit_response['Credit'], 0) . " ریال";
        } elseif ($credit_response && isset($credit_response['Message'])) {
            $current_credit_display = "خطا در دریافت اعتبار: " . htmlspecialchars($credit_response['Message']);
        } elseif ($credit_response === null){
             $current_credit_display = "پاسخی از سرویس اعتبار دریافت نشد (کلید API نامعتبر؟)";
        }
    } elseif (empty($apiKeyForCreditCheck) && $limo_sms_helper_loaded_settings) {
        // $current_credit_display مقدار پیش‌فرض خود را دارد
    } elseif (!$limo_sms_helper_loaded_settings) {
        $current_credit_display = "سرویس کمکی LimoSmsHelper.php بارگذاری نشده است.";
    }


} catch (Exception $e) {
    $errorMessageForPage = "خطا در بارگذاری صفحه تنظیمات پیامک: " . htmlspecialchars($e->getMessage());
    error_log("SMS Settings Page General Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}

?>

<div class="admin-page-content">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>

    <?php if (isset($message) && is_array($message)): ?>
        <div class="message <?php echo htmlspecialchars($message['type']); ?>">
            <?php echo htmlspecialchars($message['text']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($errorMessageForPage)): ?>
         <div class="message error"><?php echo $errorMessageForPage; ?></div>
    <?php endif; ?>

    <form action="sms_settings.php" method="post" class="settings-form">
        <fieldset>
            <legend>تنظیمات اتصال به LimoSMS API</legend>
            <div class="form-group">
                <label for="limosms_api_key_form">کلید API (ApiKey):</label>
                <input type="text" id="limosms_api_key_form" name="limosms_api_key" value="<?php echo htmlspecialchars($current_settings_form['limosms_api_key'] ?? ''); ?>" style="direction:ltr; text-align:left;" required>
            </div>
            <div class="form-group">
                <label for="limosms_sender_number_form">شماره فرستنده پیش‌فرض:</label>
                <input type="text" id="limosms_sender_number_form" name="limosms_sender_number" value="<?php echo htmlspecialchars($current_settings_form['limosms_sender_number'] ?? ''); ?>" style="direction:ltr; text-align:left;" required>
            </div>
            <div class="form-group">
                <label>اعتبار فعلی سامانه LimoSMS:</label>
                <p class="current-credit-display"><?php echo htmlspecialchars($current_credit_display); ?></p>
                <small>برای به‌روزرسانی اعتبار، پس از ذخیره کلید API جدید، صفحه را رفرش کنید.</small>
            </div>
        </fieldset>

        <fieldset>
            <legend>تنظیمات محتوای پیامک</legend>
            <div class="form-group">
                <label for="limosms_default_pattern_id_form">کد پترن پیش‌فرض (مثال: 1330):</label>
                <input type="text" id="limosms_default_pattern_id_form" name="limosms_default_pattern_id" value="<?php echo htmlspecialchars($current_settings_form['limosms_default_pattern_id'] ?? ''); ?>">
                <small>این کد برای ارسال پیامک‌های پترن به مشترکینی که "استفاده از پترن" برایشان فعال است و "کد پترن اختصاصی" ندارند، استفاده می‌شود.
                <br>توکن‌های پترن شما (مثال برای "قطعی برق {0} در آدرس {1} تاریخ {2} از {3}"):
                <br><code>{0}</code>: عنوان آدرس (مثلاً خانه)
                <br><code>{1}</code>: آدرس کامل خاموشی
                <br><code>{2}</code>: تاریخ خاموشی (شمسی Y/m/d)
                <br><code>{3}</code>: بازه زمانی (مثلاً 09:00 الی 11:00)
                </small>
            </div>
            <div class="form-group">
                <label for="sms_default_normal_template_form">متن پیش‌فرض پیامک عادی برای مشترکین:</label>
                <textarea id="sms_default_normal_template_form" name="sms_default_normal_template" rows="7" placeholder="قطعی برق امروز&#10;تاریخ: {outage_date}&#10;محدوده ({address_title}): {outage_address}&#10;از ساعت: {outage_start_time}&#10;تا ساعت: {outage_end_time}&#10;لغو۱۱"><?php echo htmlspecialchars($current_settings_form['sms_default_normal_template'] ?? ''); ?></textarea>
                <small>متغیرهای قابل استفاده: <code>{subscriber_name}</code>, <code>{address_title}</code>, <code>{outage_address}</code>, <code>{outage_date}</code>, <code>{outage_start_time}</code>, <code>{outage_end_time}</code></small>
            </div>
        </fieldset>
        
        <fieldset>
            <legend>تنظیمات زمان‌بندی و نحوه ارسال</legend>
             <div class="form-group">
                <label for="sms_allowed_send_times_form">ساعات مجاز ارسال پیامک خودکار (با کاما جدا کنید، مثال: 08:00,14:00,20:00):</label>
                <input type="text" id="sms_allowed_send_times_form" name="sms_allowed_send_times" value="<?php echo htmlspecialchars($current_settings_form['sms_allowed_send_times'] ?? ''); ?>" style="direction:ltr; text-align:left;" placeholder="07:00,07:30,08:00,13:00,18:00">
                <small>پیامک‌های خودکار (اگر نوع ارسال "در ساعات مقرر" باشد) فقط در این ساعت‌ها (دقیقه دقیق) ارسال می‌شوند.</small>
            </div>
             <div class="form-group">
                <label for="sms_send_type_form">نوع ارسال پیامک‌های خودکار به مشترکین:</label>
                <select id="sms_send_type_form" name="sms_send_type">
                    <option value="at_scheduled_times_for_active" <?php if (($current_settings_form['sms_send_type'] ?? '') === 'at_scheduled_times_for_active') echo 'selected'; ?>>فقط در ساعات مقرر (برای تمام خاموشی‌های فعال)</option>
                    <option value="always_on_new" <?php if (($current_settings_form['sms_send_type'] ?? '') === 'always_on_new') echo 'selected'; ?>>به محض شناسایی خاموشی جدید/فعال شده (در هر زمان)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="sms_max_sends_per_outage_per_day_form">حداکثر ارسال برای هر خاموشی خاص به هر مشترک در یک روز:</label>
                <input type="number" id="sms_max_sends_per_outage_per_day_form" name="sms_max_sends_per_outage_per_day" value="<?php echo htmlspecialchars($current_settings_form['sms_max_sends_per_outage_per_day'] ?? 1); ?>" min="1" style="width: 100px;">
            </div>
        </fieldset>

        <button type="submit" name="save_sms_settings" class="btn-submit">ذخیره تنظیمات پیامک</button>
    </form>
</div>
<style>
    /* استایل‌های این صفحه (می‌توانید به admin_style.css منتقل کنید) */
    .settings-form fieldset { margin-bottom: 25px; border: 1px solid #e0e6ed; padding: 20px 25px; border-radius: 8px; background-color: #fdfdfd; }
    .settings-form legend { font-weight: 600; padding: 0 10px; color: #3f51b5; font-size: 1.1em; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; margin-bottom: 7px; font-weight: 500; color: #495057; }
    .form-group input[type="text"], .form-group input[type="number"], .form-group input[type="url"], .form-group textarea, .form-group select {
        width: 100%; padding: 10px 12px; border: 1px solid #ced4da; border-radius: 5px; box-sizing: border-box;
        font-family: 'IRANSansX', Tahoma, sans-serif; font-size:0.95em; background-color: #fff;
    }
    .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
        border-color: #1abc9c; box-shadow: 0 0 0 0.2rem rgba(26, 188, 156, .20); outline: none;
    }
    .form-group textarea { min-height: 120px; resize: vertical; }
    .form-group small { display: block; margin-top: 6px; font-size: 0.88em; color: #6c757d; line-height: 1.6;}
    .form-group small code { background-color: #e9ecef; padding: 2px 5px; border-radius: 3px; font-size: 0.95em; color: #e83e8c;}
    .current-credit-display {font-weight:bold; color: #0056b3; padding: 8px; background-color: #f0f8ff; border: 1px solid #cce5ff; border-radius: 4px; display: inline-block; margin-top: 5px;}
    .btn-submit { /* استایل دکمه از admin_style.css باید اعمال شود */ }
</style>
<?php
if (file_exists(__DIR__ . '/layouts/_footer.php')) {
    require_once __DIR__ . '/layouts/_footer.php';
}
?>