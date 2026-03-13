<?php
// index.php
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/language.php';

// Temporary DB Fix for listings table
try {
    $check_ai = $pdo->query("SHOW COLUMNS FROM listings LIKE 'id'")->fetch();
    if ($check_ai && strpos($check_ai['Extra'], 'auto_increment') === false) {
        $has_zero = $pdo->query("SELECT id FROM listings WHERE id = 0")->fetch();
        if ($has_zero) {
            $max_id = $pdo->query("SELECT MAX(id) FROM listings")->fetchColumn() ?: 100;
            $new_id = $max_id + 1;
            $pdo->exec("UPDATE listings SET id = $new_id WHERE id = 0");
            $pdo->exec("UPDATE listing_images SET listing_id = $new_id WHERE listing_id = 0");
        }
        $pdo->exec("ALTER TABLE listings MODIFY id INT AUTO_INCREMENT");
    }
} catch (Exception $e) {}

// Axtarış sorğusunu alırıq
$search_query = isset($_GET['q']) ? sanitize_input($_GET['q']) : '';
$city_filter = isset($_GET['city_id']) ? (int)$_GET['city_id'] : 0;
$cat_filter = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;

// Filtrlər
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : null;
$has_deposit_filter = isset($_GET['has_deposit']) && $_GET['has_deposit'] !== '' ? (int)$_GET['has_deposit'] : null;
$sort = $_GET['sort'] ?? 'newest';

// --- AĞILLI AXTARIŞ MƏNTİQİ ---
$search_sql_part = "";
$search_params_list = [];

if (!empty($search_query)) {
    $q_lower = mb_strtolower($search_query, 'UTF-8');
    $replace_map = ['ə'=>'e','ç'=>'c','ş'=>'s','ğ'=>'g','ö'=>'o','ü'=>'u','ı'=>'i','e'=>'ə','c'=>'ç','s'=>'ş','g'=>'ğ','o'=>'ö','u'=>'ü','i'=>'ı'];
    $alt_query = strtr($q_lower, $replace_map);
    $vowels = ['a','e','ə','i','ı','o','ö','u','ü'];
    $fuzzy_query = str_replace($vowels, '_', $q_lower);
    $search_sql_part = " AND (l.title LIKE ? OR l.title LIKE ? OR l.title LIKE ?)";
    $search_params_list[] = "%$q_lower%";
    $search_params_list[] = "%$alt_query%";
    $search_params_list[] = "%$fuzzy_query%";
}

$filter_sql_part = "";
$filter_params = [];
if ($min_price !== null) { $filter_sql_part .= " AND l.price >= ?"; $filter_params[] = $min_price; }
if ($max_price !== null) { $filter_sql_part .= " AND l.price <= ?"; $filter_params[] = $max_price; }
if ($has_deposit_filter !== null) { $filter_sql_part .= " AND l.has_deposit = ?"; $filter_params[] = $has_deposit_filter; }

$order_by = "l.created_at DESC";
if ($sort === 'price_asc') $order_by = "l.price ASC";
elseif ($sort === 'price_desc') $order_by = "l.price DESC";
elseif ($sort === 'oldest') $order_by = "l.created_at ASC";

// Fetch Categories & Cities
$stmt_cats = $pdo->query("SELECT id, " . lang_col('name') . " as name FROM categories WHERE parent_id = 0 ORDER BY " . lang_col('name'));
$categories_data = $stmt_cats->fetchAll();
$stmt_cities = $pdo->query("SELECT id, " . lang_col('name') . " as name FROM cities ORDER BY " . lang_col('name'));
$cities_data = $stmt_cities->fetchAll();

// Get Selected Category Name
$selected_category_name = 'Bu kateqoriya';
if ($cat_filter > 0) {
    foreach ($categories_data as $cat) {
        if ($cat['id'] == $cat_filter) { $selected_category_name = $cat['name']; break; }
    }
}

// --- FETCH LISTINGS ---
$common_joins = "
    JOIN categories c ON l.category_id = c.id
    JOIN cities cit ON l.city_id = cit.id
    JOIN currencies cur ON l.currency_id = cur.id
    JOIN users u ON l.user_id = u.id
";

$premium_sql = "
    SELECT l.*, c.".lang_col('name')." as category_name, cit.".lang_col('name')." as city_name, cur.symbol, u.user_type,
           (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_main DESC, id ASC LIMIT 1) as main_image
    FROM listings l $common_joins
    WHERE l.status = 'active' AND l.boost_type IN ('premium', 'vip') AND l.boost_end_time > NOW()
