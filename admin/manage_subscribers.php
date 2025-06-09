<?php
// /public_html/admin/manage_subscribers.php
// این فایل جدول "users" را برای مدیریت کاربران و اشتراک پیامک مدیریت می‌کند.

// 0. فعال کردن نمایش خطاها برای دیباگ (در محیط نهایی، این را 0 کنید)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start(); // شروع بافر خروجی برای مدیریت بهتر هدرها

$pageTitle = "مدیریت کاربران و اشتراک پیامک";

// 1. لود کردن هدر ادمین (این فایل باید $db، $jdf_loaded_for_admin_layout و غیره را در دسترس قرار دهد)
if (file_exists(__DIR__ . '/layouts/_header.php')) {
    require_once __DIR__ . '/layouts/_header.php';
} else {
    error_log("MANAGE USERS FATAL ERROR: Admin header layout file not found at " . __DIR__ . "/layouts/_header.php");
    die("خطای سیستمی: فایل لایوت هدر ادمین یافت نشد.");
}
// متغیرهای $db (نمونه PDO), $jdf_loaded_for_admin_layout (بولین),
// $loggedInAdminUsername, site_url() باید توسط _header.php در دسترس باشند.

// اطمینان از اینکه سشن ادمین به درستی شروع شده (باید توسط _header.php انجام شده باشد)
if (session_status() === PHP_SESSION_NONE) {
    // این حالت نباید اتفاق بیفتد اگر _header.php شامل config.php با session_start() است
    error_log("MANAGE USERS WARNING: Session was not started by _header.php. Attempting to start.");
    if (defined('SESSION_NAME_ADMIN')) { session_name(SESSION_NAME_ADMIN); }
    else { session_name('BabolOutageAdminSID_Fallback'); }
    session_set_cookie_params([
        'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 0, 'path' => defined('SESSION_PATH') ? SESSION_PATH : '/',
        'domain' => defined('SESSION_DOMAIN') ? SESSION_DOMAIN : '', 'secure' => defined('SESSION_SECURE') ? SESSION_SECURE : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        'httponly' => defined('SESSION_HTTPONLY') ? SESSION_HTTPONLY : true, 'samesite' => 'Lax'
    ]);
    if(!session_start()){
        error_log("MANAGE USERS ERROR: Failed to start session manually.");
    }
}

$message = $_SESSION['user_management_message'] ?? null;
if ($message) unset($_SESSION['user_management_message']);

$users_list = [];
$edit_user_data = null;
$form_data_sticky = $_POST; // برای حفظ مقادیر فرم در صورت بروز خطا در POST اولیه
$errorMessageForPage = null;

