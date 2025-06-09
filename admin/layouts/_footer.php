<?php // /public_html/admin/layouts/_footer.php ?>

            </div> <footer class="admin-main-footer">
                <p>&copy; <?php echo date('Y'); ?> سامانه اطلاع‌رسانی خاموشی برق. طراحی و توسعه: <a href="https://vardi.ir/" target="_blank">آژانس تبلیغاتی وردی</a></p>
            </footer>
        </main>
    </div> <script src="<?php echo site_url('assets/admin_script.js'); ?>?v=<?php echo file_exists(ROOT_PATH . '/assets/admin_script.js') ? filemtime(ROOT_PATH . '/assets/admin_script.js') : time(); ?>"></script>
    <?php if (isset($page_specific_js) && is_array($page_specific_js)): ?>
        <?php foreach ($page_specific_js as $js_file): ?>
            <script src="<?php echo site_url($js_file); ?>?v=<?php echo file_exists(ROOT_PATH . '/' . $js_file) ? filemtime(ROOT_PATH . '/' . $js_file) : time(); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
	<script src="<?php echo site_url('lib/chart.min.js'); ?>?v=4.4.1"></script>
</body>
</html>