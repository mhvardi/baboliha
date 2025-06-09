<?php
// /public_html/public_ajax_get_outages.php

header('Content-Type: application/json; charset=utf-8');

$base_path = __DIR__;
$response_data = ['success' => false, 'outages' => [], 'total_pages' => 1, 'current_page' => 1, 'message' => 'خطای اولیه در بارگذاری اطلاعات ایجکس.', 'debug_info' => null];

if (file_exists($base_path . '/config.php')) {
    require_once $base_path . '/config.php';
} else {
    error_log("AJAX_FATAL: config.php not found.");
    $response_data['message'] = 'خطای پیکربندی سرور (AJAX_CFG).';
    echo json_encode($response_data);
    exit;
}

if (defined('DEBUG_MODE') && DEBUG_MODE) {
    ini_set('display_errors', 1); error_reporting(E_ALL); $response_data['debug_info'] = [];
} else {
    ini_set('display_errors', 0); ini_set('log_errors', 1); error_reporting(0);
}

if (file_exists($base_path . '/database.php')) {
    if (!class_exists('Database', false)) { require_once $base_path . '/database.php'; }
} else {
    error_log("AJAX_FATAL: database.php not found.");
    $response_data['message'] = 'خطای پیکربندی پایگاه داده (AJAX_DBF).';
    echo json_encode($response_data);
    exit;
}

$jdf_loaded_successfully_ajax_outage = false;
if (defined('ROOT_PATH') && file_exists(ROOT_PATH . '/lib/jdf.php')) {
    if (!function_exists('jdate')) { require_once ROOT_PATH . '/lib/jdf.php'; }
    if (function_exists('jdate') && function_exists('jalali_to_gregorian') && function_exists('jcheckdate')) {
        $jdf_loaded_successfully_ajax_outage = true;
    } else { error_log("AJAX_OUTAGE: jdf.php loaded but key functions missing."); }
} else { error_log("AJAX_OUTAGE: ROOT_PATH not defined or jdf.php not found."); }

if (!$jdf_loaded_successfully_ajax_outage) {
    if (!function_exists('jdate')) { function jdate($f,$t='',$n='',$tz='Asia/Tehran',$tn='fa'){ date_default_timezone_set($tz); return date($f,($t===''?time():(is_numeric($t)?$t:strtotime($t))));} }
    if (!function_exists('jalali_to_gregorian')) { function jalali_to_gregorian($j_y, $j_m, $j_d, $mod = '') { return [(int)$j_y, (int)$j_m, (int)$j_d]; } }
    if (!function_exists('jcheckdate')) { function jcheckdate($m, $d, $y) { return checkdate((int)$m, (int)$d, (int)$y); } }
    if (is_array($response_data['debug_info'])) { $response_data['debug_info']['jdf_status_ajax'] = 'JDF fallbacks used.'; }
}

global $pinnedAddressKeywords;
if (!isset($pinnedAddressKeywords) || !is_array($pinnedAddressKeywords)) { $pinnedAddressKeywords = []; }

