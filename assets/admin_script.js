// /public_html/assets/admin_script.js

document.addEventListener('DOMContentLoaded', function() {
    // برای باز و بسته شدن منوی سایدبار در موبایل
    const menuToggleButton = document.querySelector('.menu-toggle-btn');
    const sidebar = document.querySelector('.admin-sidebar');
    const mainContent = document.querySelector('.admin-main-content');

    if (menuToggleButton && sidebar) {
        menuToggleButton.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            // if (mainContent) {
            //     mainContent.classList.toggle('sidebar-open-for-mobile-effect');
            // }
        });
    }

    // دکمه اجرای دستی کرون در داشبورد
    const scrapeForm = document.getElementById('manualScrapeForm');
    const scrapeBtn = document.getElementById('manualScrapeBtn');

    if (scrapeForm && scrapeBtn) {
        scrapeForm.addEventListener('submit', function(event) {
            if (confirm('آیا از اجرای به‌روزرسانی دستی مطمئن هستید؟ این عملیات ممکن است کمی طول بکشد و صفحه رفرش خواهد شد.')) {
                scrapeBtn.innerHTML = 'در حال بروزرسانی... <span class="spinner"></span>';
                scrapeBtn.disabled = true;
            } else {
                event.preventDefault();
            }
        });
    }

    // تایمر برای بروزرسانی بعدی کرون سرور
    const countdownTimerSpan = document.getElementById('countdown-timer-server');
    if (countdownTimerSpan && typeof nextRunTimestampFromServer !== 'undefined' && !isNaN(nextRunTimestampFromServer) && nextRunTimestampFromServer) {
        let countdownInterval = setInterval(function() {
            const now = new Date().getTime();
            const distance = nextRunTimestampFromServer - now;

            if (distance < 0) {
                countdownTimerSpan.innerHTML = " (در انتظار اجرای بعدی...)";
                countdownTimerSpan.style.color = '#777';
                return;
            }

            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            let countdownText = " (تا ";
            if (hours > 0) countdownText += hours + " ساعت و ";
            countdownText += String(minutes).padStart(2, '0') + " دقیقه و ";
            countdownText += String(seconds).padStart(2, '0') + " ثانیه دیگر)";

            countdownTimerSpan.innerHTML = countdownText;

            if (distance < 60000) {
                countdownTimerSpan.style.color = '#e74c3c';
            } else if (distance < 300000) {
                countdownTimerSpan.style.color = '#f39c12';
            } else {
                countdownTimerSpan.style.color = '#28a745';
            }
        }, 1000);

        // اجرای اولیه
        (function() {
            const now = new Date().getTime();
            const distance = nextRunTimestampFromServer - now;
            if (distance <= 0) {
                countdownTimerSpan.innerHTML = " (در انتظار اجرای بعدی...)";
                countdownTimerSpan.style.color = '#777';
                return;
            }
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            let countdownText = " (تا ";
            if (hours > 0) countdownText += hours + " ساعت و ";
            countdownText += String(minutes).padStart(2, '0') + " دقیقه و ";
            countdownText += String(seconds).padStart(2, '0') + " ثانیه دیگر)";
            countdownTimerSpan.innerHTML = countdownText;
        })();
    } else if (countdownTimerSpan) {
        countdownTimerSpan.innerHTML = " (زمان‌بندی در دسترس نیست)";
    }
});
// تعریف ساده اسپینر (می‌توانید در CSS بهترش کنید)
// if (document.getElementById('manualScrapeBtn')) {
//     const styleSheet = document.createElement("style");
//     styleSheet.type = "text/css";
//     styleSheet.innerText = ".spinner { display: inline-block; width: 1em; height: 1em; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s ease-in-out infinite; -webkit-animation: spin 1s ease-in-out infinite; margin-right: 5px; vertical-align: middle;} @keyframes spin { to { -webkit-transform: rotate(360deg); } } @-webkit-keyframes spin { to { -webkit-transform: rotate(360deg); } }";
//     document.head.appendChild(styleSheet);
// }
<script>
    const rawUserAgents = <?php echo json_encode($stats['top_user_agents_raw'], JSON_UNESCAPED_UNICODE); ?>;

    const browserCounts = {};
    const osCounts = {};
    const deviceCounts = {};

    rawUserAgents.forEach(item => {
        const parser = new UAParser(item.user_agent);
        const browser = parser.getBrowser().name || 'ناشناخته';
        const os = parser.getOS().name || 'ناشناخته';
        const device = parser.getDevice().type || 'Desktop';

        browserCounts[browser] = (browserCounts[browser] || 0) + item.agent_count;
        osCounts[os] = (osCounts[os] || 0) + item.agent_count;
        deviceCounts[device] = (deviceCounts[device] || 0) + item.agent_count;
    });

    function drawChart(canvasId, label, dataObj, color = 'rgba(75, 192, 192, 0.6)') {
        const ctx = document.getElementById(canvasId).getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: Object.keys(dataObj),
                datasets: [{
                    label: label,
                    data: Object.values(dataObj),
                    backgroundColor: color,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true },
                    x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 } }
                }
            }
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        drawChart("browserChart", "مرورگرها", browserCounts, "rgba(54, 162, 235, 0.6)");
        drawChart("osChart", "سیستم‌عامل‌ها", osCounts, "rgba(255, 206, 86, 0.6)");
        drawChart("deviceChart", "نوع دستگاه", deviceCounts, "rgba(255, 99, 132, 0.6)");
    });
