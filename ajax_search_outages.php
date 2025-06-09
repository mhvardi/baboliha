<?php
// ajax_search_outages.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

// --- JDF Library Loading ---
$jdf_loaded_successfully_ajax = false;
if (defined('ROOT_PATH')) {
    $jdf_path_ajax = ROOT_PATH . '/lib/jdf.php';
    if (file_exists($jdf_path_ajax)) {
        require_once $jdf_path_ajax;
        if (function_exists('jdate') && function_exists('jalali_to_gregorian') && function_exists('jcheckdate')) {
            $jdf_loaded_successfully_ajax = true;
        } else { error_log("AJAX_SEARCH_OUTAGES WARNING: jdf.php loaded but key functions are missing from " . $jdf_path_ajax); }
    } else { error_log("AJAX_SEARCH_OUTAGES ERROR: jdf.php not found at " . $jdf_path_ajax); }
} else { error_log("AJAX_SEARCH_OUTAGES ERROR: ROOT_PATH constant not defined in config.php."); }
if (!$jdf_loaded_successfully_ajax) {
    if (!function_exists('jdate')) { function jdate($format, $timestamp = '', $none = '', $time_zone = 'Asia/Tehran', $tr_num = 'fa') { date_default_timezone_set($time_zone); return date($format, ($timestamp === '' ? time() : $timestamp)); } }
    if (!function_exists('jalali_to_gregorian')) { function jalali_to_gregorian($j_y, $j_m, $j_d, $mod = '') { return [$j_y, $j_m, $j_d]; } }
    if (!function_exists('jcheckdate')) { function jcheckdate($jm, $jd, $jy) { return checkdate((int)$jm, (int)$jd, (int)$jy); } }
}
// --- End JDF Library Loading ---

function updateOutdatedOutagesAjax(PDO $dbConnection, bool $isJdfActuallyLoaded) {
    if (!$isJdfActuallyLoaded || !function_exists('jdate') || !function_exists('jalali_to_gregorian') || !function_exists('jcheckdate')) { error_log("updateOutdatedOutagesAjax: Critical JDF functions are not available. Skipping update."); return; }
    date_default_timezone_set('Asia/Tehran'); $nowTehran = new DateTime("now");
    $sqlSelectExpired = "SELECT id, tarikh, ta_saat FROM outage_events_log WHERE is_currently_active = 1";
    $stmtSelect = $dbConnection->query($sqlSelectExpired); $expired_ids = [];
    while ($row = $stmtSelect->fetch(PDO::FETCH_ASSOC)) {
        $tarikh = $row['tarikh']; $ta_saat = $row['ta_saat'];
        if (empty($tarikh) || empty($ta_saat) || !preg_match('/^(\d{2}|\d{4})\/\d{1,2}\/\d{1,2}$/', $tarikh) || !preg_match('/^\d{1,2}:\d{1,2}(:\d{1,2})?$/', $ta_saat)) { continue; }
        list($jy_str, $jm_str, $jd_str) = explode('/', $tarikh);
        $jy = (int)$jy_str; $jm = (int)$jm_str; $jd = (int)$jd_str;
        if ($jy < 100 && $jy >= 0) { $jy += 1400; } if (!jcheckdate($jm, $jd, $jy)) { continue; }
        list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
        $time_parts = explode(':', $ta_saat); $hour = (int)($time_parts[0] ?? 0); $minute = (int)($time_parts[1] ?? 0);
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) { continue; }
        $gregorian_date_str = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        try { $outageEndDateTime = new DateTime($gregorian_date_str); $outageEndDateTime->setTime($hour, $minute); } catch (Exception $e) { continue; }
        if ($nowTehran > $outageEndDateTime) { $expired_ids[] = $row['id']; }
    }
    if (!empty($expired_ids)) {
        $placeholders = implode(',', array_fill(0, count($expired_ids), '?'));
        $sqlUpdate = "UPDATE outage_events_log SET is_currently_active = 0 WHERE id IN ($placeholders)";
        $stmtUpdate = $dbConnection->prepare($sqlUpdate);
        try { $stmtUpdate->execute($expired_ids); } catch (PDOException $e) { error_log("AJAX Update Outages Error: " . $e->getMessage()); }
    }
}

