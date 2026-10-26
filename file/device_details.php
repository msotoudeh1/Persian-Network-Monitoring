<?php
require_once 'config.php';
requireLogin();

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $device_id = intval($_GET['id']);
    
    // دریافت اطلاعات تجهیز (شامل ستون port)
    $stmt = $pdo->prepare("SELECT id, device_name, ip_address, IFNULL(port, 80) AS port, ping_threshold, status, last_check, response_time, created_at FROM devices WHERE id = ?");
    $stmt->execute([$device_id]);
    $device = $stmt->fetch();
    
    if ($device) {
                // تشخیص IP یا دامنه و Resolve کردن دامنه به IP
        $is_input_ip = filter_var($device['ip_address'], FILTER_VALIDATE_IP);
        $resolved_ip = $is_input_ip ? $device['ip_address'] : null;
        
        if (!$is_input_ip) {
            // تلاش برای تبدیل دامنه به IP
            $temp_ip = gethostbyname($device['ip_address']);
            if ($temp_ip !== $device['ip_address'] && filter_var($temp_ip, FILTER_VALIDATE_IP)) {
                $resolved_ip = $temp_ip;
            }
        }
        // دریافت تاریخچه 24 ساعت اخیر
        $stmt = $pdo->prepare("
            SELECT status, response_time, checked_at 
            FROM monitoring_logs 
            WHERE device_id = ? 
            AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY checked_at DESC
        ");
        $stmt->execute([$device_id]);
        $logs = $stmt->fetchAll();
        
        // محاسبه آمار
        $total_checks = count($logs);
        $online_count = 0;
        $warning_count = 0;
        $offline_count = 0;
        $total_response_time = 0;
        $max_response_time = 0;
        
        foreach ($logs as $log) {
            if ($log['status'] == 'online') $online_count++;
            if ($log['status'] == 'warning') $warning_count++;
            if ($log['status'] == 'offline') $offline_count++;
            
            $total_response_time += $log['response_time'];
            if ($log['response_time'] > $max_response_time) {
                $max_response_time = $log['response_time'];
            }
        }
        
        $avg_response_time = $total_checks > 0 ? $total_response_time / $total_checks : 0;
        $uptime_percentage = $total_checks > 0 ? (($online_count + $warning_count) / $total_checks) * 100 : 0;
        
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
            $months = [
                1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
                7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
            ];
            return $jalali[2] . ' ' . $months[$jalali[1]] . ' ' . $jalali[0] . ' - ' . date('H:i', $timestamp);
        }
        
        $today = getdate();
        $jalali_today = gregorian_to_jalali($today['year'], $today['mon'], $today['mday']);
        $months = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
            7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
        ];
        $current_date = $jalali_today[2] . ' ' . $months[$jalali_today[1]] . ' ' . $jalali_today[0];
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>جزئیات تجهیز - <?php echo htmlspecialchars($device['device_name']); ?></title>
            <script src="3.4.17.js"></script>
            <script src="chart.js"></script>
            <link rel="stylesheet" href="Vazirmatn-font-face.css">
            <style>
                * { font-family: 'Vazirmatn', sans-serif; }
                body { background: #f8fafc; }
                
                @keyframes pulse-green {
                    0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); }
                    50% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
                }
                @keyframes pulse-yellow {
                    0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.6); }
                    50% { box-shadow: 0 0 0 8px rgba(245, 158, 11, 0); }
                }
                @keyframes pulse-red {
                    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); }
                    50% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
                }
                
                .status-pulse-green { animation: pulse-green 2s infinite; }
                .status-pulse-yellow { animation: pulse-yellow 2s infinite; }
                .status-pulse-red { animation: pulse-red 2s infinite; }
                
                .toast-enter { transform: translate(-50%, -100%); opacity: 0; }
                .toast-show { transform: translate(-50%, 0); opacity: 1; }
            </style>
        </head>
        <body>
            <div id="toastContainer" class="fixed top-5 left-1/2 z-50 transition-all duration-300 -translate-x-1/2"></div>
            
            <div class="min-h-screen pb-12">
                <!-- Top Navigation -->