</script>

// /public_html/assets/admin_script.js

document.addEventListener('DOMContentLoaded', function() {
    // تعریف تابع toggleAdminSidebar
    window.toggleAdminSidebar = function() {
        const sidebar = document.querySelector('.admin-sidebar');
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
    };

    // برای باز و بسته شدن منوی سایدبار در موبایل (کد قبلی شما)
    const menuToggleButton = document.querySelector('.menu-toggle-btn');
    const sidebar = document.querySelector('.admin-sidebar');
    const mainContent = document.querySelector('.admin-main-content');

    if (menuToggleButton && sidebar) {
        menuToggleButton.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            // if (mainContent) {
            //     mainContent.classList.toggle('sidebar-open-for-mobile-effect');
            // }
        });
    }

    // دکمه اجرای دستی کرون در داشبورد
    const scrapeForm = document.getElementById('manualScrapeForm');
    const scrapeBtn = document.getElementById('manualScrapeBtn');

    if (scrapeForm && scrapeBtn) {
        scrapeForm.addEventListener('submit', function(event) {
            if (confirm('آیا از اجرای به‌روزرسانی دستی مطمئن هستید؟ این عملیات ممکن است کمی طول بکشد و صفحه رفرش خواهد شد.')) {
                scrapeBtn.innerHTML = 'در حال بروزرسانی... <span class="spinner"></span>';
                scrapeBtn.disabled = true;
            } else {
                event.preventDefault();
            }
        });
    }

    // تایمر برای بروزرسانی بعدی کرون سرور
    const countdownTimerSpan = document.getElementById('countdown-timer-server');
    if (countdownTimerSpan && typeof nextRunTimestampFromServer !== 'undefined' && !isNaN(nextRunTimestampFromServer) && nextRunTimestampFromServer) {
        let countdownInterval = setInterval(function() {
            const now = new Date().getTime();
            const distance = nextRunTimestampFromServer - now;

            if (distance < 0) {
                countdownTimerSpan.innerHTML = " (در انتظار اجرای بعدی...)";
                countdownTimerSpan.style.color = '#777';
                return;
            }

            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            let countdownText = " (تا ";
            if (hours > 0) countdownText += hours + " ساعت و ";
            countdownText += String(minutes).padStart(2, '0') + " دقیقه و ";
            countdownText += String(seconds).padStart(2, '0') + " ثانیه دیگر)";

            countdownTimerSpan.innerHTML = countdownText;

            if (distance < 60000) {
                countdownTimerSpan.style.color = '#e74c3c';
            } else if (distance < 300000) {
                countdownTimerSpan.style.color = '#f39c12';
            } else {
                countdownTimerSpan.style.color = '#28a745';
            }
        }, 1000);

        // اجرای اولیه
        (function() {
            const now = new Date().getTime();
            const distance = nextRunTimestampFromServer - now;
            if (distance <= 0) {
                countdownTimerSpan.innerHTML = " (در انتظار اجرای بعدی...)";
                countdownTimerSpan.style.color = '#777';
                return;
            }
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            let countdownText = " (تا ";
            if (hours > 0) countdownText += hours + " ساعت و ";
            countdownText += String(minutes).padStart(2, '0') + " دقیقه و ";
            countdownText += String(seconds).padStart(2, '0') + " ثانیه دیگر)";
            countdownTimerSpan.innerHTML = countdownText;
        })();
    } else if (countdownTimerSpan) {
        countdownTimerSpan.innerHTML = " (زمان‌بندی در دسترس نیست)";
    }
});