// Function to update outdated outages - (کد این تابع از پاسخ قبلی کپی شود و از call_user_func استفاده کند)
function updateOutdatedOutagesPublicAjax(PDO $dbConnection, bool $isJdfLoaded, &$debug_info_ref_ajax) {
    // ... کد کامل تابع updateOutdatedOutagesPublicAjax از پاسخ قبلی ...
    if (!$isJdfLoaded || !function_exists('jdate') || !function_exists('jalali_to_gregorian') || !function_exists('jcheckdate')) {
        error_log("updateOutdatedOutagesPublicAjax (AJAX): JDF functions are not available for date processing. Skipping update.");
        if (is_array($debug_info_ref_ajax)) $debug_info_ref_ajax['update_outdated_error'] = 'JDF missing for date conversion in update function.';
        return 0;
    }
    if (date_default_timezone_get() !== 'Asia/Tehran') { date_default_timezone_set('Asia/Tehran'); }
    $nowTehran = new DateTime("now");
    $updated_count = 0;
    $sqlSelectExpired = "SELECT id, tarikh, ta_saat FROM outage_events_log WHERE is_currently_active = 1";
    try {
        $stmtSelect = $dbConnection->query($sqlSelectExpired);
        if (!$stmtSelect) {
            error_log("updateOutdatedOutagesPublicAjax: Failed to execute select query. DB Error: " . print_r($dbConnection->errorInfo(), true));
            if (is_array($debug_info_ref_ajax)) $debug_info_ref_ajax['update_outdated_error'] = 'DB select query failed.';
            return 0;
        }
        $expired_ids = [];
        while ($row = $stmtSelect->fetch(PDO::FETCH_ASSOC)) {
            $tarikh = $row['tarikh']; $ta_saat = $row['ta_saat'];
            if (empty($tarikh) || empty($ta_saat) || !preg_match('/^(\d{2}|\d{4})\/\d{1,2}\/\d{1,2}$/', $tarikh) || !preg_match('/^\d{1,2}:\d{1,2}(:\d{1,2})?$/', $ta_saat)) { continue; }
            list($jy_str, $jm_str, $jd_str) = explode('/', $tarikh);
            $jy = (int)$jy_str; $jm = (int)$jm_str; $jd = (int)$jd_str;
            if ($jy < 100 && $jy >= 0) { $jy += 1400; }
            if (!call_user_func('jcheckdate', $jm, $jd, $jy)) { continue; }
            $gregorian_date_array = call_user_func('jalali_to_gregorian', $jy, $jm, $jd);
            if (!is_array($gregorian_date_array) || count($gregorian_date_array) !== 3) continue;
            list($gy, $gm, $gd) = $gregorian_date_array;
            $time_parts = explode(':', $ta_saat);
            $hour = (isset($time_parts[0]) && is_numeric($time_parts[0])) ? (int)$time_parts[0] : 0;
            $minute = (isset($time_parts[1]) && is_numeric($time_parts[1])) ? (int)$time_parts[1] : 0;
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) { continue; }
            $gregorian_date_str_for_dt = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
            try { $outageEndDateTime = new DateTime($gregorian_date_str_for_dt . ' ' . sprintf('%02d:%02d:00', $hour, $minute)); }
            catch (Exception $e) { error_log("updateOutdated (AJAX): DateTime exception for {$gregorian_date_str_for_dt} {$hour}:{$minute}. Error: " . $e->getMessage()); continue; }
            if ($nowTehran > $outageEndDateTime) { $expired_ids[] = (int)$row['id']; }
        }
        $stmtSelect->closeCursor();
        if (!empty($expired_ids)) {
            $placeholders = implode(',', array_fill(0, count($expired_ids), '?'));
            $sqlUpdate = "UPDATE outage_events_log SET is_currently_active = 0 WHERE id IN ($placeholders)";
            $stmtUpdate = $dbConnection->prepare($sqlUpdate);
            if ($stmtUpdate) {
                try { $stmtUpdate->execute($expired_ids); $updated_count = $stmtUpdate->rowCount(); }
                catch (PDOException $e_update) { error_log("AJAX Public Update Outages Execute Error: " . $e_update->getMessage() . " SQL: " . $sqlUpdate); }
            } else { error_log("updateOutdated (AJAX): Failed to prepare update. DB Error: " . print_r($dbConnection->errorInfo(), true));}
        }
    } catch (PDOException $e_select) {
        error_log("updateOutdatedOutagesPublicAjax: PDOException during select. " . $e_select->getMessage());
        if (is_array($debug_info_ref_ajax)) $debug_info_ref_ajax['update_outdated_error'] = 'PDOException: ' . $e_select->getMessage();
        return 0;
    }
    if (is_array($debug_info_ref_ajax)) $debug_info_ref_ajax['outdated_updated_count_in_func_ajax'] = $updated_count;
    return $updated_count;
}


