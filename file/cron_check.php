<?php
// cron_check.php - برای اجرای خودکار با Cron Job
require_once 'config.php';

// این فایل نیاز به لاگین ندارد چون توسط Cron اجرا می‌شود
function checkDevice($ip, $threshold) {
    $start = microtime(true);
    
    $ports = [80, 443, 22, 23, 8080, 53, 21, 25, 110, 143, 3389, 445, 139];
    
    foreach ($ports as $port) {
        $connection = @fsockopen($ip, $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            $end = microtime(true);
            $responseTime = ($end - $start) * 1000;
            
            if ($responseTime > $threshold) {
                return array('status' => 'warning', 'response_time' => round($responseTime, 2));
            } else {
                return array('status' => 'online', 'response_time' => round($responseTime, 2));
            }
        }
    }
    
    return array('status' => 'offline', 'response_time' => 0);
}

$devices = $pdo->query("SELECT * FROM devices")->fetchAll();
$checkedCount = 0;

foreach ($devices as $device) {
    $result = checkDevice($device['ip_address'], $device['ping_threshold']);
    
    // Update device status
    $stmt = $pdo->prepare("UPDATE devices SET status = ?, response_time = ?, last_check = NOW() WHERE id = ?");
    $stmt->execute([$result['status'], $result['response_time'], $device['id']]);
    
    // Insert log
    $stmt = $pdo->prepare("INSERT INTO monitoring_logs (device_id, status, response_time) VALUES (?, ?, ?)");
    $stmt->execute([$device['id'], $result['status'], $result['response_time']]);
    
    $checkedCount++;
}

// ثبت در لاگ سیستم
error_log("Cron check completed: $checkedCount devices checked at " . date('Y-m-d H:i:s'));
?>