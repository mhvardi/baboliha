<?php
// /public_html/admin/manual_sms_send.php

$pageTitle = "ارسال پیامک دستی و پیگیری وضعیت";

if (file_exists(__DIR__ . '/layouts/_header.php')) {
    require_once __DIR__ . '/layouts/_header.php';
} else { /* ... die ... */ }
// $db, $jdf_loaded_for_admin_layout, $loggedInAdminUsername, site_url(), ROOT_PATH باید در دسترس باشند

// بارگذاری LimoSmsHelper
$limo_sms_helper_loaded_manual = false;
$limo_helper_path_manual = '';
if (defined('ROOT_PATH')) {
    $limo_helper_path_manual = ROOT_PATH . '/lib/LimoSmsHelper.php';
    if (file_exists($limo_helper_path_manual)) {
        if (!function_exists('sendLimoNormalSms')) { require_once $limo_helper_path_manual; }
        if (function_exists('sendLimoNormalSms') && function_exists('getLimoSmsStatus')) {
            $limo_sms_helper_loaded_manual = true;
        } else { error_log("MANUAL SMS SEND WARNING: LimoSmsHelper.php included but key functions are missing.");}
    } else { error_log("MANUAL SMS SEND WARNING: LimoSmsHelper.php not found at {$limo_helper_path_manual}.");}
} else { error_log("MANUAL SMS SEND ERROR: ROOT_PATH constant not defined."); }


$message = $_SESSION['manual_sms_message'] ?? null;
if ($message) unset($_SESSION['manual_sms_message']);

$form_data_sticky = $_POST;
$errorMessageForPage = '';
$current_credit_display = "نیاز به تنظیمات API";
$limosms_api_key = null;
$limosms_sender_number = null;
$initial_sms_log_for_display = []; // برای نمایش اولیه لاگ

if (!$limo_sms_helper_loaded_manual && empty($errorMessageForPage)) {
    $errorMessageForPage = "خطا: کتابخانه LimoSmsHelper.php به درستی بارگذاری نشده است.";
}

