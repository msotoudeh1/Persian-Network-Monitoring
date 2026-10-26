<?php
date_default_timezone_set('Asia/Tehran');
require_once 'config.php';
requireLogin();

// ۱. کوئری ترکیبی جهت دریافت تمامی آمارها در یک Single Query برای کاهش Latency
$stats = $pdo->query("
    SELECT 
        COUNT(DISTINCT d.id) as total,
        SUM(CASE WHEN d.status = 'online' THEN 1 ELSE 0 END) as online,
        SUM(CASE WHEN d.status = 'warning' THEN 1 ELSE 0 END) as warning,
        SUM(CASE WHEN d.status = 'offline' THEN 1 ELSE 0 END) as offline,
        COUNT(ml.id) as total_checks,
        SUM(CASE WHEN ml.status IN ('online', 'warning') THEN 1 ELSE 0 END) as successful_checks,
        AVG(CASE WHEN ml.checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN ml.response_time ELSE NULL END) as avg_response_24h,
        MAX(CASE WHEN ml.checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN ml.response_time ELSE NULL END) as max_response_24h,
        MIN(CASE WHEN ml.checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN ml.response_time ELSE NULL END) as min_response_24h,
        COUNT(CASE WHEN ml.checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE NULL END) as checks_24h
    FROM devices d
    LEFT JOIN monitoring_logs ml ON 1=1
")->fetch();

$overall_uptime = ($stats['total_checks'] > 0) ? ($stats['successful_checks'] / $stats['total_checks']) * 100 : 0;

// ۲. دریافت تجهیزات و فیلتر کردن آن‌ها در PHP جهت جلوگیری از ارسال کوئری‌های متعدد
$devices = $pdo->query("SELECT * FROM devices ORDER BY device_name")->fetchAll();
$online_devices  = array_filter($devices, fn($d) => $d['status'] === 'online');
$warning_devices = array_filter($devices, fn($d) => $d['status'] === 'warning');
$offline_devices = array_filter($devices, fn($d) => $d['status'] === 'offline');

// ۳. صفحه‌بندی لاگ‌ها
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 5;
$offset = ($page - 1) * $per_page;

$total_logs = $pdo->query("SELECT COUNT(*) as count FROM monitoring_logs")->fetch()['count'];
$total_pages = ceil($total_logs / $per_page);

$stmt = $pdo->prepare("
    SELECT ml.*, d.device_name, d.ip_address, d.port 
    FROM monitoring_logs ml 
    JOIN devices d ON ml.device_id = d.id 
    ORDER BY ml.checked_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$per_page, $offset]);
$logs = $stmt->fetchAll();

// ۴. داده‌های نمودار روند (۲۴ ساعت اخیر)
$trend_data = $pdo->query("
    SELECT 
        DATE_FORMAT(checked_at, '%H:%i') as time_label,
        AVG(response_time) as avg_response,
        COUNT(*) as check_count
    FROM monitoring_logs 
    WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY DATE_FORMAT(checked_at, '%Y-%m-%d %H:%i')
    ORDER BY checked_at DESC
    LIMIT 30
")->fetchAll();
$trend_data = array_reverse($trend_data);

// توابع تاریخ شمسی
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 33 * ((int)($days / 12053));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    $jy += (int)(($days - 1) / 365);
    if ($days > 365) $days = ($days - 1) % 365;
    $jm = ($days < 186) ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
    return [$jy, $jm, $jd];
}

function format_jalali_date($datetime) {
    if (empty($datetime)) return '-';
    $timestamp = strtotime($datetime);
    $gy = date('Y', $timestamp);
    $gm = date('n', $timestamp);
    $gd = date('j', $timestamp);
    $jalali = gregorian_to_jalali($gy, $gm, $gd);
    $months = [1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'];
    return $jalali[2] . ' ' . $months[$jalali[1]] . ' ' . $jalali[0] . ' - ' . date('H:i', $timestamp);
}

$today = getdate();
$jalali_today = gregorian_to_jalali($today['year'], $today['mon'], $today['mday']);
$months = [1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'];
$current_date = $jalali_today[2] . ' ' . $months[$jalali_today[1]] . ' ' . $jalali_today[0];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد مانیتورینگ شبکه</title>
    <script src="3.4.17.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <script src="chart.js"></script>
    <link rel="stylesheet" href="Vazirmatn-font-face.css">
    <style>
        * { font-family: 'Vazirmatn', sans-serif; }
        .chart-container { position: relative; height: 300px; width: 100%; }
        .stat-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer; position: relative; overflow: hidden; }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        .stat-card:hover::before { left: 100%; }
        .stat-card:hover { transform: translateY(-3px); }
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 1000;
            display: flex; align-items: center; justify-content: center; animation: fadeIn 0.2s ease;
        }
        .modal-content {
            border-radius: 20px; max-width: 550px; width: 92%; max-height: 85vh; overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); animation: slideUp 0.2s ease;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .pagination-btn { transition: all 0.2s; }
        .pagination-btn:hover:not(.disabled) { background: #6366f1; color: white; }
        .pagination-btn.active { background: #6366f1; color: white; }
        .pagination-btn.disabled { opacity: 0.4; cursor: not-allowed; }
        @keyframes pulse-green { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); } 70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); } 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }
        @keyframes pulse-yellow { 0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.6); } 70% { box-shadow: 0 0 0 8px rgba(245, 158, 11, 0); } 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); } }
        @keyframes pulse-red { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); } 70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        .status-pulse-green { animation: pulse-green 2s infinite; }
        .status-pulse-yellow { animation: pulse-yellow 2s infinite; }
        .status-pulse-red { animation: pulse-red 2s infinite; }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-100 transition-colors duration-300">
    <div class="container mx-auto px-4 py-8">
        
        <!-- Header -->
