<?php
// includes/header.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/language.php';
require_once 'lic.php';

ensure_database_schema($pdo);
check_remember_me($pdo);

$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; }

$stmt_ad = $pdo->prepare("SELECT * FROM ads WHERE position = 'header_16_9' AND is_active = 1 LIMIT 1");
$stmt_ad->execute();
$header_ad = $stmt_ad->fetch();

if (($settings['site_access_restricted'] ?? '0') === '1' && !is_logged_in()) {
    $current_page = basename($_SERVER['PHP_SELF']);
    $allowed_pages = ['login.php', 'register.php', 'logout.php'];
    if (!in_array($current_page, $allowed_pages)) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        redirect('/login.php');
    }
}

check_expired_boosts($pdo);
$active_page = basename($_SERVER['PHP_SELF']);

$stmt_cats = $pdo->query("SELECT id, " . lang_col('name') . " as name, icon_path, parent_id FROM categories ORDER BY parent_id, id");
$all_cats = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);

$categories_data = [];
$categories_tree = [];
foreach ($all_cats as $row) {
    if ($row['parent_id'] == 0) {
        $categories_data[] = $row;
        $categories_tree[$row['id']] = $row;
        $categories_tree[$row['id']]['subs'] = [];
    } else {
        if (isset($categories_tree[$row['parent_id']])) {
            $categories_tree[$row['parent_id']]['subs'][] = $row;
        }
    }
}

$stmt_cities = $pdo->query("SELECT id, " . lang_col('name') . " as name FROM cities");
$cities_data = $stmt_cities->fetchAll();

