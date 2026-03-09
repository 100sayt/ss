<?php
// includes/header.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/language.php';

// Verilənlər bazası sxemini yoxla və yenilə
ensure_database_schema($pdo);

// Check Remember Me cookie
check_remember_me($pdo);

// Qlobal tənzimləmələri gətir
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Aktiv Header reklamını gətir
$stmt_ad = $pdo->prepare("SELECT * FROM ads WHERE position = 'header_16_9' AND is_active = 1 LIMIT 1");
$stmt_ad->execute();
$header_ad = $stmt_ad->fetch();

// Giriş məhdudiyyəti yoxlanışı
if (($settings['site_access_restricted'] ?? '0') === '1' && !is_logged_in()) {
    $current_page = basename($_SERVER['PHP_SELF']);
    $allowed_pages = ['login.php', 'register.php', 'logout.php'];
    if (!in_array($current_page, $allowed_pages)) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        redirect('/login.php');
    }
}

// Vaxtı keçmiş boost-ların təmizlənməsi
check_expired_boosts($pdo);

// Aktiv səhifəni təyin et (Mobil menyu rənglənməsi üçün)
$active_page = basename($_SERVER['PHP_SELF']);
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($current_lang ?? 'az') ?>">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>RentAl - Turhan Elan</title>

    <!-- Essential Fallback Styles -->
    <style>
        .hidden { display: none !important; }
        [x-cloak] { display: none !important; }
        @media (min-width: 1024px) {
            .lg\:block { display: block !important; }
            .lg\:hidden { display: none !important; }
        }
        @media (max-width: 1023px) {
            .lg\:block { display: none !important; }
            .lg\:hidden { display: flex !important; }
        }
    </style>

    <!-- Tailwind Config -->
    <script>
        window.tailwind = window.tailwind || {};
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#ff6b6b",
                        "primary-hover": "#ee5253",
                        "background-light": "#f8fafc",
                        "surface-light": "#ffffff",
                        "glass-border": "rgba(255, 107, 107, 0.1)",
                        "glass-bg": "rgba(255, 255, 255, 0.7)",
                        secondary: "#181611",
                        "nav-inactive": "#94a3b8"
                    },
                    fontFamily: {
                        sans: ["Inter", "sans-serif"],
                    }
                },
            },
        }
    </script>

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>

    <style>
        :root {
            --primary-color: #ff6b6b;
            --primary-hover: #ee5253;
        }

        body {
            background-color: #f8fafc;
            padding-bottom: 75px;
            -webkit-text-size-adjust: 100%;
            font-family: 'Inter', sans-serif;
        }

        @media (min-width: 1024px) {
            body { padding-bottom: 0; }
        }

        .material-symbols-outlined {
            font-family: 'Material Symbols Outlined' !important;
            font-weight: normal;
            font-style: normal;
            line-height: 1;
            display: inline-block;
            white-space: nowrap;
            word-wrap: normal;
            direction: ltr;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        .glass-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 107, 107, 0.08);
        }

        .mobile-nav {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.03);
        }

        .nav-active { color: #ff6b6b !important; }
        .nav-active .material-symbols-outlined { font-variation-settings: 'FILL' 1; }

        .center-btn {
            width: 58px; height: 58px; background: #ff6b6b; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; color: white;
            box-shadow: 0 10px 25px rgba(255, 107, 107, 0.4); border: 5px solid #f8fafc;
            transition: all 0.3s ease;
        }

        .mobile-drawer {
            position: fixed; inset: 0; background: white; z-index: 9999;
            transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .mobile-drawer.open { transform: translateX(0); }

        .loader-spinner {
            border: 4px solid rgba(255, 107, 107, 0.2); border-left-color: #ff6b6b;
            border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>

    <script>
        function googleTranslateElementInit() {
            new google.translate.TranslateElement({
                pageLanguage: 'az',
                includedLanguages: 'az,ru,en,tr',
                autoDisplay: false
            }, 'google_translate_element');
        }

        function setGoogTrans(lang) {
            const domain = window.location.hostname;
            document.cookie = "googtrans=/az/" + lang + "; path=/; domain=" + domain;
            document.cookie = "googtrans=/az/" + lang + "; path=/";
            localStorage.setItem('site_lang', lang);
            window.location.reload();
        }
    </script>
    <script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
</head>

<body
    x-data="{ pageLoaded: false, mobileMenuOpen: false }"
    x-init="window.onload = () => { pageLoaded = true }"
    class="bg-background-light text-slate-800 min-h-screen flex flex-col selection:bg-primary selection:text-white"
>

<div id="google_translate_element" style="display:none;"></div>

<div x-show="!pageLoaded" class="fixed inset-0 z-[9999] bg-white flex flex-col items-center justify-center">
    <div class="loader-spinner"></div>
</div>

<header class="hidden lg:block sticky top-0 z-50 glass-header w-full shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-20">
            <div class="flex items-center gap-3 cursor-pointer notranslate" onclick="window.location.href='/index.php'">
                <img src="/assets/img/logo.png" alt="Logo" class="h-10 w-auto object-contain">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">
                    Rent<span style="color: #ff6b6b;">Al</span>
                </h1>
            </div>

            <nav class="flex items-center gap-8">
                <a class="text-slate-600 hover:text-primary transition-colors text-sm font-semibold first-letter:uppercase" href="/index.php"><?= htmlspecialchars(t('home') ?? 'ana səhifə') ?></a>

                <div class="flex items-center gap-2 notranslate">
                    <button onclick="setGoogTrans('az')" class="text-[11px] font-bold hover:text-primary transition-colors">AZ</button>
                    <button onclick="setGoogTrans('ru')" class="text-[11px] font-bold hover:text-primary transition-colors">RU</button>
                    <button onclick="setGoogTrans('en')" class="text-[11px] font-bold hover:text-primary transition-colors">EN</button>
                </div>

                <a href="/add_listing.php"
                   class="flex items-center h-11 px-6 rounded-xl text-white text-sm font-bold transition-all shadow-lg active:scale-95 hover:opacity-90"
                   style="background-color: #ff6b6b; box-shadow: 0 10px 15px -3px rgba(255, 107, 107, 0.3);">
                    <span class="material-symbols-outlined mr-2 text-[20px] notranslate">add_circle</span>
                    <?= htmlspecialchars(t('add_listing') ?? 'Yeni elan') ?>
                </a>
                <?php if(is_logged_in()): ?>
                    <div class="relative group">
                        <div class="w-11 h-11 rounded-xl bg-white border border-slate-200 text-slate-600 flex items-center justify-center cursor-pointer group-hover:border-primary/30 transition-all">
                            <span class="material-symbols-outlined text-[26px] notranslate">account_circle</span>
                        </div>
                        <div class="absolute top-full right-0 mt-2 w-48 bg-white rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all border border-slate-100 overflow-hidden z-50">
                            <a href="/profile.php" class="block px-4 py-3 text-sm text-slate-700 hover:bg-slate-50"><?= htmlspecialchars(t('my_listings') ?? 'Profilim') ?></a>
                            <a href="/logout.php" class="block px-4 py-3 text-sm text-red-600 hover:bg-red-50 border-t border-slate-50">Çıxış</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="/login.php" class="w-11 h-11 rounded-xl bg-white border border-slate-200 text-slate-600 flex items-center justify-center hover:text-primary">
                        <span class="material-symbols-outlined text-[22px] notranslate">login</span>
                    </a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
</header>

<div class="lg:hidden sticky top-0 z-50 glass-header px-4 py-4 flex items-center justify-between">
    <div class="flex items-center gap-2 notranslate" onclick="window.location.href='/index.php'">
        <img src="/assets/img/logo.png" alt="Logo" class="h-8 w-auto">
        <span class="text-xl font-bold text-slate-900">Rent<span class="text-primary">Al</span></span>
    </div>
    <div class="flex items-center gap-4">
        <?php if (is_logged_in()): ?>
            <?php
            $unread_count = 0;
            $stmt_unread = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
            $stmt_unread->execute([$_SESSION['user_id']]);
            $unread_count = $stmt_unread->fetchColumn();
            ?>
            <a href="messages.php" class="relative group">
                <span class="material-symbols-outlined text-slate-500 notranslate">chat_bubble</span>
                <?php if ($unread_count > 0): ?>
                    <span class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-white notranslate">
                        <?= $unread_count ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <button @click="mobileMenuOpen = true" class="w-10 h-10 flex items-center justify-center text-slate-600">
            <span class="material-symbols-outlined text-[30px] notranslate">menu</span>
        </button>
    </div>
</div>

<!-- Mobile Drawer -->
<div x-cloak
     :class="mobileMenuOpen ? 'open' : ''"
     class="mobile-drawer flex flex-col">
    <div class="flex items-center justify-between p-6 border-b border-slate-100">
        <div class="flex items-center gap-2">
            <img src="/assets/img/logo.png" alt="Logo" class="h-8 w-auto">
            <span class="text-xl font-bold text-slate-900">Rent<span class="text-primary">Al</span></span>
        </div>
        <button @click="mobileMenuOpen = false" class="w-10 h-10 flex items-center justify-center text-slate-400">
            <span class="material-symbols-outlined text-[30px]">close</span>
        </button>
    </div>

    <div class="flex-grow overflow-y-auto p-6 space-y-8">
        <div class="notranslate">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-4">Dil Seçimi</p>
            <div class="grid grid-cols-4 gap-3">
                <button @click="setGoogTrans('az')" class="px-3 py-2 text-xs font-bold rounded border border-slate-200">AZ</button>
                <button @click="setGoogTrans('ru')" class="px-3 py-2 text-xs font-bold rounded border border-slate-200">RU</button>
                <button @click="setGoogTrans('en')" class="px-3 py-2 text-xs font-bold rounded border border-slate-200">EN</button>
            </div>
        </div>

        <div class="space-y-2">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-4">Menyu</p>
            <a href="/index.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 text-slate-900 font-bold hover:text-primary transition-all">
                <span class="material-symbols-outlined">home</span> Ana səhifə
            </a>
            <a href="/categories.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 text-slate-900 font-bold hover:text-primary transition-all">
                <span class="material-symbols-outlined">grid_view</span> Kateqoriyalar
            </a>
        </div>

        <div class="space-y-2 pt-4 border-t border-slate-100">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-4">Hesab</p>
            <?php if (is_logged_in()): ?>
                <a href="/profile.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 text-slate-900 font-bold hover:text-primary transition-all">
                    <span class="material-symbols-outlined">account_circle</span> Profilim
                </a>
                <a href="/logout.php" class="flex items-center gap-4 p-4 rounded-2xl bg-red-50 text-red-600 font-bold hover:bg-red-100 transition-all">
                    <span class="material-symbols-outlined">logout</span> Çıxış
                </a>
            <?php else: ?>
                <a href="/login.php" class="flex items-center gap-4 p-4 rounded-2xl bg-primary text-white font-bold shadow-lg shadow-primary/20">
                    <span class="material-symbols-outlined">login</span> Giriş Edin
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .mobile-nav {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-top: 1px solid rgba(0, 0, 0, 0.05);
    }
    .center-btn-wrapper {
        position: relative;
        top: -24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 100%;
    }
    .center-btn {
        width: 56px; height: 56px; background: #ff6b6b; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; color: white;
        box-shadow: 0 4px 15px rgba(255, 107, 107, 0.4); border: 4px solid white;
        transition: all 0.3s ease;
    }
    .center-btn:active { transform: scale(0.9); }
    .nav-item-active { color: #ff6b6b !important; }
    .nav-item-inactive { color: #94a3b8; }
</style>

<nav class="lg:hidden fixed bottom-0 left-0 w-full z-[100] mobile-nav h-16 bg-white/80 backdrop-blur-lg border-t border-slate-100">
    <div class="flex items-center justify-between h-full px-2 max-w-md mx-auto relative">
        <a href="/index.php" class="flex flex-col items-center justify-center w-full no-underline transition-all duration-200 active:scale-95 <?= $active_page == 'index.php' ? 'nav-item-active' : 'nav-item-inactive' ?>">
            <span class="material-symbols-outlined notranslate">home</span>
            <span class="text-[10px] font-bold mt-1 tracking-tight">Əsas</span>
        </a>
        <a href="/profile.php?tab=favorites" class="flex flex-col items-center justify-center w-full no-underline transition-all duration-200 active:scale-95 <?= $active_page == 'favorites.php' ? 'nav-item-active' : 'nav-item-inactive' ?>">
            <span class="material-symbols-outlined notranslate">favorite</span>
            <span class="text-[10px] font-bold mt-1 tracking-tight">Seçilmişlər</span>
        </a>
        <div class="w-full flex justify-center">
            <div class="center-btn-wrapper">
                <a href="/add_listing.php" class="center-btn shadow-lg">
                    <span class="material-symbols-outlined notranslate">add</span>
                </a>
                <span class="text-[10px] font-black tracking-tight mt-1 notranslate" style="color: #ff6b6b;">Yeni elan</span>
            </div>
        </div>
        <a href="/messages.php" class="flex flex-col items-center justify-center w-full no-underline transition-all duration-200 active:scale-95 <?= $active_page == 'messages.php' ? 'nav-item-active' : 'nav-item-inactive' ?>">
            <span class="material-symbols-outlined notranslate">chat_bubble</span>
            <span class="text-[10px] font-bold mt-1 tracking-tight">Mesajlar</span>
        </a>
        <a href="/profile.php" class="flex flex-col items-center justify-center w-full no-underline transition-all duration-200 active:scale-95 <?= $active_page == 'profile.php' ? 'nav-item-active' : 'nav-item-inactive' ?>">
            <span class="material-symbols-outlined notranslate">person</span>
            <span class="text-[10px] font-bold mt-1 tracking-tight">Kabinet</span>
        </a>
    </div>
</nav>

<main class="relative z-10 flex-grow flex flex-col items-center w-full">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6 w-full">
        <?php if (isset($pdo)) render_ad($pdo, 'header_16_9', 'w-full mb-6 rounded-2xl overflow-hidden shadow-sm'); ?>
    </div>
