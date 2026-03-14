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

// Yeni filtrlər
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : null;
$has_deposit_filter = isset($_GET['has_deposit']) && $_GET['has_deposit'] !== '' ? (int)$_GET['has_deposit'] : null;
$sort = $_GET['sort'] ?? 'newest';

// --- AĞILLI AXTARIŞ MƏNTİQİ ---
$search_sql_part = "";
$search_params_list = [];

if (!empty($search_query)) {
    $q_lower = mb_strtolower($search_query, 'UTF-8');
    $replace_map = [
        'ə' => 'e', 'ç' => 'c', 'ş' => 's', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u', 'ı' => 'i',
        'e' => 'ə', 'c' => 'ç', 's' => 'ş', 'g' => 'ğ', 'o' => 'ö', 'u' => 'ü', 'i' => 'ı'
    ];
    $alt_query = strtr($q_lower, $replace_map);
    $vowels = ['a', 'e', 'ə', 'i', 'ı', 'o', 'ö', 'u', 'ü'];
    $fuzzy_query = str_replace($vowels, '_', $q_lower);
    $search_sql_part = " AND (l.title LIKE ? OR l.title LIKE ? OR l.title LIKE ?)";
    $search_params_list[] = "%$q_lower%";
    $search_params_list[] = "%$alt_query%";
    $search_params_list[] = "%$fuzzy_query%";
}

// Additional Filters SQL
$filter_sql_part = "";
$filter_params = [];
if ($min_price !== null) { $filter_sql_part .= " AND l.price >= ?"; $filter_params[] = $min_price; }
if ($max_price !== null) { $filter_sql_part .= " AND l.price <= ?"; $filter_params[] = $max_price; }
if ($has_deposit_filter !== null) { $filter_sql_part .= " AND l.has_deposit = ?"; $filter_params[] = $has_deposit_filter; }

// Sorting logic
$order_by = "l.created_at DESC";
if ($sort === 'price_asc') $order_by = "l.price ASC";
elseif ($sort === 'price_desc') $order_by = "l.price DESC";
elseif ($sort === 'oldest') $order_by = "l.created_at ASC";

// --- FETCH VIP/PREMIUM LISTINGS ---
$premium_sql = "
    SELECT l.*, c.".lang_col('name')." as category_name, cit.".lang_col('name')." as city_name, cur.symbol,
           u.user_type,
           (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_main DESC, id ASC LIMIT 1) as main_image,
           l.rent_period, l.is_sale_possible, l.has_deposit
    FROM listings l
    JOIN categories c ON l.category_id = c.id
    JOIN cities cit ON l.city_id = cit.id
    JOIN currencies cur ON l.currency_id = cur.id
    JOIN users u ON l.user_id = u.id
    WHERE l.status = 'active' AND l.boost_type IN ('premium', 'vip') AND l.boost_end_time > NOW()
";
$premium_params = array_merge([], $search_params_list);
if (!empty($search_sql_part)) $premium_sql .= $search_sql_part;
if ($city_filter) { $premium_sql .= " AND l.city_id = ?"; $premium_params[] = $city_filter; }
if ($cat_filter) { $premium_sql .= " AND l.category_id = ?"; $premium_params[] = $cat_filter; }
$premium_sql .= $filter_sql_part;
$premium_params = array_merge($premium_params, $filter_params);
$premium_sql .= " ORDER BY RAND() LIMIT 8";
$stmt_premium = $pdo->prepare($premium_sql);
$stmt_premium->execute($premium_params);
$premium_listings = $stmt_premium->fetchAll();

// --- FETCH NORMAL LISTINGS ---
$normal_sql = "
    SELECT l.*, c.".lang_col('name')." as category_name, cit.".lang_col('name')." as city_name, cur.symbol,
    u.user_type,
    (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_main DESC, id ASC LIMIT 1) as main_image,
    l.rent_period, l.is_sale_possible, l.has_deposit
    FROM listings l
    JOIN categories c ON l.category_id = c.id
    JOIN cities cit ON l.city_id = cit.id
    JOIN currencies cur ON l.currency_id = cur.id
    JOIN users u ON l.user_id = u.id
    WHERE l.status = 'active'
";
$normal_params = array_merge([], $search_params_list);
if (!empty($search_sql_part)) $normal_sql .= $search_sql_part;
if ($city_filter) { $normal_sql .= " AND l.city_id = ?"; $normal_params[] = $city_filter; }
if ($cat_filter) { $normal_sql .= " AND l.category_id = ?"; $normal_params[] = $cat_filter; }
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
<?php
$stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'hero_bg' LIMIT 1");
$stmt->execute();
$db_hero_bg = $stmt->fetchColumn();
$final_hero_bg = (!empty($db_hero_bg)) ? $db_hero_bg : 'https://unblast.com/wp-content/uploads/2021/01/Space-Background-Images.jpg';
?>

