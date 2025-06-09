<?php
// /public_html/admin/ajax_search_outages.php
// این فایل فقط باید JSON برگرداند و هیچ HTML دیگری نباید echo کند.

if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} else { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Config error']); exit; }

if (file_exists(dirname(__DIR__) . '/database.php')) {
    require_once dirname(__DIR__) . '/database.php';
} else { http_response_code(500); echo json_encode(['success' => false, 'message' => 'DB error']); exit; }

// بررسی لاگین ادمین (بسیار مهم برای امنیت)
// session_start() باید توسط config.php انجام شده باشد
if (!isset($_SESSION['admin_user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیر مجاز']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$response = ['success' => false, 'outages' => [], 'message' => 'No term provided'];

$term = trim($_GET['term'] ?? '');
$subscriber_id_for_check = filter_input(INPUT_GET, 'subscriber_id', FILTER_VALIDATE_INT);


if (mb_strlen($term) >= 3) { // حداقل ۳ کاراکتر برای جستجو
    try {
        $db = Database::getInstance()->getConnection();
        
        // خواندن امضاهای آدرس‌هایی که قبلاً برای این مشترک ثبت شده‌اند
        $existing_signatures_for_subscriber = [];
        if ($subscriber_id_for_check) {
            $stmtExisting = $db->prepare("SELECT outage_address_signature FROM subscriber_address_keywords WHERE subscriber_id = ?");
            $stmtExisting->execute([$subscriber_id_for_check]);
            $existing_signatures_for_subscriber = $stmtExisting->fetchAll(PDO::FETCH_COLUMN);
        }

        // جستجو در آدرس‌های فعال
        $searchTermSql = '%' . $term . '%';
        $stmt = $db->prepare(
            "SELECT DISTINCT outage_signature, address_text 
             FROM outage_events_log 
             WHERE is_currently_active = 1 AND address_text LIKE ? 
             ORDER BY 
                CASE 
                    WHEN address_text LIKE ? THEN 1 -- شروع با کلمه جستجو
                    WHEN address_text LIKE ? THEN 2 -- وجود کلمه در ابتدا یا وسط با فاصله
                    ELSE 3
                END,
                address_text
             LIMIT 30"
        );
        // برای جستجوی دقیق‌تر، می‌توانید از Full-Text Search دیتابیس استفاده کنید اگر فعال است
        $stmt->execute([$searchTermSql, $term . '%', '% ' . $term . '%']);
        $outages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($outages) {
            $response['success'] = true;
            foreach($outages as $outage) {
                $response['outages'][] = [
                    'outage_signature' => $outage['outage_signature'],
                    'address_text' => $outage['address_text'],
                    'is_already_added' => in_array($outage['outage_signature'], $existing_signatures_for_subscriber)
                ];
            }
            $response['message'] = count($outages) . ' نتیجه یافت شد.';
        } else {
            $response['success'] = true; // جستجو انجام شد اما نتیجه‌ای نداشت
            $response['message'] = 'هیچ آدرسی مطابق با جستجوی شما یافت نشد.';
        }

    } catch (Exception $e) {
        error_log("AJAX Search Outages Error: " . $e->getMessage());
        $response['message'] = 'خطا در پردازش جستجو.';
        if(defined('DEBUG_MODE') && DEBUG_MODE) $response['debug_error'] = $e->getMessage();
    }
} elseif (mb_strlen($term) > 0) {
    $response['message'] = 'برای جستجو حداقل ۳ کاراکتر وارد کنید.';
}

echo json_encode($response);
exit;
?>