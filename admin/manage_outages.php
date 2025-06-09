<?php
// /public_html/admin/manage_outages.php

$pageTitle = "لیست و ویرایش وضعیت خاموشی‌ها";

// 1. لود کردن هدر ادمین (شامل config, auth_check, database, jdf و مقداردهی $db)
if (file_exists(__DIR__ . '/layouts/_header.php')) {
    require_once __DIR__ . '/layouts/_header.php';
} else {
    error_log("ADMIN MANAGE OUTAGES FATAL ERROR: Admin header layout file not found.");
    die("خطای سیستمی: فایل لایوت هدر ادمین یافت نشد.");
}
// از اینجا به بعد، $db, $loggedInAdminUsername, $jdf_loaded_for_admin_layout, site_url() باید در دسترس باشند.

$outages = [];
$edit_outage_data = null;
$message = $_SESSION['outage_admin_message'] ?? null;
if ($message) unset($_SESSION['outage_admin_message']);
$errorMessageForPage = null;

try {
    // اطمینان از اینکه $db توسط _header.php به درستی مقداردهی شده است
    if (!isset($db) || !$db instanceof PDO) {
        throw new Exception("اتصال به پایگاه داده برای مدیریت خاموشی‌ها در دسترس نیست. فایل admin/layouts/_header.php را بررسی کنید.");
    }

    // --- شروع بخش پردازش فرم ویرایش (باید قبل از هر خروجی HTML باشد اگر ریدایرکت دارد) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_outage_submit']) && isset($_POST['outage_id'])) {
        $outage_id = (int)$_POST['outage_id'];
        $new_tarikh_shamsi = trim($_POST['tarikh'] ?? '');
        $new_az_saat = trim($_POST['az_saat'] ?? '');
        $new_ta_saat = trim($_POST['ta_saat'] ?? '');
        $new_address = trim($_POST['address_text'] ?? '');
        $new_is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0; // دریافت وضعیت جدید

        $form_error = false;
        if (empty($new_tarikh_shamsi) || empty($new_az_saat) || empty($new_ta_saat) || empty($new_address)) {
            $_SESSION['outage_admin_message'] = ['type' => 'error', 'text' => 'تمام فیلدهای اصلی (تاریخ، ساعات، آدرس) برای ویرایش الزامی هستند.'];
            $form_error = true;
        } else {
            $tarikh_for_db = $new_tarikh_shamsi;
            $parts = explode('/', $new_tarikh_shamsi);
            $isValidShamsiDate = false;
            if (count($parts) === 3) {
                $y = (int)$parts[0]; $m = (int)$parts[1]; $d = (int)$parts[2];
                if ($y > 1300 && $y < 1500 && $m >= 1 && $m <= 12 && $d >= 1 && $d <= 31) {
                    if ($jdf_loaded_for_admin_layout && function_exists('jcheckdate')) {
                        if (jcheckdate($m, $d, $y)) $isValidShamsiDate = true;
                    } else { $isValidShamsiDate = true; }
                }
            }
            if (!$isValidShamsiDate) {
                 $_SESSION['outage_admin_message'] = ['type' => 'error', 'text' => 'فرمت تاریخ شمسی وارد شده (مثال: 1403/05/15) صحیح نیست.'];
                 $form_error = true;
            }
        }

        if (!$form_error) {
            $updateStmt = $db->prepare(
                "UPDATE outage_events_log SET 
                    tarikh = ?, 
                    az_saat = ?, 
                    ta_saat = ?, 
                    address_text = ?, 
                    is_currently_active = ?, 
                    last_scraped_at = NOW() /* یا یک فیلد last_manual_update */
                WHERE id = ?"
            );
            if ($updateStmt->execute([$tarikh_for_db, $new_az_saat, $new_ta_saat, $new_address, $new_is_active, $outage_id])) {
                $_SESSION['outage_admin_message'] = ['type' => 'success', 'text' => 'خاموشی با شناسه ' . $outage_id . ' با موفقیت به‌روز شد.'];
            } else {
                $_SESSION['outage_admin_message'] = ['type' => 'error', 'text' => 'خطا در به‌روزرسانی خاموشی.'];
            }
        }
        // ریدایرکت برای نمایش پیام سشن و جلوگیری از resubmit
        $redirect_url = "manage_outages.php";
        if ($form_error && isset($outage_id)) {
             $redirect_url .= "?edit_id=" . $outage_id; // اگر خطا بود و در حالت ویرایش، به همان فرم برگرد
        }
        if (!headers_sent()) {
            header("Location: " . $redirect_url);
            exit;
        } else {
            echo "<script>window.location.href='" . addslashes($redirect_url) . "';</script>";
            exit;
        }
    }
    // --- پایان بخش پردازش فرم ---


    // اگر درخواست ویرایش یک خاموشی خاص آمده (برای پر کردن فرم در اولین بار)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit_id']) && !isset($_POST['edit_outage_submit'])) {
        $edit_id = (int)$_GET['edit_id'];
        $stmtEdit = $db->prepare("SELECT * FROM outage_events_log WHERE id = ?");
        $stmtEdit->execute([$edit_id]);
        $edit_outage_data = $stmtEdit->fetch(PDO::FETCH_ASSOC);
        if (!$edit_outage_data) {
             $message = ['type' => 'error', 'text' => 'خاموشی مورد نظر برای ویرایش یافت نشد.'];
             // اگر پیام در سشن بود، پاکش کن چون پیام جدید داریم
             if(isset($_SESSION['outage_admin_message'])) unset($_SESSION['outage_admin_message']);
        }
    }

    // خواندن لیست تمام خاموشی‌ها از دیتابیس (فعال و غیرفعال)
    // مرتب‌سازی: ابتدا فعال‌ها، سپس بر اساس تاریخ و ساعت جدیدتر
    $stmtList = $db->query("SELECT * FROM outage_events_log ORDER BY is_currently_active DESC, tarikh DESC, az_saat DESC LIMIT 200");
    $outages = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    // اگر می‌خواهید مرتب‌سازی تاریخ شمسی دقیق‌تری در PHP داشته باشید (پس از خواندن از دیتابیس)
    if (!empty($outages) && $jdf_loaded_for_admin_layout && function_exists('jdate')) {
        usort($outages, function ($a, $b) {
            // اولویت با فعال بودن
            if ($a['is_currently_active'] != $b['is_currently_active']) {
                return $a['is_currently_active'] < $b['is_currently_active'] ? 1 : -1; // فعال‌ها (1) بالاتر از غیرفعال‌ها (0)
            }

            // سپس مرتب‌سازی بر اساس تاریخ و ساعت (جدیدترها اول)
            $val_a_str = '0'; $val_b_str = '0';
            $tarikh_a = $a['tarikh'] ?? '00/00/00'; $az_saat_a = $a['az_saat'] ?? '00:00';
            $parts_a = explode('/', (string)$tarikh_a);
            if (is_array($parts_a) && count($parts_a) === 3) {
                $y_a = (int)$parts_a[0]; $m_a = (int)$parts_a[1]; $d_a = (int)$parts_a[2];
                if ($y_a < 100 && $y_a > 0) $y_a += 1400;
                $time_parts_a = explode(':', (string)$az_saat_a);
                $h_a = (is_array($time_parts_a) && isset($time_parts_a[0])) ? (int)$time_parts_a[0] : 0;
                $min_a = (is_array($time_parts_a) && isset($time_parts_a[1])) ? (int)$time_parts_a[1] : 0;
                $val_a_str = sprintf('%04d%02d%02d%02d%02d', $y_a, $m_a, $d_a, $h_a, $min_a);
            }
            $tarikh_b = $b['tarikh'] ?? '00/00/00'; $az_saat_b = $b['az_saat'] ?? '00:00';
            $parts_b = explode('/', (string)$tarikh_b);
            if (is_array($parts_b) && count($parts_b) === 3) {
                $y_b = (int)$parts_b[0]; $m_b = (int)$parts_b[1]; $d_b = (int)$parts_b[2];
                if ($y_b < 100 && $y_b > 0) $y_b += 1400;
                $time_parts_b = explode(':', (string)$az_saat_b);
                $h_b = (is_array($time_parts_b) && isset($time_parts_b[0])) ? (int)$time_parts_b[0] : 0;
                $min_b = (is_array($time_parts_b) && isset($time_parts_b[1])) ? (int)$time_parts_b[1] : 0;
                $val_b_str = sprintf('%04d%02d%02d%02d%02d', $y_b, $m_b, $d_b, $h_b, $min_b);
            }
            return strcmp($val_b_str, $val_a_str); // DESC (b before a)
        });
    }


} catch (PDOException $e) {
    $errorMessageForPage = "خطای پایگاه داده: " . ( (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : "اشکال در ارتباط با دیتابیس." );
    error_log("Admin Manage Outages PDOException: " . $e->getMessage() . "\n" . $e->getTraceAsString());
} catch (Exception $e) {
    $errorMessageForPage = "خطای عمومی: " . ( (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : "خطای داخلی سرور." );
    error_log("Admin Manage Outages Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// اطمینان از اینکه $pageTitle برای هدر مقداردهی شده
if (!isset($pageTitle)) $pageTitle = "مدیریت خاموشی‌ها";

// محتوای اصلی صفحه بعد از پردازش فرم و خواندن داده‌ها
?>

<div class="admin-page-content">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>

    <?php if (isset($message) && is_array($message)): ?>
        <div class="message <?php echo htmlspecialchars($message['type']); ?>">
            <?php echo htmlspecialchars($message['text']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($errorMessageForPage)): ?>
         <div class="message error"><?php echo htmlspecialchars($errorMessageForPage); ?></div>
    <?php endif; ?>

    <?php if ($edit_outage_data): ?>
        <div class="edit-outage-form" style="background-color: #fdfdfd; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #e0e6ed; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3>ویرایش خاموشی (ID: <?php echo htmlspecialchars($edit_outage_data['id']); ?>)</h3>
            <p><small>امضای رکورد (غیرقابل تغییر توسط این فرم): <?php echo htmlspecialchars($edit_outage_data['outage_signature']); ?></small></p>
            <form action="manage_outages.php" method="post">
                <input type="hidden" name="outage_id" value="<?php echo htmlspecialchars($edit_outage_data['id']); ?>">
                
                <div class="form-group">
                    <label for="tarikh_edit">تاریخ (فرمت شمسی <?php echo $jdf_loaded_for_admin_layout ? 'YYYY/MM/DD' : 'مانند دیتابیس';?>):</label>
                    <input type="text" id="tarikh_edit" name="tarikh" class="<?php echo $jdf_loaded_for_admin_layout ? 'shamsi-datepicker-input' : ''; ?>" 
                           value="<?php echo htmlspecialchars($edit_outage_data['tarikh']); ?>" required 
                           style="direction:ltr; text-align:right;" autocomplete="off" placeholder="مثال: 1403/05/15">
                </div>
                <div style="display:flex; gap:15px; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1; min-width:150px;">
                        <label for="az_saat_edit">از ساعت (HH:MM):</label>
                        <input type="text" id="az_saat_edit" name="az_saat" value="<?php echo htmlspecialchars($edit_outage_data['az_saat']); ?>" required pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]" title="فرمت HH:MM" style="direction:ltr; text-align:center;">
                    </div>
                    <div class="form-group" style="flex:1; min-width:150px;">
                        <label for="ta_saat_edit">تا ساعت (HH:MM):</label>
                        <input type="text" id="ta_saat_edit" name="ta_saat" value="<?php echo htmlspecialchars($edit_outage_data['ta_saat']); ?>" required pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]" title="فرمت HH:MM" style="direction:ltr; text-align:center;">
                    </div>
                </div>
                <div class="form-group">
                    <label for="address_text_edit">آدرس / منطقه:</label>
                    <textarea id="address_text_edit" name="address_text" rows="3" required><?php echo htmlspecialchars($edit_outage_data['address_text']); ?></textarea>
                </div>
                 <div class="form-group">
                    <label for="is_active_edit">وضعیت:</label>
                    <select name="is_active" id="is_active_edit">
                        <option value="1" <?php if (isset($edit_outage_data['is_currently_active']) && $edit_outage_data['is_currently_active'] == 1) echo 'selected'; ?>>فعال</option>
                        <option value="0" <?php if (isset($edit_outage_data['is_currently_active']) && $edit_outage_data['is_currently_active'] == 0) echo 'selected'; ?>>غیرفعال</option>
                    </select>
                </div>
                <button type="submit" name="edit_outage_submit" class="btn-submit" style="background-color:#007bff;">ذخیره تغییرات</button>
                <a href="manage_outages.php" style="margin-right:10px; text-decoration:none; color: #337ab7; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; display:inline-block;">انصراف</a>
            </form>
        </div>
    <?php endif; ?>


    <h3>لیست خاموشی‌ها (فعال و غیرفعال - <?php echo count($outages); ?> مورد)</h3>
    <?php if (!empty($outages)): ?>
        <div class="table-responsive-wrapper" style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>تاریخ</th>
                        <th>از</th>
                        <th>تا</th>
                        <th style="min-width:300px;">آدرس / منطقه</th>
                        <th>وضعیت</th>
                        <th>آخرین مشاهده/ثبت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($outages as $outage): ?>
                        <?php
                            $row_class = '';
                            if (!isset($outage['is_currently_active']) || $outage['is_currently_active'] == 0) {
                                $row_class = 'inactive-row';
                            }
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><?php echo htmlspecialchars($outage['id']); ?></td>
                            <td><?php
                                $display_tarikh = $outage['tarikh'];
                                if ($jdf_loaded_for_admin_layout && function_exists('jdate') && !empty($outage['tarikh']) && strpos((string)$outage['tarikh'],'/')!==false) {
                                    $parts = explode('/', (string)$outage['tarikh']);
                                    if (is_array($parts) && count($parts) === 3) {
                                        $y = (int)$parts[0]; $m = (int)$parts[1]; $d = (int)$parts[2];
                                        if ($y < 100 && $y > 0) $y += 1400;
                                        if (function_exists('jcheckdate') && jcheckdate($m, $d, $y)) {
                                            $g_parts = jalali_to_gregorian($y, $m, $d);
                                            if ($g_parts && is_array($g_parts) && count($g_parts) === 3) {
                                               $timestamp = mktime(0,0,0, (int)$g_parts[1], (int)$g_parts[2], (int)$g_parts[0]);
                                               $display_tarikh = jdate("Y/m/d", $timestamp);
                                            }
                                        }
                                    }
                                }
                                echo htmlspecialchars($display_tarikh);
                            ?></td>
                            <td><?php echo htmlspecialchars($outage['az_saat']); ?></td>
                            <td><?php echo htmlspecialchars($outage['ta_saat']); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($outage['address_text'])); ?></td>
                            <td>
                                <?php if (isset($outage['is_currently_active']) && $outage['is_currently_active']): ?>
                                    <span style="color:green; font-weight:bold;">فعال</span>
                                <?php else: ?>
                                    <span style="color:red;">غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td><?php
                                $lastScrapedDisplay = $outage['last_scraped_at'];
                                if ($jdf_loaded_for_admin_layout && function_exists('jdate')) {
                                    $lastScrapedDisplay = jdate('Y/m/d H:i', strtotime($outage['last_scraped_at']));
                                }
                                echo htmlspecialchars($lastScrapedDisplay);
                            ?></td>
                            <td class="actions">
                                <a href="manage_outages.php?edit_id=<?php echo $outage['id']; ?>" class="btn-edit">ویرایش</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif (!$errorMessageForPage): ?>
        <p>هیچ خاموشی در پایگاه داده یافت نشد.</p>
    <?php endif; ?>
</div>

<?php
// اسکریپت Date Picker را فقط اگر فرم ویرایش نمایش داده می‌شود، اضافه کن
$page_specific_js_footer_content = '';
if ($edit_outage_data && $jdf_loaded_for_admin_layout ) {
    // اطمینان از اینکه kamaDatepicker یا معادل آن در _footer.php یا جای دیگری لود شده
    // یا لینک آن را اینجا هم قرار دهید.
    // برای این مثال، فرض می‌کنیم کتابخانه Datepicker قبلاً لود شده است.
    $page_specific_js_footer_content = <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    // اطمینان از اجرای یکباره
    if (typeof window.adminOutageDatePickerInitialized === 'undefined') {
        window.adminOutageDatePickerInitialized = true;
        
        // باید تابع kamaDatepicker یا معادل آن در دسترس باشد
        if (typeof kamaDatepicker === 'function') { 
            if (document.getElementById('tarikh_edit')) {
                kamaDatepicker('tarikh_edit', {
                    placeholder: 'YYYY/MM/DD', twodigit: false, closeAfterSelect: true,
                    nextButtonIcon: " ", previousButtonIcon: " ", // می‌توانید از آیکون فونت استفاده کنید
                    buttonsColor: "green", forceFarsiDigits: true,
                    markToday: true, gotoToday: true
                });
            }
        } else {
            console.warn("KamaDatepicker (یا کتابخانه Datepicker شمسی دیگر) برای tarikh_edit بارگذاری نشده است.");
            // Fallback ساده اگر کتابخانه Datepicker موجود نیست
            const tarikhInput = document.getElementById('tarikh_edit');
            if (tarikhInput && tarikhInput.type !== 'date') { // جلوگیری از تغییر اگر مرورگر خودش datepicker خوبی دارد
                // tarikhInput.placeholder = 'YYYY/MM/DD'; // راهنمای فرمت
            }
        }
    }
});
</script>
JS;
}

// لود کردن فوتر ادمین
if (file_exists(__DIR__ . '/layouts/_footer.php')) {
    // پاس دادن جاوااسکریپت مخصوص صفحه به فوتر اگر لایوت شما این قابلیت را دارد
    // یا مستقیماً اینجا echo کنید.
    if (!empty($page_specific_js_footer_content)) {
        // اگر _footer.php شما یک متغیر $page_specific_js_inline را می‌پذیرد:
        // $footerData = ['page_specific_js_inline' => $page_specific_js_footer_content];
        // require_once __DIR__ . '/layouts/_footer.php'; // با پاس دادن $footerData
        // در غیر این صورت:
        echo $page_specific_js_footer_content;
    }
    require_once __DIR__ . '/layouts/_footer.php';
}
?>