";
$premium_params = array_merge([], $search_params_list);
if (!empty($search_sql_part)) $premium_sql .= $search_sql_part;
if ($city_filter) { $premium_sql .= " AND l.city_id = ?"; $premium_params[] = $city_filter; }
if ($cat_filter) { $premium_sql .= " AND (l.category_id = ? OR c.parent_id = ?)"; $premium_params[] = $cat_filter; $premium_params[] = $cat_filter; }
$premium_sql .= $filter_sql_part;
$premium_params = array_merge($premium_params, $filter_params);
$premium_sql .= " ORDER BY RAND() LIMIT 8";
$stmt_premium = $pdo->prepare($premium_sql);
$stmt_premium->execute($premium_params);
$premium_listings = $stmt_premium->fetchAll();

$normal_sql = "
    SELECT l.*, c.".lang_col('name')." as category_name, cit.".lang_col('name')." as city_name, cur.symbol, u.user_type,
           (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_main DESC, id ASC LIMIT 1) as main_image
    FROM listings l $common_joins
    WHERE l.status = 'active'
";
$normal_params = array_merge([], $search_params_list);
if (!empty($search_sql_part)) $normal_sql .= $search_sql_part;
if ($city_filter) { $normal_sql .= " AND l.city_id = ?"; $normal_params[] = $city_filter; }
if ($cat_filter) { $normal_sql .= " AND (l.category_id = ? OR c.parent_id = ?)"; $normal_params[] = $cat_filter; $normal_params[] = $cat_filter; }
$normal_sql .= $filter_sql_part;
$normal_params = array_merge($normal_params, $filter_params);
$normal_sql .= " ORDER BY $order_by LIMIT 16";
$stmt_normal = $pdo->prepare($normal_sql);
$stmt_normal->execute($normal_params);
$normal_listings = $stmt_normal->fetchAll();

$rent_labels = ['hourly' => '/saat', 'day' => '/gün', 'weekly' => '/həftə', 'month' => '/ay', 'year' => '/il'];

require_once 'includes/header.php';
?>

<div class="w-full max-w-[1600px] mx-auto flex gap-4 mt-6">

<aside class="hidden xl:block w-48 shrink-0 sticky top-24 h-fit">
    <?php if(function_exists('render_ad')) render_ad($pdo, 'sidebar_left_9_16', 'w-full h-full'); ?>
</aside>

