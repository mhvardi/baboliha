<?php
if (!isset($current_page_admin)) {
    $current_page_admin = basename($_SERVER['PHP_SELF']);
}
if (!function_exists('site_url')) {
    function site_url($path = '') {
        $base = defined('BASE_URL') ? BASE_URL : '';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
?>
<style>
    .admin-sidebar {
        width: 250px;
        background-color: #1e1e2f;
        color: #fff;
        min-height: 100vh;
        padding: 20px 0;
        font-family: "IRANSans", sans-serif;
        box-shadow: 2px 0 10px rgba(0,0,0,0.2);
    }
    .admin-sidebar h3 {
        color: #f9c41e;
        font-size: 18px;
        margin: 0 20px 20px;
        border-bottom: 1px solid #333;
        padding-bottom: 10px;
    }
    .admin-sidebar ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .admin-sidebar li {
        margin: 5px 0;
    }
    .admin-sidebar a {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #ccc;
        text-decoration: none;
        padding: 12px 20px;
        transition: all 0.2s ease;
        border-right: 4px solid transparent;
    }
    .admin-sidebar a:hover {
        background-color: #2a2a3d;
        color: #fff;
    }
    .admin-sidebar a.active {
        background-color: #2a2a3d;
        color: #fff;
        border-right: 4px solid #f9c41e;
    }
    .admin-sidebar a.disabled {
        pointer-events: none;
        color: #777;
        opacity: 0.5;
        cursor: default;
    }
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />

<aside class="admin-sidebar">
    <h3>پنل مدیریت</h3>
    <ul>
        <li><a href="<?= site_url('admin/index.php') ?>" class="<?= ($current_page_admin == 'index.php') ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> داشبورد و کرون جاب</a></li>
        <li><a href="<?= site_url('admin/manage_outages.php') ?>" class="<?= ($current_page_admin == 'manage_outages.php') ? 'active' : '' ?>"><i class="fas fa-power-off"></i> لیست خاموشی</a></li>
        <li><a href="<?= site_url('admin/manage_ads.php') ?>" class="<?= ($current_page_admin == 'manage_ads.php') ? 'active' : '' ?>"><i class="fas fa-bullhorn"></i> تبلیغات</a></li>
        <li><a href="<?= site_url('admin/site_statistics.php') ?>" class="<?= ($current_page_admin == 'site_statistics.php') ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> آمار بازدید</a></li>
<li>
  <a href="manage_subscribers.php" class="<?= in_array($current_page_admin, ['manage_subscribers.php', 'manage_keywords.php']) ? 'active' : '' ?>">
    <i class="fas fa-users-cog"></i> مدیریت مشترکین
  </a>
</li>
<li>
  <a href="sms_settings.php" class="<?= ($current_page_admin == 'sms_settings.php') ? 'active' : '' ?>">
    <i class="fas fa-cog"></i> تنظیمات پیامک
  </a>
</li>
<li>
  <a href="manual_sms_send.php" class="<?= ($current_page_admin == 'manual_sms_send.php') ? 'active' : '' ?>">
    <i class="fas fa-paper-plane"></i> ارسال پیامک دستی
  </a>
</li>
<li>
  <a href="manage_areas.php" class="<?= ($current_page_admin == 'manage_areas.php') ? 'active' : '' ?>">
    <i class="fas fa-cog"></i> مدیریت شهر ها
  </a>
</li>

    </ul>
</aside>
