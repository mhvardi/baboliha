<?php
// /public_html/lib/debug_handler.php

if (defined('DEBUG_MODE') && DEBUG_MODE) {

    // تابع برای مدیریت خطاهای عادی PHP (Warning, Notice, etc.)
    function customErrorHandler($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            // This error code is not included in error_reporting
            return false;
        }

        $errType = "Unknown error type";
        switch ($errno) {
            case E_ERROR:             $errType = "Error";               break;
            case E_WARNING:           $errType = "Warning";             break;
            case E_PARSE:             $errType = "Parse Error";         break;
            case E_NOTICE:            $errType = "Notice";              break;
            case E_CORE_ERROR:        $errType = "Core Error";          break;
            case E_CORE_WARNING:      $errType = "Core Warning";        break;
            case E_COMPILE_ERROR:     $errType = "Compile Error";       break;
            case E_COMPILE_WARNING:   $errType = "Compile Warning";     break;
            case E_USER_ERROR:        $errType = "User Error";          break;
            case E_USER_WARNING:      $errType = "User Warning";        break;
            case E_USER_NOTICE:       $errType = "User Notice";         break;
            case E_STRICT:            $errType = "Strict Standards";    break;
            case E_RECOVERABLE_ERROR: $errType = "Recoverable Error";   break;
            case E_DEPRECATED:        $errType = "Deprecated";          break;
            case E_USER_DEPRECATED:   $errType = "User Deprecated";     break;
        }

        $errorMessage = "<b>{$errType}:</b> {$errstr} in <b>{$errfile}</b> on line <b>{$errline}</b><br />\n";
        
        // نمایش خطا در صفحه فقط اگر هدرها هنوز ارسال نشده‌اند (برای جلوگیری از خطای بیشتر)
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            echo "<div style='padding: 10px; margin: 10px; border: 1px solid red; background-color: #ffebeb; color: #721c24; font-family: monospace; font-size: 14px;'>{$errorMessage}</div>";
        }
        error_log("PHP {$errType}: {$errstr} in {$errfile} on line {$errline}"); // همیشه در لاگ سرور ثبت کن

        /* Don't execute PHP internal error handler if it's not a fatal error (we've handled it) */
        // For fatal errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), PHP will stop anyway.
        return true; // Supress standard PHP error handling for non-fatal errors
    }

    // تابع برای مدیریت Exception های مدیریت نشده
    function customExceptionHandler($exception) {
        $errorMessage = "<b>Uncaught Exception:</b> " . htmlspecialchars($exception->getMessage()) .
                        " in <b>" . htmlspecialchars($exception->getFile()) . "</b> on line <b>" . htmlspecialchars($exception->getLine()) . "</b><br />\n" .
                        "<pre style='background-color: #f0f0f0; padding: 10px; border: 1px solid #ccc; overflow-x: auto;'>" . htmlspecialchars($exception->getTraceAsString()) . "</pre><br />\n";

        if (!headers_sent() && PHP_SAPI !== 'cli') {
             // اطمینان از ارسال هدر Content-Type قبل از نمایش خطا
            if (!in_array('Content-Type: text/html; charset=utf-8', headers_list())) {
                header('Content-Type: text/html; charset=utf-8');
            }
            http_response_code(500); // ارسال کد خطای 500
            echo "<div style='padding: 10px; margin: 10px; border: 1px solid #d9534f; background-color: #f2dede; color: #a94442; font-family: monospace; font-size: 14px;'>{$errorMessage}</div>";
        }
        error_log("PHP Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . "\nStack trace:\n" . $exception->getTraceAsString());
        
        // پس از نمایش یا لاگ کردن، اسکریپت معمولاً به خاطر Exception متوقف می‌شود.
        // اگر می‌خواهید حتماً متوقف شود:
        // die();
    }

    // تابع برای گرفتن خطاهای Fatal که توسط set_error_handler گرفته نمی‌شوند
    function customShutdownHandler() {
        $lastError = error_get_last();
        if ($lastError !== null && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
            // اگر هدرها هنوز ارسال نشده‌اند، سعی در نمایش خطا
            if (!headers_sent() && PHP_SAPI !== 'cli') {
                if (!in_array('Content-Type: text/html; charset=utf-8', headers_list())) {
                     header('Content-Type: text/html; charset=utf-8');
                }
                 http_response_code(500); // ارسال کد خطای 500
                echo "<div style='padding: 10px; margin: 10px; border: 1px solid darkred; background-color: #ffdddd; color: darkred; font-family: monospace; font-size: 14px;'>";
                echo "<b>FATAL ERROR (Shutdown Handler):</b> [{$lastError['type']}] {$lastError['message']} in <b>{$lastError['file']}</b> on line <b>{$lastError['line']}</b>";
                echo "</div>";
            }
            // خطا قبلاً باید توسط PHP در error_log ثبت شده باشد اگر log_errors فعال است.
            // اما برای اطمینان می‌توانیم دوباره لاگ کنیم:
            error_log("PHP FATAL ERROR (Shutdown): [{$lastError['type']}] {$lastError['message']} in {$lastError['file']} on line {$lastError['line']}");
        }
    }

    // تنظیم Error Handler های سفارشی
    set_error_handler("customErrorHandler");
    set_exception_handler("customExceptionHandler");
    register_shutdown_function("customShutdownHandler");

    // یک پیام که نشان دهد دیباگر فعال است (اختیاری)
    // if (PHP_SAPI !== 'cli' && !headers_sent()) {
    //     echo "<div style='background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 5px; text-align: center; font-size: 0.9em;'>حالت دیباگ فعال است. خطاهای PHP نمایش داده خواهند شد.</div>";
    // }

} // پایان if (defined('DEBUG_MODE') && DEBUG_MODE)
?>