<nav class="bg-white/80 backdrop-blur-xl border-b border-slate-200/80 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-600/20 text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-slate-900 tracking-tight">جزئیات تجهیز</h1>
                    <p class="text-xs font-medium text-slate-500 mt-0.5"><?php echo $current_date; ?></p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <button id="btnCheckNow" onclick="checkNow(event)" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm shadow-indigo-200 transition-all duration-200 active:scale-95 cursor-pointer">
                    <svg id="btnSpinner" class="w-4 h-4 hidden animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg id="btnIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span id="btnText">بررسی فوری پینگ</span>
                </button>
                
                <!-- Dropdown Menu -->
                <div class="relative" id="dropdownContainer">
                    <button onclick="toggleDropdown(event)" class="px-4 py-2.5 rounded-2xl text-xs font-bold bg-slate-900 hover:bg-slate-800 text-white shadow-sm transition-all duration-200 flex items-center gap-2 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                        منو
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    
                    <!-- Dropdown Content -->
                    <div id="dropdownMenu" class="hidden absolute left-0 mt-2 w-56 bg-white rounded-2xl shadow-lg border border-slate-200 py-2 z-50">
                        <a href="https://monitor.alborzlink.ir/index.php" class="flex items-center gap-3 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50 transition-all duration-200">
                            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            مدیریت تجهیزات
                        </a>
                        <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50 transition-all duration-200">
                            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                            </svg>
                            بازگشت به داشبورد
                        </a>
                        <div class="border-t border-slate-100 my-1"></div>
                        <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-sm text-rose-600 hover:bg-rose-50 transition-all duration-200">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                            </svg>
                            خروج
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<script>
    function toggleDropdown(event) {
        event.stopPropagation();
        const menu = document.getElementById('dropdownMenu');
        menu.classList.toggle('hidden');
    }
    
    // بستن منو با کلیک بیرون
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('dropdownContainer');
        const menu = document.getElementById('dropdownMenu');
        if (!dropdown.contains(event.target)) {
            menu.classList.add('hidden');
        }
    });
</script>

                <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <!-- Device Header -->
                    <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-6 mb-8 border border-slate-200/80 shadow-sm">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                            <div class="flex items-center gap-4">
                                <div class="w-14 h-14 bg-indigo-50/80 border border-indigo-100/80 rounded-2xl flex items-center justify-center text-indigo-600 shadow-inner">
                                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 12V8c0-1.1.9-2 2-2h10a2 2 0 012 2v4M3 12h18M5 12v8M19 12v8M9 12v8M15 12v8M3 5h18M3 19h18"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-xl font-black text-slate-900 tracking-tight"><?php echo htmlspecialchars($device['device_name']); ?></h2>
<p class="text-slate-500 font-mono text-xs font-semibold mt-1 dir-ltr text-right break-all">
    <?php echo htmlspecialchars($device['ip_address']); ?>:<span class="text-indigo-600 font-bold"><?php echo htmlspecialchars($device['port']); ?></span>
    <?php if (!$is_input_ip && $resolved_ip): ?>
        <span class="text-slate-400 text-[10px] font-normal mr-1">(IP: <?php echo htmlspecialchars($resolved_ip); ?>)</span>
    <?php endif; ?>
