<?php
require_once 'config.php';
requireLogin();

// دریافت آمار کلی
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online,
        SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning,
        SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline
    FROM devices
")->fetch();

// دریافت تجهیزات
$devices = $pdo->query("SELECT * FROM devices ORDER BY device_name")->fetchAll();

// دریافت آخرین لاگ‌ها
$logs = $pdo->query("
    SELECT ml.*, d.device_name, d.ip_address 
    FROM monitoring_logs ml 
    JOIN devices d ON ml.device_id = d.id 
    ORDER BY ml.checked_at DESC 
    LIMIT 20
")->fetchAll();

echo json_encode(array(
    'stats' => $stats,
    'devices' => $devices,
    'logs' => $logs,
    'last_update' => date('Y-m-d H:i:s')
));
?>