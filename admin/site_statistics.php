<?php
// /public_html/admin/site_statistics.php

$pageTitle = "آمار بازدید و رفتار کاربران";

if (file_exists(__DIR__ . '/layouts/_header.php')) {
    require_once __DIR__ . '/layouts/_header.php';
} else {
    error_log("SITE STATS FATAL ERROR: Admin header layout file not found.");
    die("خطای سیستمی: فایل لایوت هدر ادمین یافت نشد.");
}
// $db, $jdf_loaded_for_admin_layout باید در دسترس باشند

$stats = [
    'today_views' => 0, 'today_unique_ips' => 0,
    'yesterday_views' => 0, 'yesterday_unique_ips' => 0,
    'last_7_days_views' => 0, 'last_7_days_unique_ips' => 0,
    'last_30_days_views' => 0, 'last_30_days_unique_ips' => 0,
    'total_views_all_time' => 0, 'total_unique_ips_all_time' => 0,
    'online_users_count' => 0,
    'daily_stats_for_chart' => [],
    'top_referrers' => [],
    'banner_stats' => [],
    'banner_chart_data' => ['labels' => [], 'clicks' => []],
    'top_pinned_outages' => [],
    'top_user_agents_raw' => [] // برای نمایش رشته خام User Agent
];
$errorMessageForPage = null;
$online_threshold_minutes = 5; // کاربران آنلاین در 5 دقیقه اخیر