<header class="relative bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border border-slate-200/80 dark:border-slate-700/80 rounded-3xl p-6 mb-8 shadow-sm transition-all z-50">
    <div class="flex items-center justify-between gap-4">

        <!-- Brand & Title -->
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 dark:from-indigo-500/20 dark:to-violet-500/20 border border-indigo-100 dark:border-indigo-800 flex items-center justify-center text-indigo-600 dark:text-indigo-400 shadow-inner">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
            </div>
            <div>
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">داشبورد مانیتورینگ</h1>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 border border-emerald-200/60 dark:border-emerald-800/60">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        فعال
                    </span>
                </div>
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mt-1 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span><?php echo $current_date; ?></span>
                    <span class="text-slate-300 dark:text-slate-600">•</span>
                    <span><?php echo date('H:i'); ?></span>
                </p>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-3">

            <!-- Check Now Button -->
            <button onclick="checkNow()" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm shadow-indigo-200 dark:shadow-none transition-all duration-200 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                بررسی فوری پینگ
            </button>

            <!-- Hamburger Button -->
            <button id="hamburgerBtn" onclick="toggleHamburger(event)" class="inline-flex items-center justify-center p-2.5 rounded-xl bg-slate-100 dark:bg-slate-700/60 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-all duration-200" title="منو" aria-label="منو">
                <svg id="hamburgerIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
                <svg id="closeIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
</header>

<!-- Hamburger Menu (بیرون از هدر برای جلوگیری از مشکل عرض) -->
<div id="hamburgerMenu"
     class="hidden fixed w-72 max-w-[calc(100vw-2rem)] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-2xl shadow-slate-900/10 dark:shadow-black/40 p-2 z-[9999] origin-top-left">

    <!-- User Info -->
    <div class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-700/40">
        <div class="w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950/60 flex items-center justify-center text-indigo-700 dark:text-indigo-300 font-bold text-sm border border-indigo-200/60 dark:border-indigo-800/60">
            <?php 
                $name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'ک';
                echo mb_substr($name, 0, 1, 'UTF-8'); 
            ?>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold text-slate-800 dark:text-slate-100 truncate">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'کاربر'); ?>
            </p>
            <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">مدیر سیستم</p>
        </div>
    </div>

    <div class="my-2 h-px bg-slate-100 dark:bg-slate-700"></div>

    <!-- Theme Toggle -->
    <button onclick="toggleDarkMode()" class="w-full flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-700/60 text-slate-700 dark:text-slate-200 text-xs font-semibold transition-all duration-150 cursor-pointer">
        <span class="flex items-center gap-2.5">
            <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
            </svg>
            حالت روشن / تاریک
        </span>
        <span class="inline-flex items-center">
            <svg class="w-4 h-4 hidden dark:block text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            <svg class="w-4 h-4 block dark:hidden text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
        </span>
    </button>

    <!-- Management Link -->
    <a href="index.php" class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-700/60 text-slate-700 dark:text-slate-200 text-xs font-semibold transition-all duration-150">
        <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
        </svg>
        مدیریت تجهیزات
    </a>

    <div class="my-2 h-px bg-slate-100 dark:bg-slate-700"></div>

    <!-- Logout -->
    <a href="logout.php" class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl hover:bg-rose-50 dark:hover:bg-rose-950/40 text-rose-600 dark:text-rose-400 text-xs font-semibold transition-all duration-150">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
        </svg>
        خروج از حساب
    </a>
</div>

<style>
    html, body {
        overflow-x: hidden;
    }
</style>

<script>
    function positionMenu() {
        const btn = document.getElementById('hamburgerBtn');
        const menu = document.getElementById('hamburgerMenu');
        if (!btn || !menu) return;

        const rect = btn.getBoundingClientRect();
        const menuWidth = menu.offsetWidth || 288;

        // موقعیت پیش‌فرض: منو از لبه‌ی چپ دکمه به سمت راست باز بشه
        let left = rect.left;

        // اگر از سمت راست صفحه بیرون زد، به داخل هلش بده
        const overflowRight = (left + menuWidth) - (window.innerWidth - 8);
        if (overflowRight > 0) {
            left = left - overflowRight;
        }
        // اگر از سمت چپ هم بیرون زد (موبایل خیلی باریک)
        if (left < 8) left = 8;

        menu.style.top = (rect.bottom + 12) + 'px';
        menu.style.left = left + 'px';
        menu.style.right = 'auto';
    }

    function toggleHamburger(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('hamburgerMenu');
        const hamIcon = document.getElementById('hamburgerIcon');
        const closeIcon = document.getElementById('closeIcon');
        if (!menu) return;

        const isHidden = menu.classList.contains('hidden');

        menu.classList.toggle('hidden');
        hamIcon.classList.toggle('hidden');
        closeIcon.classList.toggle('hidden');

        if (isHidden) {
            positionMenu();
            menu.style.opacity = '0';
            menu.style.transform = 'translateY(-6px) scale(0.98)';
            requestAnimationFrame(() => {
                menu.style.transition = 'opacity .15s ease, transform .15s ease';
                menu.style.opacity = '1';
                menu.style.transform = 'translateY(0) scale(1)';
            });
        } else {
            menu.style.transition = '';
            menu.style.opacity = '';
            menu.style.transform = '';
        }
    }

    // بستن منو با کلیک بیرون
    document.addEventListener('click', function (e) {
        const menu = document.getElementById('hamburgerMenu');
        const btn = document.getElementById('hamburgerBtn');
        if (!menu || menu.classList.contains('hidden')) return;
        if (!menu.contains(e.target) && !btn.contains(e.target)) {
            toggleHamburger();
        }
    });

    // بستن با کلید Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const menu = document.getElementById('hamburgerMenu');
            if (menu && !menu.classList.contains('hidden')) toggleHamburger();
        }
    });

    // به‌روزرسانی موقعیت منو در ریسایز و اسکرول
    window.addEventListener('resize', () => {
        const menu = document.getElementById('hamburgerMenu');
        if (menu && !menu.classList.contains('hidden')) positionMenu();
    });
    window.addEventListener('scroll', () => {
        const menu = document.getElementById('hamburgerMenu');
        if (menu && !menu.classList.contains('hidden')) positionMenu();
    }, { passive: true });
