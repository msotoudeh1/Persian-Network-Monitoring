<?php
declare(strict_types=1);

/* ============================================================
   ۱) Polyfill — سازگاری با PHP 7.x
   ============================================================ */
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool {
        return $n === '' || strpos($h, $n) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool {
        return $n === '' || strncmp($h, $n, strlen($n)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool {
        return $n === '' || substr($h, -strlen($n)) === $n;
    }
}

/* ============================================================
   ۲) Hardening
   ============================================================ */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   $isHttps ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime',  '7200');

if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    die('خطای حیاتی: فایل تنظیمات (config.php) یافت نشد.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ============================================================
   ۳) هدرهای امنیتی
   ============================================================ */
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; "
        . "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; "
        . "font-src 'self' https://cdn.jsdelivr.net data:; "
        . "img-src 'self' data:; "
        . "connect-src 'self' https://cdn.tailwindcss.com"
    );
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/* ============================================================
   ۴) انقضای سشن
   ============================================================ */
$timeout = 7200;
if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $timeout) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

/* ============================================================
   ۵) ثابت‌ها
   ============================================================ */
const MAX_USERNAME_LEN = 50;
const MAX_PASSWORD_LEN = 200;
const RATE_WINDOW      = 900;
const RATE_MAX_USER    = 5;
const RATE_MAX_IP      = 20;
const DUMMY_HASH       = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

/* ============================================================
   ۶) توابع کمکی
   ============================================================ */
function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool {
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function rateLimit(string $key, int $max, int $window, bool $checkOnly = false): array {
    $file = sys_get_temp_dir() . '/rl_' . md5($key) . '.json';
    $now  = time();
    $data = ['attempts' => []];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['attempts'])) {
                $data = $decoded;
            }
        }
    }

    $data['attempts'] = array_values(array_filter(
        (array)$data['attempts'],
        fn($t) => ($now - (int)$t) < $window
    ));

    $count   = count($data['attempts']);
    $limited = $count >= $max;

    if (!$checkOnly && !$limited) {
        $data['attempts'][] = $now;
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    return ['limited' => $limited, 'count' => $count];
}

function rateLimitReset(string $key): void {
    $file = sys_get_temp_dir() . '/rl_' . md5($key) . '.json';
    if (is_file($file)) @unlink($file);
}

function startUserSession(array $user, bool $remember): void {
    session_regenerate_id(true);

    if ($remember) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + 30 * 86400,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['last_activity'] = time();
}

/* ============================================================
   ۷) منطق صفحه
   ============================================================ */
if (function_exists('isLoggedIn') && isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$errors  = [];
$old     = ['username' => '', 'remember' => false];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);
    $honeypot = trim((string)($_POST['website'] ?? ''));

    $old['username'] = $username;
    $old['remember'] = $remember;

    $ip = clientIp();

    if ($honeypot !== '') {
        $errors['general'] = 'درخواست نامعتبر است.';
    }
    elseif (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors['general'] = 'نشست شما منقضی شده است. لطفاً صفحه را دوباره بارگذاری کنید.';
    }
    else {
        if ($username === '') {
            $errors['username'] = 'نام کاربری را وارد کنید.';
        } elseif (mb_strlen($username) > MAX_USERNAME_LEN) {
            $errors['username'] = 'نام کاربری بیش از حد طولانی است.';
        } elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $username)) {
            $errors['username'] = 'فقط حروف انگلیسی، عدد، نقطه، خط تیره و آندرلاین (۳ تا ۵۰ نویسه).';
        }

        if ($password === '') {
            $errors['password'] = 'رمز عبور را وارد کنید.';
        } elseif (mb_strlen($password) > MAX_PASSWORD_LEN) {
            $errors['password'] = 'رمز عبور بیش از حد طولانی است.';
        } elseif (mb_strlen($password) < 6) {
            $errors['password'] = 'رمز عبور باید حداقل ۶ نویسه باشد.';
        }

        if (!$errors) {
            $rlUser = rateLimit('u_' . strtolower($username), RATE_MAX_USER, RATE_WINDOW, true);
            $rlIp   = rateLimit('i_' . $ip,              RATE_MAX_IP,   RATE_WINDOW, true);
            if ($rlUser['limited'] || $rlIp['limited']) {
                $errors['general'] = 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً چند دقیقه بعد تلاش کنید.';
            }
        }

        if (!$errors && isset($pdo)) {
            $stmt = $pdo->prepare("SELECT id, username, full_name, password
                                   FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                $valid = password_verify($password, $user['password']);
            } else {
                password_verify($password, DUMMY_HASH);
                $valid = false;
            }

            if ($valid && $user) {
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->execute([$newHash, (int)$user['id']]);
                }

                rateLimitReset('u_' . strtolower($username));
                rateLimitReset('i_' . $ip);

                startUserSession($user, $remember);
                $success = true;
            } else {
                rateLimit('u_' . strtolower($username), RATE_MAX_USER, RATE_WINDOW);
                rateLimit('i_' . $ip,                  RATE_MAX_IP,   RATE_WINDOW);
                $errors['general'] = 'نام کاربری یا رمز عبور اشتباه است.';
            }
        }
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
   ۸) تاریخ شمسی
   ============================================================ */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100))
          + ((int)(($gy2 + 399) / 400)) - 80 + $gd + $g_d_m[$gm - 1];
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

