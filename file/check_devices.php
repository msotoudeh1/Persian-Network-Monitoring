<?php
// تنظیم زمان اجرا برای تعداد بالا
set_time_limit(60);

require_once 'config.php';
requireLogin();

/**
 * بررسی هوشمند و هم‌زمان (Hybrid Async) تجهیزات در هاست cPanel
 * ترکیبی از ICMP Ping (در صورت مجاز بودن) و Multi-cURL TCP
 *
 * @param array $devices
 * @return array
 */
function checkDevicesCpanel(array $devices) {
    $mh = curl_multi_init();
    $curlHandles = [];
    $results = [];

    // بررسی فعال بودن exec جهت استفاده از ICMP Ping واقعی سیستم‌عامل
    $canExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));

    // ۱. ساخت Handle برای هر تجهیز بر اساس IP و Port اختصاصی
    foreach ($devices as $index => $device) {
        $ip = $device['ip_address'];
        $port = (int)($device['port'] ?? 80);
        
        // تعیین پروتکل بر اساس پورت
        $scheme = ($port === 443) ? 'https' : 'http';
        $url = "{$scheme}://{$ip}:{$port}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => true, // فقط ارسال Header/SYN جهت افزایش سرعت
            CURLOPT_CONNECTTIMEOUT => 2,    // حداکثر زمان انتظار برقراری اتصال (ثانیه)
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        curl_multi_add_handle($mh, $ch);
        $curlHandles[$index] = [
            'handle' => $ch,
            'device' => $device,
            'port'   => $port
        ];
    }

    // ۲. اجرای موازی تمام درخواست‌های TCP
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    // ۳. پردازش نتایج و fallback به ICMP Ping در صورت نیاز
    foreach ($curlHandles as $item) {
        $ch     = $item['handle'];
        $device = $item['device'];
        $ip     = $device['ip_address'];

        $info      = curl_getinfo($ch);
        $curlError = curl_errno($ch);

        $connectTime = round($info['connect_time'] * 1000, 2);
        $threshold   = $device['ping_threshold'];
        $isOnline    = false;
        $responseTime = 0;

        // الف) تست اول: بررسی از طریق TCP Handshake پورت مشخص شده
        if ($curlError !== CURLE_OPERATION_TIMEDOUT && $connectTime > 0) {
            $isOnline = true;
            $responseTime = $connectTime;
        } 
        // ب) تست دوم: اگر TCP پاسخ نداد و exec مجاز بود، بررسی با ICMP Ping سیستمی
        elseif ($canExec) {
            $cmd = "ping -c 1 -w 2 " . escapeshellarg($ip);
            @exec($cmd, $output, $resultCode);
            if ($resultCode === 0) {
                $isOnline = true;
                $responseTime = 1.00; // زمان پیش‌فرض پاسخ ICMP
            }
        }

        // تعیین وضعیت نهایی
        if ($isOnline) {
            $status = ($responseTime > $threshold) ? 'warning' : 'online';
        } else {
            $status = 'offline';
            $responseTime = 0;
        }

        $results[] = [
            'device_id'     => $device['id'],
            'device_name'   => $device['device_name'],
            'ip'            => $ip,
            'port'          => $item['port'],
            'status'        => $status,
            'response_time' => $responseTime
        ];

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

try {
    // دریافت مستقیم اطلاعات تجهیزات شامل ستون جدید port
    $devices = $pdo->query("SELECT id, device_name, ip_address, port, ping_threshold FROM devices")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($devices)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'checked' => 0, 'results' => []]);
        exit;
    }

    // اجرای مانیتورینگ موازی
    $monitoringResults = checkDevicesCpanel($devices);

    // آماده‌سازی دستورات دیتابیس
    $updateStmt = $pdo->prepare("UPDATE devices SET status = ?, response_time = ?, last_check = NOW() WHERE id = ?");
    $logStmt    = $pdo->prepare("INSERT INTO monitoring_logs (device_id, status, response_time) VALUES (?, ?, ?)");

    // شروع Transaction برای ذخیره‌سازی سریع در دیتابیس
    $pdo->beginTransaction();

    $responseList = [];
    foreach ($monitoringResults as $res) {
        $updateStmt->execute([$res['status'], $res['response_time'], $res['device_id']]);
        $logStmt->execute([$res['device_id'], $res['status'], $res['response_time']]);

        $responseList[] = [
            'device'        => $res['device_name'],
            'ip'            => $res['ip'],
            'port'          => $res['port'],
            'status'        => $res['status'],
            'response_time' => $res['response_time']
        ];
    }

    $pdo->commit();

    // ارسال خروجی JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'checked' => count($responseList),
        'results' => $responseList
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>