try {
    // بررسی اولیه و حیاتی برای $db (باید توسط _header.php ایجاد شده باشد)
    if (!isset($db) || !$db instanceof PDO) {
        throw new Exception("خطای بحرانی: اتصال به پایگاه داده ($db) در دسترس نیست یا معتبر نمی باشد. لطفاً فایل admin/layouts/_header.php را بررسی کنید.");
    }

    // --- پردازش حذف کاربر/مشترک ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && isset($_POST['user_id_to_delete'])) {
        $id_to_delete = (int)$_POST['user_id_to_delete'];
        $db->beginTransaction();
        try {
            $stmtDelKeywords = $db->prepare("DELETE FROM subscriber_address_keywords WHERE subscriber_id = :user_id");
            $stmtDelKeywords->bindValue(':user_id', $id_to_delete, PDO::PARAM_INT);
            $stmtDelKeywords->execute();

            $stmtDelUser = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmtDelUser->bindValue(':id', $id_to_delete, PDO::PARAM_INT);
            if ($stmtDelUser->execute()) {
                $db->commit();
                $_SESSION['user_management_message'] = ['type' => 'success', 'text' => 'کاربر/مشترک با موفقیت حذف شد.'];
            } else {
                $db->rollBack();
                $_SESSION['user_management_message'] = ['type' => 'error', 'text' => 'خطا در حذف کاربر/مشترک.'];
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Delete User DB Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $_SESSION['user_management_message'] = ['type' => 'error', 'text' => 'خطای پایگاه داده هنگام حذف.'];
        }
        if (!headers_sent()) { header("Location: manage_subscribers.php"); exit; }
        else { echo "<script>window.location.href='manage_subscribers.php';</script>"; exit;}
    }

    // --- پردازش افزودن/ویرایش کاربر/مشترک ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_user']) || isset($_POST['edit_user']))) {
        $name = trim($form_data_sticky['name'] ?? '');
        $phone = trim($form_data_sticky['phone'] ?? '');
        $password_form = $_POST['password'] ?? '';
        $is_active_account = isset($form_data_sticky['is_active_account']) ? 1 : 0;
        $is_sms_subscriber = isset($form_data_sticky['is_sms_subscriber']) ? 1 : 0;
        $package_expiry_date_shamsi = trim($form_data_sticky['package_expiry_date_shamsi'] ?? '');
        $use_pattern_sms = isset($form_data_sticky['use_pattern_sms']) ? 1 : 0;
        $pattern_id_override = trim($form_data_sticky['pattern_id_override'] ?? '');
        $notes = trim($form_data_sticky['notes'] ?? '');
        $user_id_edit = isset($form_data_sticky['user_id_edit']) ? (int)$form_data_sticky['user_id_edit'] : null;

        $package_expiry_date_gregorian = null;
        $password_to_save_hash = null;
        $form_validation_error = false;

        if (empty($name)) { $message = ['type' => 'error', 'text' => 'نام کاربر/مشترک الزامی است.']; $form_validation_error = true; }
        if (!$form_validation_error && empty($phone)) { $message = ['type' => 'error', 'text' => 'شماره تلفن الزامی است.']; $form_validation_error = true; }
        if (!$form_validation_error && !preg_match('/^09[0-9]{9}$/', $phone)) { $message = ['type' => 'error', 'text' => 'فرمت شماره تلفن (09xxxxxxxxxx) صحیح نیست.']; $form_validation_error = true; }

        if (!$user_id_edit && empty($password_form)) {
             $message = ['type' => 'error', 'text' => 'رمز عبور برای کاربر جدید الزامی است.']; $form_validation_error = true;
        } elseif (!empty($password_form)) {
            if (mb_strlen($password_form) < 6) {
                $message = ['type' => 'error', 'text' => 'رمز عبور (در صورت وارد کردن) باید حداقل ۶ کاراکتر باشد.']; $form_validation_error = true;
            } else {
                $password_to_save_hash = password_hash($password_form, PASSWORD_DEFAULT);
            }
        }

        if (!$form_validation_error && !empty($package_expiry_date_shamsi)) {
            // $jdf_loaded_for_admin_layout باید توسط _header.php تعریف شده باشد
            if (isset($jdf_loaded_for_admin_layout) && $jdf_loaded_for_admin_layout && function_exists('jalali_to_gregorian') && function_exists('jcheckdate')) {
                $parts = explode('/', $package_expiry_date_shamsi);
                if (count($parts) === 3) {
                    $jy = (int)$parts[0]; $jm = (int)$parts[1]; $jd = (int)$parts[2];
                    if ($jy >= 1300 && $jy <= 1500 && $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= 31 && jcheckdate($jm, $jd, $jy)) {
                        $g_date_array = jalali_to_gregorian($jy, $jm, $jd);
                        if ($g_date_array && is_array($g_date_array) && count($g_date_array) === 3) {
                            $package_expiry_date_gregorian = vsprintf('%04d-%02d-%02d', $g_date_array);
                        } else { $message = ['type' => 'error', 'text' => 'خطا در تبدیل تاریخ انقضای پکیج به میلادی.']; $form_validation_error = true; }
                    } else { $message = ['type' => 'error', 'text' => 'تاریخ انقضای پکیج وارد شده (مثال: 1403/05/15) معتبر نیست.']; $form_validation_error = true; }
                } else { $message = ['type' => 'error', 'text' => 'فرمت تاریخ انقضای پکیج باید به صورت YY/MM/DD یا YYYY/MM/DD شمسی باشد (مثال: 03/05/15 یا 1403/05/15).']; $form_validation_error = true; } // **پیام خطا کامل شد**
            } elseif(!empty($package_expiry_date_shamsi)) { $message = ['type' => 'error', 'text' => 'خطا: کتابخانه تاریخ شمسی (jdf.php) به درستی بارگذاری نشده است.']; $form_validation_error = true; }
        }

        if (!$form_validation_error) {
            // بررسی تکراری بودن شماره تلفن
            $stmtCheckPhone = $db->prepare("SELECT id FROM users WHERE phone = :phone AND (:id_val IS NULL OR id != :id_val)");
            $stmtCheckPhone->bindValue(':phone', $phone, PDO::PARAM_STR);
            if ($user_id_edit === null) { $stmtCheckPhone->bindValue(':id_val', null, PDO::PARAM_NULL); }
            else { $stmtCheckPhone->bindValue(':id_val', $user_id_edit, PDO::PARAM_INT); }
            $stmtCheckPhone->execute();

            if ($stmtCheckPhone->fetch()) {
                $message = ['type' => 'error', 'text' => 'این شماره تلفن قبلاً برای کاربر دیگری ثبت شده است.'];
                $form_validation_error = true;
            } else {
                // $db->beginTransaction(); // اگر می‌خواهید این عملیات هم در تراکنش باشد
                if ($user_id_edit) { // ویرایش
                    $sql_parts = [
                        "name = :name", "phone = :phone", "is_active_account = :is_active_account",
                        "is_sms_subscriber = :is_sms_subscriber", "package_expiry_date = :package_expiry_date",
                        "use_pattern_sms = :use_pattern_sms", "pattern_id_override = :pattern_id_override",
                        "notes = :notes", "updated_at = NOW()"
                    ];
                    if ($password_to_save_hash !== null) { // فقط اگر پسورد جدید وارد شده
                        $sql_parts[] = "password_hash = :password_hash";
                    }
                    $sql = "UPDATE users SET " . implode(", ", $sql_parts) . " WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    $stmt->bindValue(':id', $user_id_edit, PDO::PARAM_INT);
                } else { // افزودن
                    $sql = "INSERT INTO users (name, phone, password_hash, is_active_account, is_sms_subscriber, package_expiry_date, use_pattern_sms, pattern_id_override, notes, created_at, updated_at) 
                            VALUES (:name, :phone, :password_hash, :is_active_account, :is_sms_subscriber, :package_expiry_date, :use_pattern_sms, :pattern_id_override, :notes, NOW(), NOW())";
                    $stmt = $db->prepare($sql);
                    // password_hash برای افزودن جدید همیشه باید مقدار داشته باشد (از اعتبارسنجی بالا)
                    $stmt->bindValue(':password_hash', $password_to_save_hash, PDO::PARAM_STR);
                }

                // Bind کردن پارامترهای مشترک
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
                $stmt->bindValue(':is_active_account', $is_active_account, PDO::PARAM_INT);
                $stmt->bindValue(':is_sms_subscriber', $is_sms_subscriber, PDO::PARAM_INT);
                $stmt->bindValue(':package_expiry_date', $package_expiry_date_gregorian, ($package_expiry_date_gregorian === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
                $stmt->bindValue(':use_pattern_sms', $use_pattern_sms, PDO::PARAM_INT);
                $stmt->bindValue(':pattern_id_override', empty($pattern_id_override) ? null : $pattern_id_override, (empty($pattern_id_override) ? PDO::PARAM_NULL : PDO::PARAM_STR));
                $stmt->bindValue(':notes', empty($notes) ? null : $notes, (empty($notes) ? PDO::PARAM_NULL : PDO::PARAM_STR));
                
                if ($stmt->execute()) {
                    // $db->commit(); // اگر از تراکنش استفاده می‌کنید
                    $_SESSION['user_management_message'] = ['type' => 'success', 'text' => $user_id_edit ? 'اطلاعات کاربر با موفقیت به‌روز شد.' : 'کاربر جدید با موفقیت افزوده شد.'];
                    $form_data_sticky = []; // پاک کردن فرم پس از موفقیت
                    if (!headers_sent()) { header("Location: manage_subscribers.php"); exit; }
                    else { echo "<script>window.location.href='manage_subscribers.php';</script>"; exit;}
                } else {
                    // $db->rollBack(); // اگر از تراکنش استفاده می‌کنید
                    $errorInfo = $stmt->errorInfo();
                    $message = ['type' => 'error', 'text' => 'خطا در عملیات پایگاه داده: ' . ($errorInfo[2] ?? 'خطای نامشخص')];
                    error_log("User/Subscriber DB Operation Error: " . print_r($errorInfo, true) . " SQL: " . $sql . " PARAMS: " . print_r($form_data_sticky, true));
                    $form_validation_error = true; // برای نمایش خطا و حفظ داده‌های فرم
                }
            }
        }
        // اگر خطای ولیدیشن بود، $form_data_sticky از قبل با $_POST پر شده است.
        // برای حالت ویرایش، اگر خطای ولیدیشن بود، $edit_user_data را با مقادیر POST پر می‌کنیم
        if ($form_validation_error && $user_id_edit) {
             $edit_user_data = $form_data_sticky;
             $edit_user_data['id'] = $user_id_edit;
             $edit_user_data['package_expiry_date_shamsi'] = $form_data_sticky['package_expiry_date_shamsi'] ?? '';
        }
    }
    // --- پایان پردازش فرم POST ---


    // اگر درخواست ویرایش یک کاربر خاص آمده (GET) و فرم قبلاً به خاطر خطا در همین درخواست POST پر نشده باشد
    // یا اگر در حالت افزودن هستیم و خطای ولیدیشن رخ داده (برای حفظ مقادیر)
    if (isset($_GET['edit_user_id']) && $_SERVER['REQUEST_METHOD'] === 'GET' && !$edit_user_data ) {
        $edit_id_get = (int)$_GET['edit_user_id'];
        $stmtEdit = $db->prepare("SELECT * FROM users WHERE id = :id");
        $stmtEdit->bindValue(':id', $edit_id_get, PDO::PARAM_INT);
        $stmtEdit->execute();
        $db_data_for_edit = $stmtEdit->fetch(PDO::FETCH_ASSOC);
        if ($db_data_for_edit) {
            $edit_user_data = $db_data_for_edit;
            if (!empty($edit_user_data['package_expiry_date']) && isset($jdf_loaded_for_admin_layout) && $jdf_loaded_for_admin_layout && function_exists('gregorian_to_jalali')) {
                try {
                    list($gy, $gm, $gd) = explode('-', $edit_user_data['package_expiry_date']);
                    $j_date_array = gregorian_to_jalali((int)$gy, (int)$gm, (int)$gd);
                    $edit_user_data['package_expiry_date_shamsi'] = vsprintf('%04d/%02d/%02d', $j_date_array);
                } catch (Exception $date_ex) {
                    $edit_user_data['package_expiry_date_shamsi'] = '';
                    error_log("Error converting package_expiry_date to Shamsi for edit form: " . $date_ex->getMessage());
                }
            } else {
                $edit_user_data['package_expiry_date_shamsi'] = !empty($edit_user_data['package_expiry_date']) ? $edit_user_data['package_expiry_date'] : '';
            }
        } elseif (!$message) {
             $message = ['type' => 'error', 'text' => 'کاربر با شناسه درخواستی برای ویرایش یافت نشد.'];
        }
    }

    // خواندن لیست کاربران/مشترکین برای نمایش در جدول
    $stmtList = $db->query("SELECT * FROM users ORDER BY id DESC");
    if ($stmtList) {
        $users_list = $stmtList->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("Error fetching users list: " . print_r($db->errorInfo(), true));
        $errorMessageForPage = "خطا در خواندن لیست کاربران/مشترکین.";
    }

} catch (PDOException $e_pdo) {
    $errorMessageForPage = "خطای پایگاه داده: " . ((defined('DEBUG_MODE') && DEBUG_MODE) ? $e_pdo->getMessage() : "لطفاً با مدیر تماس بگیرید.");
    error_log("Manage Users PDOException: " . $e_pdo->getMessage() . "\n" . $e_pdo->getTraceAsString());
} catch (Exception $e_gen) {
    $errorMessageForPage = "خطای عمومی: " . ((defined('DEBUG_MODE') && DEBUG_MODE) ? htmlspecialchars($e_gen->getMessage()) : "خطای داخلی سرور.");
    error_log("Manage Users General Exception: " . $e_gen->getMessage() . "\n" . $e_gen->getTraceAsString());
}

// آماده‌سازی مقادیر فرم برای نمایش (با اولویت به داده‌های ویرایش یا خطای فرم از POST)
$form_name_value = htmlspecialchars($edit_user_data['name'] ?? ($form_data_sticky['name'] ?? ''));
$form_phone_value = htmlspecialchars($edit_user_data['phone'] ?? ($form_data_sticky['phone'] ?? ''));
// فیلد پسورد در حالت ویرایش خالی نمایش داده می‌شود
$form_is_active_account_value = $edit_user_data['is_active_account'] ?? ($form_data_sticky['is_active_account'] ?? 1);
$form_is_sms_subscriber_value = $edit_user_data['is_sms_subscriber'] ?? ($form_data_sticky['is_sms_subscriber'] ?? 0);
$form_package_expiry_date_shamsi_value = htmlspecialchars($edit_user_data['package_expiry_date_shamsi'] ?? ($form_data_sticky['package_expiry_date_shamsi'] ?? ''));
$form_use_pattern_sms_value = $edit_user_data['use_pattern_sms'] ?? ($form_data_sticky['use_pattern_sms'] ?? 0);
$form_pattern_id_override_value = htmlspecialchars($edit_user_data['pattern_id_override'] ?? ($form_data_sticky['pattern_id_override'] ?? ''));
$form_notes_value = htmlspecialchars($edit_user_data['notes'] ?? ($form_data_sticky['notes'] ?? ''));

?>

<div class="admin-page-content">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>

    <?php if (isset($message) && is_array($message)): ?>
        <div class="message <?php echo htmlspecialchars($message['type']); ?>">
            <?php echo htmlspecialchars($message['text']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($errorMessageForPage)): ?>
         <div class="message error"><?php echo $errorMessageForPage; /* ممکن است HTML داشته باشد */ ?></div>
    <?php endif; ?>

    <div class="add-subscriber-form">
        <h3><?php echo $edit_user_data ? 'ویرایش کاربر/مشترک: ' . htmlspecialchars($edit_user_data['name']) : 'افزودن کاربر/مشترک جدید'; ?></h3>
        <form action="manage_subscribers.php<?php echo $edit_user_data ? '?edit_user_id=' . htmlspecialchars($edit_user_data['id']) : ''; ?>" method="post">
            <?php if ($edit_user_data): ?>
                <input type="hidden" name="user_id_edit" value="<?php echo htmlspecialchars($edit_user_data['id']); ?>">
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="name_form">نام و نام خانوادگی:</label>
                    <input type="text" id="name_form" name="name" value="<?php echo $form_name_value; ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone_form">شماره تلفن (مثال: 09123456789):</label>
                    <input type="tel" id="phone_form" name="phone" value="<?php echo $form_phone_value; ?>" pattern="^09[0-9]{9}$" title="شماره تلفن ۱۱ رقمی با شروع 09" required>
                </div>
            </div>
            <div class="form-group">
                <label for="password_form">رمز عبور <?php echo $edit_user_data ? '(اگر نمی‌خواهید تغییر کند، خالی بگذارید)' : '(حداقل ۶ کاراکتر، الزامی)'; ?>:</label>
                <input type="password" id="password_form" name="password" <?php echo !$edit_user_data ? 'required' : ''; ?> minlength="6" placeholder="<?php echo $edit_user_data ? 'برای تغییر رمز وارد کنید' : ''; ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="is_active_account_form">وضعیت کلی حساب:</label>
                    <select name="is_active_account" id="is_active_account_form">
                        <option value="1" <?php if ($form_is_active_account_value == 1) echo 'selected'; ?>>فعال</option>
                        <option value="0" <?php if ($form_is_active_account_value == 0) echo 'selected'; ?>>غیرفعال</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="is_sms_subscriber_form">اشتراک پیامک فعال باشد؟</label>
                    <select name="is_sms_subscriber" id="is_sms_subscriber_form">
                        <option value="1" <?php if ($form_is_sms_subscriber_value == 1) echo 'selected'; ?>>بله، پیامک دریافت کند</option>
                        <option value="0" <?php if ($form_is_sms_subscriber_value == 0) echo 'selected'; ?>>خیر، پیامک دریافت نکند</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="package_expiry_date_picker">تاریخ انقضای پکیج پیامک (شمسی):</label>
                <input type="text" id="package_expiry_date_picker" name="package_expiry_date_shamsi"
                       value="<?php echo $form_package_expiry_date_shamsi_value; ?>"
                       placeholder="مثال: 1403/12/29 (خالی برای نامحدود)" autocomplete="off" style="direction:ltr; text-align:right;">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="use_pattern_sms_form">استفاده از پیامک پترن؟</label>
                    <select name="use_pattern_sms" id="use_pattern_sms_form">
                        <option value="0" <?php if ($form_use_pattern_sms_value == 0) echo 'selected'; ?>>خیر (ارسال متن عادی)</option>
                        <option value="1" <?php if ($form_use_pattern_sms_value == 1) echo 'selected'; ?>>بله (استفاده از پترن)</option>
                    </select>
                    <small>اگر "بله" باشد و کد پترن زیر خالی باشد، از پترن پیش‌فرض تنظیمات سامانه استفاده خواهد شد.</small>
                </div>
                <div class="form-group">
                    <label for="pattern_id_override_form">کد پترن اختصاصی (اختیاری):</label>
                    <input type="text" id="pattern_id_override_form" name="pattern_id_override"
                           value="<?php echo $form_pattern_id_override_value; ?>"
                           placeholder="فقط اگر می‌خواهید از پترن متفاوتی استفاده کنید">
                </div>
            </div>
            <div class="form-group">
                <label for="notes_form">یادداشت‌های ادمین:</label>
                <textarea id="notes_form" name="notes" rows="3"><?php echo $form_notes_value; ?></textarea>
            </div>

            <button type="submit" name="<?php echo $edit_user_data ? 'edit_user' : 'add_user'; ?>" class="btn-submit">
                <?php echo $edit_user_data ? 'ذخیره تغییرات کاربر' : 'افزودن کاربر/مشترک'; ?>
            </button>
            <?php if ($edit_user_data): ?>
                <a href="manage_subscribers.php" class="btn-cancel">انصراف</a>
            <?php endif; ?>
        </form>
    </div>

    <h3 style="margin-top: 40px;">لیست کاربران و مشترکین (<?php echo count($users_list); ?> مورد)</h3>
    <?php if (!empty($users_list)): ?>
        <div class="table-responsive-wrapper">
            <table class="data-table modern-table">
                <thead>
                    <tr>
                        <th>ID</th><th>نام</th><th>شماره</th><th>وضعیت حساب</th><th>مشترک SMS</th>
                        <th>انقضای پکیج</th><th>نوع SMS</th><th>پترن خاص</th><th>تاریخ ثبت</th><th>آخرین به‌روزرسانی</th>
                        <th style="min-width: 200px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $today_dt_obj_for_table = new DateTime(); ?>
                    <?php foreach ($users_list as $user_row): ?>
                        <tr class="<?php echo !$user_row['is_active_account'] ? 'inactive-row-subscriber' : ''; ?>">
                            <td><?php echo htmlspecialchars($user_row['id']); ?></td>
                            <td><?php echo htmlspecialchars($user_row['name']); ?></td>
                            <td><?php echo htmlspecialchars($user_row['phone']); ?></td>
                            <td><span class="<?php echo $user_row['is_active_account'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $user_row['is_active_account'] ? 'فعال' : 'غیرفعال'; ?></span></td>
                            <td><span class="<?php echo $user_row['is_sms_subscriber'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $user_row['is_sms_subscriber'] ? 'بله' : 'خیر'; ?></span></td>
                            <td>
                                <?php
                                $pkgExpiryDateDisplay = "<span style='color:#777;'>-</span>"; $is_pkg_expired = false;
                                if (!empty($user_row['package_expiry_date'])) {
                                    try { $expiry_dt = new DateTime($user_row['package_expiry_date']);
                                          $today_start = new DateTime($today_dt_obj_for_table->format('Y-m-d'));
                                          if (isset($jdf_loaded_for_admin_layout) && $jdf_loaded_for_admin_layout && function_exists('jdate')) { $pkgExpiryDateDisplay = jdate('Y/m/d', $expiry_dt->getTimestamp()); }
                                          else { $pkgExpiryDateDisplay = $user_row['package_expiry_date']; }
                                          if ($expiry_dt < $today_start ) { $is_pkg_expired = true; $pkgExpiryDateDisplay .= " <span class='status-inactive'>(پایان یافته)</span>"; }
                                    } catch (Exception $e) { $pkgExpiryDateDisplay = "تاریخ نامعتبر"; }
                                } echo $pkgExpiryDateDisplay;
                                ?>
                            </td>
                            <td><?php echo isset($user_row['use_pattern_sms']) && $user_row['use_pattern_sms'] ? 'پترن' : 'عادی'; ?></td>
                            <td><?php echo htmlspecialchars($user_row['pattern_id_override'] ?? '-'); ?></td>
                            <td><?php echo (isset($jdf_loaded_for_admin_layout) && $jdf_loaded_for_admin_layout && !empty($user_row['created_at'])) ? jdate('Y/m/d H:i', strtotime($user_row['created_at'])) : ($user_row['created_at'] ?? '-'); ?></td>
                            <td><?php echo (isset($jdf_loaded_for_admin_layout) && $jdf_loaded_for_admin_layout && !empty($user_row['updated_at'])) ? jdate('Y/m/d H:i', strtotime($user_row['updated_at'])) : ($user_row['updated_at'] ?? '-'); ?></td>
                            <td class="actions">
                                <a href="manage_subscribers.php?edit_user_id=<?php echo $user_row['id']; ?>" class="btn-edit">ویرایش</a>
                                <a href="manage_keywords.php?subscriber_id=<?php echo $user_row['id']; ?>" class="btn-keywords">آدرس‌ها</a>
                                <form action="manage_subscribers.php" method="post" style="display:inline;" onsubmit="return confirm('آیا از حذف کاربر \'<?php echo htmlspecialchars(addslashes($user_row['name'])); ?>\' و تمام آدرس‌های او مطمئن هستید؟');">
                                    <input type="hidden" name="user_id_to_delete" value="<?php echo $user_row['id']; ?>">
                                    <button type="submit" name="delete_user" class="btn-delete">حذف</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif (empty($errorMessageForPage)) : ?>
        <p class="no-data">هنوز هیچ کاربری ثبت نشده است.</p>
    <?php endif; ?>
</div>

<?php
// اسکریپت Date Picker
$page_specific_js_footer_content = '';
if (isset($jdf_loaded_for_admin_layout) && $jdf_loaded_for_admin_layout) {
$page_specific_js_footer_content = <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (typeof kamaDatepicker === 'function' && document.getElementById('package_expiry_date_picker')) { 
        kamaDatepicker('package_expiry_date_picker', {
            placeholder: 'مثال: 1403/05/15',
            twodigit: false,
            closeAfterSelect: true,
            buttonsColor: "green", 
            forceFarsiDigits: true,
            markToday: true,
            gotoToday: true,
            syncThemeToOS: false 
        });
    } else if (document.getElementById('package_expiry_date_picker')) {
        // console.warn("KamaDatepicker (یا کتابخانه Datepicker شمسی دیگر) بارگذاری نشده یا یافت نشد.");
    }
});
</script>JS;
}

if (file_exists(__DIR__ . '/layouts/_footer.php')) {
    if (!empty($page_specific_js_footer_content)) echo $page_specific_js_footer_content;
    require_once __DIR__ . '/layouts/_footer.php';
}
ob_end_flush(); // ارسال خروجی بافر شده
?>