<?php
// /public_html/lib/CronJobOrgHelper.php

if (!defined('CRONJOBORG_API_KEY')) {
    // این ثابت باید در config.php تعریف شود
    // error_log("CronJobOrgHelper: API Key not defined in config.php");
}

if (!function_exists('cronJobOrgApiRequest')) {
    function cronJobOrgApiRequest(string $endpoint, string $method = 'GET', ?array $data = null): ?array {
        if (!defined('CRONJOBORG_API_KEY') || empty(CRONJOBORG_API_KEY)) {
            error_log("CronJobOrg API Key is missing or not defined in config.");
            return ['error' => 'API Key for cron-job.org is not configured.'];
        }
        // ... (منطق cURL مانند قبل، با اطمینان از ارسال هدر Authorization: Bearer) ...
        $baseUrl = 'https://api.cron-job.org/';
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . CRONJOBORG_API_KEY, // استفاده از Bearer token
            'Content-Type: application/json'
        ]);
        // ... (بقیه تنظیمات cURL بر اساس متد GET, POST, PUT, DELETE) ...
        switch (strtoupper($method)) {
            case 'POST': /* ... */ break;
            case 'PUT': /* ... */ break;
            case 'DELETE': /* ... */ break;
        }
        $responseBody = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
        if ($curlError) { error_log("CronJobOrg API cURL Error to {$url}: {$curlError}"); return ['error' => "cURL Error: " . $curlError]; }
        $decodedResponse = json_decode($responseBody, true);
        if ($httpCode >= 400) { /* ... لاگ خطا و بازگرداندن خطا ... */ }
        if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) { /* ... لاگ خطا و بازگرداندن خطا ... */ }
        return $decodedResponse;
    }
}

if (!function_exists('getCronJobOrgJobs')) {
    function getCronJobOrgJobs(): ?array {
        return cronJobOrgApiRequest('jobs'); //
    }
}

if (!function_exists('getCronJobOrgJobDetails')) {
    function getCronJobOrgJobDetails(string $jobId): ?array {
        if(empty($jobId)) return null;
        return cronJobOrgApiRequest('jobs/' . $jobId);
    }
}

if (!function_exists('getCronJobOrgJobHistory')) {
    /**
     * Retrieves the execution history for a specific cron job.
     * @param string $jobId The ID of the cron job.
     * @return array|null The history data or null on error.
     */
    function getCronJobOrgJobHistory(string $jobId): ?array {
        if(empty($jobId)) return null;
        // The endpoint is /jobs/{id}/history according to documentation
        return cronJobOrgApiRequest('jobs/' . $jobId . '/history'); //
    }
}
?>