<?php
// /public_html/admin/manage_areas.php
$pageTitle = "مدیریت مناطق و شهرها برای کرون";
require_once __DIR__ . '/layouts/_header.php';

$message = '';
$message_type = ''; // 'success' or 'error'

// بررسی اولیه اتصال به دیتابیس
if (!isset($db) || !$db instanceof PDO) {
    $message = "خطای بحرانی: اتصال به دیتابیس برقرار نیست. عملیات امکان‌پذیر نیست.";
    $message_type = 'error';
    // برای جلوگیری از خطاهای بعدی، اگر دیتابیس متصل نیست، متغیر $db را null می‌کنیم
    $db = null;
}

// --- تعیین عملیات فعلی (نمایش لیست، فرم افزودن، فرم ویرایش) ---
$current_action = $_GET['action'] ?? 'view_list'; // view_list, add_form, edit_form
$edit_area_code = $_GET['area_code'] ?? null;
$area_to_edit_details = null; // برای نگهداری اطلاعات رکوردی که قرار است ویرایش شود

// --- مدیریت درخواست‌های POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) { // فقط در صورت وجود اتصال به دیتابیس پردازش شود
    try {
        // --- افزودن منطقه جدید ---
        if (isset($_POST['add_area'])) {
            $area_code = trim($_POST['area_code']);
            $area_name = trim($_POST['area_name']);
            $city_code = trim($_POST['city_code']);
            $city_name = trim($_POST['city_name']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_order = intval($_POST['display_order']);

            // اعتبارسنجی اولیه
            if (empty($area_code) || empty($area_name) || empty($city_name)) {
                $message = "کد منطقه، نام منطقه و نام شهر نمی‌توانند خالی باشند.";
                $message_type = 'error';
            } else {
                // بررسی منحصر به فرد بودن area_code
                $stmt_check = $db->prepare("SELECT COUNT(*) FROM areas WHERE area_code = ?");
                $stmt_check->execute([$area_code]);
                if ($stmt_check->fetchColumn() > 0) {
                    $message = "کد منطقه '" . htmlspecialchars($area_code) . "' از قبل موجود است. لطفاً یک کد دیگر انتخاب کنید.";
                    $message_type = 'error';
                } else {
                    $sql_insert = "INSERT INTO areas (area_code, area_name, city_code, city_name, is_active, display_order) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt_insert = $db->prepare($sql_insert);
                    if ($stmt_insert->execute([$area_code, $area_name, $city_code, $city_name, $is_active, $display_order])) {
                        $message = "منطقه جدید با کد '" . htmlspecialchars($area_code) . "' با موفقیت افزوده شد.";
                        $message_type = 'success';
                    } else {
                        $message = "خطا در افزودن منطقه جدید.";
                        $message_type = 'error';
                        error_log("Admin Manage Areas: Failed to add area. DB Error: " . print_r($stmt_insert->errorInfo(), true));
                    }
                }
            }
        }
        // --- به‌روزرسانی منطقه موجود ---
        elseif (isset($_POST['update_area']) && isset($_POST['original_area_code'])) {
            $original_area_code = trim($_POST['original_area_code']);
            // $area_code = trim($_POST['area_code']); // معمولا کلید اصلی ویرایش نمی‌شود. اگر نیاز است، باید منطق آن اضافه شود.
            $area_name = trim($_POST['area_name']);
            $city_code = trim($_POST['city_code']);
            $city_name = trim($_POST['city_name']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_order = intval($_POST['display_order']);

            if (empty($original_area_code) || empty($area_name) || empty($city_name)) {
                $message = "کد منطقه اصلی، نام منطقه و نام شهر نمی‌توانند خالی باشند.";
                $message_type = 'error';
            } else {
                $sql_update = "UPDATE areas SET area_name = ?, city_code = ?, city_name = ?, is_active = ?, display_order = ? WHERE area_code = ?";
                $stmt_update = $db->prepare($sql_update);
                if ($stmt_update->execute([$area_name, $city_code, $city_name, $is_active, $display_order, $original_area_code])) {
                    $message = "منطقه با کد '" . htmlspecialchars($original_area_code) . "' با موفقیت به‌روز شد.";
                    $message_type = 'success';
                    $current_action = 'view_list'; // بازگشت به لیست پس از ویرایش موفق
                } else {
                    $message = "خطا در به‌روزرسانی منطقه.";
                    $message_type = 'error';
                    error_log("Admin Manage Areas: Failed to update area. DB Error: " . print_r($stmt_update->errorInfo(), true));
                }
            }
        }
        // --- حذف منطقه ---
        elseif (isset($_POST['delete_area']) && isset($_POST['area_code'])) {
            $area_code_to_delete = trim($_POST['area_code']);
            $stmt_delete = $db->prepare("DELETE FROM areas WHERE area_code = ?");
            if ($stmt_delete->execute([$area_code_to_delete])) {
                $message = "منطقه با کد '" . htmlspecialchars($area_code_to_delete) . "' با موفقیت حذف شد.";
                $message_type = 'success';
            } else {
                $message = "خطا در حذف منطقه.";
                $message_type = 'error';
                error_log("Admin Manage Areas: Failed to delete area. DB Error: " . print_r($stmt_delete->errorInfo(), true));
            }
        }
        // --- تغییر وضعیت is_active (با منطق اصلاح شده) ---
        elseif (isset($_POST['toggle_active']) && isset($_POST['area_code'])) {
            $area_code_to_toggle = trim($_POST['area_code']);
            error_log("Admin Manage Areas: Attempting to toggle status for area_code: {$area_code_to_toggle}");

            $stmt_current = $db->prepare("SELECT is_active FROM areas WHERE area_code = ?");
            $stmt_current->execute([$area_code_to_toggle]);
            $current_area_data = $stmt_current->fetch(PDO::FETCH_ASSOC);

            if ($current_area_data) {
                $current_status_from_db = $current_area_data['is_active'];
                error_log("Admin Manage Areas: Fetched current is_active from DB for '{$area_code_to_toggle}': '{$current_status_from_db}' (type: " . gettype($current_status_from_db) . ")");

                $current_status_int = intval($current_status_from_db); // تبدیل صریح به عدد صحیح
                $new_status_int = ($current_status_int === 1) ? 0 : 1; // تغییر وضعیت
                
                error_log("Admin Manage Areas: Interpreted current status as int: {$current_status_int}. Calculated new_status as int: {$new_status_int} for '{$area_code_to_toggle}'.");

                $stmt_update_toggle = $db->prepare("UPDATE areas SET is_active = ? WHERE area_code = ?");
                if ($stmt_update_toggle->execute([$new_status_int, $area_code_to_toggle])) {
                    $message = "وضعیت منطقه '" . htmlspecialchars($area_code_to_toggle) . "' با موفقیت به " . ($new_status_int ? 'فعال' : 'غیرفعال') . " تغییر یافت.";
                    $message_type = 'success';
                    error_log("Admin Manage Areas: Successfully updated is_active to {$new_status_int} for area_code: {$area_code_to_toggle}");
                } else {
                    $error_info = $stmt_update_toggle->errorInfo();
                    $message = "خطا در به‌روزرسانی وضعیت منطقه. لطفاً لاگ سرور را بررسی کنید.";
                    $message_type = 'error';
                    error_log("Admin Manage Areas: Failed to update is_active for area_code: {$area_code_to_toggle}. DB Error: " . ($error_info[2] ?? 'Unknown error'));
                }
            } else {
                $message = "منطقه مورد نظر ('" . htmlspecialchars($area_code_to_toggle) . "') برای تغییر وضعیت یافت نشد.";
                $message_type = 'error';
                error_log("Admin Manage Areas: Area_code '{$area_code_to_toggle}' not found for toggling status.");
            }
        }
        // --- به‌روزرسانی ترتیب نمایش ---
        elseif (isset($_POST['save_display_order']) && isset($_POST['display_orders']) && is_array($_POST['display_orders'])) {
            // (منطق این بخش مانند قبل، در صورت نیاز بازبینی شود)
            $all_orders_updated = true;
            $db->beginTransaction();
            try {
                foreach ($_POST['display_orders'] as $area_code_order => $order_value) {
                    if (!is_numeric($order_value) || intval($order_value) < 0) {
                        $message = "مقدار ترتیب نمایش برای منطقه '" . htmlspecialchars($area_code_order) . "' نامعتبر است.";
                        $message_type = 'error'; $all_orders_updated = false; break;
                    }
                    $stmt_order = $db->prepare("UPDATE areas SET display_order = ? WHERE area_code = ?");
                    if (!$stmt_order->execute([intval($order_value), $area_code_order])) {
                        error_log("Admin Manage Areas: Failed to update display_order for area_code: " . $area_code_order . " - " . print_r($stmt_order->errorInfo(), true));
                        $all_orders_updated = false;
                    }
                }
                if ($all_orders_updated) {
                    $db->commit(); $message = "ترتیب نمایش با موفقیت ذخیره شد."; $message_type = 'success';
                } else {
                    $db->rollBack(); if (empty($message)) $message = "خطا در ذخیره ترتیب نمایش."; if (empty($message_type)) $message_type = 'error';
                }
            } catch (Exception $e) {
                $db->rollBack(); $message = "خطای سیستمی: " . htmlspecialchars($e->getMessage()); $message_type = 'error';
                error_log("Admin Manage Areas: Exception during display_order save: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        $message = "خطای PDO در عملیات دیتابیس: " . htmlspecialchars($e->getMessage());
        $message_type = 'error';
        error_log("Admin Manage Areas: General PDOException in POST handling: " . $e->getMessage());
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
    } catch (Exception $e) {
        $message = "خطای عمومی در پردازش درخواست: " . htmlspecialchars($e->getMessage());
        $message_type = 'error';
        error_log("Admin Manage Areas: General Exception in POST handling: " . $e->getMessage());
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
    }
}


// --- آماده‌سازی اطلاعات برای فرم ویرایش اگر در این حالت هستیم ---
if ($current_action === 'edit_form' && $edit_area_code && $db) {
    $stmt_edit = $db->prepare("SELECT * FROM areas WHERE area_code = ?");
    $stmt_edit->execute([$edit_area_code]);
    $area_to_edit_details = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if (!$area_to_edit_details) {
        $message = "منطقه با کد '" . htmlspecialchars($edit_area_code) . "' برای ویرایش یافت نشد.";
        $message_type = 'error';
        $current_action = 'view_list'; // بازگشت به لیست
    }
}

// --- خواندن لیست مناطق از دیتابیس (همیشه بعد از عملیات POST خوانده می‌شود تا تغییرات نمایش داده شوند) ---
$areas_list = [];
if ($db) { // فقط اگر اتصال به دیتابیس برقرار است
    try {
        $stmt_list = $db->query("SELECT area_code, area_name, city_code, city_name, is_active, display_order FROM areas ORDER BY display_order ASC, area_name ASC");
        if ($stmt_list) {
            $areas_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
        } else {
            if (empty($message)) { // اگر پیام دیگری از قبل تنظیم نشده
                $message = "خطا در خواندن لیست مناطق."; $message_type = 'error';
            }
            error_log("Admin Manage Areas: Failed to fetch areas list (query failed): " . ($db->errorInfo()[2] ?? 'Unknown DB error'));
        }
    } catch (PDOException $e) {
        if (empty($message)) {
            $message = "خطای PDO در خواندن لیست مناطق: " . htmlspecialchars($e->getMessage()); $message_type = 'error';
        }
        error_log("Admin Manage Areas: PDOException fetching areas list: " . $e->getMessage());
    }
}
?>

<div class="admin-page-title">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>
</div>

<?php if (!empty($message)): ?>
    <div class="message <?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>


<?php // --- نمایش فرم افزودن یا ویرایش --- ?>
<?php if ($current_action === 'add_form' || ($current_action === 'edit_form' && $area_to_edit_details)): ?>
    <div class="form-container card">
        <h3><?php echo ($current_action === 'edit_form') ? 'ویرایش منطقه: ' . htmlspecialchars($area_to_edit_details['area_name']) : 'افزودن منطقه جدید'; ?></h3>
        <form action="manage_areas.php<?php echo ($current_action === 'edit_form') ? '?action=edit_form&area_code=' . htmlspecialchars($edit_area_code) : ''; ?>" method="POST">
            <?php if ($current_action === 'edit_form'): ?>
                <input type="hidden" name="original_area_code" value="<?php echo htmlspecialchars($area_to_edit_details['area_code']); ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="area_code">کد منطقه (لاتین، منحصر به فرد):</label>
                <input type="text" id="area_code" name="area_code" class="form-control" 
                       value="<?php echo htmlspecialchars($area_to_edit_details['area_code'] ?? ''); ?>" 
                       <?php echo ($current_action === 'edit_form') ? 'readonly' : 'required'; // کد منطقه در زمان ویرایش غیرقابل تغییر است ?> >
                 <?php if ($current_action === 'edit_form'): ?>
                    <small class="form-text text-muted">کد منطقه پس از ایجاد قابل تغییر نیست.</small>
                 <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="area_name">نام منطقه:</label>
                <input type="text" id="area_name" name="area_name" class="form-control" value="<?php echo htmlspecialchars($area_to_edit_details['area_name'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="city_code">کد شهر:</label>
                <input type="text" id="city_code" name="city_code" class="form-control" value="<?php echo htmlspecialchars($area_to_edit_details['city_code'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="city_name">نام شهر:</label>
                <input type="text" id="city_name" name="city_name" class="form-control" value="<?php echo htmlspecialchars($area_to_edit_details['city_name'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="display_order">ترتیب نمایش:</label>
                <input type="number" id="display_order" name="display_order" class="form-control" value="<?php echo htmlspecialchars($area_to_edit_details['display_order'] ?? '0'); ?>" min="0" required>
            </div>
            <div class="form-group form-check">
                <input type="checkbox" id="is_active" name="is_active" class="form-check-input" value="1" <?php echo (isset($area_to_edit_details['is_active']) && $area_to_edit_details['is_active'] == 1) ? 'checked' : (($current_action === 'add_form') ? 'checked' : ''); // پیش‌فرض فعال برای افزودن ?>>
                <label for="is_active" class="form-check-label">فعال برای کرون؟</label>
            </div>

            <div class="form-actions">
                <?php if ($current_action === 'edit_form'): ?>
                    <button type="submit" name="update_area" class="button primary-button">ذخیره تغییرات</button>
                    <a href="manage_areas.php" class="button secondary-button">انصراف</a>
                <?php else: ?>
                    <button type="submit" name="add_area" class="button primary-button">افزودن منطقه</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
<?php endif; ?>


<?php // --- نمایش لیست مناطق --- ?>
<?php if ($current_action === 'view_list'): ?>
    <div class="table-actions">
        <a href="manage_areas.php?action=add_form" class="button success-button"><i class="fas fa-plus"></i> افزودن منطقه جدید</a>
        <?php if (!empty($areas_list)): // دکمه ذخیره ترتیب فقط اگر لیستی هست ?>
        <button type="submit" name="save_display_order" class="button primary-button" form="listAreasForm">ذخیره ترتیب نمایش</button>
        <?php endif; ?>
    </div>

    <form action="manage_areas.php" method="POST" id="listAreasForm">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>نام منطقه</th>
                    <th>نام شهر</th>
                    <th>کد شهر</th>
                    <th>وضعیت کرون</th>
                    <th>عملیات</th>
                    <th>ترتیب نمایش</th>
                    <th>کد منطقه</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($areas_list)): ?>
                    <?php foreach ($areas_list as $area_item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($area_item['area_name']); ?></td>
                            <td><?php echo htmlspecialchars($area_item['city_name']); ?></td>
                            <td><?php echo htmlspecialchars($area_item['city_code']); ?></td>
                            <td>
                                <?php if ($area_item['is_active']): ?>
                                    <span class="status-active"><i class="fas fa-check-circle"></i> فعال</span>
                                <?php else: ?>
                                    <span class="status-inactive"><i class="fas fa-times-circle"></i> غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <form action="manage_areas.php" method="POST" style="display:inline-block; margin-left:5px;">
                                    <input type="hidden" name="area_code" value="<?php echo htmlspecialchars($area_item['area_code']); ?>">
                                    <button type="submit" name="toggle_active" class="button button-small <?php echo $area_item['is_active'] ? 'button-warning' : 'button-success'; ?>" title="<?php echo $area_item['is_active'] ? 'غیرفعال کردن' : 'فعال کردن'; ?>">
                                        <i class="fas fa-toggle-<?php echo $area_item['is_active'] ? 'on' : 'off'; ?>"></i>
                                    </button>
                                </form>
                                <a href="manage_areas.php?action=edit_form&area_code=<?php echo htmlspecialchars($area_item['area_code']); ?>" class="button button-small button-primary" title="ویرایش">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="manage_areas.php" method="POST" style="display:inline-block;" onsubmit="return confirm('آیا از حذف منطقه «<?php echo htmlspecialchars(addslashes($area_item['area_name'])); ?>» با کد «<?php echo htmlspecialchars(addslashes($area_item['area_code'])); ?>» مطمئن هستید؟ این عمل قابل بازگشت نیست.');">
                                    <input type="hidden" name="area_code" value="<?php echo htmlspecialchars($area_item['area_code']); ?>">
                                    <button type="submit" name="delete_area" class="button button-small button-danger" title="حذف">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <input type="number" name="display_orders[<?php echo htmlspecialchars($area_item['area_code']); ?>]" value="<?php echo htmlspecialchars($area_item['display_order']); ?>" class="input-small" min="0">
                            </td>
                            <td><?php echo htmlspecialchars($area_item['area_code']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php elseif ($db): ?>
                    <tr><td colspan="7" style="text-align: center;">هیچ منطقه‌ای یافت نشد. برای شروع، یک منطقه جدید اضافه کنید.</td></tr>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; color: red;">خطا در اتصال به دیتابیس. لطفا با مدیر سیستم تماس بگیرید.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($areas_list)): ?>
        <div class="table-actions" style="margin-top: 15px;">
             <button type="submit" name="save_display_order" class="button primary-button">ذخیره ترتیب نمایش</button>
        </div>
        <?php endif; ?>
    </form>
<?php endif; // end if current_action === 'view_list' ?>


<?php // استایل‌های اولیه برای این صفحه - بهتر است به فایل admin_style.css منتقل شوند ?>
<style>
.card { background: #fff; border: 1px solid #e9ecef; border-radius: .25rem; margin-bottom: 1.5rem; padding: 1.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); }
.form-container h3 { margin-top: 0; margin-bottom: 1rem; font-size: 1.5rem; border-bottom: 1px solid #eee; padding-bottom: 0.75rem; }
.form-group { margin-bottom: 1rem; }
.form-group label { display: block; margin-bottom: .5rem; font-weight: 500; }
.form-control { display: block; width: 100%; padding: .5rem .75rem; font-size: 1rem; line-height: 1.5; color: #495057; background-color: #fff; background-clip: padding-box; border: 1px solid #ced4da; border-radius: .25rem; transition: border-color .15s ease-in-out,box-shadow .15s ease-in-out; box-sizing: border-box; }
.form-control[readonly] { background-color: #e9ecef; opacity: 1; }
.form-check { position: relative; display: block; padding-left: 1.25rem; }
.form-check-input { position: absolute; margin-top: .3rem; margin-left: -1.25rem; }
.form-check-label { margin-bottom: 0; }
.form-text.text-muted { font-size: .875em; color: #6c757d; }
.form-actions { margin-top: 1.5rem; }
.form-actions .button { margin-right: 0.5rem; }

.button.primary-button { background-color: #007bff; color: white; border-color: #007bff;}
.button.primary-button:hover { background-color: #0069d9; border-color: #0062cc; }
.button.secondary-button { background-color: #6c757d; color: white; border-color: #6c757d;}
.button.secondary-button:hover { background-color: #5a6268; border-color: #545b62; }
.button.success-button { background-color: #28a745; color: white; border-color: #28a745;}
.button.success-button:hover { background-color: #218838; border-color: #1e7e37; }
.button.button-danger { background-color: #dc3545; color: white; border-color: #dc3545;}
.button.button-danger:hover { background-color: #c82333; border-color: #bd2130; }
.button.button-warning { background-color: #ffc107; color: #212529; border-color: #ffc107;}
.button.button-warning:hover { background-color: #e0a800; border-color: #d39e00; }

.actions-cell .button, .actions-cell a.button { margin-right: 5px; vertical-align: middle;}
.actions-cell .button i, .actions-cell a.button i { margin:0; } /* برای آیکن‌های تنها در دکمه */

/* سایر استایل‌ها مانند قبل */
.message { padding: 12px 15px; margin-bottom: 20px; border-radius: 5px; font-size: 0.95em; border: 1px solid transparent; }
.message.success { background-color: #e6ffed; color: #006421; border-color: #b3ffc6; }
.message.error { background-color: #ffe6e6; color: #a00000; border-color: #ffb3b3; }
.status-active { color: #28a745; font-weight: bold; } .status-active .fas { margin-left: 5px; }
.status-inactive { color: #dc3545; } .status-inactive .fas { margin-left: 5px; }
.input-small { width: 70px; padding: 6px 8px; text-align: center; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
.button-small { padding: 5px 8px; font-size: 0.85em; line-height:1.2; }
.admin-table { width: 100%; border-collapse: collapse; margin-top: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.admin-table th, .admin-table td { border: 1px solid #e1e1e1; padding: 10px 12px; text-align: right; vertical-align: middle; }
.admin-table thead th { background-color: #f8f9fa; color: #333; font-weight: bold; }
.admin-table tbody tr:nth-child(even) { background-color: #fdfdfd; }
.admin-table tbody tr:hover { background-color: #f1f1f1; }
.admin-page-title { margin-bottom: 25px; padding-bottom:15px; border-bottom: 1px solid #eee; }
.admin-page-title h2 { margin-top:0; }
.table-actions { margin-bottom:15px; margin-top:10px; }
.table-actions .button { margin-left: 5px; }
</style>

<?php
require_once __DIR__ . '/layouts/_footer.php';
?>