$today  = getdate();
$jalali = gregorian_to_jalali((int)$today['year'], (int)$today['mon'], (int)$today['mday']);
$months = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
    7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
];
$weekdays = [
    'Saturday' => 'شنبه', 'Sunday' => 'یکشنبه', 'Monday' => 'دوشنبه',
    'Tuesday'  => 'سه‌شنبه', 'Wednesday' => 'چهارشنبه',
    'Thursday' => 'پنجشنبه', 'Friday' => 'جمعه',
];
$weekday     = $weekdays[$today['weekday']] ?? '';
$date_string = $weekday . '، ' . $jalali[2] . ' ' . $months[$jalali[1]] . ' ' . $jalali[0];

$csrf = csrfToken();
$e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>ورود به سیستم - مانیتورینگ شبکه</title>

<script src="3.4.17.js"></script>
<script>
    tailwind.config = {
        darkMode: 'media',
        theme: {
            extend: {
                fontFamily: { sans: ['Vazirmatn', 'sans-serif'] },
                colors: {
                    ink:    { DEFAULT: '#0f172a', 2: '#334155', 3: '#64748b', 4: '#94a3b8' },
                    paper:  '#ffffff',
                    canvas: '#f1f5f9',
                },
            },
        },
    };
</script>
<link rel="stylesheet" href="Vazirmatn-font-face.css">

<style>
/* ============================================================
   BENTO GRID + ATOMIC CSS
   ============================================================ */

