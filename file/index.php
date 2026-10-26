<?php
require_once 'config.php';
requireLogin();

$message = '';
$error = '';
$edit_mode = false;
$edit_device = null;

// بررسی حالت ویرایش
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_mode = true;
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_device = $stmt->fetch();
    
    if (!$edit_device) {
        header('Location: index.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $device_name = trim($_POST['device_name'] ?? '');
    $ip_address = trim($_POST['ip_address'] ?? '');
    $port = intval($_POST['port'] ?? 80);
    $ping_threshold = intval($_POST['ping_threshold'] ?? 100);
    
    if (empty($device_name) || empty($ip_address)) {
        $error = 'لطفاً تمام فیلدها را پر کنید';
    } else {
        // بررسی اینکه ورودی هم IP باشد و هم دامنه را قبول کند
        $is_valid_ip = filter_var($ip_address, FILTER_VALIDATE_IP);
        $is_valid_domain = filter_var($ip_address, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        
        if (!$is_valid_ip && !$is_valid_domain) {
            $error = 'آدرس IP یا دامنه وارد شده معتبر نیست';
        } else {
            if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
                $stmt = $pdo->prepare("UPDATE devices SET device_name = ?, ip_address = ?, port = ?, ping_threshold = ? WHERE id = ?");
                if ($stmt->execute([$device_name, $ip_address, $port, $ping_threshold, $_POST['edit_id']])) {
                    $message = 'تجهیز با موفقیت ویرایش شد';
                    $edit_mode = false;
                } else {
                    $error = 'خطا در ویرایش تجهیز';
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO devices (device_name, ip_address, port, ping_threshold) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$device_name, $ip_address, $port, $ping_threshold])) {
                    $message = 'تجهیز با موفقیت اضافه شد';
                } else {
                    $error = 'خطا در ذخیره اطلاعات';
                }
            }
        }
    }
}

$devices = $pdo->query("SELECT * FROM devices ORDER BY created_at DESC")->fetchAll();