$response = ['error' => null, 'outages' => [], 'total_pages' => 1];
$items_per_page = 20;
$current_page = isset($_POST['page']) && is_numeric($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;
$search_city = isset($_POST['city']) ? trim($_POST['city']) : '';
$search_address = isset($_POST['search']) ? trim($_POST['search']) : '';
$guest_identifier = isset($_POST['guest_id']) ? trim($_POST['guest_id']) : '';

try {
    $dbInstance = Database::getInstance(); $db = $dbInstance->getConnection();
    updateOutdatedOutagesAjax($db, $jdf_loaded_successfully_ajax);
    $area_codes = [
        'AMOL_CITY_' => 'شهرستان آمل', 'BABOL_CITY' => 'بابل', 'BABOL_CITY_OVERALL' => 'سراسری بابل',
        'BABOLSAR_C' => 'بابلسر', 'BEHSHAHR_C' => 'بهشهر', 'FEREIDON_K' => 'فریدونکنار',
        'GALUGAH_CI' => 'گلوگاه', 'GHAEMSHAHR' => 'قائمشهر', 'JOUYBAR_CI' => 'جویبار',
        'MIANDEHROO' => 'میاندرود', 'NEKA_CITY_' => 'نکا', 'SARI_CITY_' => 'ساری',
        'SAVADKOOH_' => 'سوادکوه', 'SIMORG_CIT' => 'سیمرغ', 'NORTH_SAVA' => 'سوادکوه شمالی'
    ];
    $params_count = []; $params_data = [];
    $sql_base_from = "FROM outage_events_log o ";
    $sql_join_guest_pinned = "LEFT JOIN guest_pinned_outages gpo ON o.address_normalized_hash = gpo.address_hash AND gpo.guest_identifier = :guest_identifier_for_join ";
    $params_data[':guest_identifier_for_join'] = $guest_identifier ?: '';
    $sql_where_conditions = "WHERE o.is_currently_active = 1 ";
    if (!empty($search_city)) { $sql_where_conditions .= " AND o.area_code_source = :city "; $params_count[':city'] = $search_city; $params_data[':city'] = $search_city; }
    if (!empty($search_address)) { $sql_where_conditions .= " AND o.address_text LIKE :address "; $params_count[':address'] = '%' . $search_address . '%'; $params_data[':address'] = '%' . $search_address . '%'; }
    $sql_count_simplified = "SELECT COUNT(o.id) " . $sql_base_from . $sql_where_conditions;
    $stmtCount = $db->prepare($sql_count_simplified); $stmtCount->execute($params_count);
    $total_items = $stmtCount->fetchColumn();
    $response['total_pages'] = $total_items > 0 ? ceil($total_items / $items_per_page) : 1;
    $sql_select_fields = "SELECT o.id, o.outage_signature, o.tarikh, o.az_saat, o.ta_saat, o.address_text, o.address_normalized_hash, o.area_code_source, CASE WHEN gpo.address_hash IS NOT NULL THEN 1 ELSE 0 END AS is_sql_guest_pinned ";
    $sql_order = "ORDER BY is_sql_guest_pinned DESC, CASE WHEN o.area_code_source = 'BABOL_CITY_OVERALL' OR o.area_code_source = 'BABOL_CITY' THEN 0 ELSE 1 END, o.tarikh, o.az_saat ";
    $sql_limit = "LIMIT :offset, :limit";
    $sql = $sql_select_fields . $sql_base_from . $sql_join_guest_pinned . $sql_where_conditions . $sql_order . $sql_limit;
    $stmtOutages = $db->prepare($sql);
    foreach ($params_data as $key => $value) { $stmtOutages->bindValue($key, $value); }
    $stmtOutages->bindValue(':offset', $offset, PDO::PARAM_INT); $stmtOutages->bindValue(':limit', $items_per_page, PDO::PARAM_INT);

    if ($stmtOutages->execute()) {
        $outagesFromDb = $stmtOutages->fetchAll(PDO::FETCH_ASSOC);
        $guest_pinned_items_on_page = []; $php_pinned_items_on_page = []; $regular_items_on_page = [];
        $today_jalali_str = ''; $tomorrow_jalali_str = '';
        if ($jdf_loaded_successfully_ajax) {
            date_default_timezone_set('Asia/Tehran');
            $today_jalali_str = jdate('Y/m/d'); $today_parts = explode('/', $today_jalali_str); $today_jalali_str = sprintf('%04d/%02d/%02d', (int)$today_parts[0], (int)$today_parts[1], (int)$today_parts[2]);
            $tomorrow_jalali_str = jdate('Y/m/d', time() + 86400); $tomorrow_parts = explode('/', $tomorrow_jalali_str); $tomorrow_jalali_str = sprintf('%04d/%02d/%02d', (int)$tomorrow_parts[0], (int)$tomorrow_parts[1], (int)$tomorrow_parts[2]);
        }
        global $pinnedAddressKeywords;
        foreach ($outagesFromDb as $row) {
            $isGuestPinnedBySql = (bool)$row['is_sql_guest_pinned']; $isPhpKeyPinned = false;
            if (!$isGuestPinnedBySql && isset($row['address_text']) && !empty($pinnedAddressKeywords) && is_array($pinnedAddressKeywords)) {
                foreach ($pinnedAddressKeywords as $pinKeyword) { if (mb_strpos(preg_replace('/\s+/', '', mb_strtolower($row['address_text'])), preg_replace('/\s+/', '', mb_strtolower($pinKeyword))) !== false) { $isPhpKeyPinned = true; break; } }
            }
            $tarikh_val = $row['tarikh'] ?? 'نامشخص';
            $tarikh_display = htmlspecialchars($tarikh_val); // htmlspecialchars for base date
            if ($jdf_loaded_successfully_ajax && !empty($tarikh_val) && $tarikh_val !== 'نامشخص' && strpos($tarikh_val, '/') !== false) {
                $tarikh_parts = explode('/', $tarikh_val);
                 if (count($tarikh_parts) === 3) {
                    $jy = (int)$tarikh_parts[0]; if ($jy < 100 && $jy >= 0) $jy += 1400;
                    $normalized_tarikh_row = sprintf('%04d/%02d/%02d', $jy, (int)$tarikh_parts[1], (int)$tarikh_parts[2]);
                    if ($normalized_tarikh_row === $today_jalali_str) { $tarikh_display .= ' <span class="date-meta">(امروز)</span>'; }
                    elseif ($normalized_tarikh_row === $tomorrow_jalali_str) { $tarikh_display .= ' <span class="date-meta">(فردا)</span>'; }
                }
            }
            $city_name_raw = isset($row['area_code_source'], $area_codes[$row['area_code_source']]) ? $area_codes[$row['area_code_source']] : ($row['area_code_source'] ?? '');
            $address_text_prepared = nl2br(htmlspecialchars($row['address_text'] ?? 'آدرس نامشخص'));
            $outage_item = [
                'tarikh_display' => $tarikh_display,
                'az_saat' => htmlspecialchars($row['az_saat'] ?? ''),
                'ta_saat' => htmlspecialchars($row['ta_saat'] ?? ''),
                'address_raw' => $address_text_prepared,
                'signature' => htmlspecialchars($row['outage_signature'] ?? md5(rand().time().($row['address_text'] ?? ''))),
                'address_hash' => htmlspecialchars($row['address_normalized_hash'] ?? ''),
                'is_guest_pinned' => $isGuestPinnedBySql,
                'is_php_pinned' => $isPhpKeyPinned,
                'area_code_source_name' => htmlspecialchars($city_name_raw ?: 'نامشخص'),
            ];
            if ($isGuestPinnedBySql) { $guest_pinned_items_on_page[] = $outage_item; }
            elseif ($isPhpKeyPinned) { $php_pinned_items_on_page[] = $outage_item; }
            else { $regular_items_on_page[] = $outage_item; }
        }
        $response['outages'] = array_merge($guest_pinned_items_on_page, $php_pinned_items_on_page, $regular_items_on_page);
    } else { $response['error'] = "خطا در دریافت لیست خاموشی‌ها."; error_log("AJAX_SEARCH_OUTAGES DB Error: " . print_r($stmtOutages->errorInfo(), true)); }
} catch (Exception $e) { $response['error'] = "خطای سیستم: " . htmlspecialchars($e->getMessage()); error_log("AJAX_SEARCH_OUTAGES Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString()); }
echo json_encode($response);
?>