</script>

        <!-- Statistics Cards (5-Grid) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5 mb-8">

    <!-- Total Devices -->
    <div onclick="showDevicesModal('all')"
         class="group relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1 transition-all duration-300 cursor-pointer">

        <!-- Glow پشت کارت -->
        <div class="absolute -top-16 -right-16 w-40 h-40 rounded-full bg-indigo-400/25 dark:bg-indigo-500/15 blur-3xl opacity-70 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>

        <!-- نوار گرادیان بالای کارت -->
        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-l from-indigo-400 via-indigo-500 to-violet-500"></div>

        <div class="relative flex items-start justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1.5">کل تجهیزات</p>
                <p class="text-4xl font-black text-slate-900 dark:text-white tracking-tighter leading-none">
                    <?php echo $stats['total']; ?>
                </p>
                <p class="mt-2 flex items-center gap-1 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                    <span class="w-1 h-1 rounded-full bg-indigo-500"></span>
                    <span class="text-indigo-600 dark:text-indigo-400 font-bold"><?php echo $stats['checks_24h']; ?></span>
                    بررسی اخیر
                </p>
            </div>

            <div class="relative flex-shrink-0">
                <div class="absolute inset-0 rounded-2xl bg-indigo-500/30 blur-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:scale-110 transition-transform duration-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 12V8c0-1.1.9-2 2-2h10a2 2 0 012 2v4M3 12h18M5 12v8M19 12v8M9 12v8M15 12v8M3 5h18M3 19h18"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- نوار پیشرفت خیلی نازک در پایین -->
        <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-slate-100 dark:bg-slate-700/50">
            <div class="h-full w-full bg-gradient-to-l from-indigo-400 to-violet-500"></div>
        </div>
    </div>

    <!-- Online Devices -->
    <div onclick="showDevicesModal('online')"
         class="group relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-xl hover:shadow-emerald-500/10 hover:-translate-y-1 transition-all duration-300 cursor-pointer">

        <div class="absolute -top-16 -right-16 w-40 h-40 rounded-full bg-emerald-400/25 dark:bg-emerald-500/15 blur-3xl opacity-70 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>

        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-l from-emerald-400 via-emerald-500 to-teal-500"></div>

        <div class="relative flex items-start justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1.5">زنده و فعال</p>
                <p class="text-4xl font-black text-emerald-600 dark:text-emerald-400 tracking-tighter leading-none">
                    <?php echo $stats['online']; ?>
                </p>
                <div class="mt-2 flex items-center gap-1.5">
                    <span class="relative flex w-1.5 h-1.5">
                        <span class="absolute inline-flex w-full h-full rounded-full bg-emerald-500 opacity-75 animate-ping"></span>
                        <span class="relative inline-flex w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    </span>
                    <span class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                        <?php echo $stats['total'] > 0 ? round(($stats['online'] / $stats['total']) * 100, 1) : 0; ?>٪ پایداری
                    </span>
                </div>
            </div>

            <div class="relative flex-shrink-0">
                <div class="absolute inset-0 rounded-2xl bg-emerald-500/30 blur-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/30 group-hover:scale-110 transition-transform duration-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-slate-100 dark:bg-slate-700/50">
            <div class="h-full bg-gradient-to-l from-emerald-400 to-teal-500 transition-all duration-1000"
                 style="width: <?php echo $stats['total'] > 0 ? ($stats['online'] / $stats['total']) * 100 : 0; ?>%"></div>
        </div>
    </div>

    <!-- Warning Devices -->
    <div onclick="showDevicesModal('warning')"
         class="group relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-xl hover:shadow-amber-500/10 hover:-translate-y-1 transition-all duration-300 cursor-pointer">

        <div class="absolute -top-16 -right-16 w-40 h-40 rounded-full bg-amber-400/25 dark:bg-amber-500/15 blur-3xl opacity-70 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>

        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-l from-amber-400 via-amber-500 to-orange-500"></div>

        <div class="relative flex items-start justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1.5">دارای هشدار</p>
                <p class="text-4xl font-black text-amber-500 dark:text-amber-400 tracking-tighter leading-none">
                    <?php echo $stats['warning']; ?>
                </p>
                <p class="mt-2 flex items-center gap-1 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                    <span class="w-1 h-1 rounded-full bg-amber-500"></span>
                    سهم هشدار:
                    <span class="text-amber-600 dark:text-amber-400 font-bold">
                        <?php echo $stats['total'] > 0 ? round(($stats['warning'] / $stats['total']) * 100, 1) : 0; ?>٪
                    </span>
                </p>
            </div>

            <div class="relative flex-shrink-0">
                <div class="absolute inset-0 rounded-2xl bg-amber-500/30 blur-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-400 to-orange-600 flex items-center justify-center text-white shadow-lg shadow-amber-500/30 group-hover:scale-110 transition-transform duration-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M12 3l9 16H3l9-16z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-slate-100 dark:bg-slate-700/50">
            <div class="h-full bg-gradient-to-l from-amber-400 to-orange-500 transition-all duration-1000"
                 style="width: <?php echo $stats['total'] > 0 ? ($stats['warning'] / $stats['total']) * 100 : 0; ?>%"></div>
        </div>
    </div>

    <!-- Offline Devices -->
    <div onclick="showDevicesModal('offline')"
         class="group relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-xl hover:shadow-rose-500/10 hover:-translate-y-1 transition-all duration-300 cursor-pointer">

        <div class="absolute -top-16 -right-16 w-40 h-40 rounded-full bg-rose-400/25 dark:bg-rose-500/15 blur-3xl opacity-70 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>

        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-l from-rose-400 via-rose-500 to-red-600"></div>

        <div class="relative flex items-start justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1.5">قطعی / تایم‌اوت</p>
                <p class="text-4xl font-black text-rose-600 dark:text-rose-400 tracking-tighter leading-none">
                    <?php echo $stats['offline']; ?>
                </p>
                <p class="mt-2 flex items-center gap-1 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                    <span class="w-1 h-1 rounded-full bg-rose-500"></span>
                    نرخ قطعی:
                    <span class="text-rose-600 dark:text-rose-400 font-bold">
                        <?php echo $stats['total'] > 0 ? round(($stats['offline'] / $stats['total']) * 100, 1) : 0; ?>٪
                    </span>
                </p>
            </div>

            <div class="relative flex-shrink-0">
                <div class="absolute inset-0 rounded-2xl bg-rose-500/30 blur-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-rose-500 to-red-600 flex items-center justify-center text-white shadow-lg shadow-rose-500/30 group-hover:scale-110 transition-transform duration-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-slate-100 dark:bg-slate-700/50">
            <div class="h-full bg-gradient-to-l from-rose-400 to-red-600 transition-all duration-1000"
                 style="width: <?php echo $stats['total'] > 0 ? ($stats['offline'] / $stats['total']) * 100 : 0; ?>%"></div>
        </div>
    </div>

    <!-- Avg Latency Card -->
    <div class="group relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-xl hover:shadow-sky-500/10 hover:-translate-y-1 transition-all duration-300">

        <div class="absolute -top-16 -right-16 w-40 h-40 rounded-full bg-sky-400/25 dark:bg-sky-500/15 blur-3xl opacity-70 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>

        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-l from-sky-400 via-cyan-500 to-blue-600"></div>

        <div class="relative flex items-start justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1.5">میانگین تاخیر ۲۴h</p>
                <p class="text-4xl font-black text-sky-600 dark:text-sky-400 tracking-tighter leading-none flex items-baseline gap-1">
                    <?php echo round($stats['avg_response_24h'] ?? 0, 1); ?>
                    <span class="text-[11px] font-bold text-slate-400 dark:text-slate-500">ms</span>
                </p>
                <div class="mt-2 flex items-center gap-2 text-[10px] font-bold">
                    <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"></path></svg>
                        <?php echo round($stats['min_response_24h'] ?? 0); ?>ms
                    </span>
                    <span class="inline-flex items-center gap-1 text-rose-600 dark:text-rose-400">
                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                        <?php echo round($stats['max_response_24h'] ?? 0); ?>ms
                    </span>
                </div>
            </div>

            <div class="relative flex-shrink-0">
                <div class="absolute inset-0 rounded-2xl bg-sky-500/30 blur-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-sky-500 to-blue-600 flex items-center justify-center text-white shadow-lg shadow-sky-500/30 group-hover:scale-110 transition-transform duration-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-slate-100 dark:bg-slate-700/50">
            <div class="h-full w-full bg-gradient-to-l from-sky-400 to-blue-600"></div>
        </div>
    </div>