try {
    if (!isset($db) || !$db instanceof PDO) {
        throw new Exception("اتصال به پایگاه داده برای آمار بازدید در دسترس نیست.");
    }

    // --- آمار بازدید صفحه ---
    $today_gregorian = date('Y-m-d');
    $yesterday_gregorian = date('Y-m-d', strtotime('-1 day'));
    $seven_days_ago_gregorian = date('Y-m-d', strtotime('-6 days'));
    $thirty_days_ago_gregorian = date('Y-m-d', strtotime('-29 days'));

    if (!function_exists('getPageCountsForStats')) {
        function getPageCountsForStats(PDO $db, string $date_condition, array $params = []): array {
            $sql = "SELECT COUNT(*) as total_views, COUNT(DISTINCT ip_address) as unique_ips FROM page_views WHERE {$date_condition}";
            $stmt = $db->prepare($sql);
            if (!$stmt) { error_log("Prepare failed for getPageCounts: (" . $db->errorCode() . ") " . implode(" ", $db->errorInfo())); return ['views' => 0, 'unique_ips' => 0]; }
            if (!$stmt->execute($params)) { error_log("Execute failed for getPageCounts: (" . $stmt->errorCode() . ") " . implode(" ", $stmt->errorInfo())); return ['views' => 0, 'unique_ips' => 0]; }
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return ['views' => (int)($data['total_views'] ?? 0), 'unique_ips' => (int)($data['unique_ips'] ?? 0)];
        }
    }
    $stats['today_views'] = getPageCountsForStats($db, "view_date = ?", [$today_gregorian])['views'];
    $stats['today_unique_ips'] = getPageCountsForStats($db, "view_date = ?", [$today_gregorian])['unique_ips'];
    $stats['yesterday_views'] = getPageCountsForStats($db, "view_date = ?", [$yesterday_gregorian])['views'];
    $stats['yesterday_unique_ips'] = getPageCountsForStats($db, "view_date = ?", [$yesterday_gregorian])['unique_ips'];
    $stats['last_7_days_views'] = getPageCountsForStats($db, "view_date >= ?", [$seven_days_ago_gregorian])['views'];
    $stats['last_7_days_unique_ips'] = getPageCountsForStats($db, "view_date >= ?", [$seven_days_ago_gregorian])['unique_ips'];
    $stats['last_30_days_views'] = getPageCountsForStats($db, "view_date >= ?", [$thirty_days_ago_gregorian])['views'];
    $stats['last_30_days_unique_ips'] = getPageCountsForStats($db, "view_date >= ?", [$thirty_days_ago_gregorian])['unique_ips'];
    $stats['total_views_all_time'] = getPageCountsForStats($db, "1=1")['views'];
    $stats['total_unique_ips_all_time'] = getPageCountsForStats($db, "1=1")['unique_ips'];

    $time_ago_for_online = date('Y-m-d H:i:s', strtotime("-{$online_threshold_minutes} minutes"));
    $stmtOnline = $db->prepare("SELECT COUNT(DISTINCT ip_address) as online_count FROM page_views WHERE view_datetime >= ?");
    $stmtOnline->execute([$time_ago_for_online]);
    $stats['online_users_count'] = (int)($stmtOnline->fetchColumn() ?? 0);

    // --- داده‌های نمودار بازدید روزانه ---
    $chart_start_date = $thirty_days_ago_gregorian;
    $stmtChart = $db->prepare("SELECT view_date, COUNT(*) as total_views, COUNT(DISTINCT ip_address) as unique_ips FROM page_views WHERE view_date >= :start_date GROUP BY view_date ORDER BY view_date ASC");
    $stmtChart->bindParam(':start_date', $chart_start_date, PDO::PARAM_STR);
    $stmtChart->execute();
    $chart_data_raw = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

    $temp_chart_data = [];
    $current_chart_dt = new DateTime($chart_start_date); $end_chart_dt = new DateTime($today_gregorian);
    while($current_chart_dt <= $end_chart_dt) {
        $date_key = $current_chart_dt->format('Y-m-d');
        $temp_chart_data[$date_key] = ['total_views' => 0, 'unique_ips' => 0];
        $current_chart_dt->modify('+1 day');
    }
    foreach ($chart_data_raw as $data_point) {
        if (isset($temp_chart_data[$data_point['view_date']])) {
            $temp_chart_data[$data_point['view_date']]['total_views'] = (int)$data_point['total_views'];
            $temp_chart_data[$data_point['view_date']]['unique_ips'] = (int)$data_point['unique_ips'];
        }
    }
    $stats['daily_stats_for_chart'] = [];
    if ($jdf_loaded_for_admin_layout && function_exists('gregorian_to_jalali') && function_exists('jdate')) {
        foreach ($temp_chart_data as $date_key => $counts) {
            $shamsi_label = $date_key;
            try { list($y, $m, $d) = explode('-', $date_key); $j_date_parts = gregorian_to_jalali((int)$y, (int)$m, (int)$d);
                  $shamsi_label = sprintf('%02d/%02d', $j_date_parts[1], $j_date_parts[2]);
            } catch (Exception $e) { $shamsi_label = date('m/d', strtotime($date_key)); }
            $stats['daily_stats_for_chart'][] = ['date_label' => $shamsi_label, 'total_views' => $counts['total_views'], 'unique_ips' => $counts['unique_ips']];
        }
    } else {
        foreach ($temp_chart_data as $date_key => $counts) {
            $stats['daily_stats_for_chart'][] = ['date_label' => date('m/d', strtotime($date_key)), 'total_views' => $counts['total_views'], 'unique_ips' => $counts['unique_ips']];
        }
    }

    // --- خواندن آمار کلیک بنرها ---
    $stmtBannersStats = $db->query("SELECT id, name, image_url, clicks, position FROM banners WHERE is_active = 1 ORDER BY clicks DESC");
    if($stmtBannersStats) $stats['banner_stats'] = $stmtBannersStats->fetchAll(PDO::FETCH_ASSOC);
    else $stats['banner_stats'] = []; // اطمینان از اینکه آرایه است

    $stats['banner_chart_data'] = ['labels' => [], 'clicks' => []]; // بازنشانی
    if (!empty($stats['banner_stats'])) {
        foreach ($stats['banner_stats'] as $banner) {
            $stats['banner_chart_data']['labels'][] = $banner['name'] . ' (ID:' . $banner['id'] . ')';
            $stats['banner_chart_data']['clicks'][] = (int)$banner['clicks'];
        }
    }
    
    // --- خواندن بیشترین منابع ورودی (Referrers) ---
    $base_host_for_referrer = defined('BASE_URL') ? parse_url(BASE_URL, PHP_URL_HOST) : ($_SERVER['HTTP_HOST'] ?? '');
    $base_host_for_referrer = str_replace('www.', '', $base_host_for_referrer);
    
    $sqlReferrers = "SELECT referrer_url, COUNT(*) as referrer_count 
                     FROM page_views 
                     GROUP BY referrer_url 
                     ORDER BY referrer_count DESC";
    $stmtReferrers = $db->query($sqlReferrers);
    $all_raw_referrers = $stmtReferrers ? $stmtReferrers->fetchAll(PDO::FETCH_ASSOC) : [];
    
    $processed_referrers_temp = [];
    $direct_and_internal_visits_count = 0;
    foreach ($all_raw_referrers as $ref) {
        $referrer_url_item = trim($ref['referrer_url'] ?? '');
        if (empty($referrer_url_item)) {
            $direct_and_internal_visits_count += (int)$ref['referrer_count'];
            continue;
        }
        $host = parse_url($referrer_url_item, PHP_URL_HOST);
        if ($host) {
            $display_host = strtolower(str_replace('www.', '', $host));
            if ($display_host === $base_host_for_referrer || stripos($referrer_url_item, 'android-app://') === 0 || stripos($display_host, 'localhost') !== false) {
                $direct_and_internal_visits_count += (int)$ref['referrer_count'];
                continue;
            }
            if (stripos($display_host, 't.me') !== false || stripos($display_host, 'telegram.org') !== false || stripos($display_host, 'telegram.me') !== false) $display_host = 'Telegram';
            elseif (stripos($display_host, 'instagram.com') !== false) $display_host = 'Instagram';
            elseif (stripos($display_host, 'google.') !== false) $display_host = 'Google';
            $processed_referrers_temp[$display_host] = ($processed_referrers_temp[$display_host] ?? 0) + (int)$ref['referrer_count'];
        } else { $direct_and_internal_visits_count += (int)$ref['referrer_count']; }
    }
    if ($direct_and_internal_visits_count > 0) {
        $processed_referrers_temp['مستقیم / داخلی / نامشخص'] = $direct_and_internal_visits_count;
    }
    arsort($processed_referrers_temp);
    $stats['top_referrers'] = array_slice($processed_referrers_temp, 0, 10, true);

    // --- خواندن آمار User Agents (رشته خام) ---
    $stmtTopUARaw = $db->query("SELECT user_agent, COUNT(*) as agent_count FROM page_views WHERE user_agent IS NOT NULL AND user_agent != '' GROUP BY user_agent ORDER BY agent_count DESC LIMIT 10");
    if($stmtTopUARaw) $stats['top_user_agents_raw'] = $stmtTopUARaw->fetchAll(PDO::FETCH_ASSOC);
    else $stats['top_user_agents_raw'] = [];


       $sqlPinned = "SELECT 
                    oel.address_text, 
                    oel.address_normalized_hash, -- برای دیباگ و اطمینان از یکتایی
                    COUNT(gpo.id) as pin_count 
                  FROM 
                    guest_pinned_outages gpo
                  JOIN 
                    outage_events_log oel ON gpo.address_hash = oel.address_normalized_hash
                  WHERE 
                    oel.address_normalized_hash IS NOT NULL AND oel.address_normalized_hash != ''
                  GROUP BY 
                    oel.address_normalized_hash, oel.address_text 
                  ORDER BY 
                    pin_count DESC
                  LIMIT 10"; // نمایش ۱۰ مورد برتر

    $stmtPinned = $db->query($sqlPinned);
    if ($stmtPinned) {
        $stats['top_pinned_outages'] = $stmtPinned->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stats['top_pinned_outages'] = []; // در صورت خطا، آرایه خالی باشد
        $errorInfo = $db->errorInfo();
        // لاگ کردن خطای دقیق دیتابیس برای بررسی بیشتر
        error_log("Site Statistics Error: Failed to fetch top pinned outages. DB error: (" . ($errorInfo[0] ?? 'N/A') . ") " . ($errorInfo[2] ?? 'Unknown error') . " SQL: " . $sqlPinned);
        // می‌توانید یک پیام خطا هم برای نمایش به کاربر ست کنید اگر لازم است
        // $errorMessageForPage = "خطا در بارگذاری آمار آیتم‌های پین شده.";
    }



} catch (Exception $e) {
    $errorMessageForPage = "خطا در بارگذاری آمار: " . htmlspecialchars($e->getMessage());
    error_log("Site Statistics Page Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// آماده‌سازی داده‌های JSON برای JavaScript نمودارها
$chartPageViewsLabelsJson = json_encode(array_values(array_column($stats['daily_stats_for_chart'], 'date_label')));
$chartPageViewsDataJson = json_encode(array_values(array_column($stats['daily_stats_for_chart'], 'total_views')));
$chartUniqueIPsDataJson = json_encode(array_values(array_column($stats['daily_stats_for_chart'], 'unique_ips')));
$bannerChartLabelsJson = json_encode($stats['banner_chart_data']['labels'] ?? []); // اطمینان از اینکه آرایه است
$bannerChartClicksJson = json_encode($stats['banner_chart_data']['clicks'] ?? []); // اطمینان از اینکه آرایه است
$referrerChartLabelsJson = json_encode(array_keys($stats['top_referrers']));
$referrerChartDataJson = json_encode(array_values($stats['top_referrers']));

// برای نمودارهای OS و Browser، چون کتابخانه نداریم، داده خالی می‌فرستیم
$osChartLabelsJson = "[]";
$osChartDataJson = "[]";
$browserChartLabelsJson = "[]";
$browserChartDataJson = "[]";

?>

<div class="admin-page-content">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>

    <?php if (isset($errorMessageForPage)): ?>
         <div class="message error"><?php echo $errorMessageForPage; ?></div>
    <?php endif; ?>

    <div class="stats-section">
        <h3>آمار بازدید کلی صفحه اصلی</h3>
        <div class="table-responsive-wrapper stats-table-wrapper">
            <table class="data-table modern-stats-table">
                <thead><tr><th>بازه زمانی</th><th>بازدید کل</th><th>IP یکتا</th></tr></thead>
                <tbody>
                    <tr><td>امروز</td><td><?php echo number_format($stats['today_views']); ?></td><td><?php echo number_format($stats['today_unique_ips']); ?></td></tr>
                    <tr><td>دیروز</td><td><?php echo number_format($stats['yesterday_views']); ?></td><td><?php echo number_format($stats['yesterday_unique_ips']); ?></td></tr>
                    <tr><td>۷ روز گذشته</td><td><?php echo number_format($stats['last_7_days_views']); ?></td><td><?php echo number_format($stats['last_7_days_unique_ips']); ?></td></tr>
                    <tr><td>۳۰ روز گذشته</td><td><?php echo number_format($stats['last_30_days_views']); ?></td><td><?php echo number_format($stats['last_30_days_unique_ips']); ?></td></tr>
                    <tr><td>کل بازدیدها</td><td><?php echo number_format($stats['total_views_all_time']); ?></td><td><?php echo number_format($stats['total_unique_ips_all_time']); ?></td></tr>
<tr>
    <td>کاربران آنلاین <small>لحظه ایی</small></td>
    <td colspan="2" class="online-users-count-cell">
        <span class="live-indicator"></span> <strong id="liveOnlineUsersCount"><?php echo number_format($stats['online_users_count']); ?></strong> نفر
    </td>
</tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($stats['daily_stats_for_chart']) && count(json_decode($chartPageViewsLabelsJson)) > 0 ): ?>
    <div class="chart-container-wrapper">
        <h3>نمودار بازدید روزانه (۳۰ روز گذشته)</h3>
        <div class="chart-container" style="height: 350px;"><canvas id="dailyPageViewsChart"></canvas></div>
    </div>
    <?php else: ?>
    <p class="no-data" style="text-align:center; margin-top:20px;">داده‌ای برای نمایش نمودار بازدید روزانه در ۳۰ روز گذشته وجود ندارد.</p>
    <?php endif; ?>

    <hr class="section-divider">

    <div class="stats-section">
        <h3>آمار کلیک بنرها (بنرهای فعال)</h3>
        <?php if (!empty($stats['banner_stats'])): ?>
            <div class="table-responsive-wrapper">
                <table class="data-table">
                    <thead><tr><th>ID</th><th>نام بنر</th><th>موقعیت</th><th>تعداد کلیک</th><th>پیش‌نمایش</th></tr></thead>
                    <tbody>
                        <?php foreach ($stats['banner_stats'] as $banner): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($banner['id']); ?></td>
                                <td><?php echo htmlspecialchars($banner['name']); ?></td>
                                <td><?php echo htmlspecialchars($banner['position']); ?></td>
                                <td style="font-weight:bold;"><?php echo number_format($banner['clicks']); ?></td>
                                <td>
                                    <?php if(!empty($banner['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars( (stripos($banner['image_url'], 'http') === 0) ? $banner['image_url'] : site_url($banner['image_url']) ); ?>" alt="<?php echo htmlspecialchars($banner['name']); ?>" style="max-height: 30px; max-width: 80px; border:1px solid #eee; vertical-align:middle;">
                                    <?php else: echo "-"; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty(json_decode($bannerChartLabelsJson))): ?>
            <div class="chart-container-wrapper">
                <h3>نمودار مقایسه‌ای کلیک بنرها</h3>
                <div class="chart-container" style="height: <?php echo max(200, count(json_decode($bannerChartLabelsJson)) * 35 + 60); ?>px;"><canvas id="bannerClicksChart"></canvas></div>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="no-data" style="margin-top:0;">هنوز هیچ بنر فعالی برای نمایش آمار کلیک وجود ندارد.</p>
        <?php endif; ?>
    </div>
    
    <hr class="section-divider">

    <div class="stats-section">
        <h3>بیشترین منابع ورودی (Referrers)</h3>
        <?php if (!empty($stats['top_referrers'])): ?>
            <div class="table-responsive-wrapper">
                <table class="data-table">
                    <thead><tr><th>منبع</th><th>تعداد ارجاع</th></tr></thead>
                    <tbody>
                        <?php foreach ($stats['top_referrers'] as $source => $count): ?>
                            <tr><td><?php echo htmlspecialchars($source); ?></td><td style="font-weight:bold;"><?php echo number_format($count); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty(json_decode($referrerChartLabelsJson))): ?>
            <div class="chart-container-wrapper">
                <h4>نمودار منابع ورودی</h4>
                <div class="chart-container" style="height: <?php echo max(200, count(json_decode($referrerChartLabelsJson)) * 30 + 80); ?>px;"><canvas id="referrersChart"></canvas></div>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="no-data" style="margin-top:0;">اطلاعات کافی برای نمایش منابع ورودی وجود ندارد.</p>
        <?php endif; ?>
    </div>

    <hr class="section-divider">
    
    <div class="stats-section">
        <?php if(!empty($stats['top_user_agents_raw'])): ?>
            <h4>۱۰ User Agent پرتکرار (رشته خام):</h4>
            <div class="table-responsive-wrapper"><table class="data-table"><thead><tr><th style="width:70%;">User Agent</th><th style="text-align:center;">تعداد</th></tr></thead><tbody>
            <?php foreach($stats['top_user_agents_raw'] as $ua): ?>
            <tr><td style="font-size:0.8em; direction:ltr; text-align:left; max-width: 500px; overflow-wrap: break-word;"><?php echo htmlspecialchars($ua['user_agent']);?></td><td style="text-align:center;"><?php echo number_format($ua['agent_count']);?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?>
             <p class="no-data">اطلاعات User Agent در دسترس نیست.</p>
        <?php endif; ?>
    </div>

    <hr class="section-divider">

  <div class="stats-section">
        <h3>محبوب‌ترین آدرس‌های پین شده (توسط کاربران مهمان)</h3>
        <?php if (!empty($stats['top_pinned_outages'])): ?>
            <div class="table-responsive-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:70%;">آدرس خاموشی (بخشی)</th>
                            <th style="text-align:center; width:30%;">تعداد پین</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['top_pinned_outages'] as $pinned_item): ?>
                            <tr>
                                <td style="max-width: 450px; overflow-wrap: break-word;">
                                    <?php
                                        // نمایش بخشی از آدرس برای خوانایی بهتر
                                        $address_display = $pinned_item['address_text'] ?? 'آدرس نامشخص';
                                        echo htmlspecialchars(mb_substr($address_display, 0, 120) . (mb_strlen($address_display) > 120 ? '...' : ''));
                                    ?>
                                </td>
                                <td style="font-weight:bold; text-align:center;">
                                    <?php echo number_format($pinned_item['pin_count']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="no-data" style="margin-top:0;">هنوز هیچ آیتمی توسط کاربران مهمان پین نشده است یا داده‌های لازم برای نمایش این آمار کامل نیست.</p>
        <?php endif; ?>
    </div>


</div> <?php
// اسکریپت Chart.js و مقداردهی اولیه آن
$page_specific_js_footer_content = '';
// شرط برای اطمینان از اینکه حداقل یکی از نمودارها داده برای نمایش دارد
if (
    (!empty($stats['daily_stats_for_chart']) && count(json_decode($chartPageViewsLabelsJson)) > 0) ||
    (!empty($stats['banner_chart_data']['labels']) && count(json_decode($bannerChartLabelsJson)) > 0) ||
    (!empty(array_keys($stats['top_referrers'])) && count(json_decode($referrerChartLabelsJson)) > 0)
    // نمودارهای OS و Browser فعلاً داده‌ای ندارند
) {
    $chart_js_cdn_path = "https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js";

$page_specific_js_footer_content = <<<JS
<script src="{$chart_js_cdn_path}"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (typeof Chart === 'undefined') {
        console.error("Chart.js library not loaded from CDN!");
        document.querySelectorAll('.chart-container canvas').forEach(canvasEl => {
            if(canvasEl.parentElement) canvasEl.parentElement.innerHTML = "<p class='message error' style='text-align:center;'>کتابخانه نمودار بارگذاری نشده است.</p>";
        });
        return;
    }

    Chart.defaults.font.family = 'IRANSansX, Tahoma, sans-serif';
    Chart.defaults.borderColor = '#e0e6ed';
    Chart.defaults.color = '#495057';
    Chart.defaults.plugins.tooltip.titleFont = { weight: '600', family: 'IRANSansX, Tahoma, sans-serif' };
    Chart.defaults.plugins.tooltip.bodyFont = { weight: '500', family: 'IRANSansX, Tahoma, sans-serif' };
    Chart.defaults.plugins.legend.labels.padding = 15;
    Chart.defaults.plugins.legend.labels.boxWidth = 12;

    // نمودار بازدید روزانه
    const dailyViewsCtx = document.getElementById('dailyPageViewsChart');
    const dailyLabels = {$chartPageViewsLabelsJson};
    const dailyViewsData = {$chartPageViewsDataJson};
    const dailyUniqueIPsData = {$chartUniqueIPsDataJson};
    if (dailyViewsCtx && dailyLabels && dailyLabels.length > 0) {
        new Chart(dailyViewsCtx, { type: 'line', data: { labels: dailyLabels, datasets: [
                { label: 'بازدید کل', data: dailyViewsData, borderColor: 'rgb(54, 162, 235)', backgroundColor: 'rgba(54, 162, 235, 0.1)', tension: 0.3, fill: 'origin', pointRadius: 3, pointHoverRadius: 6, borderWidth: 1.5 },
                { label: 'IP یکتا', data: dailyUniqueIPsData, borderColor: 'rgb(255, 99, 132)', backgroundColor: 'rgba(255, 99, 132, 0.1)', tension: 0.3, fill: 'origin', pointRadius: 3, pointHoverRadius: 6, borderWidth: 1.5 }
            ]},
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { position: 'bottom' }, tooltip: { mode: 'index', intersect: false, bodySpacing: 5, padding: 10 }} }
        });
    } else if (dailyViewsCtx) { dailyViewsCtx.parentElement.innerHTML = "<p class='no-data'>داده‌ای برای نمودار بازدید روزانه نیست.</p>"; }

    // نمودار کلیک بنرها
    const bannerClicksCtx = document.getElementById('bannerClicksChart');
    const bannerLabels = {$bannerChartLabelsJson};
    const bannerClicksData = {$bannerChartClicksJson};
    if (bannerClicksCtx && bannerLabels && bannerLabels.length > 0) {
        new Chart(bannerClicksCtx, { type: 'bar', data: { labels: bannerLabels, datasets: [{ label: 'تعداد کلیک', data: bannerClicksData,
            backgroundColor: ['rgba(255, 159, 64, 0.7)', 'rgba(75, 192, 192, 0.7)', 'rgba(255, 205, 86, 0.7)', 'rgba(54, 162, 235, 0.7)',  'rgba(153, 102, 255, 0.7)'],
            borderColor: ['#fff'], borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false }, title: { display: true, text: 'مقایسه کلیک بنرها', font:{size:14, weight:'600'} }} }
        });
    } else if (bannerClicksCtx) { bannerClicksCtx.parentElement.innerHTML = "<p class='no-data'>داده‌ای برای نمودار کلیک بنرها نیست.</p>"; }

    // نمودار منابع ورودی
    const referrersCtx = document.getElementById('referrersChart');
    const referrerLabels = {$referrerChartLabelsJson};
    const referrerData = {$referrerChartDataJson};
    if (referrersCtx && referrerLabels && referrerLabels.length > 0) {
        new Chart(referrersCtx, { type: 'bar', data: { labels: referrerLabels, datasets: [{ label: 'تعداد ارجاع', data: referrerData,
            backgroundColor: 'rgba(153, 102, 255, 0.7)', borderColor: 'rgba(153, 102, 255, 1)', borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false }, title: { display: true, text: 'بیشترین منابع ارجاع دهنده', font:{size:14, weight:'600'} }} }
        });
    } else if (referrersCtx) { referrersCtx.parentElement.innerHTML = "<p class='no-data'>داده‌ای برای نمودار منابع ورودی نیست.</p>"; }

    // نمودارهای سیستم عامل و مرورگر دیگر در این نسخه نمایش داده نمی‌شوند
    // چون کتابخانه whichbrowser/parser استفاده نشده است.
    const osCtx = document.getElementById('osChart');
    if(osCtx) osCtx.parentElement.innerHTML = "<p class='message info' style='text-align:center;'>نمایش آمار سیستم عامل و مرورگر نیاز به نصب کتابخانه اضافی دارد.</p>";
    
    const browserCtx = document.getElementById('browserChart');
    if(browserCtx) browserCtx.parentElement.innerHTML = ""; // بخش مرورگر را خالی می‌کنیم یا پیام مشابه می‌دهیم

});
</script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const refreshInterval = 3000; // هر ۱۰ ثانیه یکبار
    const countElement = document.getElementById('liveOnlineUsersCount');
    const indicator = document.querySelector('.live-indicator');

    function fetchOnlineUsers() {
        fetch('/admin/ajax_get_online_users.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && countElement) {
                    countElement.textContent = new Intl.NumberFormat('fa-IR').format(data.online_users);

                    // نشانگر زنده‌بودن چشمک بزنه
                    if (indicator) {
                        indicator.classList.remove('blink');
                        void indicator.offsetWidth; // Trick برای ریست انیمیشن
                        indicator.classList.add('blink');
                    }
                }
            })
            .catch(error => {
                console.error('خطا در دریافت آمار کاربران آنلاین:', error);
            });
    }

    fetchOnlineUsers(); // بار اول
    setInterval(fetchOnlineUsers, refreshInterval); // به‌صورت دوره‌ای
});
</script>


JS;
}

if (file_exists(__DIR__ . '/layouts/_footer.php')) {
    if (!empty($page_specific_js_footer_content)) echo $page_specific_js_footer_content;
    require_once __DIR__ . '/layouts/_footer.php';
}
?>