if (isset($db) && $db instanceof PDO) {
    try {
        $stmtSettings = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('limosms_api_key', 'limosms_sender_number')");
        if ($stmtSettings) {
            $settings_array = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);
            $limosms_api_key = $settings_array['limosms_api_key'] ?? null;
            $limosms_sender_number = $settings_array['limosms_sender_number'] ?? null;

            if ($limo_sms_helper_loaded_manual && !empty($limosms_api_key) && function_exists('getLimoSmsCredit')) {
                $credit_response = getLimoSmsCredit($limosms_api_key);
                if ($credit_response && isset($credit_response['IsSuccessful']) && $credit_response['IsSuccessful'] === true && isset($credit_response['Credit']) && is_numeric($credit_response['Credit'])) {
                    $current_credit_display = number_format((float)$credit_response['Credit'], 0) . " ریال";
                } else { $current_credit_display = "خطا: " . htmlspecialchars($credit_response['Message'] ?? 'عدم دریافت اعتبار');}
            } elseif (empty($limosms_api_key)) { $current_credit_display = "کلید API تنظیم نشده."; }
        }
    } catch (Exception $e) { /* ... لاگ خطا ... */ }

    // پردازش ارسال پیامک
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_manual_sms']) && $limo_sms_helper_loaded_manual && !empty($limosms_api_key) && !empty($limosms_sender_number)) {
        $phone_numbers_str = trim($_POST['phone_numbers'] ?? '');
        $selected_subscribers_phones = $_POST['selected_subscribers'] ?? [];
        $sms_text = trim($_POST['sms_text'] ?? '');
        $all_recipients = [];

        if (!empty($phone_numbers_str)) { /* ... منطق استخراج و اعتبارسنجی شماره‌ها از textarea ... */ }
        if (!empty($selected_subscribers_phones)) { /* ... افزودن شماره‌های انتخاب شده از لیست ... */ }
        $all_recipients = array_unique(array_filter($all_recipients, function($num){ return preg_match('/^09[0-9]{9}$/', $num); }));


        if (empty($all_recipients)) { $message = ['type' => 'error', 'text' => 'حداقل یک شماره تلفن معتبر لازم است.']; }
        elseif (empty($sms_text)) { $message = ['type' => 'error', 'text' => 'متن پیامک نمی‌تواند خالی باشد.']; }
        else {
            $responseLimo = sendLimoNormalSms($all_recipients, $sms_text, $limosms_api_key, $limosms_sender_number);
            $batch_id_for_log = uniqid('manual_'); // یک شناسه برای این دسته از ارسال‌ها

            if ($responseLimo && isset($responseLimo['IsSuccessful'])) {
                if ($responseLimo['IsSuccessful']) {
                    $sent_to_count = count($all_recipients);
                    // MessageId از لیمو پیامک ممکن است یک شناسه واحد برای گروه یا آرایه‌ای از شناسه‌ها باشد
                    $limo_msg_id_group = $responseLimo['MessageId'] ?? null; 
                    if (is_array($limo_msg_id_group)) $limo_msg_id_group = implode(',', $limo_msg_id_group);

                    $_SESSION['manual_sms_message'] = ['type' => 'success', 'text' => "پیامک برای {$sent_to_count} شماره به صف ارسال اضافه شد." . ($limo_msg_id_group ? " (شناسه گروهی: ".htmlspecialchars($limo_msg_id_group).")" : "")];
                    
                    // لاگ کردن هر پیامک در sms_log
                    $stmtLogSms = $db->prepare("INSERT INTO sms_log (phone_number, message_type, message_content_or_pattern_id, limosms_response_id, status_message, batch_identifier, sent_at) VALUES (:phone, 'manual_normal', :msg_content, :limo_id, :status_msg, :batch_id, NOW())");
                    $status_log = 'Sent (Limo: '.($responseLimo['Message'] ?? 'OK').')';
                    
                    // اگر LimoSms برای هر شماره MessageId جدا برمی‌گرداند، باید آن را مدیریت کرد
                    // فعلاً فرض می‌کنیم یک MessageId کلی برای گروه داریم یا اصلاً نداریم
                    foreach($all_recipients as $recipient_phone){
                        $stmtLogSms->bindValue(':phone', $recipient_phone, PDO::PARAM_STR);
                        $stmtLogSms->bindValue(':msg_content', mb_substr($sms_text, 0, 1000), PDO::PARAM_STR);
                        $stmtLogSms->bindValue(':limo_id', $limo_msg_id_group, $limo_msg_id_group === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        $stmtLogSms->bindValue(':status_msg', $status_log, PDO::PARAM_STR);
                        $stmtLogSms->bindValue(':batch_id', $batch_id_for_log, PDO::PARAM_STR); // شناسه دسته
                        $stmtLogSms->execute();
                    }
                     $_SESSION['last_sms_batch_id'] = $batch_id_for_log; // ذخیره شناسه دسته برای نمایش در جدول

                } else { $_SESSION['manual_sms_message'] = ['type' => 'error', 'text' => 'خطا از لیمو: ' . htmlspecialchars($responseLimo['Message'] ?? 'خطای نامشخص')];}
            } else { $_SESSION['manual_sms_message'] = ['type' => 'error', 'text' => 'خطا در ارتباط با سرویس پیامک.'];}
            if(!headers_sent()){ header("Location: manual_sms_send.php"); exit; }
            else { echo "<script>window.location.href='manual_sms_send.php';</script>"; exit; }
        }
    }

    // خواندن لاگ پیامک‌های دستی اخیر برای نمایش اولیه
    $last_batch_id = $_SESSION['last_sms_batch_id'] ?? null;
    if ($last_batch_id) {
        $stmtLog = $db->prepare("SELECT id, phone_number, message_content_or_pattern_id, limosms_response_id, status_message, sent_at, is_delivered FROM sms_log WHERE batch_identifier = ? ORDER BY sent_at DESC LIMIT 50");
        $stmtLog->execute([$last_batch_id]);
        $initial_sms_log_for_display = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
    } elseif (isset($_SESSION['user_management_message'])) { // اگر پیام از عملیات دیگری است، لاگ خالی باشد
         $initial_sms_log_for_display = [];
    }
     else { // اگر هیچ batch_id در سشن نیست، چندتای آخر را نشان بده
        $stmtLog = $db->query("SELECT id, phone_number, message_content_or_pattern_id, limosms_response_id, status_message, sent_at, is_delivered FROM sms_log WHERE message_type = 'manual_normal' ORDER BY id DESC LIMIT 10");
        if($stmtLog) $initial_sms_log_for_display = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
    }


    // خواندن لیست مشترکین فعال برای نمایش در select
    $active_users_for_select = [];
    $stmtSelectUsers = $db->query("SELECT phone, name FROM users WHERE is_active_account = 1 AND is_sms_subscriber = 1 ORDER BY name ASC");
    if ($stmtSelectUsers) { $active_users_for_select = $stmtSelectUsers->fetchAll(PDO::FETCH_ASSOC); }

} catch (Exception $e) { $errorMessageForPage = "خطا: " . htmlspecialchars($e->getMessage()); error_log("Manual SMS Send Page Error: " . $e->getMessage()); }