$h_search_query = isset($_GET['q']) ? sanitize_input($_GET['q']) : '';
$h_city_filter = isset($_GET['city_id']) ? (int)$_GET['city_id'] : 0;
$h_cat_filter = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$h_min_price = isset($_GET['min_price']) ? $_GET['min_price'] : '';
$h_max_price = isset($_GET['max_price']) ? $_GET['max_price'] : '';
$h_has_deposit = isset($_GET['has_deposit']) ? $_GET['has_deposit'] : '';
$h_sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($current_lang ?? 'az') ?>">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" name="viewport"/>
    <title>RentAl - Turhan Elan</title>

    <script>
        window.tailwind = {
            config: {
                darkMode: "class",
                theme: {
                    extend: {
                        colors: {
                            primary: "#ff6b6b", "primary-hover": "#ee5253", "background-light": "#f8fafc", "surface-light": "#ffffff",
                            "glass-border": "rgba(255, 107, 107, 0.1)", "glass-bg": "rgba(255, 255, 255, 0.7)", secondary: "#181611", "nav-inactive": "#94a3b8"
                        },
                        fontFamily: { sans: ["Inter", "sans-serif"] }
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>

    <style>
        :root { --primary-color: #ff6b6b; --primary-hover: #ee5253; }
        body { background-color: #f8fafc; padding-bottom: 75px; -webkit-tap-highlight-color: transparent; }
        @media (min-width: 1024px) { body { padding-bottom: 0; } }
        .glass-header { background: #ffffff; border-bottom: 1px solid rgba(0, 0, 0, 0.05); }
        .mobile-nav { background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); border-top: 1px solid rgba(0, 0, 0, 0.05); box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.03); }
        .nav-active { color: #ff6b6b !important; }
        .nav-active .material-symbols-outlined { font-variation-settings: 'FILL' 1; }
        [x-cloak] { display: none !important; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        .center-btn { width: 56px; height: 56px; background: #ff6b6b; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; box-shadow: 0 4px 15px rgba(255, 107, 107, 0.4); border: 4px solid white; transition: all 0.3s ease; }
        .center-btn:active { transform: scale(0.9); }
        .notranslate { translate: no; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 48; display: inline-block; line-height: 1; text-transform: none; letter-spacing: normal; word-wrap: normal; white-space: nowrap; direction: ltr; -webkit-font-smoothing: antialiased; }
        .mobile-drawer { position: fixed; inset: 0; background: white; z-index: 99999; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .mobile-drawer.open { transform: translateX(0); }
    </style>

    <script>
    function changeLang(lang) {
        const domain = window.location.hostname;
        const mainDomain = domain.includes('.') ? domain.substring(domain.lastIndexOf(".", domain.lastIndexOf(".") - 1)) : domain;

        if (lang === 'az') {
            localStorage.removeItem('site_lang');
            const cookies = ['googtrans', '_googtrans', 'goog.translate.active'];
            const domains = [domain, '.' + domain, mainDomain, '.' + mainDomain, ''];
            cookies.forEach(name => {
                domains.forEach(dom => {
                    document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=" + dom;
                });
                document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/";
            });
            sessionStorage.clear();
            location.href = location.pathname + location.search;
        } else {
            localStorage.setItem('site_lang', lang);
            document.cookie = "googtrans=/az/" + lang + "; path=/";
            document.cookie = "googtrans=/az/" + lang + "; path=/; domain=" + domain;
            location.reload();
        }
    }
    function googleTranslateElementInit() {
        new google.translate.TranslateElement({pageLanguage: 'az', includedLanguages: 'az,ru,en', autoDisplay: false}, 'google_translate_element');
    }
    </script>
    <script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
</head>
<body x-data="{
    pageLoaded: false,
    mobileMenuOpen: false,
    searchQuery: '<?= addslashes($h_search_query) ?>',
    searchSuggestions: [],
    filterModalOpen: false,
    catalogModalOpen: false,
    langOpen: false,
    activeCat: null,
    cityId: '<?= $h_city_filter ?: '' ?>',
    catId: '<?= $h_cat_filter ?: '' ?>',
    minPrice: '<?= $h_min_price ?>',
    maxPrice: '<?= $h_max_price ?>',
    hasDeposit: '<?= $h_has_deposit ?>',
    sortOrder: '<?= $h_sort ?>',
    submitSearch() {
        let url = new URL('/index.php', window.location.origin);
        if(this.searchQuery) url.searchParams.set('q', this.searchQuery);
        if(this.cityId) url.searchParams.set('city_id', this.cityId);
        if(this.catId) url.searchParams.set('cat_id', this.catId);
        if(this.minPrice) url.searchParams.set('min_price', this.minPrice);
        if(this.maxPrice) url.searchParams.set('max_price', this.maxPrice);
        if(this.hasDeposit !== '') url.searchParams.set('has_deposit', this.hasDeposit);
        if(this.sortOrder !== 'newest') url.searchParams.set('sort', this.sortOrder);
        window.location.href = url.toString();
    }
}" x-init="window.onload = () => { pageLoaded = true }" class="bg-background-light text-slate-800 min-h-screen flex flex-col selection:bg-primary selection:text-white">

<div id="google_translate_element" style="display:none;"></div>
<div x-show="!pageLoaded" class="fixed inset-0 z-[1000000] bg-white flex flex-col items-center justify-center"><div class="border-4 border-primary/20 border-l-primary rounded-full w-10 h-10 animate-spin"></div></div>

<header class="sticky top-0 z-50 glass-header w-full shadow-sm bg-white">
    <!-- Desktop Header -->
    <div class="hidden lg:block max-w-[1400px] mx-auto px-4">
        <div class="flex flex-col">
            <div class="flex items-center justify-between h-20 gap-8">
                <div class="flex items-center gap-3 cursor-pointer notranslate shrink-0" onclick="window.location.href='/index.php'">
                    <img src="/assets/img/logo.png" alt="" class="h-10 w-auto object-contain">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900"><span style="color: #ff6b6b;">RentAl</span></h1>
                </div>

                <div class="flex-1 flex items-center gap-3">
                    <!-- Kataloq first, aligned with search -->
                    <button @click="catalogModalOpen = true" class="h-12 px-6 bg-slate-100 rounded-2xl flex items-center gap-3 font-black text-slate-700 hover:bg-slate-200 transition-all shrink-0">
                        <span class="material-symbols-outlined text-[24px]">grid_view</span>
                        Kataloq
                    </button>

                    <div class="flex-1 max-w-2xl">
                        <form @submit.prevent="submitSearch()" class="relative flex items-center gap-2 bg-slate-100 p-1 rounded-2xl border-2 border-transparent focus-within:bg-white focus-within:border-primary/20 transition-all">
                            <div class="flex items-center h-10 px-4 shrink-0 border-r border-slate-200">
                                <span class="material-symbols-outlined text-slate-400 text-[28px]">search</span>
                            </div>
                            <div class="flex-1 flex items-center h-10">
                                <input x-model="searchQuery" @input.debounce.200ms="if(searchQuery.length > 0) { fetch('/ajax/search_suggestions.php?q=' + encodeURIComponent(searchQuery)).then(res => res.json()).then(data => searchSuggestions = data) } else { searchSuggestions = [] }" class="bg-transparent border-0 outline-none text-slate-800 placeholder-slate-400 w-full text-sm font-medium focus:ring-0" placeholder="Əşya və ya xidmət axtarışı" type="text" autocomplete="off"/>
                            </div>
                            <button type="button" @click="filterModalOpen = true" class="h-10 w-10 flex items-center justify-center text-slate-500 hover:text-primary transition-all shrink-0"><span class="material-symbols-outlined text-[24px]">tune</span></button>
                            <button type="submit" class="h-10 px-8 bg-primary text-white rounded-xl text-sm font-black shadow-md hover:brightness-110 transition-all shrink-0">Axtar</button>

                            <div x-show="searchSuggestions.length > 0" @click.outside="searchSuggestions = []" class="absolute top-full left-0 right-0 mt-2 bg-white rounded-2xl shadow-2xl border border-slate-100 overflow-hidden z-[9999]" x-cloak>
                                <template x-for="sug in searchSuggestions" :key="sug">
                                    <div @click="searchQuery = sug; searchSuggestions = []; submitSearch()" class="px-5 py-3 text-sm text-slate-700 hover:bg-slate-50 cursor-pointer flex items-center gap-3 border-b border-slate-50 last:border-0"><span class="material-symbols-outlined text-slate-300 text-lg">history</span><span x-text="sug"></span></div>
                                </template>
                            </div>
                        </form>
                    </div>
                </div>

                <nav class="flex items-center gap-6 shrink-0">
                    <!-- Language Dropdown -->
                    <div class="relative" @click.outside="langOpen = false">
                        <button @click="langOpen = !langOpen" class="flex items-center gap-1 text-[13px] font-black uppercase text-slate-600 hover:text-primary transition-all px-3 py-2 rounded-xl bg-slate-50">
                            <span id="current-lang-text"><?= strtoupper($current_lang ?? 'az') ?></span>
                            <span class="material-symbols-outlined text-[18px]" :class="langOpen ? 'rotate-180' : ''">expand_more</span>
                        </button>
                        <div x-show="langOpen" x-transition class="absolute top-full right-0 mt-2 bg-white rounded-xl shadow-2xl border border-slate-50 py-2 w-32 z-[10000]" x-cloak>
                            <button onclick="changeLang('az')" class="block w-full text-left px-4 py-2.5 text-[12px] font-bold uppercase hover:bg-slate-50 hover:text-primary">AZ - Azerbaycan</button>
                            <button onclick="changeLang('ru')" class="block w-full text-left px-4 py-2.5 text-[12px] font-bold uppercase hover:bg-slate-50 hover:text-primary border-t border-slate-50">RU - Русский</button>
                            <button onclick="changeLang('en')" class="block w-full text-left px-4 py-2.5 text-[12px] font-bold uppercase hover:bg-slate-50 hover:text-primary border-t border-slate-50">EN - English</button>
                        </div>
                    </div>

                    <?php if (is_logged_in()): ?>
                        <?php $unread_count = 0; $stmt_unread = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0"); $stmt_unread->execute([$_SESSION['user_id']]); $unread_count = $stmt_unread->fetchColumn(); ?>
                        <a href="messages.php" class="relative p-2 text-slate-500 hover:text-primary transition-all"><span class="material-symbols-outlined text-[26px]">chat_bubble</span><?php if ($unread_count > 0): ?><span class="absolute top-1 right-1 w-4 h-4 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-white"><?= $unread_count ?></span><?php endif; ?></a>
                    <?php endif; ?>
                    <a href="/add_listing.php" class="flex items-center h-11 px-5 rounded-xl text-white text-sm font-black transition-all shadow-lg active:scale-95 hover:opacity-90 bg-primary shadow-primary/20"><span class="material-symbols-outlined mr-2 text-[20px]">add_circle</span>Yeni elan</a>
                    <?php if(is_logged_in()): ?>
                        <div class="relative group">
                            <div class="w-11 h-11 rounded-xl bg-slate-50 text-slate-600 flex items-center justify-center cursor-pointer hover:bg-primary/10 hover:text-primary transition-all"><span class="material-symbols-outlined text-[28px]">account_circle</span></div>
                            <div class="absolute top-full right-0 mt-2 w-56 bg-white rounded-2xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all border border-slate-100 overflow-hidden z-50">
                                <?php if (is_admin()): ?><a href="/admin/index.php" class="block px-5 py-4 text-sm text-red-600 font-bold hover:bg-red-100 border-b border-slate-50 flex items-center gap-3"><span class="material-symbols-outlined text-[20px]">admin_panel_settings</span>Admin Panel</a><?php endif; ?>
                                <a href="/profile.php" class="block px-5 py-3.5 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-3"><span class="material-symbols-outlined text-[20px] text-slate-400">person</span> Profilim</a>
                                <a href="/logout.php" class="block px-5 py-3.5 text-sm text-red-600 hover:bg-red-50 border-t border-slate-50 flex items-center gap-3"><span class="material-symbols-outlined text-[20px]">logout</span> Çıxış</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="/login.php" class="w-11 h-11 rounded-xl bg-slate-50 text-slate-600 flex items-center justify-center hover:bg-primary/10 hover:text-primary transition-all"><span class="material-symbols-outlined text-[24px]">login</span></a>
                    <?php endif; ?>
                </nav>
            </div>
            <div class="h-14 border-t border-slate-50 flex items-center overflow-x-auto scrollbar-hide select-none">
                <div class="flex items-center gap-1">
                    <?php foreach($categories_data as $cat): ?>
                        <a href="index.php?cat_id=<?= $cat['id'] ?>" class="flex items-center gap-2 px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-50 transition-all shrink-0 <?= ($h_cat_filter == $cat['id']) ? 'bg-primary/5 text-primary' : '' ?>">
                            <?php if(!empty($cat['icon_path'])): ?><?php if(strpos($cat['icon_path'], '/') !== false): ?><img src="<?= htmlspecialchars($cat['icon_path']) ?>" class="w-5 h-5 object-contain"><?php else: ?><span class="material-symbols-outlined text-[18px]"><?= htmlspecialchars($cat['icon_path']) ?></span><?php endif; ?><?php endif; ?>
                            <span class="text-sm font-bold whitespace-nowrap"><?= htmlspecialchars($cat['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Header -->
    <div class="lg:hidden w-full flex flex-col bg-white border-b border-slate-50">
        <div class="flex items-center justify-between px-4 py-3">
            <button @click="mobileMenuOpen = true" class="w-10 h-10 flex items-center justify-center text-slate-600"><span class="material-symbols-outlined text-[30px]">menu</span></button>
            <div class="flex items-center gap-2" onclick="window.location.href='/index.php'"><img src="/assets/img/logo.png" alt="" class="h-7 w-auto"><span class="text-xl font-black text-primary">RentAl</span></div>
            <a href="/add_listing.php" class="w-10 h-10 flex items-center justify-center text-primary"><span class="material-symbols-outlined text-[32px]">add_circle</span></a>
        </div>
        <div class="px-4 pb-3">
            <form @submit.prevent="submitSearch()" class="relative flex items-center bg-slate-100 rounded-xl h-12 px-3 border-2 border-transparent focus-within:bg-white focus-within:border-primary/20 transition-all">
                <span class="material-symbols-outlined text-slate-400 text-[28px] notranslate">search</span>
                <input x-model="searchQuery" class="bg-transparent border-0 outline-none text-slate-800 placeholder-slate-400 w-full text-base font-medium focus:ring-0 pl-3" placeholder="Axtar" type="text" autocomplete="off"/>
                <button type="button" @click="filterModalOpen = true" class="text-slate-500 pl-2"><span class="material-symbols-outlined text-[24px]">tune</span></button>
            </form>
        </div>
        <div class="flex items-center gap-4 overflow-x-auto scrollbar-hide px-4 pb-4 select-none">
            <div @click="catalogModalOpen = true" class="flex flex-col items-center gap-2 shrink-0">
                <div class="w-14 h-14 rounded-2xl bg-slate-50 flex items-center justify-center text-primary shadow-sm ring-1 ring-slate-100"><span class="material-symbols-outlined text-[28px]">grid_view</span></div>
                <span class="text-[11px] font-bold text-slate-500">Kataloq</span>
            </div>
            <?php foreach($categories_data as $cat): ?>
                <a href="index.php?cat_id=<?= $cat['id'] ?>" class="flex flex-col items-center gap-2 shrink-0">
                    <div class="w-14 h-14 rounded-2xl bg-slate-50 flex items-center justify-center overflow-hidden shadow-sm ring-1 ring-slate-100">
                        <?php if(!empty($cat['icon_path'])): ?><?php if(strpos($cat['icon_path'], '/') !== false): ?><img src="<?= htmlspecialchars($cat['icon_path']) ?>" class="w-full h-full object-contain p-3"><?php else: ?><span class="material-symbols-outlined text-[28px] text-slate-500"><?= htmlspecialchars($cat['icon_path']) ?></span><?php endif; ?><?php endif; ?>
                    </div>
                    <span class="text-[11px] font-bold text-slate-500 text-center max-w-[70px] truncate"><?= htmlspecialchars($cat['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Filter Modal -->
    <template x-teleport="body">
        <div x-show="filterModalOpen" class="fixed inset-0 z-[1000000] flex items-center justify-center p-4" x-cloak>
            <div x-show="filterModalOpen" x-transition.opacity class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="filterModalOpen = false"></div>
            <div x-show="filterModalOpen" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 translate-y-8" x-transition:enter-end="opacity-100 scale-100 translate-y-0" class="relative w-full max-w-sm bg-white rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-white shrink-0"><h3 class="text-base font-black text-slate-900 flex items-center gap-2"><span class="material-symbols-outlined text-primary">tune</span> Filtrlər</h3><button @click="filterModalOpen = false" class="w-9 h-9 rounded-xl bg-slate-50 hover:bg-red-50 hover:text-red-500 flex items-center justify-center text-slate-400 transition-all"><span class="material-symbols-outlined text-xl">close</span></button></div>
                <div class="flex-1 overflow-y-auto p-5 space-y-5">
                    <div><label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase tracking-wider">Şəhər</label><select x-model="cityId" class="w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none appearance-none cursor-pointer"><option value="">Bütün Azərbaycan</option><?php foreach(($cities_data ?? []) as $city): ?><option value="<?= $city['id'] ?>"><?= htmlspecialchars($city['name']) ?></option><?php endforeach; ?></select></div>
                    <div><label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase tracking-wider">Kateqoriya</label><select x-model="catId" class="w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none appearance-none cursor-pointer"><option value="">Bütün kateqoriyalar</option><?php foreach(($categories_data ?? []) as $cat): ?><option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?></select></div>
                    <div><label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase tracking-wider">Qiymət</label><div class="grid grid-cols-2 gap-2"><input type="number" x-model="minPrice" placeholder="Min" class="w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none"><input type="number" x-model="maxPrice" placeholder="Max" class="w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none"></div></div>
                    <div><label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase tracking-wider">Sıralama</label><div class="grid grid-cols-3 gap-2"><template x-for="opt in [{v:'all', l:'Hamısı'}, {v:'price_asc', l:'Ucuz'}, {v:'price_desc', l:'Baha'}]"><button type="button" @click="sortOrder = opt.v" :class="sortOrder == opt.v ? 'bg-primary text-white border-primary' : 'bg-white text-slate-600 border-slate-100'" class="h-8 rounded-lg text-[10px] font-bold border-2 transition-all" x-text="opt.l"></button></template></div></div>
                    <div><label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase tracking-wider">Depozit</label><div class="grid grid-cols-3 gap-2"><template x-for="opt in [{v:'',l:'Hamısı'}, {v:'1',l:'Depozitli'}, {v:'0',l:'Depozitsiz'}]"><button type="button" @click="hasDeposit = opt.v" :class="hasDeposit === opt.v ? 'bg-primary text-white border-primary' : 'bg-white text-slate-600 border-slate-100'" class="h-8 rounded-lg text-[10px] font-bold border-2 transition-all" x-text="opt.l"></button></template></div></div>
                </div>
                <div class="p-4 border-t border-slate-50 bg-white flex gap-3 shrink-0"><button type="button" @click="cityId=''; catId=''; minPrice=''; maxPrice=''; hasDeposit=''; sortOrder='newest'; searchQuery=''; submitSearch()" class="flex-1 h-10 text-[12px] font-bold text-slate-400 hover:text-red-500 transition-colors">Sıfırla</button><button type="button" @click="submitSearch()" class="flex-1 h-10 bg-primary text-white rounded-lg text-[13px] font-bold shadow-md transition-all">Göstər</button></div>
            </div>
        </div>
    </template>

    <!-- Catalog Drawer -->
    <template x-teleport="body">
        <div x-show="catalogModalOpen" class="fixed inset-0 z-[1000001] flex justify-end" x-cloak>
            <div x-show="catalogModalOpen" x-transition.opacity class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" @click="catalogModalOpen = false"></div>
            <div x-show="catalogModalOpen" x-transition:enter="transform transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="relative w-full max-w-sm h-full bg-white shadow-2xl flex flex-col">
                <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 shadow-sm"><h2 class="text-xl font-black text-slate-900 flex items-center gap-2"><span class="material-symbols-outlined text-primary">grid_view</span> Kataloq</h2><button @click="catalogModalOpen = false" class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-slate-500 hover:text-red-500 transition-all"><span class="material-symbols-outlined">close</span></button></div>
                <div class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50/50 scrollbar-hide">
                    <?php foreach($categories_tree as $cat): ?>
                        <div class="flex flex-col">
                            <div @click="activeCat = (activeCat === <?= $cat['id'] ?> ? null : <?= $cat['id'] ?>)" class="flex items-center gap-4 p-4 rounded-2xl transition-all cursor-pointer bg-white border border-slate-100" :class="activeCat === <?= $cat['id'] ?> ? 'ring-2 ring-primary/20 border-primary' : ''">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center transition-all overflow-hidden" :class="activeCat === <?= $cat['id'] ?> ? 'bg-primary text-white shadow-lg shadow-primary/20' : 'bg-slate-50 text-slate-400'"><?php if(!empty($cat['icon_path'])): ?><?php if(strpos($cat['icon_path'], '/') !== false): ?><img src="<?= htmlspecialchars($cat['icon_path']) ?>" class="w-full h-full object-contain p-2" :class="activeCat === <?= $cat['id'] ?> ? 'brightness-0 invert' : ''"><?php else: ?><span class="material-symbols-outlined text-[20px]"><?= htmlspecialchars($cat['icon_path']) ?></span><?php endif; ?><?php endif; ?></div>
                                <span class="flex-1 text-[15px] font-black text-slate-700"><?= htmlspecialchars($cat['name']) ?></span>
                                <span class="material-symbols-outlined text-slate-300 transition-transform duration-300" :class="activeCat === <?= $cat['id'] ?> ? 'rotate-180 text-primary' : ''">expand_more</span>
                            </div>
                            <div x-show="activeCat === <?= $cat['id'] ?>" x-collapse><div class="pl-12 pr-2 py-2 space-y-1"><?php foreach($cat['subs'] as $sub): ?><a href="index.php?cat_id=<?= $sub['id'] ?>" class="flex items-center justify-between p-3 rounded-xl text-[14px] font-bold text-slate-500 hover:text-primary hover:bg-white transition-all no-underline group"><?= htmlspecialchars($sub['name']) ?><span class="material-symbols-outlined text-xs opacity-0 group-hover:opacity-100 transition-all">arrow_forward</span></a><?php endforeach; ?><a href="index.php?cat_id=<?= $cat['id'] ?>" class="block p-3 text-[11px] font-black text-primary uppercase tracking-widest no-underline border-t border-slate-100/50 mt-1">Hamısına bax →</a></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </template>
</header>

<!-- Mobile Main Drawer -->
<div x-cloak :class="mobileMenuOpen ? 'open' : ''" class="mobile-drawer flex flex-col">
    <div class="flex items-center justify-between p-6 border-b border-slate-100">
        <div class="flex items-center gap-2"><img src="/assets/img/logo.png" alt="" class="h-8 w-auto"><span class="text-xl font-bold text-slate-900">RentAl</span></div>
        <button @click="mobileMenuOpen = false" class="w-10 h-10 flex items-center justify-center text-slate-400 active:scale-90 transition-all"><span class="material-symbols-outlined text-[30px] notranslate">close</span></button>
    </div>
    <div class="flex-grow overflow-y-auto p-6 space-y-8">
        <!-- Language Select Dropdown in Mobile -->
        <div x-data="{ m_langOpen: false }" class="notranslate mt-4">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-4">Dil Seçimi</p>
            <div class="relative">
                <button @click="m_langOpen = !m_langOpen" class="w-full flex items-center justify-between p-4 rounded-2xl bg-slate-50 font-bold border border-slate-100">
                    <span class="uppercase"><?= strtoupper($current_lang ?? 'az') ?></span>
                    <span class="material-symbols-outlined" :class="m_langOpen ? 'rotate-180' : ''">expand_more</span>
                </button>
                <div x-show="m_langOpen" x-transition class="mt-2 bg-white rounded-2xl border border-slate-100 overflow-hidden shadow-xl">
                    <button @click="changeLang('az'); m_langOpen = false" class="block w-full text-left p-4 font-bold hover:bg-slate-50 border-b border-slate-50 last:border-0">Azerbaycan - AZ</button>
                    <button @click="changeLang('ru'); m_langOpen = false" class="block w-full text-left p-4 font-bold hover:bg-slate-50 border-b border-slate-50 last:border-0">Русский - RU</button>
                    <button @click="changeLang('en'); m_langOpen = false" class="block w-full text-left p-4 font-bold hover:bg-slate-50 last:border-0">English - EN</button>
                </div>
            </div>
        </div>
        <div class="space-y-2">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-4">Menyu</p>
            <a href="/index.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 text-slate-900 font-bold hover:bg-primary/5 hover:text-primary transition-all no-underline"><span class="material-symbols-outlined notranslate">home</span> Əsas səhifə</a>
            <a href="/categories.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 text-slate-900 font-bold hover:bg-primary/5 hover:text-primary transition-all no-underline"><span class="material-symbols-outlined notranslate">grid_view</span> Kateqoriyalar</a>
            <?php if (function_exists('is_admin') && is_admin()): ?><a href="/admin/index.php" class="flex items-center gap-4 p-4 rounded-2xl bg-red-50 text-red-600 font-bold hover:bg-red-100 transition-all no-underline"><span class="material-symbols-outlined notranslate">admin_panel_settings</span> Admin Panel</a><?php endif; ?>
        </div>
        <div class="space-y-2 pt-4 border-t border-slate-100">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-4">Hesab</p>
            <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                <a href="/profile.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 text-slate-900 font-bold no-underline"><span class="material-symbols-outlined notranslate">account_circle</span> Profilim</a>
                <a href="/profile.php?tab=my_listings" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 text-slate-900 font-bold no-underline"><span class="material-symbols-outlined notranslate">list_alt</span> Mənim elanlarım</a>
                <a href="/logout.php" class="flex items-center gap-4 p-4 rounded-2xl bg-red-50 text-red-600 font-bold no-underline"><span class="material-symbols-outlined notranslate">logout</span> Çıxış</a>
            <?php else: ?>
                <a href="/login.php" class="flex items-center gap-4 p-4 rounded-2xl bg-primary text-white font-bold shadow-lg shadow-primary/20 no-underline"><span class="material-symbols-outlined notranslate">login</span> Giriş Edin</a>
                <a href="/register.php" class="flex items-center gap-4 p-4 rounded-2xl bg-white border border-slate-200 text-slate-900 font-bold no-underline mt-2"><span class="material-symbols-outlined notranslate">person_add</span> Qeydiyyat</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<nav class="lg:hidden fixed bottom-0 left-0 w-full z-[1000] mobile-nav h-16 flex items-center justify-between px-2 max-w-md mx-auto relative select-none">
    <a href="/index.php" class="flex flex-col items-center justify-center w-full no-underline transition-all duration-200 active:scale-90 <?= ($active_page ?? '') == 'index.php' ? 'nav-item-active' : 'nav-item-inactive' ?>"><span class="material-symbols-outlined text-[24px] notranslate">home</span><span class="text-[10px] font-bold mt-1 tracking-tight">Əsas</span></a>
    <a href="/profile.php?tab=favorites" class="flex flex-col items-center justify-center w-full no-underline transition-all duration-200 active:scale-90 <?= ($active_page ?? '') == 'favorites.php' ? 'nav-item-active' : 'nav-item-inactive' ?>"><span class="material-symbols-outlined text-[24px] notranslate">favorite</span><span class="text-[10px] font-bold mt-1 tracking-tight">Seçilmişlər</span></a>
    <div class="w-full flex justify-center"><div class="center-btn-wrapper"><a href="/add_listing.php" class="center-btn shadow-lg active:scale-95"><span class="material-symbols-outlined text-[32px] notranslate">add</span></a><span class="text-[10px] font-black tracking-tight mt-1 notranslate" style="color: #ff6b6b;">Yeni elan</span></div></div>
    <a href="/messages.php" class="flex flex-col items-center justify-center w-full no-underline transition-all duration-200 active:scale-90 <?= ($active_page ?? '') == 'messages.php' ? 'nav-item-active' : 'nav-item-inactive' ?>"><span class="material-symbols-outlined text-[24px] notranslate">chat_bubble</span><span class="text-[10px] font-bold mt-1 tracking-tight">Mesajlar</span></a>
    <a href="/profile.php" class="flex flex-col items-center justify-center w-full no-underline transition-all duration-200 active:scale-90 <?= ($active_page ?? '') == 'profile.php' ? 'nav-item-active' : 'nav-item-inactive' ?>"><span class="material-symbols-outlined text-[24px] notranslate">person</span><span class="text-[10px] font-bold mt-1 tracking-tight">Kabinet</span></a>
</nav>

<main class="relative z-10 w-full bg-transparent">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6 mb-0 w-full flex justify-center">
        <?php
        $allowed_pages = ['index.php', 'listing.php'];
        $current_page = basename($_SERVER['SCRIPT_NAME']);
        if (in_array($current_page, $allowed_pages) && isset($pdo) && function_exists('render_ad')):
            ob_start();
            render_ad($pdo, 'header_16_9', 'w-full h-auto max-h-[150px] sm:max-h-[230px] object-contain block mx-auto transition-transform duration-700 group-hover:scale-[1.01]');
            $ad_content = ob_get_clean();
            if (!empty(trim($ad_content))): ?>
            <div x-data="{ loaded: false }" x-init="setTimeout(() => loaded = true, 150)" x-show="loaded" x-transition:enter="transition ease-out duration-500" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" class="relative w-full max-w-[1000px] group leading-[0]">
                <div class="relative overflow-hidden rounded-2xl bg-white border border-gray-200 shadow-sm hover:shadow-md transition-all duration-300">
                    <div class="flex flex-col">
                        <div class="w-full overflow-hidden flex justify-center bg-gray-50/50"><?= $ad_content ?></div>
                        <div class="bg-gray-50/50 py-0.5 px-4 flex justify-end items-center border-t border-gray-100/50">
                            <span class="text-[8px] font-bold text-gray-300 uppercase tracking-[2px]">Reklam</span>
                        </div>
                    </div>
                </div>
                <div class="absolute inset-0 pointer-events-none rounded-2xl ring-1 ring-inset ring-black/5"></div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
