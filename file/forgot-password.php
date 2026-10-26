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

/* ============================================================
   ۲) Hardening — قبل از config.php
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

require_once 'config.php';

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
   ۴) اگر کاربر لاگین است، این صفحه لزومی ندارد
   ============================================================ */
if (function_exists('isLoggedIn') && isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>بازیابی رمز عبور - مانیتورینگ شبکه</title>

<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        darkMode: 'media',
        theme: { extend: { fontFamily: { sans: ['Vazirmatn', 'sans-serif'] } } },
    };
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">

<style>
/* ============================================================
   Ambient CSS — همان سیستم صفحه ورود
   ============================================================ */
:root {
    --surface-base:     #e9eef6;
    --surface-card:     linear-gradient(145deg, #ffffff 0%, #f3f7fb 100%);
    --surface-inset:    linear-gradient(145deg, #eef2f7 0%, #f8fafc 100%);
    --text-1: #0f172a;
    --text-2: #475569;
    --text-3: #94a3b8;
    --accent-1: #6366f1;
    --accent-2: #8b5cf6;
    --shadow-dark: rgba(15, 23, 42, 0.14);
    --shadow-soft: rgba(15, 23, 42, 0.07);
    --highlight:   rgba(255, 255, 255, 0.95);
    --edge-shadow: rgba(15, 23, 42, 0.06);
    --bg-pos: 0% 0%;
    --key-x:  6px;  --key-y:  8px;
    --soft-x: 14px; --soft-y: 26px;
    --hl-x:  1px;   --hl-y:  1px;
    --es-x: -1px;   --es-y: -1px;
    --btn-x: 4px;   --btn-y: 6px;
}
@media (prefers-color-scheme: dark) {
    :root {
        --surface-base:  #070b16;
        --surface-card:  linear-gradient(145deg, #1e293b 0%, #0f172a 100%);
        --surface-inset: linear-gradient(145deg, #0b1220 0%, #131c31 100%);
        --text-1: #f1f5f9;
        --text-2: #94a3b8;
        --text-3: #64748b;
        --shadow-dark: rgba(0, 0, 0, 0.55);
        --shadow-soft: rgba(0, 0, 0, 0.35);
        --highlight:   rgba(255, 255, 255, 0.06);
        --edge-shadow: rgba(0, 0, 0, 0.4);
    }
}

* { font-family: 'Vazirmatn', sans-serif; box-sizing: border-box; }
html, body { height: 100%; margin: 0; }

body {
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem 1rem;
    color: var(--text-1);
    background: radial-gradient(circle at var(--bg-pos),
                #ffffff 0%, var(--surface-base) 55%, #cfd8e5 100%);
    transition: background 0.6s ease;
    position: relative;
    overflow-x: hidden;
}
@media (prefers-color-scheme: dark) {
    body { background: radial-gradient(circle at var(--bg-pos),
                #1e293b 0%, var(--surface-base) 55%, #030712 100%); }
}

/* -------- چراغ‌های محیطی -------- */
.ambient-lights { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
.ambient-lights span { position: absolute; border-radius: 50%; filter: blur(110px); opacity: .22; }
.ambient-lights span:nth-child(1) { width:480px; height:480px; background:#6366f1; top:-160px; right:-160px; }
.ambient-lights span:nth-child(2) { width:380px; height:380px; background:#8b5cf6; bottom:-120px; left:-120px; }
.ambient-lights span:nth-child(3) { width:300px; height:300px; background:#06b6d4; top:45%; left:25%; }

@media (prefers-reduced-motion: no-preference) {
    .ambient-lights span { animation: drift 16s ease-in-out infinite; }
    .ambient-lights span:nth-child(2) { animation-delay: 4s; }
    .ambient-lights span:nth-child(3) { animation-delay: 8s; }
    .login-container { animation: rise 0.7s cubic-bezier(0.2, 0.9, 0.3, 1) both; }
    .contact-row { animation: rise 0.6s cubic-bezier(0.2, 0.9, 0.3, 1) both; }
    .contact-row:nth-child(1) { animation-delay: 0.10s; }
    .contact-row:nth-child(2) { animation-delay: 0.20s; }
    .contact-row:nth-child(3) { animation-delay: 0.30s; }
}
@keyframes drift {
    0%, 100% { transform: translate(0,0) scale(1); }
    50%      { transform: translate(-40px,50px) scale(1.08); }
}
@keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }

/* -------- کارت -------- */
.login-container { width: 100%; max-width: 460px; position: relative; z-index: 10; }

.amb-card {
    background: var(--surface-card);
    border-radius: 20px;
    border: 1px solid color-mix(in srgb, var(--highlight) 60%, transparent);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow:
        var(--key-x) var(--key-y) 18px var(--shadow-dark),
        var(--soft-x) var(--soft-y) 42px var(--shadow-soft),
        inset var(--hl-x) var(--hl-y) 1px var(--highlight),
        inset var(--es-x) var(--es-y) 1px var(--edge-shadow);
    transition: box-shadow 0.5s ease;
}

.amb-inset {
    background: var(--surface-inset);
    border-radius: 12px;
    box-shadow:
        inset 2px 2px 5px rgba(15,23,42,0.09),
        inset -1px -1px 2px var(--highlight),
        0 1px 0 var(--highlight);
}
@media (prefers-color-scheme: dark) {
    .amb-inset {
        box-shadow:
            inset 2px 2px 5px rgba(0,0,0,0.55),
            inset -1px -1px 2px rgba(255,255,255,0.04),
            0 1px 0 rgba(255,255,255,0.03);
    }
}

.amb-icon {
    width: 60px; height: 60px; border-radius: 18px;
    background: var(--surface-card);
    display: flex; align-items: center; justify-content: center;
    color: var(--accent-1);
    box-shadow:
        var(--key-x) var(--key-y) 12px var(--shadow-dark),
        inset var(--hl-x) var(--hl-y) 1px var(--highlight),
        inset var(--es-x) var(--es-y) 1px var(--edge-shadow);
}

/* -------- ردیف تماس -------- */
.contact-row {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 0.9rem 1rem;
    border-radius: 12px;
    background: var(--surface-inset);
    box-shadow:
        inset 2px 2px 5px rgba(15,23,42,0.08),
        inset -1px -1px 2px var(--highlight),
        0 1px 0 var(--highlight);
    text-decoration: none;
    color: inherit;
    transition: transform .2s ease, box-shadow .25s ease;
    position: relative;
}
@media (prefers-color-scheme: dark) {
    .contact-row {
        box-shadow:
            inset 2px 2px 5px rgba(0,0,0,0.55),
            inset -1px -1px 2px rgba(255,255,255,0.04),
            0 1px 0 rgba(255,255,255,0.03);
    }
}
.contact-row:hover {
    transform: translateY(-1px);
    box-shadow:
        inset 2px 2px 5px rgba(15,23,42,0.06),
        inset -1px -1px 2px var(--highlight),
        0 4px 12px rgba(99,102,241,0.18);
}
.contact-row:active {
    transform: translateY(0);
    box-shadow:
        inset 3px 3px 8px rgba(15,23,42,0.12),
        inset -1px -1px 2px var(--highlight);
}

.contact-icon {
    flex-shrink: 0;
    width: 42px; height: 42px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(145deg, var(--accent-1), var(--accent-2));
    color: #fff;
    box-shadow:
        2px 3px 8px rgba(99,102,241,0.35),
        inset 1px 1px 1px rgba(255,255,255,0.35);
}
.contact-icon svg { width: 20px; height: 20px; }

.contact-body { flex: 1; min-width: 0; }
.contact-label {
    font-size: 0.72rem;
    color: var(--text-2);
    margin: 0 0 0.15rem;
}
.contact-value {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--text-1);
    direction: ltr;
    text-align: right;
    word-break: break-all;
}
html[dir="rtl"] .contact-value { text-align: right; }

.contact-arrow {
    flex-shrink: 0;
    color: var(--text-3);
    transition: transform .2s ease, color .2s ease;
}
.contact-row:hover .contact-arrow {
    color: var(--accent-1);
    transform: translateX(-3px);
}

/* -------- دکمه بازگشت -------- */
.amb-btn {
    width: 100%;
    padding: 0.8rem 1rem;
    font-size: 0.92rem;
    font-weight: 600;
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    background: linear-gradient(145deg, var(--accent-1), var(--accent-2));
    box-shadow:
        var(--btn-x) var(--btn-y) 14px rgba(99,102,241,0.35),
        var(--btn-x) var(--btn-y) 4px  rgba(99,102,241,0.25),
        inset var(--hl-x) var(--hl-y) 1px rgba(255,255,255,0.4),
        inset var(--es-x) var(--es-y) 1px rgba(0,0,0,0.12);
    transition: transform .15s ease, box-shadow .25s ease;
    display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    text-decoration: none;
}
.amb-btn:hover {
    transform: translate(calc(var(--btn-x) * -0.15), calc(var(--btn-y) * -0.15));
    box-shadow:
        var(--btn-x) var(--btn-y) 22px rgba(99,102,241,0.45),
        var(--btn-x) var(--btn-y) 6px  rgba(99,102,241,0.3),
        inset var(--hl-x) var(--hl-y) 1px rgba(255,255,255,0.5),
        inset var(--es-x) var(--es-y) 1px rgba(0,0,0,0.12);
}
.amb-btn:active {
    transform: translate(calc(var(--btn-x) * 0.2), calc(var(--btn-y) * 0.2));
    box-shadow:
        inset 2px 2px 6px rgba(0,0,0,0.22),
        inset -1px -1px 2px rgba(255,255,255,0.15);
}

/* -------- نوتیس -------- */
.notice {
    display: flex; gap: 0.6rem; align-items: flex-start;
    padding: 0.75rem 0.9rem;
    border-radius: 10px;
    background: linear-gradient(145deg, #eef2ff, #e0e7ff);
    color: #4338ca;
    font-size: 0.78rem;
    line-height: 1.6;
    box-shadow:
        inset 1px 1px 1px rgba(255,255,255,0.9),
        inset -1px -1px 1px rgba(67,56,202,0.08);
}
@media (prefers-color-scheme: dark) {
    .notice {
        background: linear-gradient(145deg, #1e1b4b, #312e81);
        color: #c7d2fe;
        box-shadow:
            inset 1px 1px 1px rgba(255,255,255,0.06),
            inset -1px -1px 1px rgba(0,0,0,0.3);
    }
}

/* -------- سوییچ جهت نور -------- */
.light-switch {
    position: fixed; bottom: 1rem; left: 1rem; z-index: 30;
    display: flex; gap: 4px; padding: 5px;
    border-radius: 12px;
    background: color-mix(in srgb, var(--surface-card) 80%, transparent);
    backdrop-filter: blur(10px);
    box-shadow:
        2px 4px 12px var(--shadow-soft),
        inset 1px 1px 1px var(--highlight),
        inset -1px -1px 1px var(--edge-shadow);
}
.light-switch button {
    width: 26px; height: 26px; border-radius: 7px;
    border: 1px solid color-mix(in srgb, var(--text-3) 25%, transparent);
    background: var(--surface-inset); color: var(--text-2);
    cursor: pointer; font-size: 12px; line-height: 1;
    display: flex; align-items: center; justify-content: center; padding: 0;
    transition: color .2s, box-shadow .2s;
}
.light-switch button:hover { color: var(--accent-1); }
.light-switch button.active {
    background: linear-gradient(145deg, var(--accent-1), var(--accent-2));
    color: #fff; border-color: transparent;
    box-shadow: 2px 3px 8px rgba(99,102,241,0.35);
}

/* جهت‌های نور */
body[data-light="tr"] { --bg-pos:100% 0%; --key-x:-6px; --key-y:8px; --soft-x:-14px; --soft-y:26px; --hl-x:-1px; --hl-y:1px; --es-x:1px; --es-y:-1px; --btn-x:-4px; --btn-y:6px; }
body[data-light="bl"] { --bg-pos:0% 100%; --key-x:6px; --key-y:-8px; --soft-x:14px; --soft-y:-26px; --hl-x:1px; --hl-y:-1px; --es-x:-1px; --es-y:1px; --btn-x:4px; --btn-y:-6px; }
body[data-light="br"] { --bg-pos:100% 100%; --key-x:-6px; --key-y:-8px; --soft-x:-14px; --soft-y:-26px; --hl-x:-1px; --hl-y:-1px; --es-x:1px; --es-y:1px; --btn-x:-4px; --btn-y:-6px; }

@media (max-width: 480px) { .light-switch { display: none; } }
</style>
</head>

<body data-light="tl">

<!-- چراغ‌های محیطی -->
<div class="ambient-lights"><span></span><span></span><span></span></div>

<main class="login-container">

    <!-- برند -->
    <header class="text-center mb-6">
        <div class="amb-icon mx-auto mb-3">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" class="w-7 h-7">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
            </svg>
        </div>
        <h1 class="text-xl font-bold mb-1" style="color:var(--text-1)">بازیابی رمز عبور</h1>
        <p class="text-sm" style="color:var(--text-2)">راه‌های ارتباط با پشتیبانی</p>
    </header>

    <!-- کارت -->
    <section class="amb-card p-6">

        <!-- نوتیس -->
        <div class="notice mb-5">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="m-0">
                برای دریافت <strong>نام کاربری</strong> و <strong>بازنشانی رمز عبور</strong>،
                لطفاً از طریق راه‌های زیر با پشتیبانی تماس بگیرید. پس از احراز هویت،
                اطلاعات ورود شما در اختیارتان قرار می‌گیرد.
            </p>
        </div>

        <!-- ردیف‌های تماس -->
        <div class="space-y-3">

            <!-- تماس تلفنی -->
            <a href="tel:+989113309618" class="contact-row" aria-label="تماس تلفنی با پشتیبانی">
                <span class="contact-icon" aria-hidden="true">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                </span>
                <span class="contact-body">
                    <p class="contact-label">پشتیبانی</p>
                    <p class="contact-value">0911 330 9618</p>
                </span>
                <span class="contact-arrow" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M15 19l-7-7 7-7"/>
                    </svg>
                </span>
            </a>

            <!-- ایمیل -->
            <a href="mailto:info@msotoudeh.ir" class="contact-row" aria-label="ارسال ایمیل به پشتیبانی">
                <span class="contact-icon" aria-hidden="true">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </span>
                <span class="contact-body">
                    <p class="contact-label">ایمیل پشتیبانی</p>
                    <p class="contact-value">info@msotoudeh.ir</p>
                </span>
                <span class="contact-arrow" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M15 19l-7-7 7-7"/>
                    </svg>
                </span>
            </a>

            <!-- سایت -->
            <a href="https://msotoudeh.ir" target="_blank" rel="noopener noreferrer"
               class="contact-row" aria-label="وب‌سایت پشتیبانی">
                <span class="contact-icon" aria-hidden="true">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18M12 3a15 15 0 000 18"/>
                    </svg>
                </span>
                <span class="contact-body">
                    <p class="contact-label">وب‌سایت رسمی</p>
                    <p class="contact-value">msotoudeh.ir</p>
                </span>
                <span class="contact-arrow" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M15 19l-7-7 7-7"/>
                    </svg>
                </span>
            </a>

        </div>

        <!-- دکمه بازگشت -->
        <a href="login.php" class="amb-btn mt-6">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M11 17l-5-5m0 0l5-5m-5 5h12"/>
            </svg>
            بازگشت به صفحه ورود
        </a>

    </section>

    <!-- فوتر -->
    <p class="text-center text-xs mt-5" style="color:var(--text-3)">
        سیستم مانیتورینگ شبکه — تمامی حقوق محفوظ است
    </p>

</main>

<!-- سوییچ جهت نور -->
<div class="light-switch" title="جهت منبع نور">
    <button data-dir="tl" class="active" title="بالا-چپ">◤</button>
    <button data-dir="tr" title="بالا-راست">◥</button>
    <button data-dir="bl" title="پایین-چپ">◣</button>
    <button data-dir="br" title="پایین-راست">◢</button>
</div>

<script>
document.querySelectorAll('.light-switch button').forEach(b => {
    b.addEventListener('click', () => {
        document.body.dataset.light = b.dataset.dir;
        document.querySelectorAll('.light-switch button')
            .forEach(x => x.classList.toggle('active', x === b));
    });
});
</script>

</body>
</html>