</p>
                                </div>
                            </div>
                            
                            <?php
                            $statusClass = 'bg-slate-100 text-slate-700 border-slate-200';
                            $statusText = 'نامشخص';
                            $pulseClass = '';
                            
                            if ($device['status'] == 'online') {
                                $statusClass = 'bg-emerald-50 text-emerald-700 border-emerald-200/80';
                                $statusText = 'زنده';
                                $pulseClass = 'status-pulse-green';
                            } elseif ($device['status'] == 'warning') {
                                $statusClass = 'bg-amber-50 text-amber-700 border-amber-200/80';
                                $statusText = 'هشدار (تاخیر بالا)';
                                $pulseClass = 'status-pulse-yellow';
                            } elseif ($device['status'] == 'offline') {
                                $statusClass = 'bg-rose-50 text-rose-700 border-rose-200/80';
                                $statusText = 'قطع / تایم‌اوت';
                                $pulseClass = 'status-pulse-red';
                            }
                            ?>
                            <span class="inline-flex items-center gap-2.5 px-4 py-2 rounded-2xl text-xs font-bold border shadow-xs <?php echo $statusClass; ?> <?php echo $pulseClass; ?>">
                                <span class="relative flex h-2.5 w-2.5">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 bg-current"></span>
                                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-current"></span>
                                </span>
                                <?php echo $statusText; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Main Stats Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                        <!-- کارت جدید: آدرس IP یا دامنه -->
                        <!-- کارت: آدرس IP / دامنه + IP Resolve شده -->
                        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold text-slate-500">
                                    <?php echo $is_input_ip ? 'آدرس IP' : 'آدرس دامنه'; ?>
                                </span>
                                <div class="w-9 h-9 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600 shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
                                </div>
                            </div>
                            <p class="text-sm font-black text-slate-900 font-mono dir-ltr text-right break-all">
                                <?php echo htmlspecialchars($device['ip_address']); ?>
                            </p>
                            
                            <?php if (!$is_input_ip): ?>
                                <div class="mt-2 pt-2 border-t border-slate-100">
                                    <span class="text-[10px] text-slate-400 font-semibold block mb-0.5">IP Resolved ↴</span>
                                    <?php if ($resolved_ip): ?>
                                        <p class="text-sm font-black text-indigo-600 font-mono dir-ltr text-right break-all">
                                            <?php echo htmlspecialchars($resolved_ip); ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="text-xs font-bold text-rose-500">قابل Resolve نیست</p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- کارت جدید: پورت -->
                        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold text-slate-500">پورت (Port)</span>
                                <div class="w-9 h-9 bg-purple-50 rounded-xl flex items-center justify-center text-purple-600 shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                                </div>
                            </div>
                            <p class="text-2xl font-black text-slate-900 font-mono dir-ltr text-right">
                                <?php echo htmlspecialchars($device['port']); ?>
                            </p>
                        </div>

                        <!-- کارت: آستانه مجاز -->
                        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold text-slate-500">آستانه مجاز</span>
                                <div class="w-9 h-9 bg-amber-50 rounded-xl flex items-center justify-center text-amber-600 shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                            </div>
                            <p class="text-2xl font-black text-slate-900"><?php echo $device['ping_threshold']; ?> <span class="text-xs font-bold text-slate-400">ms</span></p>
                        </div>

                        <!-- کارت: میانگین تاخیر -->
                        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold text-slate-500">میانگین تاخیر (۲۴س)</span>
                                <div class="w-9 h-9 bg-blue-50 rounded-xl flex items-center justify-center text-blue-600 shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                                </div>
                            </div>
                            <p class="text-2xl font-black text-slate-900"><?php echo round($avg_response_time, 1); ?> <span class="text-xs font-bold text-slate-400">ms</span></p>
                        </div>

                        <!-- کارت: پایداری اتصال -->
                        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-all">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold text-slate-500">پایداری اتصال</span>
                                <div class="w-9 h-9 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600 shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                            </div>
                            <p class="text-2xl font-black text-emerald-600"><?php echo round($uptime_percentage, 1); ?>٪</p>
                        </div>
                    </div>

                    <!-- NEW WIDGET: Network Analysis & Health -->
                    <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-6 mb-8 border border-slate-200/80 shadow-sm">
                        <h3 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                            تحلیل سلامت و رفتار شبکه (۲۴ ساعت گذشته)
                        </h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                            <div class="p-4 bg-slate-50/80 rounded-2xl border border-slate-100">
                                <span class="text-xs text-slate-500 font-medium block mb-1">کل پایش‌ها</span>
                                <span class="text-xl font-bold text-slate-800"><?php echo $total_checks; ?></span>
                            </div>
                            <div class="p-4 bg-emerald-50/50 rounded-2xl border border-emerald-100">
                                <span class="text-xs text-emerald-700 font-medium block mb-1">تعداد وضعیت آنلاین</span>
                                <span class="text-xl font-bold text-emerald-700"><?php echo $online_count; ?></span>
                            </div>
                            <div class="p-4 bg-rose-50/50 rounded-2xl border border-rose-100">
                                <span class="text-xs text-rose-700 font-medium block mb-1">تعداد قطعی (تایم‌اوت)</span>
                                <span class="text-xl font-bold text-rose-700"><?php echo $offline_count; ?></span>
                            </div>
                            <div class="p-4 bg-amber-50/50 rounded-2xl border border-amber-100">
                                <span class="text-xs text-amber-700 font-medium block mb-1">حداکثر تاخیر ثبت‌شده</span>
                                <span class="text-xl font-bold text-amber-700"><?php echo $max_response_time; ?> <span class="text-xs">ms</span></span>
                            </div>
                        </div>
                    </div>

                    <!-- Response Time Chart -->
                    <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-6 mb-8 border border-slate-200/80 shadow-sm">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-sm font-bold text-slate-900 tracking-tight">تاریخچه زمان پاسخ (Ping Latency)</h3>
                                <p class="text-xs text-slate-500 mt-0.5">نمودار نوسانات به همراه خط آستانه هشدار</p>
                            </div>
                            <span class="px-3 py-1 rounded-full bg-indigo-50 text-indigo-600 border border-indigo-200/60 text-xs font-bold">
                                ۲۴ ساعت اخیر
                            </span>
                        </div>
                        <div class="relative h-72 w-full">
                            <canvas id="historyChart"></canvas>
                        </div>
                    </div>

                    <!-- Logs Table -->
                    <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-sm overflow-hidden border border-slate-200/80">
                        <div class="flex justify-between items-center p-6 border-b border-slate-100 bg-slate-50/50">
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">جدول لاگ‌های سیستم</h3>
                                <p class="text-xs text-slate-500 mt-0.5">ثبت جزئیات تمامی درخواست‌های ارسال شده</p>
                            </div>
                            <span class="px-3 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200/60 text-xs font-semibold">
                                <?php echo $total_checks; ?> رکورد
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-right border-collapse">
                                <thead class="bg-slate-50/80 text-slate-500 uppercase text-[11px] font-bold border-b border-slate-100">
                                    <tr>
                                        <th class="px-6 py-4">زمان ثبت</th>
                                        <th class="px-6 py-4">وضعیت</th>
                                        <th class="px-6 py-4">زمان پاسخ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-sm">
                                    <?php foreach ($logs as $log): ?>
                                    <tr class="hover:bg-slate-50/60 transition-colors">
                                        <td class="px-6 py-4 text-slate-600 font-medium text-xs">
                                            <?php echo format_jalali_date($log['checked_at']); ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php
                                            $logStatusClass = 'text-slate-600 bg-slate-50 border-slate-200';
                                            $logStatusText = 'نامشخص';
                                            
                                            if ($log['status'] == 'online') {
                                                $logStatusClass = 'text-emerald-700 bg-emerald-50 border-emerald-200';
                                                $logStatusText = 'زنده';
                                            } elseif ($log['status'] == 'warning') {
                                                $logStatusClass = 'text-amber-700 bg-amber-50 border-amber-200';
                                                $logStatusText = 'هشدار';
                                            } elseif ($log['status'] == 'offline') {
                                                $logStatusClass = 'text-rose-700 bg-rose-50 border-rose-200';
                                                $logStatusText = 'تایم اوت';
                                            }
                                            ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold border <?php echo $logStatusClass; ?>">
                                                <?php echo $logStatusText; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="font-black text-slate-900"><?php echo $log['response_time']; ?></span>
                                            <span class="text-slate-400 text-xs font-semibold mr-0.5">ms</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </main>
            </div>

            <script>
                // دریافت داده‌ها از سمت PHP
                const rawLogs = <?php echo json_encode(array_reverse($logs)); ?>;
                const threshold = <?php echo $device['ping_threshold']; ?>;
                
                const times = rawLogs.map(item => {
                    const date = new Date(item.checked_at);
                    return date.getHours().toString().padStart(2, '0') + ':' + date.getMinutes().toString().padStart(2, '0');
                });
                const responseTimes = rawLogs.map(item => item.response_time);
                const statuses = rawLogs.map(item => item.status);

                // تنظیمات Chart.js بهینه‌شده
                const ctx = document.getElementById('historyChart').getContext('2d');
                
                // افکت Gradient برای زیر خط نمودار
                const gradient = ctx.createLinearGradient(0, 0, 0, 300);
                gradient.addColorStop(0, 'rgba(99, 102, 241, 0.35)');
                gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: times,
                        datasets: [
                            {
                                label: 'زمان پاسخ (ms)',
                                data: responseTimes,
                                borderColor: '#6366f1',
                                backgroundColor: gradient,
                                fill: true,
                                tension: 0.3,
                                borderWidth: 2,
                                pointRadius: 2,
                                pointHoverRadius: 6,
                                pointBackgroundColor: '#6366f1'
                            },
                            {
                                label: 'خط آستانه مجاز (Threshold)',
                                data: Array(times.length).fill(threshold),
                                borderColor: '#ef4444',
                                borderDash: [6, 6],
                                borderWidth: 1.5,
                                pointRadius: 0,
                                fill: false
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(226, 232, 240, 0.8)' },
                                ticks: { font: { family: 'Vazirmatn', size: 11 } }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { font: { family: 'Vazirmatn', size: 10 }, maxRotation: 0, maxTicksLimit: 12 }
                            }
                        },
                        plugins: {
                            legend: {
                                labels: { font: { family: 'Vazirmatn', size: 12 } }
                            },
                            tooltip: {
                                titleFont: { family: 'Vazirmatn' },
                                bodyFont: { family: 'Vazirmatn' },
                                callbacks: {
                                    afterBody: function(context) {
                                        const index = context[0].dataIndex;
                                        const status = statuses[index];
                                        let statusText = 'نامشخص';
                                        if (status === 'online') statusText = 'وضعیت: زنده';
                                        if (status === 'warning') statusText = 'وضعیت: هشدار تاخیر';
                                        if (status === 'offline') statusText = 'وضعیت: قطعی / تایم‌اوت';
                                        return statusText;
                                    }
                                }
                            }
                        }
                    }
                });

                // تابع بررسی فوری بهینه‌شده با لودینگ اختصاصی
                function checkNow(event) {
                    const btn = document.getElementById('btnCheckNow');
                    const spinner = document.getElementById('btnSpinner');
                    const icon = document.getElementById('btnIcon');
                    const btnText = document.getElementById('btnText');

                    btn.disabled = true;
                    btn.classList.add('opacity-75', 'cursor-not-allowed');
                    spinner.classList.remove('hidden');
                    icon.classList.add('hidden');
                    btnText.textContent = 'در حال بررسی...';
                    
                    fetch('check_devices.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showNotification('بررسی با موفقیت انجام شد.', 'success');
                                setTimeout(() => location.reload(), 1200);
                            } else {
                                showNotification('خطا در بررسی تجهیزات', 'error');
                                resetBtn();
                            }
                        })
                        .catch(() => {
                            showNotification('خطا در ارتباط با سرور', 'error');
                            resetBtn();
                        });

                    function resetBtn() {
                        btn.disabled = false;
                        btn.classList.remove('opacity-75', 'cursor-not-allowed');
                        spinner.classList.add('hidden');
                        icon.classList.remove('hidden');
                        btnText.textContent = 'بررسی فوری';
                    }
                }

                // سیستم نمایش اعلانات (Toast)
                function showNotification(message, type = 'success') {
                    const container = document.getElementById('toastContainer');
                    const toast = document.createElement('div');
                    
                    const bgClass = type === 'success' ? 'bg-emerald-600' : 'bg-rose-600';
                    toast.className = `${bgClass} text-white text-xs font-bold px-5 py-3 rounded-2xl shadow-xl flex items-center gap-2 transition-all duration-300 toast-enter`;
                    toast.innerHTML = `
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span>${message}</span>
                    `;
                    
                    container.appendChild(toast);
                    setTimeout(() => toast.classList.add('toast-show'), 10);
                    
                    setTimeout(() => {
                        toast.classList.remove('toast-show');
                        setTimeout(() => toast.remove(), 300);
                    }, 3000);
                }
            </script>
        </body>
        </html>
        <?php
    } else {
        header('Location: dashboard.php');
        exit();
    }
} else {
    header('Location: dashboard.php');
    exit();
}
?>