<aside class="hidden xl:block w-48 shrink-0 sticky top-24 h-fit">
    <?php if(function_exists('render_ad')) render_ad($pdo, 'sidebar_left_9_16', 'w-full h-full'); ?>
</aside>

<div class="flex-1 min-w-0">
    <!-- Hero Slider Restoration -->
    <section class="w-full px-4 sm:px-6 lg:px-8 pt-4 pb-6">
        <div class="relative rounded-[1.5rem] overflow-hidden flex flex-col items-center justify-center text-center py-12 px-6 sm:px-10 border border-slate-100 shadow-xl shadow-slate-200/50 min-h-[320px]">
            <?php
            try {
                $slides_stmt = $pdo->query("SELECT image_url FROM hero_slides ORDER BY display_order ASC, id ASC");
                $hero_slides = $slides_stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) { $hero_slides = []; }
            if (empty($hero_slides)) { $hero_slides = [$final_hero_bg]; }
            ?>
            <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('heroSlider', () => ({
                    slides: <?= json_encode($hero_slides) ?>, current: 0, timer: null,
                    init() { this.timer = setInterval(() => { this.next(); }, 5000); },
                    next() { this.current = (this.current + 1) % this.slides.length; },
                    goTo(i) { this.current = i; clearInterval(this.timer); this.timer = setInterval(() => { this.next(); }, 5000); }
                }));
            });
            </script>
            <div x-data="heroSlider" class="absolute inset-0 z-0 overflow-hidden">
                <template x-for="(slide, index) in slides" :key="index">
                    <div class="absolute inset-0 bg-cover bg-center transition-opacity duration-1000" :class="index === current ? 'opacity-100' : 'opacity-0'" :style="'background-image: url(\'' + slide + '\');'"></div>
                </template>
                <template x-if="slides.length > 1">
                    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 z-20 flex gap-2">
                        <template x-for="(slide, index) in slides" :key="'dot-'+index">
                            <button @click="goTo(index)" class="h-2 rounded-full transition-all duration-300" :class="index === current ? 'bg-white w-5' : 'bg-white/50 w-2'"></button>
                        </template>
                    </div>
                </template>
            </div>
            <div class="relative z-30 flex flex-col items-center max-w-3xl w-full">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/20 border border-white/40 text-white text-[10px] font-bold uppercase tracking-widest mb-4 backdrop-blur-md">
                    <span class="w-2 h-2 rounded-full bg-[#ff6b6b] animate-pulse"></span> Premium Kirayə Platforması
                </div>
                <h2 class="text-3xl md:text-4xl font-black text-white mb-3 leading-tight tracking-tight drop-shadow-lg">İstədiyiniz hər şeyi icarəyə götürün</h2>
                <p class="text-white/90 text-sm md:text-base mb-8 max-w-md mx-auto font-medium drop-shadow-md">Elektronikadan daşınmaz əmlaka qədər hər şey bir kliklə.</p>
            </div>
        </div>
    </section>

    <?php if (!empty($premium_listings)): ?>
    <section class="w-full px-4 sm:px-6 lg:px-8 py-10">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-3">
                <span class="flex h-2.5 w-2.5 relative">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" style="background-color: #ff6b6b;"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5" style="background-color: #ff6b6b;"></span>
                </span>
                <h3 class="text-xl font-black text-slate-900 tracking-tight">VIP elanlar</h3>
            </div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-3 sm:gap-5">
            <?php foreach ($premium_listings as $ad): ?>
                <div class="glass-panel rounded-[1.2rem] sm:rounded-[1.5rem] overflow-hidden glow-effect transition-all duration-500 group flex flex-col h-full bg-white border border-slate-100 hover:-translate-y-1.5 cursor-pointer shadow-sm hover:shadow-xl" onclick="window.location.href='/listing.php?id=<?= (int)$ad['id'] ?>'">
                    <div class="relative h-32 sm:h-48 w-full overflow-hidden">
                        <div class="absolute top-2 left-2 z-10 flex flex-col gap-1">
                            <div class="px-2 py-0.5 text-white text-[8px] sm:text-[9px] font-black uppercase rounded-md tracking-wider shadow-lg" style="background-color: <?= ($ad['user_type'] === 'company') ? '#4f46e5' : '#ff6b6b' ?>;">
                                <?= ($ad['user_type'] === 'company') ? 'Mağaza' : 'Premium' ?>
                            </div>
                        </div>
                        <button class="absolute top-1.5 right-1.5 z-10 w-7 h-7 flex items-center justify-center bg-transparent text-slate-400 hover:text-red-500 transition-all active:scale-90" onclick="event.stopPropagation(); toggleFavorite(<?= (int)$ad['id'] ?>, this)">
                            <span class="material-symbols-outlined text-[16px] drop-shadow-sm <?= (function_exists('is_favorite') && is_favorite($pdo, $ad['id'])) ? 'text-red-500 fill-icon' : '' ?>" style="<?= (function_exists('is_favorite') && is_favorite($pdo, $ad['id'])) ? "font-variation-settings: 'FILL' 1" : '' ?>">favorite</span>
                        </button>
                        <div class="w-full h-full bg-cover bg-center group-hover:scale-105 transition-transform duration-[1s]" style="background-image: url('<?= htmlspecialchars($ad['main_image'] ?? '/assets/img/no-image.jpg') ?>');"></div>
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-900/30 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    </div>
                    <div class="p-3 sm:p-5 flex flex-col flex-grow">
                        <h4 class="text-sm sm:text-base font-bold text-slate-900 mb-1.5 line-clamp-1 group-hover:text-[#ff6b6b] transition-colors"><?= htmlspecialchars($ad['title']) ?></h4>
                        <div class="flex items-center text-slate-500 text-[10px] sm:text-[11px] mb-3 font-medium">
                            <span class="material-symbols-outlined text-[14px] sm:text-[16px] mr-1" style="color: #ff6b6b;">location_on</span> <?= htmlspecialchars($ad['city_name']) ?> <span class="mx-1.5 text-slate-200">|</span> <?= htmlspecialchars($ad['category_name']) ?>
                        </div>
                        <div class="mt-auto flex items-center justify-between border-t border-slate-50 pt-3 sm:pt-4">
                            <div class="overflow-hidden">
                                <p class="text-[8px] sm:text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">QİYMƏT</p>
                                <p class="text-slate-900 font-black text-sm sm:text-xl tracking-tight truncate"><?php echo ($ad['price'] == (int)$ad['price']) ? number_format($ad['price'], 0, '.', ' ') : number_format($ad['price'], 2, '.', ' '); ?> <span class="text-[9px] sm:text-[11px] font-bold text-slate-400"><?= htmlspecialchars($ad['symbol']) ?><?= ($ad['listing_type'] ?? 'rent') === 'rent' ? ($rent_labels[$ad['rent_period']] ?? '/gün') : '' ?></span></p>
                            </div>
                            <button class="flex-shrink-0 p-1.5 sm:p-2.5 rounded-lg text-white transition-all shadow-md active:scale-95 hover:brightness-110" style="background-color: #ff6b6b;"><span class="material-symbols-outlined text-[16px] sm:text-[18px]">arrow_forward</span></button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="w-full px-4 sm:px-6 lg:px-8 py-4">
        <div class="relative w-full rounded-[1.5rem] overflow-hidden border border-slate-100 shadow-lg shadow-red-500/5">
            <div class="absolute inset-0 z-10" style="background: linear-gradient(to right, rgba(255, 107, 107, 0.95), rgba(238, 82, 83, 0.9));"></div>
            <div class="absolute inset-0 bg-cover bg-center opacity-30 z-0" style="background-image: url('https://unblast.com/wp-content/uploads/2021/01/Space-Background-Images.jpg');"></div>
            <div class="relative z-20 p-6 md:p-8 flex flex-col md:flex-row items-center justify-between gap-6 text-center md:text-left">
                <div class="max-w-lg">
                    <h3 class="text-xl md:text-2xl font-black text-white mb-2 tracking-tight">Təhlükəsiz və Sığortalı İcarə</h3>
                    <p class="text-white/90 text-[13px] md:text-sm font-medium leading-relaxed">RentAl vasitəsilə etdiyiniz bütün əməliyyatlar tam qorunur. Məhsullarınızı sığortalayın və rahatlıqla icarəyə verin.</p>
                </div>
                <button class="whitespace-nowrap px-6 py-3 bg-white text-[13px] font-black rounded-xl hover:bg-slate-50 transition-all shadow-md hover:shadow-lg active:scale-95" style="color: #ff6b6b;">Ətraflı Öyrən</button>
            </div>
        </div>
    </section>

    <section class="w-full px-4 sm:px-6 lg:px-8 py-12 mb-12">
        <div class="flex items-center justify-between mb-10">
            <h3 class="text-2xl font-black text-slate-900 flex items-center gap-3">
                <span class="w-2 h-8 bg-slate-200 rounded-full"></span> Son elanlar
            </h3>
        </div>
        <?php if(empty($normal_listings)): ?>
            <div class="w-full py-24 text-center glass-panel rounded-3xl border border-slate-100">
                <span class="material-symbols-outlined text-7xl text-slate-200 mb-4 <?= $cat_filter ? 'animate-pulse' : 'animate-bounce' ?>"><?= $cat_filter ? 'category' : 'inbox' ?></span>
                <?php if ($cat_filter): ?>
                    <h3 class="text-2xl font-black text-slate-800 mb-2">Bu kateqoriyada elan yoxdur</h3>
                    <p class="text-slate-500 font-medium text-sm md:text-base">Hal-hazırda <span class="font-bold" style="color: #ff6b6b;">"<?= htmlspecialchars($selected_category_name ?? 'Bu kateqoriya') ?>"</span> kateqoriyası üzrə aktiv elan tapılmadı.</p>
                    <a href="/add_listing.php" class="inline-block mt-6 px-6 py-3 text-white font-bold rounded-xl transition-all shadow-lg active:scale-95 hover:opacity-90" style="background-color: #ff6b6b;">İlk elanı sən yerləşdir</a>
                <?php else: ?>
                    <h3 class="text-2xl font-black text-slate-400">Elan tapılmadı</h3>
                    <p class="text-slate-400 font-medium">Bu axtarışa uyğun elan artıq aktiv deyil.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                <?php foreach ($normal_listings as $ad): ?>
                    <div class="glass-panel p-4 rounded-3xl hover:shadow-2xl transition-all duration-500 group bg-white border border-slate-50 hover:-translate-y-1 cursor-pointer flex flex-col h-full" onclick="window.location.href='/listing.php?id=<?= $ad['id'] ?>'">
                        <div class="aspect-square rounded-2xl bg-cover bg-center mb-5 relative overflow-hidden shadow-inner flex-shrink-0" style="background-image: url('<?= htmlspecialchars($ad['main_image'] ?? '/assets/img/no-image.jpg') ?>');">
                            <div class="absolute bottom-2 right-2 bg-white/95 backdrop-blur-sm px-2.5 py-1 rounded-lg text-[11px] font-black text-slate-900 shadow-md border border-slate-100/50">
                                <?php echo ($ad['price'] == (int)$ad['price']) ? number_format($ad['price'], 0, '.', ' ') : number_format($ad['price'], 2, '.', ' '); ?> <?= htmlspecialchars($ad['symbol']) ?><?= ($ad['listing_type'] ?? 'rent') === 'rent' ? ($rent_labels[$ad['rent_period']] ?? '/gün') : '' ?>
                            </div>
                            <div class="absolute top-3 left-3 flex flex-col gap-1">
                                <?php if (($ad['boost_type'] == 'vip' || $ad['boost_type'] == 'premium') && strtotime($ad['boost_end_time'] ?? '') > time()): ?>
                                    <div class="text-white text-[8px] font-black px-2 py-0.5 rounded-md uppercase tracking-widest shadow-lg" style="background-color: #ff6b6b;"><?= htmlspecialchars($ad['boost_type']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <h5 class="text-sm font-black text-slate-800 line-clamp-2 mb-2 group-hover:text-[#ff6b6b] transition-colors flex-grow"><?= htmlspecialchars($ad['title']) ?></h5>
                        <div class="flex items-center text-[10px] text-slate-400 font-bold tracking-tight uppercase flex-shrink-0 mt-auto">
                            <span class="material-symbols-outlined text-[14px] mr-1" style="color: rgba(255, 107, 107, 0.5);">location_on</span> <?= htmlspecialchars($ad['city_name']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<aside class="hidden xl:block w-48 shrink-0 sticky top-24 h-fit">
    <?php if(function_exists('render_ad')) render_ad($pdo, 'sidebar_right_9_16', 'w-full h-full'); ?>
</aside>
</div>

<script>
function toggleFavorite(listingId, btn) {
    const formData = new FormData();
    formData.append('listing_id', listingId);
    fetch('/ajax/favorite.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            const icons = document.querySelectorAll(`button[onclick*="toggleFavorite(${listingId}"] span`);
            if(data.action === 'added') {
                icons.forEach(icon => { icon.classList.add('text-red-500'); icon.style.fontVariationSettings = "'FILL' 1"; });
            } else {
                icons.forEach(icon => { icon.classList.remove('text-red-500'); icon.style.fontVariationSettings = "'FILL' 0"; });
            }
        } else { if(data.message === 'Lütfən giriş edin.') { window.location.href = '/login.php'; } else { alert(data.message); } }
    }).catch(err => console.error(err));
}
</script>

<?php require_once 'includes/footer.php'; ?>
