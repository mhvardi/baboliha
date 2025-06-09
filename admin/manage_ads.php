<?php
// /public_html/admin/manage_ads.php
$pageTitle = "مدیریت تبلیغات بنری";
if (file_exists(__DIR__ . '/layouts/_header.php')) { require_once __DIR__ . '/layouts/_header.php'; }
else { die("Header not found"); }
// $db باید از هدر در دسترس باشد

$message = $_SESSION['ads_message'] ?? null;
if ($message) unset($_SESSION['ads_message']);
$banners = [];
$edit_banner_data = null;
$errorMessageForPage = null;

define('BANNER_UPLOAD_DIR', ROOT_PATH . '/assets/images/banners/'); // ROOT_PATH از config.php
define('BANNER_UPLOAD_URL', site_url('assets/images/banners/'));

if (!is_dir(BANNER_UPLOAD_DIR)) {
    if (!mkdir(BANNER_UPLOAD_DIR, 0775, true)) { // سعی در ایجاد پوشه با دسترسی مناسب
        $errorMessageForPage = "خطا: امکان ایجاد پوشه آپلود بنرها در مسیر " . BANNER_UPLOAD_DIR . " وجود ندارد. لطفاً دسترسی‌ها را بررسی کنید یا پوشه را دستی ایجاد کنید.";
        error_log("Manage Ads Error: Could not create banner upload directory: " . BANNER_UPLOAD_DIR);
    }
} elseif (!is_writable(BANNER_UPLOAD_DIR)) {
     $errorMessageForPage = "خطا: پوشه آپلود بنرها (" . BANNER_UPLOAD_DIR . ") قابل نوشتن نیست. لطفاً دسترسی‌ها را بررسی کنید.";
     error_log("Manage Ads Error: Banner upload directory is not writable: " . BANNER_UPLOAD_DIR);
}