<div class="flex-1 min-w-0" x-data="{
    filterModalOpen: false,
    searchSuggestions: [],
    searchQuery: '<?= addslashes($search_query) ?>',
    minPrice: '<?= $min_price ?>',
    maxPrice: '<?= $max_price ?>',
    hasDeposit: '<?= $has_deposit_filter ?>',
    sortOrder: '<?= $sort ?>',
    catId: '<?= $cat_filter ?>',
    cityId: '<?= $city_filter ?>'
}">
    <section class="w-full px-4 sm:px-6 lg:px-8 pt-4 pb-6">
        <div class="relative rounded-[1.5rem] overflow-hidden flex flex-col items-center justify-center text-center py-12 px-6 sm:px-10 border border-slate-100 shadow-xl shadow-slate-200/50 min-h-[320px]">

            <?php
            try { $hero_slides = $pdo->query("SELECT image_url FROM hero_slides ORDER BY display_order ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $hero_slides = []; }
            if (empty($hero_slides)) { $hero_slides = ['https://unblast.com/wp-content/uploads/2021/01/Space-Background-Images.jpg']; }
            ?>

            <div x-data="{ current: 0, slides: <?= json_encode($hero_slides) ?> }" x-init="setInterval(() => current = (current + 1) % slides.length, 5000)" class="absolute inset-0 z-0">
                <template x-for="(slide, index) in slides" :key="index">
                    <div class="absolute inset-0 bg-cover bg-center transition-opacity duration-1000" :class="index === current ? 'opacity-100' : 'opacity-0'" :style="'background-image: url(\'' + slide + '\');'"></div>
                </template>
            </div>

            <div class="relative z-30 flex flex-col items-center max-w-3xl w-full">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/20 border border-white/40 text-white text-[10px] font-bold uppercase tracking-widest mb-4 backdrop-blur-md">
                    <span class="w-2 h-2 rounded-full bg-[#ff6b6b] animate-pulse"></span> Premium Kirayə Platforması
                </div>
                <h2 class="text-3xl md:text-4xl font-black text-white mb-3 leading-tight tracking-tight drop-shadow-lg">İstədiyiniz hər şeyi icarəyə götürün</h2>
                <p class="text-white/90 text-sm md:text-base mb-8 max-w-md mx-auto font-medium drop-shadow-md">Elektronikadan daşınmaz əmlaka qədər hər şey bir kliklə.</p>

                <div class="w-full max-w-2xl relative z-[1000]">
                    <form action="/index.php" method="GET" id="mainSearchForm" class="bg-white p-2 rounded-2xl flex items-center gap-2 shadow-2xl relative">
                        <div class="flex-1 flex items-center h-12 px-4 bg-slate-50 rounded-xl border border-transparent focus-within:border-[#ff6b6b]/30 transition-all relative">
                            <span class="material-symbols-outlined text-slate-400 text-[22px]">search</span>
                            <input name="q"
                                   x-model="searchQuery"
                                   @input.debounce.200ms="if(searchQuery.length > 0) { fetch('/ajax/search_suggestions.php?q=' + encodeURIComponent(searchQuery)).then(res => res.json()).then(data => searchSuggestions = data) } else { searchSuggestions = [] }"
                                   class="bg-transparent border-0 outline-none text-slate-800 placeholder-slate-400 w-full text-sm focus:ring-0 pl-3"
                                   placeholder="Nə axtarırsınız?"
                                   type="text"
                                   autocomplete="off"/>

                            <!-- Search Suggestions Dropdown -->
                            <div x-show="searchSuggestions.length > 0"
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 translate-y-2"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 @click.away="searchSuggestions = []"
                                 class="absolute top-full left-0 right-0 bg-white mt-3 rounded-2xl shadow-[0_20px_50px_rgba(0,0,0,0.2)] border border-slate-100 z-[10001] overflow-hidden text-left py-1">
                                <template x-for="item in searchSuggestions" :key="item.id">
                                    <div @click="searchQuery = item.title; searchSuggestions = []; document.getElementById('mainSearchForm').submit()" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 transition-colors group cursor-pointer border-b border-slate-50 last:border-0">
                                        <span class="material-symbols-outlined text-slate-300 text-sm group-hover:text-[#ff6b6b]">search</span>
                                        <span class="text-sm font-bold text-slate-700 group-hover:text-[#ff6b6b] truncate" x-text="item.title"></span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <button type="button" @click="filterModalOpen = true" class="h-12 w-12 flex items-center justify-center bg-slate-50 rounded-xl text-slate-500 hover:text-[#ff6b6b] hover:bg-[#ff6b6b]/10 transition-all border border-transparent shrink-0">
                            <span class="material-symbols-outlined">tune</span>
                        </button>

                        <button type="submit" class="h-12 px-6 sm:px-10 text-white rounded-xl text-sm font-black transition-all shadow-lg active:scale-95 hover:brightness-110 shrink-0" style="background-color: #ff6b6b;">Axtar</button>

                        <input type="hidden" name="min_price" :value="minPrice">
                        <input type="hidden" name="max_price" :value="maxPrice">
                        <input type="hidden" name="has_deposit" :value="hasDeposit">
                        <input type="hidden" name="sort" :value="sortOrder">
                        <input type="hidden" name="cat_id" :value="catId">
                        <input type="hidden" name="city_id" :value="cityId">
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Refined Compact Filter Modal -->
    <template x-teleport="body">
        <div x-show="filterModalOpen" class="fixed inset-0 z-[1000000] flex items-center justify-center p-4 sm:p-6" x-cloak>
            <div x-show="filterModalOpen" x-transition.opacity class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" @click="filterModalOpen = false"></div>
            <div x-show="filterModalOpen"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95 translate-y-8"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 class="relative w-full max-w-sm bg-white rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">

                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-white shrink-0">
                    <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#ff6b6b]">tune</span> Filtrlər
                    </h3>
                    <button @click="filterModalOpen = false" class="w-9 h-9 rounded-xl bg-slate-50 hover:bg-red-50 hover:text-red-500 flex items-center justify-center text-slate-400 transition-all"><span class="material-symbols-outlined text-xl">close</span></button>
                </div>

                <div class="flex-1 overflow-y-auto p-5 space-y-5">
                    <!-- City Selection -->
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Şəhər</label>
                        <div class="relative">
                            <select x-model="cityId" class="w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:border-[#ff6b6b]/30 focus:bg-white focus:ring-0 outline-none transition-all appearance-none cursor-pointer">
                                <option value="">Bütün Azərbaycan</option>
                                <?php foreach($cities_data as $city): ?>
                                    <option value="<?= $city['id'] ?>"><?= htmlspecialchars($city['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-lg">location_on</span>
                        </div>
                    </div>

                    <!-- Category Selection -->
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Kateqoriya</label>
                        <div class="relative">
                            <select x-model="catId" class="w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:border-[#ff6b6b]/30 focus:bg-white focus:ring-0 outline-none transition-all appearance-none cursor-pointer">
                                <option value="">Bütün kateqoriyalar</option>
                                <?php foreach($categories_data as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-lg">category</span>
                        </div>
                    </div>

                    <!-- Price Input (Constrained) -->
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Qiymət (AZN)</label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" x-model="minPrice" placeholder="Min" class="w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:border-[#ff6b6b]/30 focus:bg-white focus:ring-0 outline-none transition-all">
                            <input type="number" x-model="maxPrice" placeholder="Max" class="w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:border-[#ff6b6b]/30 focus:bg-white focus:ring-0 outline-none transition-all">
                        </div>
                    </div>

                    <!-- Deposit -->
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Depozit</label>
                        <div class="grid grid-cols-3 gap-2">
                            <template x-for="opt in [{v:'',l:'Hamısı'}, {v:'1',l:'Var'}, {v:'0',l:'Yox'}]">
                                <button type="button" @click="hasDeposit = opt.v"
                                        :class="hasDeposit == opt.v ? 'bg-slate-900 text-white border-slate-900 shadow-md' : 'bg-slate-50 text-slate-600 border-transparent hover:bg-slate-100'"
                                        class="h-9 rounded-xl text-[9px] font-black uppercase tracking-tighter border-2 transition-all" x-text="opt.l"></button>
                            </template>
                        </div>
                    </div>

                    <!-- Sorting -->
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Sıralama</label>
                        <div class="grid grid-cols-2 gap-2">
                            <template x-for="opt in [{v:'newest',l:'Yeni'}, {v:'oldest',l:'Köhnə'}, {v:'price_asc',l:'Ucuz'}, {v:'price_desc',l:'Baha'}]">
                                <button type="button" @click="sortOrder = opt.v"
                                        :class="sortOrder == opt.v ? 'border-[#ff6b6b] bg-[#ff6b6b]/5 text-[#ff6b6b]' : 'border-slate-100 text-slate-600'"
                                        class="h-10 rounded-xl text-[10px] font-bold border-2 transition-all" x-text="opt.l"></button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="p-5 border-t border-slate-100 bg-white flex gap-3 shrink-0">
                    <button type="button" @click="minPrice=''; maxPrice=''; hasDeposit=''; sortOrder='newest'; catId=''; cityId=''; document.getElementById('mainSearchForm').submit()" class="flex-1 h-11 text-[11px] font-black text-slate-400 uppercase tracking-widest hover:text-red-500 transition-colors">Sıfırla</button>
                    <button type="button" @click="document.getElementById('mainSearchForm').submit()" class="flex-[2] h-11 bg-[#ff6b6b] text-white rounded-xl text-xs font-black uppercase tracking-widest hover:brightness-110 transition-all shadow-lg shadow-[#ff6b6b]/20">Göstər</button>
                </div>
            </div>
        </div>
    </template>

<?php
$stmt = $pdo->query("SELECT * FROM categories ORDER BY parent_id, id");
$all_cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categories_tree = [];
foreach ($all_cats as $row) {
    if ($row['parent_id'] == 0) { $categories_tree[$row['id']] = $row; $categories_tree[$row['id']]['subs'] = []; }
    else { if (isset($categories_tree[$row['parent_id']])) { $categories_tree[$row['parent_id']]['subs'][] = $row; } }
}
?>

<section x-data="{ catalogModalOpen: false, activeCat: null }" class="w-full px-4 sm:px-6 lg:px-8 py-6 relative select-none">
    <div class="mb-5"><h3 class="text-lg font-black text-slate-900 flex items-center gap-2"><span class="w-1 h-5 rounded-full" style="background-color: #ff6b6b;"></span> Populyar kateqoriyalar</h3></div>
    <div x-data="{ isDown: false, startX: 0, scrollLeft: 0, mouseStart(e) { this.isDown = true; this.startX = e.pageX - this.$el.offsetLeft; this.scrollLeft = this.$el.scrollLeft; this.$el.classList.add('cursor-grabbing'); }, mouseLeave() { this.isDown = false; }, mouseUp() { this.isDown = false; }, mouseMove(e) { if (!this.isDown) return; e.preventDefault(); const x = e.pageX - this.$el.offsetLeft; const walk = (x - this.startX) * 2; this.$el.scrollLeft = this.scrollLeft - walk; } }" @mousedown="mouseStart" @mouseleave="mouseLeave" @mouseup="mouseUp" @mousemove="mouseMove" class="flex gap-3 overflow-x-auto pb-4 scrollbar-hide snap-x snap-mandatory scroll-smooth -mx-4 px-4 sm:mx-0 sm:px-0 cursor-grab">
        <button @click="catalogModalOpen = true; activeCat = null" class="flex-shrink-0 w-20 md:w-24 flex flex-col items-center justify-center gap-2 p-3 rounded-xl shadow-sm hover:shadow-md transition-all duration-300 group snap-start outline-none border border-slate-100 bg-white">
            <div class="w-10 h-10 rounded-lg bg-slate-50 flex items-center justify-center pointer-events-none group-hover:scale-110 transition-transform"><span class="material-symbols-outlined text-[#ff6b6b]">grid_view</span></div>
            <span class="text-[11px] font-bold text-[#ff6b6b]">Kataloq</span>
        </button>
        <?php foreach($categories_tree as $cat): ?>
            <div @click="activeCat = <?= $cat['id'] ?>; catalogModalOpen = true" class="flex-shrink-0 w-20 md:w-24 flex flex-col items-center justify-center gap-2 p-3 rounded-xl transition-all duration-300 group snap-start cursor-pointer border <?= ($cat_filter == $cat['id']) ? 'shadow-lg bg-[#ff6b6b] border-[#ff6b6b]' : 'bg-white border-slate-50 shadow-sm' ?>">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center overflow-hidden transition-all duration-300 pointer-events-none <?= ($cat_filter == $cat['id']) ? 'bg-white/20' : 'bg-slate-50 group-hover:bg-[#ff6b6b]/10' ?>">
                    <?php if(!empty($cat['icon_path'])): ?>
                        <?php if(strpos($cat['icon_path'], '/') !== false): ?> <img src="<?= htmlspecialchars($cat['icon_path']) ?>" class="w-full h-full object-contain p-1.5 transition-transform duration-300 group-hover:scale-110">
                        <?php else: ?> <span class="material-symbols-outlined text-[22px] transition-colors duration-300 <?= ($cat_filter == $cat['id']) ? 'text-white' : 'text-slate-500 group-hover:text-[#ff6b6b]' ?>"><?= htmlspecialchars($cat['icon_path']) ?></span> <?php endif; ?>
                    <?php else: ?> <span class="material-symbols-outlined text-[22px] text-slate-400">category</span> <?php endif; ?>
                </div>
                <span class="text-[11px] font-bold text-center w-full truncate pointer-events-none <?= ($cat_filter == $cat['id']) ? 'text-white' : 'text-slate-600 group-hover:text-[#ff6b6b]' ?>"><?= htmlspecialchars($cat['name_az']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <template x-teleport="body">
        <div x-show="catalogModalOpen" class="fixed inset-0 z-[999999] flex justify-end" x-cloak>
            <div x-show="catalogModalOpen" x-transition.opacity class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" @click="catalogModalOpen = false"></div>
            <div x-show="catalogModalOpen" x-transition:enter="transform transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="relative w-full max-w-sm h-full bg-white shadow-2xl flex flex-col">
                <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 bg-white shadow-sm"><h2 class="text-xl font-black text-slate-900 flex items-center gap-2"><span class="material-symbols-outlined text-[#ff6b6b]">tune</span> Kataloq</h2><button @click="catalogModalOpen = false" class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-slate-500 hover:text-red-500 transition-all"><span class="material-symbols-outlined">close</span></button></div>
                <div class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50/50 scrollbar-hide">
                    <?php foreach($categories_tree as $cat): ?>
                        <div class="flex flex-col">
                            <div @click="activeCat = (activeCat === <?= $cat['id'] ?> ? null : <?= $cat['id'] ?>)" class="flex items-center gap-4 p-4 rounded-2xl transition-all cursor-pointer bg-white border border-slate-100" :class="activeCat === <?= $cat['id'] ?> ? 'ring-2 ring-[#ff6b6b]/20 border-[#ff6b6b]' : ''">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center transition-all overflow-hidden" :class="activeCat === <?= $cat['id'] ?> ? 'bg-[#ff6b6b] text-white shadow-lg shadow-[#ff6b6b]/20' : 'bg-slate-50 text-slate-400'">
                                    <?php if(!empty($cat['icon_path'])): ?> <?php if(strpos($cat['icon_path'], '/') !== false): ?> <img src="<?= htmlspecialchars($cat['icon_path']) ?>" class="w-full h-full object-contain p-2" :class="activeCat === <?= $cat['id'] ?> ? 'scale-110' : ''"> <?php else: ?> <span class="material-symbols-outlined text-[20px]"><?= htmlspecialchars($cat['icon_path']) ?></span> <?php endif; ?> <?php else: ?> <span class="material-symbols-outlined text-[20px]">category</span> <?php endif; ?>
                                </div>
                                <span class="flex-1 text-[15px] font-black text-slate-700"><?= htmlspecialchars($cat['name_az']) ?></span>
                                <span class="material-symbols-outlined text-slate-300 transition-transform duration-300" :class="activeCat === <?= $cat['id'] ?> ? 'rotate-180 text-[#ff6b6b]' : ''">expand_more</span>
                            </div>
                            <div x-show="activeCat === <?= $cat['id'] ?>" x-collapse>
                                <div class="pl-12 pr-2 py-2 space-y-1">
                                    <?php if(!empty($cat['subs'])): ?> <?php foreach($cat['subs'] as $sub): ?> <a href="index.php?cat_id=<?= $sub['id'] ?>" class="flex items-center justify-between p-3 rounded-xl text-[14px] font-bold text-slate-500 hover:text-[#ff6b6b] hover:bg-white transition-all no-underline group"><?= htmlspecialchars($sub['name_az']) ?><span class="material-symbols-outlined text-xs opacity-0 group-hover:opacity-100 transition-all">arrow_forward</span></a> <?php endforeach; ?> <?php else: ?> <p class="p-3 text-[13px] text-slate-400 italic">Bu kateqoriyada alt bölmə yoxdur.</p> <?php endif; ?>
                                    <a href="index.php?cat_id=<?= $cat['id'] ?>" class="block p-3 text-[11px] font-black text-[#ff6b6b] uppercase tracking-widest no-underline border-t border-slate-100/50 mt-1">Hamısına bax →</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </template>
</section>

<?php if (!empty($premium_listings)): ?>
<section class="w-full px-4 sm:px-6 lg:px-8 py-10">
    <div class="flex items-center justify-between mb-8"><div class="flex items-center gap-3"><span class="flex h-2.5 w-2.5 relative"><span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" style="background-color: #ff6b6b;"></span><span class="relative inline-flex rounded-full h-2.5 w-2.5" style="background-color: #ff6b6b;"></span></span><h3 class="text-xl font-black text-slate-900 tracking-tight">VIP elanlar</h3></div></div>
    <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-3 sm:gap-5">
        <?php foreach ($premium_listings as $ad): ?>
            <div class="glass-panel rounded-[1.2rem] sm:rounded-[1.5rem] overflow-hidden glow-effect transition-all duration-500 group flex flex-col h-full bg-white border border-slate-100 hover:-translate-y-1.5 cursor-pointer shadow-sm hover:shadow-xl" onclick="window.location.href='/listing.php?id=<?= (int)$ad['id'] ?>'">
                <div class="relative h-32 sm:h-48 w-full overflow-hidden">
                    <div class="absolute top-2 left-2 z-10 flex flex-col gap-1">
                        <div class="px-2 py-0.5 text-white text-[8px] sm:text-[9px] font-black uppercase rounded-md tracking-wider shadow-lg" style="background-color: <?= ($ad['user_type'] === 'company') ? '#4f46e5' : '#ff6b6b' ?>;"><?= ($ad['user_type'] === 'company') ? 'Mağaza' : 'Premium' ?></div>
                        <?php if($ad['has_deposit']): ?> <div class="px-2 py-0.5 bg-slate-900/80 backdrop-blur-sm text-white text-[8px] sm:text-[9px] font-black uppercase rounded-md tracking-wider shadow-lg">Depozit</div> <?php endif; ?>
                    </div>
                    <button class="absolute top-2 right-2 z-10 p-1.5 sm:p-2 bg-white/90 backdrop-blur-md rounded-full text-slate-400 hover:text-red-500 transition-colors shadow-sm" onclick="event.stopPropagation(); toggleFavorite(<?= (int)$ad['id'] ?>, this)"><span class="material-symbols-outlined text-[16px] sm:text-[18px] <?= (function_exists('is_favorite') && is_favorite($pdo, $ad['id'])) ? 'text-red-500' : '' ?>" style="<?= (function_exists('is_favorite') && is_favorite($pdo, $ad['id'])) ? "font-variation-settings: 'FILL' 1" : '' ?>">favorite</span></button>
                    <div class="w-full h-full bg-cover bg-center group-hover:scale-105 transition-transform duration-[1s]" style="background-image: url('<?= htmlspecialchars($ad['main_image'] ?? '/assets/img/no-image.jpg') ?>');"></div>
                    <div class="absolute inset-0 bg-gradient-to-t from-slate-900/30 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>
                <div class="p-3 sm:p-5 flex flex-col flex-grow">
                    <h4 class="text-sm sm:text-base font-bold text-slate-900 mb-1.5 line-clamp-1 group-hover:text-[#ff6b6b] transition-colors"><?= htmlspecialchars($ad['title']) ?></h4>
                    <div class="flex items-center text-slate-500 text-[10px] sm:text-[11px] mb-3 font-medium"><span class="material-symbols-outlined text-[14px] sm:text-[16px] mr-1" style="color: #ff6b6b;">location_on</span> <?= htmlspecialchars($ad['city_name']) ?><span class="mx-1.5 text-slate-200">|</span><?= htmlspecialchars($ad['category_name']) ?></div>
                    <div class="mt-auto flex items-center justify-between border-t border-slate-50 pt-3 sm:pt-4">
                        <div class="overflow-hidden"><p class="text-[8px] sm:text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">QİYMƏT</p><p class="text-slate-900 font-black text-sm sm:text-xl tracking-tight truncate"><?= ($ad['price'] == (int)$ad['price']) ? number_format($ad['price'], 0, '.', ' ') : number_format($ad['price'], 2, '.', ' '); ?> <span class="text-[9px] sm:text-[11px] font-bold text-slate-400"><?= htmlspecialchars($ad['symbol']) ?><?= ($ad['listing_type'] ?? 'rent') === 'rent' ? ($rent_labels[$ad['rent_period']] ?? '/gün') : '' ?></span></p></div>
                        <button class="flex-shrink-0 p-1.5 sm:p-2.5 rounded-lg bg-slate-900 text-white hover:bg-[#ff6b6b] transition-all shadow-md"><span class="material-symbols-outlined text-[16px] sm:text-[18px]">arrow_forward</span></button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="w-full px-4 sm:px-6 lg:px-8 py-4"><div class="relative w-full rounded-[1.5rem] overflow-hidden border border-slate-100 shadow-lg shadow-red-500/5"><div class="absolute inset-0 z-10" style="background: linear-gradient(to right, rgba(255, 107, 107, 0.95), rgba(238, 82, 83, 0.9));"></div><div class="absolute inset-0 bg-cover bg-center opacity-30 z-0" style="background-image: url('https://unblast.com/wp-content/uploads/2021/01/Space-Background-Images.jpg');"></div><div class="relative z-20 p-6 md:p-8 flex flex-col md:flex-row items-center justify-between gap-6 text-center md:text-left"><div class="max-w-lg"><h3 class="text-xl md:text-2xl font-black text-white mb-2 tracking-tight">Təhlükəsiz və Sığortalı İcarə</h3><p class="text-white/90 text-[13px] md:text-sm font-medium leading-relaxed">RentAl vasitəsilə etdiyiniz bütün əməliyyatlar tam qorunur. Məhsullarınızı sığortalayın və rahatlıqla icarəyə verin.</p></div><button class="whitespace-nowrap px-6 py-3 bg-white text-[13px] font-black rounded-xl hover:bg-slate-50 transition-all shadow-md hover:shadow-lg active:scale-95" style="color: #ff6b6b;">Ətraflı Öyrən</button></div></div></section>

<section class="w-full px-4 sm:px-6 lg:px-8 py-12 mb-12">
    <div class="flex items-center justify-between mb-10"><h3 class="text-2xl font-black text-slate-900 flex items-center gap-3"><span class="w-2 h-8 bg-slate-200 rounded-full"></span> Son elanlar</h3></div>
    <?php if(empty($normal_listings)): ?>
        <div class="w-full py-24 text-center glass-panel rounded-3xl border border-slate-100"><span class="material-symbols-outlined text-7xl text-slate-200 mb-4 <?= $cat_filter ? 'animate-pulse' : 'animate-bounce' ?>"><?= $cat_filter ? 'category' : 'inbox' ?></span><?php if ($cat_filter): ?><h3 class="text-2xl font-black text-slate-800 mb-2">Bu kateqoriyada elan yoxdur</h3><p class="text-slate-500 font-medium text-sm md:text-base">Hal-hazırda <span class="font-bold" style="color: #ff6b6b;">"<?= htmlspecialchars($selected_category_name) ?>"</span> kateqoriyası üzrə aktiv elan tapılmadı.</p><a href="/add_listing.php" class="inline-block mt-6 px-6 py-3 text-white font-bold rounded-xl transition-all shadow-lg active:scale-95 hover:opacity-90" style="background-color: #ff6b6b;">İlk elanı sən yerləşdir</a><?php else: ?><h3 class="text-2xl font-black text-slate-400">Elan tapılmadı</h3><p class="text-slate-400 font-medium">Bu axtarışa uyğun elan artıq aktiv deyil.</p><?php endif; ?></div>
    <?php else: ?>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
            <?php foreach ($normal_listings as $ad): ?>
                <div class="glass-panel p-4 rounded-3xl hover:shadow-2xl transition-all duration-500 group bg-white border border-slate-50 hover:-translate-y-1 cursor-pointer flex flex-col h-full" onclick="window.location.href='/listing.php?id=<?= $ad['id'] ?>'">
                    <div class="aspect-square rounded-2xl bg-cover bg-center mb-5 relative overflow-hidden shadow-inner flex-shrink-0" style="background-image: url('<?= htmlspecialchars($ad['main_image'] ?? '/assets/img/no-image.jpg') ?>');">
                        <div class="absolute bottom-2 right-2 bg-white/95 backdrop-blur-sm px-2.5 py-1 rounded-lg text-[11px] font-black text-slate-900 shadow-md border border-slate-100/50"><?= ($ad['price'] == (int)$ad['price']) ? number_format($ad['price'], 0, '.', ' ') : number_format($ad['price'], 2, '.', ' '); ?> <?= htmlspecialchars($ad['symbol']) ?><?= ($ad['listing_type'] ?? 'rent') === 'rent' ? ($rent_labels[$ad['rent_period']] ?? '/gün') : '' ?></div>
                        <div class="absolute top-3 left-3 flex flex-col gap-1">
                            <?php if (($ad['boost_type'] == 'vip' || $ad['boost_type'] == 'premium') && strtotime($ad['boost_end_time'] ?? '') > time()): ?> <div class="text-white text-[8px] font-black px-2 py-0.5 rounded-md uppercase tracking-widest shadow-lg" style="background-color: #ff6b6b;"><?= htmlspecialchars($ad['boost_type']) ?></div> <?php endif; ?>
                            <?php if ($ad['has_deposit']): ?> <div class="px-2 py-0.5 bg-slate-900/80 backdrop-blur-sm text-white text-[8px] sm:text-[9px] font-black uppercase rounded-md tracking-wider shadow-lg">Depozit</div> <?php endif; ?>
                        </div>
                    </div>
                    <h5 class="text-sm font-black text-slate-800 line-clamp-2 mb-2 group-hover:text-[#ff6b6b] transition-colors flex-grow"><?= htmlspecialchars($ad['title']) ?></h5>
                    <div class="flex items-center text-[10px] text-slate-400 font-bold tracking-tight uppercase flex-shrink-0 mt-auto"><span class="material-symbols-outlined text-[14px] mr-1" style="color: rgba(255, 107, 107, 0.5);">location_on</span> <?= htmlspecialchars($ad['city_name']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

</div>
<aside class="hidden xl:block w-48 shrink-0 sticky top-24 h-fit"><?php if(function_exists('render_ad')) render_ad($pdo, 'sidebar_right_9_16', 'w-full h-full'); ?></aside>
</div>

<script>
function toggleFavorite(listingId, btn) {
    const formData = new FormData(); formData.append('listing_id', listingId);
    fetch('/ajax/favorite.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
        if(data.success) {
            const icons = document.querySelectorAll(`button[onclick*="toggleFavorite(${listingId}"] span`);
            if(data.action === 'added') { icons.forEach(icon => { icon.classList.add('text-red-500'); icon.style.fontVariationSettings = "'FILL' 1"; }); }
            else { icons.forEach(icon => { icon.classList.remove('text-red-500'); icon.style.fontVariationSettings = "'FILL' 0"; }); }
        } else { if(data.message === 'Lütfən giriş edin.') { window.location.href = '/login.php'; } else { alert(data.message); } }
    }).catch(err => console.error(err));
}
</script>
<?php require_once 'includes/footer.php'; ?>