</div>

<!-- Overall Uptime -->
<div class="relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-6 mb-8 border border-slate-200/80 dark:border-slate-700/80 shadow-sm">

    <!-- Glow پس‌زمینه -->
    <div class="absolute -top-24 -left-24 w-56 h-56 rounded-full bg-emerald-400/20 dark:bg-emerald-500/10 blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-24 -right-24 w-56 h-56 rounded-full bg-teal-400/20 dark:bg-teal-500/10 blur-3xl pointer-events-none"></div>

    <div class="relative flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">

        <!-- آیکون + عنوان -->
        <div class="flex items-center gap-4">
            <div class="relative">
                <div class="absolute inset-0 rounded-2xl bg-emerald-500/30 blur-lg"></div>
                <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/30">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                    </svg>
                </div>
            </div>
            <div>
                <h3 class="text-base font-bold text-slate-900 dark:text-white tracking-tight">درصد در دسترس بودن کلی</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">وضعیت پایداری کل پایش‌ها در دیتابیس</p>
            </div>
        </div>

        <!-- درصد به‌شکل برجسته -->
        <div class="flex items-center gap-3">
            <div class="flex flex-col items-end">
                <div class="flex items-baseline gap-1">
                    <span class="text-4xl font-black tracking-tighter bg-gradient-to-br from-emerald-500 to-teal-600 dark:from-emerald-400 dark:to-teal-500 bg-clip-text text-transparent">
                        <?php echo round($overall_uptime, 2); ?>
                    </span>
                    <span class="text-lg font-bold text-emerald-600 dark:text-emerald-400">٪</span>
                </div>
                <div class="flex items-center gap-1.5 mt-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">پایدار</span>
                </div>
            </div>
        </div>
    </div>

    <!-- نوار پیشرفت مدرن -->
    <div class="relative mt-6">
        <div class="relative w-full h-4 bg-slate-100 dark:bg-slate-700/50 rounded-full overflow-hidden border border-slate-200/60 dark:border-slate-700 shadow-inner">
            <!-- رگه‌های پس‌زمینه -->
            <div class="absolute inset-0 flex">
                <div class="flex-1 border-r border-slate-200/60 dark:border-slate-700/50"></div>
                <div class="flex-1 border-r border-slate-200/60 dark:border-slate-700/50"></div>
                <div class="flex-1 border-r border-slate-200/60 dark:border-slate-700/50"></div>
                <div class="flex-1"></div>
            </div>
            <!-- نوار پیشرفت -->
            <div class="relative h-full rounded-full bg-gradient-to-l from-emerald-400 via-emerald-500 to-teal-600 transition-all duration-1000 shadow-[0_0_12px_rgba(16,185,129,0.5)]"
                 style="width: <?php echo $overall_uptime; ?>%">
                <!-- درخشش متحرک -->
                <div class="absolute inset-0 rounded-full bg-gradient-to-l from-transparent via-white/30 to-transparent animate-pulse"></div>
                <!-- نقطه سر نوار -->
                <div class="absolute top-1/2 -translate-y-1/2 right-0 w-3 h-3 rounded-full bg-white shadow-md shadow-emerald-500/50 ring-2 ring-emerald-500"></div>
            </div>
        </div>

        <!-- مقیاس زیر نوار -->
        <div class="flex justify-between mt-2 px-1">
            <span class="text-[10px] font-medium text-slate-400 dark:text-slate-500">۰٪</span>
            <span class="text-[10px] font-medium text-slate-400 dark:text-slate-500">۲۵٪</span>
            <span class="text-[10px] font-medium text-slate-400 dark:text-slate-500">۵۰٪</span>
            <span class="text-[10px] font-medium text-slate-400 dark:text-slate-500">۷۵٪</span>
            <span class="text-[10px] font-medium text-slate-400 dark:text-slate-500">۱۰۰٪</span>
        </div>
    </div>