try {
    if (!isset($db) || !$db instanceof PDO) { throw new Exception("اتصال به دیتابیس برقرار نیست."); }

    // --- پردازش افزودن/ویرایش بنر ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_banner']) || isset($_POST['edit_banner']))) {
        $banner_id_edit = isset($_POST['banner_id_edit']) ? (int)$_POST['banner_id_edit'] : null;
        $name = trim($_POST['name'] ?? '');
        $target_url = trim($_POST['target_url'] ?? '');
        $position = trim($_POST['position'] ?? 'top_banner');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null; // فرض بر اینکه DatePicker میلادی است یا تبدیل شده
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $current_image_url = $_POST['current_image_url'] ?? null; // برای حالت ویرایش

        $image_url_for_db = $current_image_url; // پیش‌فرض تصویر فعلی در حالت ویرایش

        if (empty($name) || empty($target_url) || empty($position)) {
            $message = ['type' => 'error', 'text' => 'نام بنر، لینک مقصد و موقعیت الزامی هستند.'];
        } else {
            // مدیریت آپلود تصویر
            if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
                if (is_writable(BANNER_UPLOAD_DIR)) {
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $file_type = $_FILES['banner_image']['type'];
                    $file_size = $_FILES['banner_image']['size'];
                    $max_size = 2 * 1024 * 1024; // 2MB

                    if (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
                        $file_extension = pathinfo($_FILES['banner_image']['name'], PATHINFO_EXTENSION);
                        $new_filename = uniqid('banner_', true) . '.' . $file_extension;
                        $upload_path = BANNER_UPLOAD_DIR . $new_filename;

                        if (move_uploaded_file($_FILES['banner_image']['tmp_name'], $upload_path)) {
                            $image_url_for_db = BANNER_UPLOAD_URL . $new_filename; // URL برای ذخیره در دیتابیس
                            // اگر در حالت ویرایش بودیم و تصویر جدید آپلود شد، تصویر قبلی را (اگر متفاوت است) می‌توان حذف کرد
                            if ($banner_id_edit && $current_image_url && $current_image_url !== $image_url_for_db) {
                                $old_file_path = str_replace(BANNER_UPLOAD_URL, BANNER_UPLOAD_DIR, $current_image_url);
                                if (file_exists($old_file_path)) @unlink($old_file_path);
                            }
                        } else {
                            $message = ['type' => 'error', 'text' => 'خطا در آپلود تصویر بنر.'];
                        }
                    } else {
                        $message = ['type' => 'error', 'text' => 'نوع فایل تصویر مجاز نیست یا حجم آن بیش از حد است (حداکثر 2MB برای JPG, PNG, GIF, WEBP).'];
                    }
                } else {
                     $message = ['type' => 'error', 'text' => 'پوشه آپلود بنر قابل نوشتن نیست.'];
                }
            } elseif (!$banner_id_edit && empty($image_url_for_db) && (!isset($_FILES['banner_image']) || $_FILES['banner_image']['error'] !== UPLOAD_ERR_OK) ) {
                // اگر در حالت افزودن هستیم و تصویری انتخاب نشده
                $message = ['type' => 'error', 'text' => 'لطفاً یک تصویر برای بنر انتخاب کنید.'];
            }


            if (!isset($message)) { // اگر خطایی در آپلود یا ولیدیشن اولیه نبود
                if ($banner_id_edit) {
                    $sql = "UPDATE banners SET name=:name, image_url=:image_url, target_url=:target_url, position=:position, is_active=:is_active, start_date=:start_date, end_date=:end_date, updated_at=NOW() WHERE id=:id";
                    $stmt = $db->prepare($sql);
                    $stmt->bindParam(':id', $banner_id_edit, PDO::PARAM_INT);
                } else {
                    $sql = "INSERT INTO banners (name, image_url, target_url, position, is_active, start_date, end_date, created_at, updated_at) 
                            VALUES (:name, :image_url, :target_url, :position, :is_active, :start_date, :end_date, NOW(), NOW())";
                    $stmt = $db->prepare($sql);
                }
                $stmt->bindParam(':name', $name, PDO::PARAM_STR);
                $stmt->bindParam(':image_url', $image_url_for_db, PDO::PARAM_STR);
                $stmt->bindParam(':target_url', $target_url, PDO::PARAM_STR);
                $stmt->bindParam(':position', $position, PDO::PARAM_STR);
                $stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
                $stmt->bindParam(':start_date', $start_date, $start_date === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindParam(':end_date', $end_date, $end_date === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

                if ($stmt->execute()) {
                    $_SESSION['ads_message'] = ['type' => 'success', 'text' => $banner_id_edit ? 'بنر با موفقیت ویرایش شد.' : 'بنر با موفقیت افزوده شد.'];
                } else {
                    $_SESSION['ads_message'] = ['type' => 'error', 'text' => 'خطا در ذخیره اطلاعات بنر در پایگاه داده.'];
                    error_log("Banner Save Error: " . print_r($stmt->errorInfo(), true));
                }
                if(!headers_sent()) { header("Location: manage_ads.php"); exit;}
                else { echo "<script>window.location.href='manage_ads.php';</script>"; exit;}
            }
        }
    }
    
    // پردازش حذف بنر
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_banner']) && isset($_POST['banner_id_to_delete'])) {
        $id_to_delete_banner = (int)$_POST['banner_id_to_delete'];
        // خواندن اطلاعات بنر برای حذف فایل تصویر
        $stmtFetch = $db->prepare("SELECT image_url FROM banners WHERE id = ?");
        $stmtFetch->execute([$id_to_delete_banner]);
        $banner_to_delete_file = $stmtFetch->fetch(PDO::FETCH_ASSOC);

        $stmtDelBanner = $db->prepare("DELETE FROM banners WHERE id = ?");
        if ($stmtDelBanner->execute([$id_to_delete_banner])) {
            if ($banner_to_delete_file && !empty($banner_to_delete_file['image_url'])) {
                $file_path_to_delete = str_replace(BANNER_UPLOAD_URL, BANNER_UPLOAD_DIR, $banner_to_delete_file['image_url']);
                if (file_exists($file_path_to_delete)) {
                    @unlink($file_path_to_delete);
                }
            }
            $_SESSION['ads_message'] = ['type' => 'success', 'text' => 'بنر با موفقیت حذف شد.'];
        } else {
            $_SESSION['ads_message'] = ['type' => 'error', 'text' => 'خطا در حذف بنر.'];
        }
        if(!headers_sent()) { header("Location: manage_ads.php"); exit;}
        else { echo "<script>window.location.href='manage_ads.php';</script>"; exit;}
    }


    // اگر درخواست ویرایش یک بنر خاص آمده
    if (isset($_GET['edit_banner_id'])) {
        $edit_id_banner = (int)$_GET['edit_banner_id'];
        $stmtEditBanner = $db->prepare("SELECT * FROM banners WHERE id = ?");
        $stmtEditBanner->execute([$edit_id_banner]);
        $edit_banner_data = $stmtEditBanner->fetch(PDO::FETCH_ASSOC);
        if (!$edit_banner_data) {
             $message = ['type' => 'error', 'text' => 'بنر مورد نظر برای ویرایش یافت نشد.'];
        }
    }


    // خواندن لیست بنرها
    $stmtBanners = $db->query("SELECT * FROM banners ORDER BY created_at DESC");
    $banners = $stmtBanners->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $errorMessageForPage = "خطا: " . htmlspecialchars($e->getMessage());
    error_log("Manage Ads Page Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}

$form_name = htmlspecialchars($edit_banner_data['name'] ?? ($_POST['name'] ?? ''));
$form_target_url = htmlspecialchars($edit_banner_data['target_url'] ?? ($_POST['target_url'] ?? 'https://'));
$form_position = $edit_banner_data['position'] ?? ($_POST['position'] ?? 'top_banner');
$form_is_active = $edit_banner_data['is_active'] ?? ($_POST['is_active'] ?? 1);
$form_start_date = $edit_banner_data['start_date'] ?? ($_POST['start_date'] ?? '');
$form_end_date = $edit_banner_data['end_date'] ?? ($_POST['end_date'] ?? '');
$form_current_image = $edit_banner_data['image_url'] ?? null;

?>

<div class="admin-page-content">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>

    <?php if (isset($message) && is_array($message)): ?>
        <div class="message <?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div>
    <?php endif; ?>
    <?php if (isset($errorMessageForPage)): ?>
         <div class="message error"><?php echo $errorMessageForPage; ?></div>
    <?php endif; ?>

    <div class="add-banner-form" style="margin-bottom: 30px; padding: 20px; background-color:#f9f9f9; border:1px solid #eee; border-radius:8px;">
        <h3><?php echo $edit_banner_data ? 'ویرایش بنر: ' . htmlspecialchars($edit_banner_data['name']) : 'افزودن بنر جدید'; ?></h3>
        <form action="manage_ads.php<?php echo $edit_banner_data ? '?edit_banner_id=' . $edit_banner_data['id'] : ''; ?>" method="post" enctype="multipart/form-data">
            <?php if ($edit_banner_data): ?>
                <input type="hidden" name="banner_id_edit" value="<?php echo htmlspecialchars($edit_banner_data['id']); ?>">
                <input type="hidden" name="current_image_url" value="<?php echo htmlspecialchars($edit_banner_data['image_url']); ?>">
            <?php endif; ?>
            <div class="form-group">
                <label for="banner_name">نام بنر (برای شناسایی):</label>
                <input type="text" id="banner_name" name="name" value="<?php echo $form_name; ?>" required>
            </div>
            <div class="form-group">
                <label for="banner_image">فایل تصویر بنر (JPG, PNG, GIF, WEBP - حداکثر 2MB):</label>
                <input type="file" id="banner_image" name="banner_image" accept="image/jpeg,image/png,image/gif,image/webp">
                <?php if ($edit_banner_data && $form_current_image): ?>
                    <p><small>تصویر فعلی: <img src="<?php echo htmlspecialchars($form_current_image); ?>" alt="تصویر فعلی" style="max-width: 200px; max-height: 60px; vertical-align: middle; margin-top:5px;"></small></p>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="banner_target_url">لینک مقصد (با http یا https):</label>
                <input type="url" id="banner_target_url" name="target_url" value="<?php echo $form_target_url; ?>" placeholder="https://example.com" required style="direction:ltr; text-align:left;">
            </div>
            <div class="form-row" style="display:flex; gap:20px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label for="banner_position">موقعیت نمایش:</label>
                    <select name="position" id="banner_position" required>
                        <option value="top_banner" <?php if ($form_position === 'top_banner') echo 'selected'; ?>>بنر بالا (زیر عنوان صفحه اصلی)</option>
                        <option value="bottom_banner" <?php if ($form_position === 'bottom_banner') echo 'selected'; ?>>بنر پایین (بالای فوتر صفحه اصلی)</option>
                        </select>
                </div>
                <div class="form-group" style="flex-basis: 150px;">
                    <label for="banner_is_active">وضعیت:</label>
                    <select name="is_active" id="banner_is_active">
                        <option value="1" <?php if ($form_is_active == 1) echo 'selected'; ?>>فعال</option>
                        <option value="0" <?php if ($form_is_active == 0) echo 'selected'; ?>>غیرفعال</option>
                    </select>
                </div>
            </div>
            <div class="form-row" style="display:flex; gap:20px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label for="banner_start_date">تاریخ شروع نمایش (اختیاری):</label>
                    <input type="date" id="banner_start_date" name="start_date" value="<?php echo htmlspecialchars($form_start_date); ?>" style="direction:ltr; text-align:right;">
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label for="banner_end_date">تاریخ پایان نمایش (اختیاری):</label>
                    <input type="date" id="banner_end_date" name="end_date" value="<?php echo htmlspecialchars($form_end_date); ?>" style="direction:ltr; text-align:right;">
                </div>
            </div>
            <button type="submit" name="<?php echo $edit_banner_data ? 'edit_banner' : 'add_banner'; ?>" class="btn-submit">
                <?php echo $edit_banner_data ? 'ذخیره تغییرات بنر' : 'افزودن بنر'; ?>
            </button>
            <?php if ($edit_banner_data): ?>
                <a href="manage_ads.php" class="btn-cancel" style="margin-right:10px; background-color:#6c757d; color:white; padding: 10px 15px; text-decoration:none; border-radius:5px; font-size:1em;display:inline-block;">انصراف</a>
            <?php endif; ?>
        </form>
    </div>

    <h3>لیست بنرهای موجود</h3>
    <?php if (!empty($banners)): ?>
        <div class="table-responsive-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>نام</th>
                        <th>تصویر</th>
                        <th>لینک مقصد</th>
                        <th>موقعیت</th>
                        <th>وضعیت</th>
                        <th>تاریخ شروع</th>
                        <th>تاریخ پایان</th>
                        <th>کلیک‌ها</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($banners as $banner): ?>
                        <tr class="<?php echo !$banner['is_active'] ? 'inactive-row-subscriber' : ''; ?>">
                            <td><?php echo htmlspecialchars($banner['id']); ?></td>
                            <td><?php echo htmlspecialchars($banner['name']); ?></td>
                            <td><img src="<?php echo htmlspecialchars($banner['image_url']); ?>" alt="<?php echo htmlspecialchars($banner['name']); ?>" style="max-width:150px; max-height:50px;"></td>
                            <td><a href="<?php echo htmlspecialchars($banner['target_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(mb_substr($banner['target_url'],0,30).'...'); ?></a></td>
                            <td><?php echo htmlspecialchars($banner['position']); ?></td>
                            <td><span class="<?php echo $banner['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $banner['is_active'] ? 'فعال' : 'غیرفعال'; ?></span></td>
                            <td><?php echo !empty($banner['start_date']) ? htmlspecialchars($banner['start_date']) : '-'; ?></td>
                            <td><?php echo !empty($banner['end_date']) ? htmlspecialchars($banner['end_date']) : '-'; ?></td>
                            <td><?php echo number_format($banner['clicks']); ?></td>
                            <td class="actions">
                                <a href="manage_ads.php?edit_banner_id=<?php echo $banner['id']; ?>" class="btn-edit">ویرایش</a>
                                <form action="manage_ads.php" method="post" style="display:inline;" onsubmit="return confirm('آیا از حذف این بنر مطمئن هستید؟');">
                                    <input type="hidden" name="banner_id_to_delete" value="<?php echo $banner['id']; ?>">
                                    <button type="submit" name="delete_banner" class="btn-delete">حذف</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>هنوز هیچ بنری ثبت نشده است.</p>
    <?php endif; ?>
</div>

<?php
// جاوااسکریپت برای Date Picker های تاریخ شروع و پایان بنر (اگر لازم است)
// می‌توانید از همان KamaDatepicker استفاده کنید یا date input خود مرورگر
// $page_specific_js_footer_content = "<script> /* ... KamaDatepicker for banner_start_date and banner_end_date ... */ </script>";
if (file_exists(__DIR__ . '/layouts/_footer.php')) {
    // if (!empty($page_specific_js_footer_content)) echo $page_specific_js_footer_content;
    require_once __DIR__ . '/layouts/_footer.php';
}
?>