/* ─── Atomic utilities (u- prefix = سفارشی، یک ویژگی در هر کلاس) ─── */
.u-grid          { display: grid; }
.u-gap-3         { gap: 0.75rem; }
.u-gap-4         { gap: 1rem; }
.u-tile          { background: #fff; border: 1px solid #e2e8f0; border-radius: 1.5rem; padding: 1.25rem; }
.u-tile-hover    { transition: transform .25s cubic-bezier(.2,.9,.3,1.2), box-shadow .25s ease, border-color .25s ease; }
.u-tile-hover:hover { transform: translateY(-3px); box-shadow: 0 20px 40px -18px rgba(15,23,42,0.18); border-color: #cbd5e1; }
.u-flex          { display: flex; }
.u-flex-col      { flex-direction: column; }
.u-items-center  { align-items: center; }
.u-items-start   { align-items: flex-start; }
.u-justify-between { justify-content: space-between; }
.u-justify-center { justify-content: center; }

@media (prefers-color-scheme: dark) {
    .u-tile { background: #0f172a; border-color: #1e293b; }
    .u-tile-hover:hover { border-color: #334155; box-shadow: 0 20px 40px -18px rgba(0,0,0,0.6); }
}

/* ─── Bento Grid Layout ─── */
.bento {
    display: grid;
    gap: 0.875rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    grid-template-rows: auto auto auto;
    grid-template-areas:
        "brand  brand  date     status"
        "form   form   support  system"
        "form   form   support  system";
}

.tile-brand   { grid-area: brand; }
.tile-date    { grid-area: date; }
.tile-status  { grid-area: status; }
.tile-form    { grid-area: form; }
.tile-support { grid-area: support; }
.tile-system  { grid-area: system; }

/* تبلت: ۲ ستون */
@media (max-width: 900px) {
    .bento {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-template-areas:
            "brand    brand"
            "date     status"
            "form     form"
            "form     form"
            "support  system";
    }
}

/* موبایل: ۱ ستون */
@media (max-width: 560px) {
    .bento {
        grid-template-columns: 1fr;
        grid-template-areas:
            "brand"
            "date"
            "status"
            "form"
            "support"
            "system";
    }
}

/* ─── وضعیت سیستم (نقطه سبز پالس) ─── */
.u-status-dot {
    width: 8px; height: 8px; border-radius: 9999px;
    background: #10b981;
    box-shadow: 0 0 0 3px rgba(16,185,129,0.2);
}
@media (prefers-reduced-motion: no-preference) {
    .u-status-dot { animation: pulse-dot 2s ease-in-out infinite; }
}
@keyframes pulse-dot {
    0%, 100% { box-shadow: 0 0 0 3px rgba(16,185,129,0.2); }
    50%      { box-shadow: 0 0 0 6px rgba(16,185,129,0.05); }
}

/* ─── ورودی اتمی ─── */
.u-input {
    width: 100%;
    padding: 0.75rem 0.95rem;
    font-size: 16px;
    color: #0f172a;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 0.875rem;
    outline: none;
    transition: border-color .2s, background .2s, box-shadow .2s;
}
.u-input::placeholder { color: #94a3b8; }
.u-input:focus {
    border-color: #6366f1;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(99,102,241,0.12);
}
.u-input[aria-invalid="true"] {
    border-color: #ef4444;
    background: #fef2f2;
}
.u-input[aria-invalid="true"]:focus {
    box-shadow: 0 0 0 4px rgba(239,68,68,0.12);
}
@media (prefers-color-scheme: dark) {
    .u-input { background: #1e293b; border-color: #334155; color: #f1f5f9; }
    .u-input:focus { background: #0f172a; }
    .u-input[aria-invalid="true"] { background: #2a1515; }
}

/* ─── برچسب اتمی ─── */
.u-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.4rem;
    letter-spacing: 0.01em;
}
@media (prefers-color-scheme: dark) {
    .u-label { color: #cbd5e1; }
}

/* ─── خطای اتمی ─── */
.u-error {
    font-size: 0.7rem;
    color: #ef4444;
    margin-top: 0.3rem;
    min-height: 0.9rem;
    font-weight: 500;
}

/* ─── دکمه اصلی اتمی ─── */
.u-btn-primary {
    width: 100%;
    padding: 0.85rem 1rem;
    font-size: 0.9rem;
    font-weight: 700;
    color: #fff;
    background: #0f172a;
    border: none;
    border-radius: 0.875rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    letter-spacing: 0.02em;
    transition: background .2s, transform .15s, box-shadow .25s;
    box-shadow: 0 4px 14px -4px rgba(15,23,42,0.3);
}
.u-btn-primary:hover:not(:disabled) {
    background: #6366f1;
    box-shadow: 0 10px 24px -6px rgba(99,102,241,0.5);
    transform: translateY(-1px);
}
.u-btn-primary:active:not(:disabled) {
    transform: translateY(0);
    box-shadow: 0 2px 8px -2px rgba(99,102,241,0.4);
}
.u-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

/* ─── دکمه چشم رمز ─── */
.u-eye {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    left: 0.6rem;
    width: 2rem;
    height: 2rem;
    background: transparent;
    border: none;
    color: #64748b;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    transition: color .2s, background .2s;
}
.u-eye:hover { color: #6366f1; background: #f1f5f9; }
@media (prefers-color-scheme: dark) {
    .u-eye { color: #94a3b8; }
    .u-eye:hover { color: #a5b4fc; background: #1e293b; }
}

/* ─── چک‌باکس اتمی ─── */
.u-check {
    appearance: none;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    border: 1.5px solid #cbd5e1;
    border-radius: 0.4rem;
    background: #fff;
    cursor: pointer;
    position: relative;
    flex-shrink: 0;
    margin: 0;
    transition: background .15s, border-color .15s;
}
.u-check:checked {
    background: #0f172a;
    border-color: #0f172a;
}
.u-check:checked::after {
    content: '';
    position: absolute;
    left: 4px; top: 0px;
    width: 5px; height: 10px;
    border: solid #fff;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}
@media (prefers-color-scheme: dark) {
    .u-check { background: #1e293b; border-color: #475569; }
    .u-check:checked { background: #6366f1; border-color: #6366f1; }
}

/* ─── Alert ─── */
.u-alert {
    padding: 0.65rem 0.9rem;
    border-radius: 0.75rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: #b91c1c;
    background: #fef2f2;
    border: 1px solid #fecaca;
    text-align: center;
    margin-bottom: 1rem;
}
@media (prefers-color-scheme: dark) {
    .u-alert { background: #2a1515; border-color: #7f1d1d; color: #fca5a5; }
}

/* ─── Spinner ─── */
.spinner {
    width: 15px; height: 15px;
    border: 2.5px solid rgba(255,255,255,0.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ─── انیمیشن ─── */
@media (prefers-reduced-motion: no-preference) {
    .bento { animation: bento-in .6s cubic-bezier(.2,.9,.3,1) both; }
    .shake { animation: shake .4s cubic-bezier(.36,.07,.19,.97); }
}
@keyframes bento-in {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes shake {
    10%, 90% { transform: translateX(-2px); }
    20%, 80% { transform: translateX(4px); }
    30%, 50%, 70% { transform: translateX(-6px); }
    40%, 60% { transform: translateX(6px); }
}

.hidden { display: none !important; }

/* ─── Honeypot — کاملاً مخفی از دید کاربر، قابل خواندن برای ربات ─── */
.hp-field {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    clip-path: inset(50%) !important;
    white-space: nowrap !important;
    border: 0 !important;
    opacity: 0 !important;
    pointer-events: none !important;
    top: 0 !important;
    left: 0 !important;
}

</style>
</head>

<body class="min-h-screen flex items-center justify-center p-3 sm:p-5 bg-slate-100 dark:bg-slate-950 text-slate-900 dark:text-slate-100 antialiased">

<main class="w-full max-w-5xl">

    <section class="bento <?php echo (!empty($errors) && !$success) ? 'shake' : ''; ?>">

    <?php if ($success): ?>
        <!-- حالت موفقیت: یک tile تمام‌عرض -->
        <article class="u-tile u-flex u-flex-col u-items-center u-justify-center gap-5 py-16 text-center" style="grid-column: 1 / -1;">
            <div class="w-20 h-20 rounded-full bg-emerald-500 flex items-center justify-center text-white shadow-lg shadow-emerald-500/30">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="40" height="40">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">ورود موفقیت‌آمیز بود</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">در حال انتقال به داشبورد…</p>
            </div>
        </article>
        <script>setTimeout(() => location.href = 'dashboard.php', 900);</script>

    <?php else: ?>

        <!-- ─────────── Tile ۱: Brand (2×1) ─────────── -->
        <article class="tile-brand u-tile u-tile-hover u-flex u-items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-slate-900 dark:bg-indigo-600 text-white flex items-center justify-center flex-shrink-0">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                          d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <div class="min-w-0">
                <h1 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white truncate">مانیتورینگ شبکه</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Network Monitoring System</p>
            </div>
        </article>

        <!-- ─────────── Tile ۲: Date (1×1) ─────────── -->
        <article class="tile-date u-tile u-tile-hover u-flex u-flex-col u-justify-between">
            <div class="u-flex u-items-center gap-2 text-slate-400 dark:text-slate-500">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="14" height="14">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="text-[10px] font-bold uppercase tracking-widest">Today</span>
            </div>
            <div class="mt-3">
                <p class="text-xs font-semibold text-slate-800 dark:text-slate-200 leading-relaxed">
                    <?php echo $e($date_string); ?>
                </p>
                <p class="text-lg font-mono font-bold text-slate-900 dark:text-white mt-1 tracking-tight" id="liveClock">--:--:--</p>
            </div>
        </article>

        <!-- ─────────── Tile ۳: Status (1×1) ─────────── -->
        <article class="tile-status u-tile u-tile-hover u-flex u-flex-col u-justify-between">
            <div class="u-flex u-items-center gap-2 text-slate-400 dark:text-slate-500">
                <span class="u-status-dot"></span>
                <span class="text-[10px] font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400">Online</span>
            </div>
            <div class="mt-3">
                <p class="text-xs font-semibold text-slate-800 dark:text-slate-200">سرویس فعال</p>
                <p class="text-[10px] font-mono text-slate-500 dark:text-slate-500 mt-1">v 1.0.0</p>
            </div>
        </article>

        <!-- ─────────── Tile ۴: Login Form (2×2) ─────────── -->
        <article class="tile-form u-tile u-tile-hover u-flex u-flex-col" style="padding: 1.5rem;">

            <div class="mb-4">
                <h2 class="text-base font-bold text-slate-900 dark:text-white">ورود به سیستم</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">اطلاعات کاربری خود را وارد کنید</p>
            </div>

            <?php if (!empty($errors['general'])): ?>
                <div role="alert" class="u-alert"><?php echo $e($errors['general']); ?></div>
            <?php endif; ?>

            <form method="POST" id="loginForm" novalidate class="u-flex u-flex-col u-gap-3 flex-1">

                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">

<div class="hp-field" aria-hidden="true">
    <label>Website
        <input type="text" name="website" tabindex="-1" autocomplete="off" value="">
    </label>
</div>

                <div>
                    <label for="username" class="u-label">نام کاربری</label>
                    <input id="username" name="username" type="text"
                           required maxlength="50" autocomplete="username"
                           value="<?php echo $e($old['username']); ?>"
                           class="u-input"
                           aria-invalid="<?php echo isset($errors['username']) ? 'true' : 'false'; ?>"
                           placeholder="username">
                    <p class="u-error" id="usernameError"><?php echo $e($errors['username'] ?? ''); ?></p>
                </div>

                <div>
                    <label for="password" class="u-label">رمز عبور</label>
                    <div class="relative">
                        <input id="password" name="password" type="password"
                               required maxlength="200" autocomplete="current-password"
                               class="u-input"
                               style="padding-left: 2.75rem;"
                               aria-invalid="<?php echo isset($errors['password']) ? 'true' : 'false'; ?>"
                               placeholder="••••••••">
                        <button type="button" id="togglePwd" tabindex="-1"
                                class="u-eye" aria-label="نمایش رمز">
                            <svg id="eyeOpen" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg id="eyeClosed" width="16" height="16" class="hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                      d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                            </svg>
                        </button>
                    </div>
                    <p class="u-error" id="passwordError"><?php echo $e($errors['password'] ?? ''); ?></p>
                    <p id="capsWarning" class="text-[10px] text-amber-600 dark:text-amber-400 mt-1 font-semibold hidden">
                        ⚠ Caps Lock روشن است
                    </p>
                </div>

                <div class="u-flex u-items-center u-justify-between text-xs pt-1">
                    <label class="u-flex u-items-center gap-2 cursor-pointer text-slate-600 dark:text-slate-400 font-medium">
                        <input type="checkbox" name="remember" value="1" class="u-check"
                               <?php echo $old['remember'] ? 'checked' : ''; ?>>
                        <span>مرا به خاطر بسپار</span>
                    </label>
                    <a href="forgot-password.php" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">
                        فراموشی رمز
                    </a>
                </div>

                <button type="submit" id="submitBtn" class="u-btn-primary mt-auto">
                    <span id="btnText">ورود به سیستم</span>
                    <span id="btnSpinner" class="spinner hidden"></span>
                </button>
            </form>
        </article>

        <!-- ─────────── Tile ۵: Support (1×2) ─────────── -->
        <article class="tile-support u-tile u-tile-hover u-flex u-flex-col">
            <div class="u-flex u-items-center gap-2 text-slate-400 dark:text-slate-500 mb-3">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="14" height="14">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <span class="text-[10px] font-bold uppercase tracking-widest">Support</span>
            </div>

            <div class="u-flex u-flex-col gap-3 text-xs flex-1">
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-0.5">تلفن</p>
                    <p class="font-mono font-semibold text-slate-800 dark:text-slate-200" dir="ltr">09123456789</p>
                </div>
                <div class="border-t border-slate-100 dark:border-slate-800 pt-3">
                    <p class="text-[10px] uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-0.5">ایمیل</p>
                    <p class="font-mono font-semibold text-slate-800 dark:text-slate-200 text-[11px] break-all" dir="ltr">info@test.com</p>
                </div>
                <div class="border-t border-slate-100 dark:border-slate-800 pt-3">
                    <p class="text-[10px] uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-0.5">وب‌سایت</p>
                    <p class="font-mono font-semibold text-slate-800 dark:text-slate-200 text-[11px]" dir="ltr">test.com</p>
                </div>
            </div>
        </article>

        <!-- ─────────── Tile ۶: System (1×2) ─────────── -->
        <article class="tile-system u-tile u-tile-hover u-flex u-flex-col">
            <div class="u-flex u-items-center gap-2 text-slate-400 dark:text-slate-500 mb-3">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="14" height="14">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                </svg>
                <span class="text-[10px] font-bold uppercase tracking-widest">System</span>
            </div>

            <div class="u-flex u-flex-col gap-3 text-xs flex-1">
                <div class="u-flex u-items-center u-justify-between">
                    <span class="text-slate-500 dark:text-slate-400">وضعیت سرور</span>
                    <span class="u-flex u-items-center gap-1.5 font-semibold text-emerald-600 dark:text-emerald-400">
                        <span class="u-status-dot" style="width:6px;height:6px;"></span>
                        فعال
                    </span>
                </div>
                <div class="u-flex u-items-center u-justify-between border-t border-slate-100 dark:border-slate-800 pt-3">
                    <span class="text-slate-500 dark:text-slate-400">نسخه</span>
                    <span class="font-mono font-semibold text-slate-800 dark:text-slate-200" dir="ltr">1.0.0</span>
                </div>
                <div class="u-flex u-items-center u-justify-between border-t border-slate-100 dark:border-slate-800 pt-3">
                    <span class="text-slate-500 dark:text-slate-400">پروتکل</span>
                    <span class="font-mono font-semibold text-slate-800 dark:text-slate-200" dir="ltr"><?php echo $isHttps ? 'HTTPS' : 'HTTP'; ?></span>
                </div>
            </div>
        </article>

    <?php endif; ?>

    </section>
</main>

<script>
(function tick() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');
    const el = document.getElementById('liveClock');
    if (el) el.textContent = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    setTimeout(tick, 1000);
})();

const pwd      = document.getElementById('password');
const toggle   = document.getElementById('togglePwd');
const eyeOpen  = document.getElementById('eyeOpen');
const eyeClose = document.getElementById('eyeClosed');
toggle?.addEventListener('click', () => {
    const show = pwd.type === 'password';
    pwd.type = show ? 'text' : 'password';
    eyeOpen.classList.toggle('hidden', show);
    eyeClose.classList.toggle('hidden', !show);
    toggle.setAttribute('aria-label', show ? 'مخفی کردن رمز' : 'نمایش رمز');
});

const capsWarn = document.getElementById('capsWarning');
pwd?.addEventListener('keyup', e => {
    if (typeof e.getModifierState === 'function') {
        capsWarn.classList.toggle('hidden', !e.getModifierState('CapsLock'));
    }
});
pwd?.addEventListener('blur', () => capsWarn.classList.add('hidden'));

const usernameInput = document.getElementById('username');
const usernameErr   = document.getElementById('usernameError');
const passwordErr   = document.getElementById('passwordError');

usernameInput?.addEventListener('input', () => {
    const v = usernameInput.value.trim();
    let msg = '';
    if (v && !/^[A-Za-z0-9_.\-]{3,50}$/.test(v)) {
        msg = 'فقط حروف انگلیسی، عدد، نقطه، خط تیره و آندرلاین (۳ تا ۵۰ نویسه).';
    }
    usernameErr.textContent = msg;
    usernameInput.setAttribute('aria-invalid', msg ? 'true' : 'false');
});
pwd?.addEventListener('input', () => {
    let msg = '';
    if (pwd.value && pwd.value.length < 6) msg = 'رمز عبور باید حداقل ۶ نویسه باشد.';
    passwordErr.textContent = msg;
    pwd.setAttribute('aria-invalid', msg ? 'true' : 'false');
});

const form       = document.getElementById('loginForm');
const btn        = document.getElementById('submitBtn');
const btnText    = document.getElementById('btnText');
const btnSpinner = document.getElementById('btnSpinner');

form?.addEventListener('submit', e => {
    if (!form.checkValidity()) {
        form.reportValidity();
        e.preventDefault();
        return;
    }
    btn.disabled = true;
    btnText.textContent = 'در حال ورود…';
    btnSpinner.classList.remove('hidden');
});
</script>

</body>
</html>