</div>

       <!-- Charts Section -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

    <!-- Status Chart Card -->
    <div class="relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-6 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-lg hover:shadow-indigo-500/5 transition-all duration-300">

        <!-- Glow پس‌زمینه -->
        <div class="absolute -top-20 -right-20 w-48 h-48 rounded-full bg-indigo-400/15 dark:bg-indigo-500/10 blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-20 -left-20 w-48 h-48 rounded-full bg-violet-400/15 dark:bg-violet-500/10 blur-3xl pointer-events-none"></div>

        <div class="relative flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="relative">
                    <div class="absolute inset-0 rounded-2xl bg-indigo-500/25 blur-lg"></div>
                    <div class="relative w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                        </svg>
                    </div>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white tracking-tight">توزیع وضعیت تجهیزات</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-1.5">
                        <span class="w-1 h-1 rounded-full bg-indigo-400"></span>
                        تفکیک وضعیت فعلی گره‌ها
                    </p>
                </div>
            </div>
            <span class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800/60 text-[10px] font-semibold">
                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span>
                زنده
            </span>
        </div>

        <div class="relative h-64 w-full">
            <canvas id="statusChart"></canvas>
        </div>
    </div>

    <!-- Response Time Chart Card -->
    <div class="relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-6 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-lg hover:shadow-cyan-500/5 transition-all duration-300">

        <!-- Glow پس‌زمینه -->
        <div class="absolute -top-20 -right-20 w-48 h-48 rounded-full bg-cyan-400/15 dark:bg-cyan-500/10 blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-20 -left-20 w-48 h-48 rounded-full bg-sky-400/15 dark:bg-sky-500/10 blur-3xl pointer-events-none"></div>

        <div class="relative flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="relative">
                    <div class="absolute inset-0 rounded-2xl bg-cyan-500/25 blur-lg"></div>
                    <div class="relative w-11 h-11 rounded-2xl bg-gradient-to-br from-cyan-500 to-sky-600 flex items-center justify-center text-white shadow-lg shadow-cyan-500/30">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white tracking-tight">زمان پاسخگویی تجهیزات</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-1.5">
                        <span class="w-1 h-1 rounded-full bg-cyan-400"></span>
                        تاخیر شبکه به تفکیک دستگاه (ms)
                    </p>
                </div>
            </div>
            <span class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-cyan-50 dark:bg-cyan-950/60 text-cyan-600 dark:text-cyan-400 border border-cyan-100 dark:border-cyan-800/60 text-[10px] font-semibold">
                <span class="w-1.5 h-1.5 rounded-full bg-cyan-500 animate-pulse"></span>
                زنده
            </span>
        </div>

        <div class="relative h-64 w-full">
            <canvas id="responseChart"></canvas>
        </div>
    </div>

</div>

        <!-- Trend Chart -->
<?php if (!empty($trend_data)): ?>
<div class="relative overflow-hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl p-6 mb-8 border border-slate-200/80 dark:border-slate-700/80 shadow-sm hover:shadow-lg hover:shadow-violet-500/5 transition-all duration-300">

    <!-- Glow پس‌زمینه -->
    <div class="absolute -top-24 -right-24 w-56 h-56 rounded-full bg-violet-400/15 dark:bg-violet-500/10 blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-24 -left-24 w-56 h-56 rounded-full bg-fuchsia-400/15 dark:bg-fuchsia-500/10 blur-3xl pointer-events-none"></div>

    <div class="relative flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">

        <!-- آیکون + عنوان -->
        <div class="flex items-center gap-3">
            <div class="relative">
                <div class="absolute inset-0 rounded-2xl bg-violet-500/25 blur-lg"></div>
                <div class="relative w-11 h-11 rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-600 flex items-center justify-center text-white shadow-lg shadow-violet-500/30">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                    </svg>
                </div>
            </div>
            <div>
                <h3 class="text-base font-bold text-slate-900 dark:text-white tracking-tight">روند نوسان Latency</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-1.5">
                    <span class="w-1 h-1 rounded-full bg-violet-400"></span>
                    تحلیل زمانی پایداری شبکه در ۲۴ ساعت اخیر
                </p>
            </div>
        </div>

        <!-- نشانگر زنده -->
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-violet-50 dark:bg-violet-950/60 text-violet-600 dark:text-violet-400 border border-violet-200/60 dark:border-violet-800/60 text-[11px] font-semibold">
                <span class="relative flex w-2 h-2">
                    <span class="absolute inline-flex w-full h-full rounded-full bg-violet-500 opacity-75 animate-ping"></span>
                    <span class="relative inline-flex w-2 h-2 rounded-full bg-violet-500"></span>
                </span>
                Live Trend
            </span>
        </div>
    </div>

    <!-- کانواس -->
    <div class="relative h-64 w-full">
        <canvas id="trendChart"></canvas>
    </div>