?>

<div class="admin-page-content">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>

    <?php if (isset($message) && is_array($message)): ?>
        <div class="message <?php echo htmlspecialchars($message['type']); ?>">
            <?php echo htmlspecialchars($message['text']); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMessageForPage)): ?>
         <div class="message error"><?php echo $errorMessageForPage; ?></div>
    <?php endif; ?>

    <?php if (!$limo_sms_helper_loaded_manual || empty($limosms_api_key) || empty($limosms_sender_number)): ?>
        <p class="message error">
            سرویس پیامک به درستی پیکربندی نشده است.
            <?php if (empty($limosms_api_key) || empty($limosms_sender_number)): ?>
                لطفاً ابتدا از بخش <a href="<?php echo site_url('admin/sms_settings.php'); ?>">تنظیمات پیامک</a>، کلید API و شماره فرستنده را وارد کنید.
            <?php endif; ?>
            <?php if (!$limo_sms_helper_loaded_manual): ?>
                <br>فایل LimoSmsHelper.php یا توابع آن به درستی بارگذاری نشده‌اند.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="manual-sms-container">
            <div class="sms-form-section">
                <h3>ارسال پیامک دستی</h3>
                <p>اعتبار فعلی LimoSMS: <strong class="current-credit-display"><?php echo $current_credit_display; ?></strong></p>
                <form action="manual_sms_send.php" method="post" id="manualSmsSendForm">
                    <div class="form-group">
                        <label for="phone_numbers_input">شماره(های) گیرنده (با کاما، فاصله یا خط جدید جدا کنید):</label>
                        <textarea id="phone_numbers_input" name="phone_numbers" rows="3" placeholder="09123456789, 0935..." style="direction:ltr; text-align:left;"><?php echo htmlspecialchars($form_data_sticky['phone_numbers'] ?? ''); ?></textarea>
                    </div>
                    <?php if (!empty($active_users_for_select)): ?>
                    <div class="form-group">
                        <label for="selected_subscribers_select">یا انتخاب از لیست مشترکین فعال (برای انتخاب چندتایی، Ctrl+Click یا Shift+Click):</label>
                        <select name="selected_subscribers[]" id="selected_subscribers_select" multiple size="5" style="font-family:Tahoma, sans-serif; min-height:100px;">
                            <?php foreach ($active_users_for_select as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['phone']); ?>"
                                    <?php if(isset($form_data_sticky['selected_subscribers']) && is_array($form_data_sticky['selected_subscribers']) && in_array($user['phone'], $form_data_sticky['selected_subscribers'])) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($user['name']) . ' (' . htmlspecialchars($user['phone']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="sms_text_input">متن پیامک:</label>
                        <textarea id="sms_text_input" name="sms_text" rows="5" required placeholder="متن پیامک خود را اینجا بنویسید..."><?php echo htmlspecialchars($form_data_sticky['sms_text'] ?? ''); ?></textarea>
                        <small>توجه: این فرم فقط برای ارسال پیامک عادی (غیر پترن) است. حداکثر طول استاندارد پیامک فارسی حدود ۷۰ کاراکتر است.</small>
                    </div>
                    <button type="submit" name="send_manual_sms" class="btn-submit">ارسال پیامک</button>
                </form>
            </div>

            <div class="sms-log-section" id="manualSmsLogSection">
                <h3>گزارش ارسال‌های اخیر (این بخش خودکار به‌روز می‌شود)</h3>
                <div class="table-responsive-wrapper">
                    <table class="data-table" id="smsLogTable">
                        <thead>
                            <tr>
                                <th>ID لاگ</th>
                                <th>شماره گیرنده</th>
                                <th style="max-width: 200px;">بخشی از متن</th>
                                <th>شناسه پیامک (Limo)</th>
                                <th>وضعیت اولیه</th>
                                <th>وضعیت نهایی (Live)</th>
                                <th>زمان ارسال</th>
                            </tr>
                        </thead>
                        <tbody id="smsLogTableBody">
                            <?php if (empty($initial_sms_log_for_display)): ?>
                                <tr><td colspan="7" style="text-align:center;">هنوز هیچ پیامک دستی ارسالی برای نمایش وجود ندارد.</td></tr>
                            <?php else: ?>
                                <?php foreach ($initial_sms_log_for_display as $log): ?>
                                <tr data-log-id="<?php echo $log['id']; ?>" data-limo-id="<?php echo htmlspecialchars($log['limosms_response_id'] ?? ''); ?>">
                                    <td><?php echo $log['id']; ?></td>
                                    <td><?php echo htmlspecialchars($log['phone_number']); ?></td>
                                    <td style="max-width: 200px; overflow-wrap: break-word; font-size:0.85em;"><?php echo htmlspecialchars(mb_substr($log['message_content_or_pattern_id'], 0, 70) . '...'); ?></td>
                                    <td><?php echo htmlspecialchars($log['limosms_response_id'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($log['status_message']); ?></td>
                                    <td class="live-status" id="status-<?php echo $log['id']; ?>">
                                        <?php echo ($log['is_delivered'] === 1) ? '<span class="status-active">رسیده به گوشی</span>' : (($log['is_delivered'] === 0 && !empty($log['limosms_response_id'])) ? 'نرسیده/نامشخص' : 'در حال بررسی...'); ?>
                                    </td>
                                    <td><?php echo ($jdf_loaded_for_admin_layout && function_exists('jdate')) ? jdate('Y/m/d H:i:s', strtotime($log['sent_at'])) : $log['sent_at']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <small style="display:block; text-align:center; margin-top:10px;">وضعیت نهایی پیامک‌ها هر 15 ثانیه به‌روز می‌شود (تا زمانی که به وضعیت قطعی برسند).</small>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .manual-sms-container { display: flex; flex-wrap: wrap; gap: 20px; }
    .sms-form-section { flex: 1; min-width: 350px; max-width:500px; }
    .sms-log-section { flex: 2; min-width: 400px; }
    .current-credit-display {font-weight:bold; color: #0056b3; padding: 8px; background-color: #f0f8ff; border: 1px solid #cce5ff; border-radius: 4px; display: inline-block; margin: 5px 0 15px 0;}
    .live-status .spinner-mini { display: inline-block; width: 1em; height: 1em; border: 2px solid rgba(0,0,0,0.1); border-left-color: #28a745; border-radius: 50%; animation: spin 0.8s linear infinite; margin-left:5px; vertical-align: middle;}
    @keyframes spin { to { transform: rotate(360deg); } }
    /* سایر استایل‌ها از admin_style.css */
</style>

<?php
$page_specific_js_footer_content = '';
// فقط اگر سرویس پیامک آماده است، جاوااسکریپت مربوط به رفرش لاگ را اضافه کن
if ($limo_sms_helper_loaded_manual && !empty($limosms_api_key)) {
    $ajax_get_sms_status_url_js = site_url("admin/ajax_get_sms_batch_status.php"); // یک فایل AJAX جدید

$page_specific_js_footer_content = <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    const smsLogTableBody = document.getElementById('smsLogTableBody');
    const ajaxStatusUrl = '{$ajax_get_sms_status_url_js}';
    let intervalId = null;
    let rowsToUpdate = [];

    function updateSmsStatuses() {
        rowsToUpdate = []; // ردیف‌هایی که هنوز وضعیت نهایی ندارند
        const trs = smsLogTableBody.querySelectorAll('tr[data-log-id]');
        let limoIdsToCheck = [];

        trs.forEach(tr => {
            const statusCell = tr.querySelector('.live-status');
            const limoId = tr.getAttribute('data-limo-id');
            // اگر هنوز در حال بررسی است و شناسه لیمو دارد
            if (statusCell && statusCell.textContent.includes('در حال بررسی') && limoId && limoId !== '-') {
                rowsToUpdate.push(tr);
                if (!limoIdsToCheck.includes(limoId)) { // فقط شناسه‌های یکتا
                     // اگر شناسه شامل کاما است (گروهی)، فقط بخش اول را برای بررسی تکی بفرستیم (یا API باید گروهی را پشتیبانی کند)
                     // فرض می‌کنیم getstatus می‌تواند آرایه‌ای از messageId ها را بپذیرد
                     const idsInGroup = limoId.split(',');
                     idsInGroup.forEach(id => {
                         if(id.trim() && !limoIdsToCheck.includes(id.trim())) limoIdsToCheck.push(id.trim());
                     });
                }
            }
        });

        if (limoIdsToCheck.length === 0) {
            if (intervalId) clearInterval(intervalId); // اگر همه نهایی شدند، متوقف کن
            // console.log("All SMS statuses are final. Stopping updates.");
            return;
        }
        if (!document.hidden) { // فقط اگر تب فعال است درخواست بفرست
            // console.log("Checking status for Limo IDs:", limoIdsToCheck);
            fetch(ajaxStatusUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'message_ids=' + JSON.stringify(limoIdsToCheck) // ارسال آرایه JSON شده
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.statuses) {
                    // console.log("Statuses received:", data.statuses);
                    rowsToUpdate.forEach(tr => {
                        const logId = tr.getAttribute('data-log-id');
                        const limoId = tr.getAttribute('data-limo-id'); // شناسه اصلی یا گروهی
                        const statusCell = tr.querySelector('#status-' + logId);
                        let finalStatusFound = false;

                        // اگر شناسه گروهی بود، وضعیت اولین شناسه در گروه را در نظر می‌گیریم (یا باید منطق بهتری پیاده شود)
                        const firstLimoIdInGroup = limoId ? limoId.split(',')[0].trim() : null;

                        if (statusCell && firstLimoIdInGroup && data.statuses[firstLimoIdInGroup]) {
                            const statusInfo = data.statuses[firstLimoIdInGroup];
                            statusCell.innerHTML = htmlspecialchars(statusInfo.StatusText); // StatusText از API شما
                            if (statusInfo.IsDelivered === 1) { // یا هر شرطی که نشان‌دهنده وضعیت نهایی "رسیده" باشد
                                statusCell.innerHTML = '<span class="status-active">رسیده به گوشی</span>';
                                finalStatusFound = true;
                            } else if (statusInfo.IsDelivered === 0 && statusInfo.Status !== 'Pending' && statusInfo.Status !== 'SentToNetwork' && statusInfo.Status !== 'Sent') { // وضعیت نهایی "نرسیده"
                                statusCell.innerHTML = '<span class="status-inactive">' + htmlspecialchars(statusInfo.StatusText) + '</span>';
                                finalStatusFound = true;
                            } else {
                                statusCell.innerHTML = htmlspecialchars(statusInfo.StatusText) + ' <span class="spinner-mini"></span>';
                            }
                        }
                         // اگر می‌خواهید برای هر شناسه در یک گروه، وضعیت جداگانه نمایش دهید، باید HTML جدول را تغییر دهید
                    });
                } else {
                    console.warn("Failed to parse statuses or no statuses returned:", data.message);
                }
            })
            .catch(error => console.error('Error fetching SMS statuses:', error));
        }
    }

    if (smsLogTableBody && smsLogTableBody.rows.length > 0 && document.getElementById('liveOnlineUsersCount')) { // فقط اگر جدولی برای آپدیت هست
        // بررسی اولیه که آیا ردیفی نیاز به آپدیت دارد
        let needsInitialUpdate = false;
        smsLogTableBody.querySelectorAll('tr[data-log-id]').forEach(tr => {
             const statusCell = tr.querySelector('.live-status');
             if (statusCell && statusCell.textContent.includes('در حال بررسی')) {
                 needsInitialUpdate = true;
             }
        });
        if (needsInitialUpdate) {
            updateSmsStatuses(); // فراخوانی اولیه
            intervalId = setInterval(updateSmsStatuses, 15000); // هر ۱۵ ثانیه
        }
    }
    // تابع کمکی برای جاوااسکریپت
    function htmlspecialchars(str) {
        if (typeof str !== 'string') return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return str.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
</script>
JS;
}

if (file_exists(__DIR__ . '/layouts/_footer.php')) {
    if (!empty($page_specific_js_footer_content)) echo $page_specific_js_footer_content;
    require_once __DIR__ . '/layouts/_footer.php';
}
?>