$items_per_page_ajax_script = (defined('ITEMS_PER_PAGE_PUBLIC') && is_numeric(ITEMS_PER_PAGE_PUBLIC) && ITEMS_PER_PAGE_PUBLIC > 0) ? (int)ITEMS_PER_PAGE_PUBLIC : 10;
$current_page_from_req = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$selected_city_code_from_req = isset($_GET['city']) ? trim($_GET['city']) : '';
$search_query_from_req = isset($_GET['search']) ? trim($_GET['search']) : '';
$guest_identifier_from_req = isset($_GET['guest_id']) ? trim($_GET['guest_id']) : '';

if (is_array($response_data['debug_info'])) {
    $response_data['debug_info']['request_params_get_ajax_script'] = $_GET;
}

try {
    if (!class_exists('Database')) { throw new Exception("Database class not found (AJAX)."); }
    $dbInstance = Database::getInstance();
    $db = $dbInstance->getConnection();
    if (!$db instanceof PDO) { throw new Exception("Failed to get PDO connection (AJAX)."); }

    updateOutdatedOutagesPublicAjax($db, $jdf_loaded_successfully_ajax_outage, $response_data['debug_info']);

    $all_cities_map_ajax_script = [];
    $stmtCitiesAjaxScript = $db->query("SELECT area_code, area_name, city_name FROM areas WHERE is_active = 1 ORDER BY display_order ASC, area_name ASC");
    if ($stmtCitiesAjaxScript) {
        foreach($stmtCitiesAjaxScript->fetchAll(PDO::FETCH_ASSOC) as $city_row_ajax_script) {
            $all_cities_map_ajax_script[$city_row_ajax_script['area_code']] = htmlspecialchars($city_row_ajax_script['city_name'] ?: $city_row_ajax_script['area_name']);
        }
        $stmtCitiesAjaxScript->closeCursor();
    } else { error_log("AJAX Script: Failed to fetch cities map. DB Error: " . print_r($db->errorInfo(), true)); }

    $params_sql_ajax_script = [];
    $guest_id_bind_val = !empty($guest_identifier_from_req) ? $guest_identifier_from_req : ('EMPTY_GUEST_ID_PLACEHOLDER_AJAX_' . microtime(true)); // Ensure unique if empty
    $params_sql_ajax_script[':guest_identifier_for_join'] = $guest_id_bind_val;

    $sql_select = "SELECT o.id, o.outage_signature, o.tarikh, o.az_saat, o.ta_saat, o.address_text, o.address_normalized_hash, o.area_code_source, CASE WHEN (o.address_normalized_hash IS NOT NULL AND o.address_normalized_hash != '' AND gpo.address_hash IS NOT NULL) THEN 1 ELSE 0 END AS is_sql_guest_pinned ";
    $sql_from = "FROM outage_events_log o LEFT JOIN guest_pinned_outages gpo ON o.address_normalized_hash = gpo.address_hash AND gpo.guest_identifier = :guest_identifier_for_join ";
    $sql_where = "WHERE o.is_currently_active = 1 ";

    if (!empty($selected_city_code_from_req) && $selected_city_code_from_req !== 'all') {
        $sql_where .= " AND o.area_code_source = :city ";
        $params_sql_ajax_script[':city'] = $selected_city_code_from_req;
    }
    if (!empty($search_query_from_req)) {
        $sql_where .= " AND o.address_text LIKE :address_search ";
        $params_sql_ajax_script[':address_search'] = '%' . $search_query_from_req . '%';
    }

    $sql_full_query = $sql_select . $sql_from . $sql_where;
    $stmt_all_outages_ajax = $db->prepare($sql_full_query);

    if (!$stmt_all_outages_ajax) { throw new Exception("AJAX: Query preparation failed. DB Error: " . print_r($db->errorInfo(), true) . " SQL: " . $sql_full_query); }
    if (!$stmt_all_outages_ajax->execute($params_sql_ajax_script)) { throw new Exception("AJAX: Query execution failed. DB Error: " . print_r($stmt_all_outages_ajax->errorInfo(), true) . " Params: " . print_r($params_sql_ajax_script, true)); }
    $all_outages_from_db_ajax_script = $stmt_all_outages_ajax->fetchAll(PDO::FETCH_ASSOC);
    $stmt_all_outages_ajax->closeCursor();

    if (is_array($response_data['debug_info'])) { $response_data['debug_info']['db_results_count_ajax'] = count($all_outages_from_db_ajax_script); }

    // PHP-based Sorting (Guest Pinned > PHP Pinned > Babol city > Date/Time)
    if (!empty($all_outages_from_db_ajax_script)) {
        usort($all_outages_from_db_ajax_script, function ($a_item, $b_item) use ($pinnedAddressKeywords) {
            $a_is_guest_pinned_sort = (bool)($a_item['is_sql_guest_pinned'] ?? false);
            $b_is_guest_pinned_sort = (bool)($b_item['is_sql_guest_pinned'] ?? false);
            if ($a_is_guest_pinned_sort != $b_is_guest_pinned_sort) { return $a_is_guest_pinned_sort ? -1 : 1; }
            $a_is_php_pinned_sort = false;
            if (!$a_is_guest_pinned_sort && isset($a_item['address_text']) && is_array($pinnedAddressKeywords) && !empty($pinnedAddressKeywords)) { foreach ($pinnedAddressKeywords as $keyword_sort) { if (mb_stripos(preg_replace('/\s+/', '', mb_strtolower((string)$a_item['address_text'])), preg_replace('/\s+/', '', mb_strtolower((string)$keyword_sort))) !== false) { $a_is_php_pinned_sort = true; break; } } }
            $b_is_php_pinned_sort = false;
            if (!$b_is_guest_pinned_sort && isset($b_item['address_text']) && is_array($pinnedAddressKeywords) && !empty($pinnedAddressKeywords)) { foreach ($pinnedAddressKeywords as $keyword_sort) { if (mb_stripos(preg_replace('/\s+/', '', mb_strtolower((string)$b_item['address_text'])), preg_replace('/\s+/', '', mb_strtolower((string)$keyword_sort))) !== false) { $b_is_php_pinned_sort = true; break; } } }
            if ($a_is_php_pinned_sort != $b_is_php_pinned_sort) { return $a_is_php_pinned_sort ? -1 : 1; }
            $is_a_babol_sort = (isset($a_item['area_code_source']) && ($a_item['area_code_source'] === 'BABOL_CITY_OVERALL' || $a_item['area_code_source'] === 'BABOL_CITY'));
            $is_b_babol_sort = (isset($b_item['area_code_source']) && ($b_item['area_code_source'] === 'BABOL_CITY_OVERALL' || $b_item['area_code_source'] === 'BABOL_CITY'));
            if ($is_a_babol_sort != $is_b_babol_sort) { return $is_a_babol_sort ? -1 : 1; }
            $val_a_str_sort = '999999999999'; $val_b_str_sort = '999999999999';
            $tarikh_a_sort = $a_item['tarikh'] ?? ''; $az_saat_a_sort = $a_item['az_saat'] ?? '';
            $parts_a_sort = explode('/', (string)$tarikh_a_sort);
            if (count($parts_a_sort) === 3) { $y_a_sort = (int)$parts_a_sort[0]; $m_a_sort = (int)$parts_a_sort[1]; $d_a_sort = (int)$parts_a_sort[2]; if ($y_a_sort < 100 && $y_a_sort > 0) $y_a_sort += 1400; if ($y_a_sort >=1300 && $m_a_sort >=1 && $d_a_sort >=1) { $time_parts_a_sort = explode(':', (string)$az_saat_a_sort); $h_a_sort = (isset($time_parts_a_sort[0]) && is_numeric($time_parts_a_sort[0])) ? (int)$time_parts_a_sort[0] : 0; $min_a_sort = (isset($time_parts_a_sort[1]) && is_numeric($time_parts_a_sort[1])) ? (int)$time_parts_a_sort[1] : 0; $val_a_str_sort = sprintf('%04d%02d%02d%02d%02d', $y_a_sort, $m_a_sort, $d_a_sort, $h_a_sort, $min_a_sort); } }
            $tarikh_b_sort = $b_item['tarikh'] ?? ''; $az_saat_b_sort = $b_item['az_saat'] ?? '';
            $parts_b_sort = explode('/', (string)$tarikh_b_sort);
            if (count($parts_b_sort) === 3) { $y_b_sort = (int)$parts_b_sort[0]; $m_b_sort = (int)$parts_b_sort[1]; $d_b_sort = (int)$parts_b_sort[2]; if ($y_b_sort < 100 && $y_b_sort > 0) $y_b_sort += 1400; if ($y_b_sort >=1300 && $m_b_sort >=1 && $d_b_sort >=1) { $time_parts_b_sort = explode(':', (string)$az_saat_b_sort); $h_b_sort = (isset($time_parts_b_sort[0]) && is_numeric($time_parts_b_sort[0])) ? (int)$time_parts_b_sort[0] : 0; $min_b_sort = (isset($time_parts_b_sort[1]) && is_numeric($time_parts_b_sort[1])) ? (int)$time_parts_b_sort[1] : 0; $val_b_str_sort = sprintf('%04d%02d%02d%02d%02d', $y_b_sort, $m_b_sort, $d_b_sort, $h_b_sort, $min_b_sort); } }
            return strcmp($val_a_str_sort, $val_b_str_sort);
         });
    }

    $processed_outages_for_json = [];
    if (!empty($all_outages_from_db_ajax_script)) {
        foreach ($all_outages_from_db_ajax_script as $row_for_json) {
            $is_guest_pinned_json = (bool)($row_for_json['is_sql_guest_pinned'] ?? false);
            $is_php_pinned_json = false;
            if (!$is_guest_pinned_json && isset($row_for_json['address_text']) && is_array($pinnedAddressKeywords) && !empty($pinnedAddressKeywords)) {
                foreach ($pinnedAddressKeywords as $pinKeywordJson) { if (mb_stripos(preg_replace('/\s+/', '', mb_strtolower((string)$row_for_json['address_text'])), preg_replace('/\s+/', '', mb_strtolower((string)$pinKeywordJson))) !== false) { $is_php_pinned_json = true; break; } }
            }
            $tarikh_display_val_json = htmlspecialchars($row_for_json['tarikh'] ?? 'نامشخص'); $date_meta_span_json = '';
            if ($jdf_loaded_successfully_ajax_outage && !empty($row_for_json['tarikh']) && strpos((string)$row_for_json['tarikh'], '/') !== false) {
                $parts_json = explode('/', (string)$row_for_json['tarikh']);
                if (is_array($parts_json) && count($parts_json) === 3) {
                    $y_json = (int)$parts_json[0]; $m_json = (int)$parts_json[1]; $d_json = (int)$parts_json[2];
                    if ($y_json < 100 && $y_json > 0) $y_json += 1400;
                    if (call_user_func('jcheckdate', $m_json, $d_json, $y_json)) {
                        $g_parts_json = call_user_func('jalali_to_gregorian', $y_json, $m_json, $d_json);
                        if ($g_parts_json && is_array($g_parts_json) && count($g_parts_json) === 3) {
                           $timestamp_json = mktime(0,0,0, (int)$g_parts_json[1], (int)$g_parts_json[2], (int)$g_parts_json[0]);
                           $formatted_jdate_json = call_user_func('jdate',"Y/m/d", $timestamp_json);
                           $tarikh_display_val_json = htmlspecialchars($formatted_jdate_json);
                           $today_jalali_str_json = call_user_func('jdate','Y/m/d'); $tomorrow_jalali_str_json = call_user_func('jdate','Y/m/d', time() + 86400);
                           if ($formatted_jdate_json === $today_jalali_str_json) $date_meta_span_json = ' <span class="date-meta">(امروز)</span>';
                           elseif ($formatted_jdate_json === $tomorrow_jalali_str_json) $date_meta_span_json = ' <span class="date-meta">(فردا)</span>';
                        }
                    }
                }
            }
            $processed_outages_for_json[] = [
                'tarikh_display' => $tarikh_display_val_json . $date_meta_span_json, 'tarikh_raw' => $row_for_json['tarikh'] ?? '',
                'az_saat' => $row_for_json['az_saat'] ?? '', 'ta_saat' => $row_for_json['ta_saat'] ?? '',
                'address' => $row_for_json['address_text'] ?? 'آدرس نامشخص',
                'address_html' => nl2br(htmlspecialchars($row_for_json['address_text'] ?? 'آدرس نامشخص')),
                'signature' => $row_for_json['outage_signature'] ?? md5(rand().time().($row_for_json['address_text'] ?? '')),
                'address_hash' => $row_for_json['address_normalized_hash'] ?? '',
                'is_guest_pinned' => $is_guest_pinned_json, 'is_php_pinned' => $is_php_pinned_json,
                'area_code_source' => $row_for_json['area_code_source'] ?? '',
                'city_name_for_share' => $all_cities_map_ajax_script[$row_for_json['area_code_source'] ?? ''] ?? htmlspecialchars($row_for_json['area_code_source'] ?? 'نامشخص')
            ];
        }
    }

    $total_items_final_ajax = count($processed_outages_for_json);
    $response_data['total_pages'] = $total_items_final_ajax > 0 ? ceil($total_items_final_ajax / $items_per_page_ajax_script) : 1;
    $current_page_final_ajax = max(1, min($current_page_from_req, $response_data['total_pages'])); // Use current_page_from_req
    $offset_final_ajax = ($current_page_final_ajax - 1) * $items_per_page_ajax_script;
    $response_data['outages'] = array_slice($processed_outages_for_json, $offset_final_ajax, $items_per_page_ajax_script);
    $response_data['current_page'] = $current_page_final_ajax;
    $response_data['success'] = true;
    $response_data['message'] = $total_items_final_ajax > 0 ? '' : 'موردی برای نمایش با فیلترهای انتخابی یافت نشد.';

    if (is_array($response_data['debug_info'])) {
        $response_data['debug_info']['total_items_after_processing_sort_ajax_final'] = $total_items_final_ajax;
        $response_data['debug_info']['num_outages_sent_in_json_ajax_final'] = count($response_data['outages']);
    }

} catch (PDOException $e_pdo_ajax_script) {
    $response_data['message'] = 'خطای پایگاه داده در هنگام بارگذاری خاموشی‌ها (AJAX_PDO).';
    error_log("AJAX Get Outages PDOException: " . $e_pdo_ajax_script->getMessage() . " Trace: " . $e_pdo_ajax_script->getTraceAsString());
    if(is_array($response_data['debug_info'])) { $response_data['debug_info']['db_error_ajax'] = $e_pdo_ajax_script->getMessage(); }
} catch (Throwable $e_general_ajax_script) {
    $response_data['message'] = 'خطای عمومی سیستم هنگام بارگذاری خاموشی‌ها (AJAX_GEN).';
    error_log("AJAX Get Outages Throwable: " . $e_general_ajax_script->getMessage() . " in " . $e_general_ajax_script->getFile() . " on line " . $e_general_ajax_script->getLine() . "\nTrace: " . $e_general_ajax_script->getTraceAsString());
    if(is_array($response_data['debug_info'])) { $response_data['debug_info']['general_error_ajax'] = $e_general_ajax_script->getMessage(); $response_data['debug_info']['error_file_line_ajax'] = $e_general_ajax_script->getFile() . ":" . $e_general_ajax_script->getLine(); }
}

echo json_encode($response_data);
exit;
?>