</div>
<?php endif; ?>

        <!-- Live Devices Table -->
        <div class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl shadow-sm overflow-hidden mb-8 border border-slate-200/80 dark:border-slate-700/80">
            <div class="flex justify-between items-center p-6 border-b border-slate-100 dark:border-slate-700/60">
                <div class="flex items-center gap-2.5">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 border border-indigo-100 dark:border-indigo-800 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path></svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white tracking-tight">وضعیت زنده تجهیزات</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">پایش آنلاین تمامی گره‌ها</p>
                    </div>
                </div>
                <span class="px-3 py-1 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-slate-200/60 dark:border-slate-600 text-xs font-bold">
                    <?php echo count($devices); ?> دستگاه
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-50/70 dark:bg-slate-900/40 border-b border-slate-100 dark:border-slate-700/60 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                            <th class="px-6 py-4">نام تجهیز</th>
                            <th class="px-6 py-4">آدرس IP</th>
                            <th class="px-6 py-4">پورت</th>
                            <th class="px-6 py-4">وضعیت</th>
                            <th class="px-6 py-4">زمان پاسخ</th>
                            <th class="px-6 py-4">آخرین بررسی</th>
                            <th class="px-6 py-4">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50 text-sm">
                        <?php foreach ($devices as $device): ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-700/30 transition-colors">
                            <td class="px-6 py-4">
                                <a href="device_details.php?id=<?php echo $device['id']; ?>" class="font-bold text-slate-900 dark:text-slate-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                                    <?php echo htmlspecialchars($device['device_name']); ?>
                                </a>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs font-medium text-slate-600 dark:text-slate-400 dir-ltr text-right"><?php echo htmlspecialchars($device['ip_address']); ?></td>
                            <td class="px-6 py-4 font-mono text-xs font-semibold text-slate-700 dark:text-slate-300 dir-ltr text-right">
                                <?php echo !empty($device['port']) ? htmlspecialchars($device['port']) : '<span class="text-slate-400 italic">پیش‌فرض</span>'; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php
                                $statusClass = 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 border-slate-200';
                                $statusText = 'نامشخص';
                                $pulseClass = '';
                                if ($device['status'] == 'online') {
                                    $statusClass = 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border-emerald-200/80 dark:border-emerald-800';
                                    $statusText = 'زنده';
                                    $pulseClass = 'status-pulse-green';
                                } elseif ($device['status'] == 'warning') {
                                    $statusClass = 'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 border-amber-200/80 dark:border-amber-800';
                                    $statusText = 'هشدار';
                                    $pulseClass = 'status-pulse-yellow';
                                } elseif ($device['status'] == 'offline') {
                                    $statusClass = 'bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border-rose-200/80 dark:border-rose-800';
                                    $statusText = 'تایم اوت';
                                    $pulseClass = 'status-pulse-red';
                                }
                                ?>
                                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold border <?php echo $statusClass; ?> <?php echo $pulseClass; ?>">
                                    <span class="w-2 h-2 rounded-full bg-current"></span>
                                    <?php echo $statusText; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-bold text-slate-900 dark:text-slate-100"><?php echo $device['response_time']; ?></span>
                                <span class="text-slate-400 text-xs">ms</span>
                            </td>
                            <td class="px-6 py-4 text-xs font-medium text-slate-600 dark:text-slate-400">
                                <?php echo format_jalali_date($device['last_check']); ?>
                            </td>
                            <td class="px-6 py-4">
                                <a href="device_details.php?id=<?php echo $device['id']; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/80 text-indigo-700 dark:text-indigo-300 rounded-xl transition-all text-xs font-bold border border-indigo-100 dark:border-indigo-800/80">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    مشاهده
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Logs Section (Glassmorphic) -->
        <div class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-3xl shadow-sm overflow-hidden border border-slate-200/80 dark:border-slate-700/80">
            <div class="flex justify-between items-center p-6 border-b border-slate-100 dark:border-slate-700/60">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">لاگ‌های اخیر رویدادها</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">آخرین پایش‌های انجام شده سیستم</p>
                </div>
                <span class="text-xs font-semibold px-3 py-1 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300"><?php echo $total_logs; ?> رویداد</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-50/70 dark:bg-slate-900/40 border-b border-slate-100 dark:border-slate-700/60 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                            <th class="px-6 py-4">زمان ثبت</th>
                            <th class="px-6 py-4">نام تجهیز</th>
                            <th class="px-6 py-4">آدرس IP</th>
                            <th class="px-6 py-4">وضعیت</th>
                            <th class="px-6 py-4">زمان پاسخ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50 text-sm">
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-700/30 transition-colors">
                            <td class="px-6 py-4 text-xs font-medium text-slate-600 dark:text-slate-400"><?php echo format_jalali_date($log['checked_at']); ?></td>
                            <td class="px-6 py-4 font-bold text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($log['device_name']); ?></td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-600 dark:text-slate-400 dir-ltr text-right">
                                <?php echo htmlspecialchars($log['ip_address']) . (!empty($log['port']) ? ':' . htmlspecialchars($log['port']) : ''); ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php
                                $logStatusClass = 'text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-700';
                                $logStatusText = 'نامشخص';
                                if ($log['status'] == 'online') {
                                    $logStatusClass = 'text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/60 border-emerald-200/80 dark:border-emerald-800';
                                    $logStatusText = 'زنده';
                                } elseif ($log['status'] == 'warning') {
                                    $logStatusClass = 'text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/60 border-amber-200/80 dark:border-amber-800';
                                    $logStatusText = 'هشدار';
                                } elseif ($log['status'] == 'offline') {
                                    $logStatusClass = 'text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/60 border-rose-200/80 dark:border-rose-800';
                                    $logStatusText = 'تایم اوت';
                                }
                                ?>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border <?php echo $logStatusClass; ?>">
                                    <?php echo $logStatusText; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-bold text-slate-900 dark:text-slate-100"><?php echo $log['response_time']; ?></span>
                                <span class="text-slate-400 text-xs">ms</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="flex items-center justify-between px-6 py-4 border-t border-slate-100 dark:border-slate-700/60 bg-slate-50/50 dark:bg-slate-900/20">
                <div class="text-xs font-medium text-slate-500 dark:text-slate-400">
                    صفحه <?php echo $page; ?> از <?php echo $total_pages; ?>
                </div>
                <div class="flex items-center gap-1">
                    <a href="?page=<?php echo $page - 1; ?>" class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?> w-8 h-8 flex items-center justify-center rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </a>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    if ($start_page > 1): ?>
                        <a href="?page=1" class="pagination-btn w-8 h-8 flex items-center justify-center rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-xs">1</a>
                        <?php if ($start_page > 2): ?><span class="px-1 text-slate-400">...</span><?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?> w-8 h-8 flex items-center justify-center rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                    
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?><span class="px-1 text-slate-400">...</span><?php endif; ?>
                        <a href="?page=<?php echo $total_pages; ?>" class="pagination-btn w-8 h-8 flex items-center justify-center rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-xs"><?php echo $total_pages; ?></a>
                    <?php endif; ?>
                    
                    <a href="?page=<?php echo $page + 1; ?>" class="pagination-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?> w-8 h-8 flex items-center justify-center rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal (Enhanced with Port Info) -->
    <div id="devicesModal" class="modal-overlay hidden" onclick="closeModal(event)">
        <div class="modal-content p-6 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100" onclick="event.stopPropagation()">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-slate-100 dark:border-slate-700">
                <h3 id="modalTitle" class="text-base font-bold text-slate-900 dark:text-white"></h3>
                <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div id="modalContent" class="space-y-3"></div>
        </div>
    </div>

    <script>
        // Dark Mode Logic
        function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        }

        function toggleDarkMode() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
            updateChartColors();
        }

        initTheme();

        // Color helper for Charts in Dark/Light
        function isDark() { return document.documentElement.classList.contains('dark'); }
        function getTextColor() { return isDark() ? '#94a3b8' : '#64748b'; }
        function getGridColor() { return isDark() ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)'; }

        // Chart.js - Status Chart
        const ctx1 = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: ['زنده', 'هشدار', 'تایم اوت'],
                datasets: [{
                    data: [
                        <?php echo $stats['online']; ?>,
                        <?php echo $stats['warning']; ?>,
                        <?php echo $stats['offline']; ?>
                    ],
                    backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick: function(e, elements) {
                    if (elements.length > 0) {
                        const idx = elements[0].index;
                        if (idx === 0) showDevicesModal('online');
                        else if (idx === 1) showDevicesModal('warning');
                        else if (idx === 2) showDevicesModal('offline');
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        rtl: true,
                        labels: { font: { family: 'Vazirmatn', size: 11 }, color: getTextColor(), padding: 15 }
                    }
                },
                cutout: '65%'
            }
        });

        // Response Time Chart
        const ctx2 = document.getElementById('responseChart').getContext('2d');
        const deviceNames = <?php echo json_encode(array_column($devices, 'device_name')); ?>;
        const responseTimes = <?php echo json_encode(array_column($devices, 'response_time')); ?>;
        const deviceIds = <?php echo json_encode(array_column($devices, 'id')); ?>;
        const deviceIPs = <?php echo json_encode(array_column($devices, 'ip_address')); ?>;
        const devicePorts = <?php echo json_encode(array_column($devices, 'port')); ?>;
        const deviceStatuses = <?php echo json_encode(array_column($devices, 'status')); ?>;
        
        const responseChart = new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: deviceNames,
                datasets: [{
                    label: 'زمان پاسخ (ms)',
                    data: responseTimes,
                    backgroundColor: responseTimes.map(time => (time == 0 ? '#EF4444' : (time > 100 ? '#F59E0B' : '#10B981'))),
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick: function(e, elements) {
                    if (elements.length > 0) {
                        const idx = elements[0].index;
                        showSingleDeviceModal(deviceIds[idx], deviceNames[idx], deviceIPs[idx], devicePorts[idx], deviceStatuses[idx], responseTimes[idx]);
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: getGridColor() },
                        ticks: { color: getTextColor(), font: { family: 'Vazirmatn', size: 10 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: getTextColor(), font: { family: 'Vazirmatn', size: 10 }, maxRotation: 45 }
                    }
                },
                plugins: { legend: { display: false } }
            }
        });

        <?php if (!empty($trend_data)): ?>
        // Trend Chart
        const ctx3 = document.getElementById('trendChart').getContext('2d');
        const trendLabels = <?php echo json_encode(array_column($trend_data, 'time_label')); ?>;
        const trendValues = <?php echo json_encode(array_column($trend_data, 'avg_response')); ?>;
        
        const trendChart = new Chart(ctx3, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'میانگین زمان پاسخ (ms)',
                    data: trendValues,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 2,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: getGridColor() },
                        ticks: { color: getTextColor(), font: { family: 'Vazirmatn', size: 10 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: getTextColor(), font: { family: 'Vazirmatn', size: 10 }, maxTicksLimit: 12 }
                    }
                },
                plugins: {
                    legend: {
                        display: true, position: 'top', rtl: true,
                        labels: { font: { family: 'Vazirmatn', size: 11 }, color: getTextColor() }
                    }
                }
            }
        });
        <?php endif; ?>

        function updateChartColors() {
            const tc = getTextColor();
            const gc = getGridColor();
            [statusChart, responseChart, typeof trendChart !== 'undefined' ? trendChart : null].forEach(chart => {
                if(!chart) return;
                if(chart.options.scales?.y) chart.options.scales.y.grid.color = gc;
                if(chart.options.scales?.y) chart.options.scales.y.ticks.color = tc;
                if(chart.options.scales?.x) chart.options.scales.x.ticks.color = tc;
                if(chart.options.plugins?.legend) chart.options.plugins.legend.labels.color = tc;
                chart.update();
            });
        }

        // Modal Handler
        function showDevicesModal(type) {
            const modal = document.getElementById('devicesModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');
            
            let title = '';
            let devices = [];
            
            switch(type) {
                case 'all': title = 'همه تجهیزات'; devices = <?php echo json_encode($devices); ?>; break;
                case 'online': title = 'تجهیزات زنده'; devices = <?php echo json_encode(array_values($online_devices)); ?>; break;
                case 'warning': title = 'تجهیزات با هشدار'; devices = <?php echo json_encode(array_values($warning_devices)); ?>; break;
                case 'offline': title = 'تجهیزات تایم اوت'; devices = <?php echo json_encode(array_values($offline_devices)); ?>; break;
            }
            
            modalTitle.textContent = title + ' (' + devices.length + ')';
            
            if (devices.length === 0) {
                modalContent.innerHTML = '<p class="text-slate-400 text-center py-6 text-xs">هیچ تجهیزی یافت نشد</p>';
            } else {
                let html = '';
                devices.forEach(d => {
                    let stBg = 'bg-emerald-500/10 text-emerald-500';
                    let stTxt = 'زنده';
                    if (d.status === 'warning') { stBg = 'bg-amber-500/10 text-amber-500'; stTxt = 'هشدار'; }
                    else if (d.status === 'offline') { stBg = 'bg-rose-500/10 text-rose-500'; stTxt = 'تایم اوت'; }
                    
                    const portStr = d.port ? `:${d.port}` : '';
                    
                    html += `<div class="flex items-center justify-between p-3.5 bg-slate-50 dark:bg-slate-700/40 rounded-xl border border-slate-100 dark:border-slate-700/60">
                        <div>
                            <p class="font-bold text-xs text-slate-900 dark:text-white">${d.device_name}</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 font-mono mt-0.5">${d.ip_address}${portStr}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold ${stBg}">${stTxt} - ${d.response_time}ms</span>
                            <a href="device_details.php?id=${d.id}" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-[10px] font-semibold transition-colors">جزئیات</a>
                        </div>
                    </div>`;
                });
                modalContent.innerHTML = html;
            }
            modal.classList.remove('hidden');
        }

        function showSingleDeviceModal(id, name, ip, port, status, responseTime) {
            const modal = document.getElementById('devicesModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');
            
            let stBg = 'bg-emerald-500/10 text-emerald-500';
            let stTxt = 'زنده';
            if (status === 'warning') { stBg = 'bg-amber-500/10 text-amber-500'; stTxt = 'هشدار'; }
            else if (status === 'offline') { stBg = 'bg-rose-500/10 text-rose-500'; stTxt = 'تایم اوت'; }
            
            const portStr = port ? `:${port}` : '';

            modalTitle.textContent = 'جزئیات سریع تجهیز';
            modalContent.innerHTML = `
                <div class="p-4 bg-slate-50 dark:bg-slate-700/40 rounded-2xl border border-slate-100 dark:border-slate-700/60 space-y-3">
                    <div class="flex justify-between items-start">
                        <div>
                            <h4 class="font-bold text-sm text-slate-900 dark:text-white">${name}</h4>
                            <p class="text-xs font-mono text-slate-500 dark:text-slate-400 mt-0.5">${ip}${portStr}</p>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold ${stBg}">${stTxt}</span>
                    </div>
                    <div class="text-xs text-slate-600 dark:text-slate-300 pt-2 border-t border-slate-200/60 dark:border-slate-600/60 flex justify-between items-center">
                        <span>زمان پاسخ: <strong>${responseTime} ms</strong></span>
                        <a href="device_details.php?id=${id}" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-xs transition-colors">مشاهده صفحه کامل</a>
                    </div>
                </div>
            `;
            modal.classList.remove('hidden');
        }

        function closeModal(event) {
            if (event && event.target !== event.currentTarget) return;
            document.getElementById('devicesModal').classList.add('hidden');
        }

        function checkNow() {
            const button = event.currentTarget;
            const originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = 'در حال بررسی...';
            
            fetch('check_devices.php')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showNotification('بررسی با موفقیت انجام شد. ' + data.checked + ' دستگاه پایش شد.', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification('خطا در بررسی تجهیزات', 'error');
                    }
                })
                .catch(() => showNotification('خطا در ارتباط با سرور', 'error'))
                .finally(() => {
                    button.disabled = false;
                    button.innerHTML = originalText;
                });
        }

        function showNotification(message, type) {
            const n = document.createElement('div');
            n.className = 'fixed top-4 left-1/2 transform -translate-x-1/2 z-50 px-6 py-3 rounded-2xl shadow-xl text-white font-bold text-xs transition-all';
            n.style.backgroundColor = type === 'success' ? '#10B981' : '#EF4444';
            n.textContent = message;
            document.body.appendChild(n);
            setTimeout(() => n.remove(), 3000);
        }

        setInterval(() => location.reload(), 300000);
    </script>
</body>
</html>