// تابع تبدیل تاریخ میلادی به شمسی
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
    return $jalali[2] . ' ' . $months[$jalali[1]] . ' ' . $jalali[0];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت تجهیزات شبکه</title>
    <script src="3.4.17.js"></script>
    <link rel="stylesheet" href="Vazirmatn-font-face.css">
    <style>
        * { 
            font-family: 'Vazirmatn', sans-serif; 
        }
        body {
            background: #f8fafc;
        }
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        @keyframes pulse-yellow {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(245, 158, 11, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }
        @keyframes pulse-red {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        @keyframes pulse-gray {
            0% { box-shadow: 0 0 0 0 rgba(107, 114, 128, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(107, 114, 128, 0); }
            100% { box-shadow: 0 0 0 0 rgba(107, 114, 128, 0); }
        }
        .status-pulse-green { animation: pulse-green 2s infinite; }
        .status-pulse-yellow { animation: pulse-yellow 2s infinite; }
        .status-pulse-red { animation: pulse-red 2s infinite; }
        .status-pulse-gray { animation: pulse-gray 2s infinite; }
        @keyframes blink-green { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        @keyframes blink-yellow { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        @keyframes blink-red { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .blink-green { animation: blink-green 1.5s ease-in-out infinite; }
        .blink-yellow { animation: blink-yellow 1.5s ease-in-out infinite; }
        .blink-red { animation: blink-red 1.5s ease-in-out infinite; }
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            z-index: 1000;
            display: flex; align-items: center; justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 25px 50px rgba(0,0,0,0.15);
            animation: slideUp 0.3s ease;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>
    <div class="min-h-screen">
       <nav class="bg-white/80 backdrop-blur-xl border-b border-slate-200/80 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-600/20 text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-slate-900 tracking-tight">مدیریت تجهیزات</h1>
                    <p class="text-xs font-medium text-slate-500 mt-0.5"><?php echo format_jalali_date(date('Y-m-d')); ?></p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="px-4 py-2.5 rounded-2xl text-xs font-bold bg-slate-900 hover:bg-slate-800 text-white shadow-sm transition-all duration-200 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    داشبورد
                </a>
                <a href="logout.php" class="px-4 py-2.5 rounded-2xl text-xs font-bold bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-100 transition-all duration-200 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                    خروج
                </a>
            </div>
        </div>
    </div>
</nav>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Add/Edit Device Form -->
            <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-sm p-6 mb-8 border border-slate-200/80">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-2.5">
                        <div class="w-10 h-10 rounded-xl <?php echo $edit_mode ? 'bg-amber-50 border border-amber-100 text-amber-600' : 'bg-indigo-50 border border-indigo-100 text-indigo-600'; ?> flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <?php if ($edit_mode): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                <?php else: ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"></path>
                                <?php endif; ?>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-slate-900 tracking-tight">
                                <?php echo $edit_mode ? 'ویرایش تجهیز' : 'افزودن تجهیز جدید'; ?>
                            </h2>
                            <p class="text-xs text-slate-500 mt-0.5"><?php echo $edit_mode ? 'به‌روزرسانی اطلاعات تجهیز شبکه' : 'ثبت تجهیز جدید جهت نظارت و پایش'; ?></p>
                        </div>
                    </div>
                    <?php if ($edit_mode): ?>
                        <a href="index.php" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-600 transition-all duration-200">انصراف</a>
                    <?php endif; ?>
                </div>
                
                <?php if ($edit_mode): ?>
                    <div class="bg-amber-50/80 border border-amber-200/80 text-amber-800 px-4 py-3 rounded-2xl mb-6 text-xs font-medium flex items-center gap-2 shadow-xs">
                        <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>در حال ویرایش مشخصات: <strong class="font-bold underline decoration-amber-300 underline-offset-2"><?php echo htmlspecialchars($edit_device['device_name']); ?></strong></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="edit_id" value="<?php echo $edit_device['id']; ?>">
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">نام تجهیز</label>
                        <input type="text" name="device_name" required 
                               value="<?php echo $edit_mode ? htmlspecialchars($edit_device['device_name']) : ''; ?>" 
                               class="w-full px-4 py-2.5 text-xs font-semibold text-slate-900 bg-slate-50/50 border border-slate-200/80 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 placeholder:text-slate-400"
                               placeholder="مثال: روتر مرکزی شعبه ۱">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">آدرس IP یا دامنه</label>
                        <input type="text" name="ip_address" required 
                               value="<?php echo $edit_mode ? htmlspecialchars($edit_device['ip_address']) : ''; ?>" 
                               class="w-full px-4 py-2.5 text-xs font-semibold text-slate-900 bg-slate-50/50 border border-slate-200/80 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 font-mono dir-ltr text-right placeholder:text-slate-400"
                               placeholder="192.168.1.1 یا example.com">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">پورت (Port)</label>
                        <input type="number" name="port" required 
                               value="<?php echo $edit_mode ? $edit_device['port'] : 80; ?>" 
                               min="1" max="65535"
                               class="w-full px-4 py-2.5 text-xs font-semibold text-slate-900 bg-slate-50/50 border border-slate-200/80 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 font-mono dir-ltr text-right"
                               placeholder="80">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">حداکثر زمان پاسخ <span class="text-slate-400 font-normal">(ms)</span></label>
                        <input type="number" name="ping_threshold" 
                               value="<?php echo $edit_mode ? $edit_device['ping_threshold'] : 100; ?>" 
                               min="10" max="1000"
                               class="w-full px-4 py-2.5 text-xs font-semibold text-slate-900 bg-slate-50/50 border border-slate-200/80 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 font-mono dir-ltr text-right">
                    </div>
                    <div class="flex items-end">
                        <button type="submit" 
                                class="w-full px-4 py-2.5 rounded-2xl text-white text-xs font-bold shadow-sm transition-all duration-200 active:scale-95 cursor-pointer flex items-center justify-center gap-2 <?php echo $edit_mode ? 'bg-amber-600 hover:bg-amber-700 shadow-amber-200' : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-200'; ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <?php if ($edit_mode): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 13l4 4L19 7"></path>
                                <?php else: ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"></path>
                                <?php endif; ?>
                            </svg>
                            <?php echo $edit_mode ? 'ذخیره تغییرات' : 'افزودن تجهیز'; ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Devices List -->
            <div class="bg-white/85 backdrop-blur-xl rounded-3xl shadow-sm overflow-hidden border border-slate-200/80">
                <div class="flex justify-between items-center p-6 border-b border-slate-100">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 border border-indigo-100 text-indigo-600 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-slate-900 tracking-tight">لیست تجهیزات</h2>
                            <p class="text-xs text-slate-500 mt-0.5">مدیریت و پایش وضعیت تجهیزات شبکه</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-100">
                        <?php echo count($devices); ?> دستگاه
                    </span>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse">
                        <thead class="bg-slate-50/70 border-b border-slate-100">
                            <tr>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">نام تجهیز</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">آدرس IP / دامنه</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">پورت</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">حد آستانه</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">وضعیت</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">آخرین بررسی</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100/80 text-xs font-medium text-slate-700">
                            <?php foreach ($devices as $device): ?>
                            <tr class="hover:bg-slate-50/60 transition-all duration-150">
                                <td class="px-6 py-4">
                                    <span class="font-bold text-slate-900"><?php echo htmlspecialchars($device['device_name']); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-mono text-slate-600 bg-slate-100/80 px-2.5 py-1 rounded-lg dir-ltr inline-block break-all"><?php echo htmlspecialchars($device['ip_address']); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-mono text-slate-600 bg-slate-100/80 px-2.5 py-1 rounded-lg dir-ltr inline-block"><?php echo htmlspecialchars($device['port'] ?? 80); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-slate-600 font-semibold"><?php echo $device['ping_threshold']; ?> <span class="text-slate-400 font-normal">ms</span></span>
                                </td>
                                <td class="px-6 py-4">
                                    <?php
                                    $statusClass = 'bg-slate-50 text-slate-700 border-slate-200';
                                    $statusText = 'نامشخص';
                                    $pulseClass = 'status-pulse-gray';
                                    $blinkClass = '';
                                    $dotColor = 'bg-slate-400';
                                    
                                    if ($device['status'] == 'online') {
                                        $statusClass = 'bg-emerald-50 text-emerald-700 border-emerald-200/80';
                                        $statusText = 'زنده';
                                        $pulseClass = 'status-pulse-green';
                                        $blinkClass = 'blink-green';
                                        $dotColor = 'bg-emerald-500';
                                    } elseif ($device['status'] == 'warning') {
                                        $statusClass = 'bg-amber-50 text-amber-700 border-amber-200/80';
                                        $statusText = 'هشدار';
                                        $pulseClass = 'status-pulse-yellow';
                                        $blinkClass = 'blink-yellow';
                                        $dotColor = 'bg-amber-500';
                                    } elseif ($device['status'] == 'offline') {
                                        $statusClass = 'bg-rose-50 text-rose-700 border-rose-200/80';
                                        $statusText = 'تایم اوت';
                                        $pulseClass = 'status-pulse-red';
                                        $blinkClass = 'blink-red';
                                        $dotColor = 'bg-rose-500';
                                    }
                                    ?>
                                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold border shadow-2xs <?php echo $statusClass; ?> <?php echo $pulseClass; ?>">
                                        <span class="relative flex h-2 w-2">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 <?php echo $dotColor; ?>"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 <?php echo $dotColor; ?> <?php echo $blinkClass; ?>"></span>
                                        </span>
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-slate-500 font-normal"><?php echo format_jalali_date($device['last_check']); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-1.5">
                                        <!-- Details -->
                                        <a href="device_details.php?id=<?php echo $device['id']; ?>" 
                                           title="جزئیات"
                                           class="p-2 bg-sky-500/10 hover:bg-sky-500/20 text-sky-400 border border-sky-500/20 rounded-xl transition-all duration-200 hover:scale-105 active:scale-95">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                        </a>
                                        
                                        <!-- Edit -->
                                        <a href="index.php?edit=<?php echo $device['id']; ?>" 
                                           title="ویرایش"
                                           class="p-2 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 border border-indigo-500/20 rounded-xl transition-all duration-200 hover:scale-105 active:scale-95">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                        
                                        <!-- Delete -->
                                        <button onclick="deleteDevice(<?php echo $device['id']; ?>)" 
                                                title="حذف"
                                                class="p-2 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 rounded-xl transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 backdrop-blur-xs p-4" onclick="closeDeleteModal(event)">
        <div class="modal-content bg-white/90 backdrop-blur-2xl rounded-3xl p-6 max-w-sm w-full shadow-2xl border border-slate-200/80 transform transition-all" onclick="event.stopPropagation()">
            <div class="text-center mb-6">
                <div class="w-14 h-14 bg-rose-50 border border-rose-100 rounded-2xl flex items-center justify-center mx-auto mb-4 text-rose-600 shadow-sm">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <h3 class="text-sm font-bold text-slate-900 tracking-tight mb-1">حذف تجهیز</h3>
                <p class="text-xs text-slate-500 font-medium">آیا از حذف این تجهیز و اطلاعات مرتبط با آن اطمینان دارید؟</p>
            </div>
            <div class="flex gap-2.5">
                <button onclick="confirmDelete()" class="flex-1 px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl text-xs font-bold shadow-sm shadow-rose-200 transition-all duration-200 active:scale-95 cursor-pointer">
                    بله، حذف کن
                </button>
                <button onclick="closeDeleteModal()" class="flex-1 px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-2xl text-xs font-bold transition-all duration-200 active:scale-95 cursor-pointer">
                    انصراف
                </button>
            </div>
        </div>
    </div>

    <script>
        let deleteId = null;
        
        function deleteDevice(id) {
            deleteId = id;
            document.getElementById('deleteModal').classList.remove('hidden');
        }
        
        function confirmDelete() {
            if (deleteId) {
                fetch('delete_device.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'id=' + deleteId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('خطا در حذف تجهیز');
                    }
                });
            }
            closeDeleteModal();
        }
        
        function closeDeleteModal(event) {
            if (event && event.target !== event.currentTarget) {
                return;
            }
            document.getElementById('deleteModal').classList.add('hidden');
        }
    </script>
</body>
</html>