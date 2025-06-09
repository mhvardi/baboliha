<?php // /public_html/admin/layouts/_topbar.php ?>
<header class="admin-top-header">

    <div class="welcome-message">
<span class="menu-toggle-btn" onclick="toggleAdminSidebar()">☰</span>
        خوش آمدید، <strong><?php echo htmlspecialchars($loggedInAdminUsername ?? 'ادمین'); ?>!</strong>
    </div>
    <div class="logout-link"><a href="<?php echo site_url('admin/logout.php'); ?>">خروج از سیستم</a></div>
</header>