<?php
// config.php
session_start();

// تنظیمات دیتابیس - این مقادیر را با اطلاعات واقعی هاست خود جایگزین کنید
define('DB_HOST', 'localhost');
define('DB_NAME', 'database_name');
define('DB_USER', 'database_user'); // معمولاً نام کاربری با نام دیتابیس یکی است
define('DB_PASS', 'database_password'); // رمز واقعی دیتابیس را وارد کنید

// فعال کردن نمایش خطاها برای دیباگ
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    // ذخیره خطا در فایل لاگ
    error_log("Database connection error: " . $e->getMessage());
    
    // نمایش پیام خطای قابل فهم
    die("خطا در اتصال به دیتابیس. لطفاً تنظیمات config.php را بررسی کنید.");
}

// تابع بررسی لاگین
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// تابع بررسی دسترسی
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}
?>