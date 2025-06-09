<?php
// /public_html/admin/manage_keywords.php

$pageTitle = "مدیریت آدرس‌های مشترک";
if (file_exists(__DIR__ . '/layouts/_header.php')) {
    require_once __DIR__ . '/layouts/_header.php';
} else { die("خطای بارگذاری: فایل لایوت هدر ادمین یافت نشد."); }
// $db, $jdf_loaded_for_admin_layout, $loggedInAdminUsername, site_url() باید در دسترس باشند

$subscriber_id = filter_input(INPUT_GET, 'subscriber_id', FILTER_VALIDATE_INT);
$subscriber_info = null;
$current_keywords = []; // آدرس‌های ثبت شده برای این مشترک
$message = $_SESSION['keyword_message'] ?? null;
if ($message) unset($_SESSION['keyword_message']);
$errorMessageForPage = null;

if (!$subscriber_id) {
    $errorMessageForPage = "شناسه مشترک برای مدیریت آدرس‌ها مشخص نشده است.";
} else {
    try {
        if (!isset($db) || !$db instanceof PDO) { throw new Exception("اتصال به پایگاه داده برقرار نیست."); }

        // دریافت اطلاعات مشترک
        $stmtSub = $db->prepare("SELECT id, name, phone FROM subscribers WHERE id = ?");
        $stmtSub->execute([$subscriber_id]);
        $subscriber_info = $stmtSub->fetch(PDO::FETCH_ASSOC);

        if (!$subscriber_info) {
            $errorMessageForPage = "مشترک با شناسه " . htmlspecialchars($subscriber_id) . " یافت نشد.";
        } else {
            $pageTitle = "مدیریت آدرس‌ها برای: " . htmlspecialchars($subscriber_info['name']);

            // پردازش حذف کلمه/آدرس کلیدی
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_keyword']) && isset($_POST['keyword_id_to_delete'])) {
                $keyword_id = (int)$_POST['keyword_id_to_delete'];
                $stmtDel = $db->prepare("DELETE FROM subscriber_address_keywords WHERE id = ? AND subscriber_id = ?");
                if ($stmtDel->execute([$keyword_id, $subscriber_id])) {
                    $_SESSION['keyword_message'] = ['type' => 'success', 'text' => 'آدرس با موفقیت حذف شد.'];
                } else {
                    $_SESSION['keyword_message'] = ['type' => 'error', 'text' => 'خطا در حذف آدرس.'];
                }
                header("Location: manage_keywords.php?subscriber_id=" . $subscriber_id); exit;
            }

            // پردازش افزودن آدرس جدید از لیست خاموشی‌ها
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_selected_address'])) {
                $selected_outage_signature = trim($_POST['selected_outage_signature'] ?? '');
                $address_title = trim($_POST['address_title'] ?? '');

                if (empty($selected_outage_signature) || empty($address_title)) {
                    $message = ['type' => 'error', 'text' => 'لطفاً یک آدرس از لیست انتخاب کرده و یک عنوان برای آن وارد کنید.'];
                } else {
                    // بررسی عدم وجود تکراری (همان مشترک، همان امضای آدرس)
                    $stmtCheck = $db->prepare("SELECT id FROM subscriber_address_keywords WHERE subscriber_id = ? AND outage_address_signature = ?");
                    $stmtCheck->execute([$subscriber_id, $selected_outage_signature]);
                    if ($stmtCheck->fetch()) {
                        $message = ['type' => 'error', 'text' => 'این آدرس قبلاً برای این مشترک با یک عنوان دیگر ثبت شده است.'];
                    } else {
                        $stmtAdd = $db->prepare("INSERT INTO subscriber_address_keywords (subscriber_id, outage_address_signature, title) VALUES (?, ?, ?)");
                        if ($stmtAdd->execute([$subscriber_id, $selected_outage_signature, $address_title])) {
                            $_SESSION['keyword_message'] = ['type' => 'success', 'text' => 'آدرس با عنوان "' . htmlspecialchars($address_title) . '" با موفقیت افزوده شد.'];
                        } else {
                            $_SESSION['keyword_message'] = ['type' => 'error', 'text' => 'خطا در افزودن آدرس.'];
                        }
                        header("Location: manage_keywords.php?subscriber_id=" . $subscriber_id); exit;
                    }
                }
            }

            // خواندن آدرس‌های کلیدی فعلی مشترک
            // ما نیاز به متن آدرس هم داریم، پس باید با outage_events_log جوین کنیم
            $stmtKeys = $db->prepare(
                "SELECT sak.id, sak.title, oel.address_text, sak.outage_address_signature 
                 FROM subscriber_address_keywords sak
                 JOIN outage_events_log oel ON sak.outage_address_signature = oel.outage_signature
                 WHERE sak.subscriber_id = ? 
                 ORDER BY sak.title"
            );
            $stmtKeys->execute([$subscriber_id]);
            $current_keywords = $stmtKeys->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $errorMessageForPage = "خطا: " . $e->getMessage();
        error_log("Manage Keywords Error for subscriber $subscriber_id: " . $e->getMessage());
    }
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
         <div class="message error"><?php echo htmlspecialchars($errorMessageForPage); ?></div>
    <?php endif; ?>

    <?php if ($subscriber_info): ?>
        <p><strong>مشترک:</strong> <?php echo htmlspecialchars($subscriber_info['name']); ?> (<?php echo htmlspecialchars($subscriber_info['phone']); ?>)</p>
        <hr>
        
        <div class="add-address-section">
            <h3>افزودن آدرس جدید برای مشترک از لیست خاموشی‌ها</h3>
            <form action="manage_keywords.php?subscriber_id=<?php echo $subscriber_id; ?>" method="post" id="addAddressForm">
                <div class="form-group">
                    <label for="outage_search">جستجو در آدرس خاموشی‌ها:</label>
                    <input type="text" id="outage_search" placeholder="بخشی از آدرس را وارد کنید..." style="margin-bottom:10px;">
                </div>
                <div class="form-group">
                    <label for="selected_outage_signature">انتخاب آدرس از لیست (فقط آدرس‌های فعال نمایش داده می‌شوند):</label>
                    <select name="selected_outage_signature" id="selected_outage_signature" style="width:100%; padding:10px; min-height: 100px; font-family:Tahoma;" size="5" required>
                        <option value="">-- ابتدا جستجو کنید یا از لیست انتخاب نمایید --</option>
                        <?php
                        // خواندن آدرس‌های منحصر به فرد از outage_events_log برای نمایش در لیست
                        // این بخش می‌تواند با AJAX بهینه شود اگر لیست خیلی طولانی است
                        // فعلاً برای سادگی، همه را یکجا می‌خوانیم
                        // $activeOutagesForSelect = [];
                        // if ($db) {
                        //    $stmtOutages = $db->query("SELECT DISTINCT outage_signature, address_text FROM outage_events_log WHERE is_currently_active = 1 ORDER BY address_text LIMIT 500"); // محدودیت برای جلوگیری از لیست خیلی طولانی
                        //    $activeOutagesForSelect = $stmtOutages->fetchAll(PDO::FETCH_ASSOC);
                        //    foreach($activeOutagesForSelect as $outage) {
                        //        echo '<option value="' . htmlspecialchars($outage['outage_signature']) . '">' . htmlspecialchars(mb_substr($outage['address_text'],0,150) . (mb_strlen($outage['address_text'])>150 ? '...' : '')) . '</option>';
                        //    }
                        // }
                        ?>
                    </select>
                    <small>لیست آدرس‌ها با تایپ در فیلد جستجو، به صورت خودکار فیلتر می‌شود.</small>
                </div>
                <div class="form-group">
                    <label for="address_title">عنوان برای این آدرس (مثال: خانه، محل کار):</label>
                    <input type="text" id="address_title" name="address_title" required>
                </div>
                <button type="submit" name="add_selected_address" class="btn-submit">افزودن آدرس انتخاب شده</button>
            </form>
        </div>

        <div class="current-keywords-section" style="margin-top: 30px;">
            <h3>آدرس‌های ثبت شده برای این مشترک</h3>
            <?php if (!empty($current_keywords)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>عنوان آدرس</th>
                            <th>متن آدرس مرتبط</th>
                            <th>امضای آدرس</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($current_keywords as $kw): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($kw['title']); ?></td>
                                <td style="max-width:400px; overflow-wrap:break-word;"><?php echo htmlspecialchars($kw['address_text']); ?></td>
                                <td><small><?php echo htmlspecialchars($kw['outage_address_signature']); ?></small></td>
                                <td class="actions">
                                    <form action="manage_keywords.php?subscriber_id=<?php echo $subscriber_id; ?>" method="post" style="display:inline;" onsubmit="return confirm('آیا از حذف این آدرس مطمئن هستید؟');">
                                        <input type="hidden" name="keyword_id_to_delete" value="<?php echo $kw['id']; ?>">
                                        <button type="submit" name="delete_keyword" class="btn-delete">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>هنوز هیچ آدرسی برای این مشترک ثبت نشده است.</p>
            <?php endif; ?>
        </div>
        <p style="margin-top:30px;"><a href="manage_subscribers.php" class="btn-info" style="background-color:#6c757d;">بازگشت به لیست مشترکین</a></p>

    <?php else: ?>
        <?php if(!$errorMessageForPage): // اگر خطای اصلی صفحه نبود، پیام عدم یافتن مشترک را نشان بده ?>
            <p>مشترک مورد نظر یافت نشد.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById('outage_search');
    const selectElement = document.getElementById('selected_outage_signature');
    let allOutageOptions = []; // برای نگهداری تمام option های اولیه

    // تابعی برای بارگذاری اولیه یا آپدیت لیست آدرس‌ها
    function populateOutageList(searchTerm = '') {
        // در یک اپلیکیشن واقعی، این بخش باید با AJAX داده‌ها را از سرور بگیرد
        // مخصوصاً اگر لیست خاموشی‌ها خیلی طولانی است.
        // برای این مثال، فرض می‌کنیم یک لیست اولیه داریم یا باید آن را بسازیم.
        // فعلاً به صورت دستی این بخش را خالی می‌گذاریم و کاربر باید از لیست موجود انتخاب کند
        // یا اینکه شما باید لیست $activeOutagesForSelect را با PHP پر کنید.

        // اگر از قبل option ها را داریم، آنها را فیلتر می‌کنیم
        if (allOutageOptions.length > 0) {
            selectElement.innerHTML = ''; // پاک کردن گزینه‌های فعلی
            let hasResults = false;
            allOutageOptions.forEach(option => {
                if (searchTerm === '' || option.text.toUpperCase().includes(searchTerm.toUpperCase())) {
                    selectElement.add(new Option(option.text, option.value));
                    hasResults = true;
                }
            });
            if (!hasResults && searchTerm !== '') {
                 selectElement.add(new Option('نتیجه‌ای برای جستجوی شما یافت نشد.', ''));
            } else if (!hasResults && searchTerm === '') {
                 selectElement.add(new Option('-- لیستی برای انتخاب وجود ندارد --', ''));
            }
        } else {
            // اگر allOutageOptions خالی است، سعی کن از option های اولیه در HTML بخوان (اگر با PHP پر شده)
            // این کد برای زمانی است که لیست اولیه توسط PHP پر شده باشد.
            if(selectElement.options.length > 1 && selectElement.options[0].value === "") { // option اول placeholder است
                 for(let i=1; i < selectElement.options.length; i++) {
                     allOutageOptions.push({value: selectElement.options[i].value, text: selectElement.options[i].text});
                 }
                 // پس از خواندن، دوباره فیلتر کن اگر متنی در جستجو هست
                 if(searchTerm !== '') populateOutageList(searchTerm);
            } else {
                // در اینجا باید با AJAX لیست آدرس‌ها را از سرور بگیریم
                // فعلاً یک پیام می‌گذاریم
                 selectElement.innerHTML = '<option value="">-- برای جستجو تایپ کنید یا لیست با AJAX بارگذاری شود --</option>';
            }
        }
    }
    
    // برای اینکه لیست اولیه آدرس‌ها از سرور بیاید (اگر خیلی زیاد نیستند)
    // این کد PHP باید در بالا، جایی که $activeOutagesForSelect را کامنت کردم، فعال شود.
    // و سپس اینجا آن را مقداردهی اولیه کنیم.
    <?php
        // $jsOutageOptions = [];
        // if ($db && $subscriber_info) { // فقط اگر مشترک معتبر است
        //    $stmtOutagesJS = $db->query("SELECT DISTINCT outage_signature, address_text FROM outage_events_log WHERE is_currently_active = 1 ORDER BY address_text LIMIT 300");
        //    foreach($stmtOutagesJS->fetchAll(PDO::FETCH_ASSOC) as $outage) {
        //        $jsOutageOptions[] = ['value' => $outage['outage_signature'], 'text' => mb_substr($outage['address_text'],0,120) . (mb_strlen($outage['address_text'])>120 ? '...' : '')];
        //    }
        // }
        // echo "allOutageOptions = " . json_encode($jsOutageOptions) . ";\n";
    ?>
    // populateOutageList(); // بارگذاری اولیه لیست

    // راه حل بهتر: استفاده از یک Endpoint AJAX برای جستجوی آدرس‌ها
    if (searchInput && selectElement) {
        let debounceTimer;
        searchInput.addEventListener('keyup', function() {
            clearTimeout(debounceTimer);
            const searchTerm = this.value.trim();
            if (searchTerm.length < 3 && searchTerm.length !== 0) { // فقط اگر حداقل ۳ کاراکتر وارد شده جستجو کن
                selectElement.innerHTML = '<option value="">-- برای جستجو حداقل ۳ کاراکتر وارد کنید --</option>';
                return;
            }
            if (searchTerm.length === 0) {
                 selectElement.innerHTML = '<option value="">-- ابتدا جستجو کنید یا از لیست انتخاب نمایید --</option>';
                allOutageOptions = []; // برای جستجوی بعدی دوباره از سرور بخواند
                return;
            }

            debounceTimer = setTimeout(() => {
                selectElement.innerHTML = '<option value="">در حال جستجو...</option>';
                // اینجا باید یک درخواست AJAX به سرور برای گرفتن آدرس‌های مطابق با searchTerm ارسال شود
                // مثال: fetch('ajax_search_outages.php?term=' + encodeURIComponent(searchTerm))
                // و سپس selectElement را با نتایج پر کنید.
                // برای این مثال، فعلاً فیلتر سمت کلاینت را روی یک لیست فرضی (اگر با PHP پر شده بود) انجام می‌دهیم
                // که در این نسخه، چون لیست اولیه با PHP پر نمی‌شود، این بخش کار نخواهد کرد مگر اینکه با AJAX جایگزین شود.
                // populateOutageList(searchTerm); // این تابع باید برای AJAX تغییر کند

                // --- شروع بخش AJAX فرضی (نیاز به ساخت ajax_search_outages.php دارد) ---
                fetch(`ajax_search_outages.php?term=${encodeURIComponent(searchTerm)}&subscriber_id=<?php echo $subscriber_id; ?>`)
                    .then(response => response.json())
                    .then(data => {
                        selectElement.innerHTML = ''; // پاک کردن
                        if (data.success && data.outages && data.outages.length > 0) {
                            allOutageOptions = []; // بازنشانی برای جستجوی بعدی
                            data.outages.forEach(outage => {
                                let displayText = outage.address_text;
                                if (displayText.length > 120) displayText = displayText.substring(0, 120) + "...";
                                const option = new Option(displayText + (outage.is_already_added ? ' ( قبلاً برای این مشترک افزوده شده)' : ''), outage.outage_signature);
                                if(outage.is_already_added) option.disabled = true; // غیرفعال کردن اگر قبلا اضافه شده
                                selectElement.add(option);
                                allOutageOptions.push({value: outage.outage_signature, text: displayText}); // برای فیلترهای بعدی اگر لازم شد
                            });
                        } else if (data.message) {
                            selectElement.add(new Option(data.message, ''));
                        } else {
                            selectElement.add(new Option('نتیجه‌ای یافت نشد.', ''));
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching outage addresses:', error);
                        selectElement.innerHTML = '<option value="">خطا در جستجوی آدرس‌ها</option>';
                    });
                // --- پایان بخش AJAX فرضی ---

            }, 500); // تاخیر ۵۰۰ میلی‌ثانیه برای جلوگیری از درخواست‌های مکرر هنگام تایپ
        });
    }
});
</script>
<?php
if (file_exists(__DIR__ . '/layouts/_footer.php')) {
    require_once __DIR__ . '/layouts/_footer.php';
}
?>