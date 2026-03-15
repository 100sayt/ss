<?php
// index.php
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/language.php';

// Temporary DB Fix for listings table
try {
    $check_ai = $pdo->query("SHOW COLUMNS FROM listings LIKE 'id'")->fetch();
    if ($check_ai && strpos($check_ai['Extra'], 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE listings MODIFY id INT AUTO_INCREMENT");
    }
} catch (Exception $e) {}

// Parameters for queries
$search_query = isset($_GET['q']) ? sanitize_input($_GET['q']) : '';
$city_filter = isset($_GET['city_id']) ? (int)$_GET['city_id'] : 0;
$cat_filter = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : null;
$has_deposit_filter = isset($_GET['has_deposit']) && $_GET['has_deposit'] !== '' ? (int)$_GET['has_deposit'] : null;
$sort = $_GET['sort'] ?? 'newest';

// Search Logic
$search_sql_part = "";
$search_params_list = [];
if (!empty($search_query)) {
    $q_lower = mb_strtolower($search_query, 'UTF-8');
    $search_sql_part = " AND (l.title LIKE ? OR l.title LIKE ?)";
    $search_params_list[] = "%$q_lower%";
    $search_params_list[] = "%$q_lower%";
}

$filter_sql_part = "";
$filter_params = [];
if ($min_price !== null) { $filter_sql_part .= " AND l.price >= ?"; $filter_params[] = $min_price; }
if ($max_price !== null) { $filter_sql_part .= " AND l.price <= ?"; $filter_params[] = $max_price; }
if ($has_deposit_filter !== null) { $filter_sql_part .= " AND l.has_deposit = ?"; $filter_params[] = $has_deposit_filter; }

$order_by = "l.created_at DESC";
if ($sort === 'price_asc') $order_by = "l.price ASC";
elseif ($sort === 'price_desc') $order_by = "l.price DESC";

// --- FETCH VIP/PREMIUM LISTINGS ---
$premium_sql = "
    SELECT l.*, c.".lang_col('name')." as category_name, cit.".lang_col('name')." as city_name, cur.symbol,
           u.user_type,
           (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_main DESC, id ASC LIMIT 1) as main_image,
           l.rent_period
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
$premium_sql .= $filter_sql_part . " ORDER BY RAND() LIMIT 8";
$stmt_premium = $pdo->prepare($premium_sql);
$stmt_premium->execute(array_merge($premium_params, $filter_params));
$premium_listings = $stmt_premium->fetchAll();

// --- FETCH NORMAL LISTINGS ---
$normal_sql = "
    SELECT l.*, c.".lang_col('name')." as category_name, cit.".lang_col('name')." as city_name, cur.symbol,
    u.user_type,
    (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_main DESC, id ASC LIMIT 1) as main_image,
    l.rent_period
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
$normal_sql .= $filter_sql_part . " ORDER BY $order_by LIMIT 40";
$stmt_normal = $pdo->prepare($normal_sql);
$stmt_normal->execute(array_merge($normal_params, $filter_params));
$normal_listings = $stmt_normal->fetchAll();

$rent_labels = ['hourly' => '/saat', 'day' => '/gün', 'weekly' => '/həftə', 'month' => '/ay', 'year' => '/il'];

require_once 'includes/header.php';
?>

<div class="w-full max-w-[1600px] mx-auto flex gap-4 mt-6">
    <aside class="hidden xl:block w-48 shrink-0 sticky top-24 h-fit">
        <?php if(function_exists('render_ad')) render_ad($pdo, 'sidebar_left_9_16', 'w-full h-full'); ?>
    </aside>

    <div class="flex-1 min-w-0">
        <!-- Hero Section remains here but without search form -->
        <section class="w-full px-4 sm:px-6 lg:px-8 pt-4 pb-6">
            <div class="relative rounded-[2.5rem] overflow-hidden flex flex-col items-center justify-center text-center py-20 px-6 sm:px-10 border border-slate-100 shadow-xl shadow-slate-200/50 min-h-[400px]">
                <?php
                $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'hero_bg' LIMIT 1");
                $stmt->execute();
                $db_hero_bg = $stmt->fetchColumn();
                $final_hero_bg = (!empty($db_hero_bg)) ? $db_hero_bg : 'https://unblast.com/wp-content/uploads/2021/01/Space-Background-Images.jpg';

                try {
                    $slides_stmt = $pdo->query("SELECT image_url FROM hero_slides ORDER BY display_order ASC, id ASC");
                    $hero_slides = $slides_stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) { $hero_slides = []; }
                if (empty($hero_slides)) { $hero_slides = [$final_hero_bg]; }
                ?>
                <div x-data="{
                    slides: <?= json_encode($hero_slides) ?>, current: 0,
                    init() { setInterval(function() { this.current = (this.current + 1) % this.slides.length }.bind(this), 6000); }
                }" class="absolute inset-0 z-0">
                    <template x-for="(slide, index) in slides" :key="index">
                        <div class="absolute inset-0 bg-cover bg-center transition-opacity duration-1000" :class="index === current ? 'opacity-100' : 'opacity-0'" :style="'background-image: url(\'' + slide + '\');'"></div>
                    </template>
                    <div class="absolute inset-0 bg-slate-900/40"></div>
                </div>

                <div class="relative z-30 flex flex-col items-center max-w-3xl w-full">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/20 border border-white/40 text-white text-[10px] font-bold uppercase tracking-widest mb-4 backdrop-blur-md">
                        <span class="w-2 h-2 rounded-full bg-primary animate-pulse"></span> Premium Kirayə Platforması
                    </div>
                    <h2 class="text-4xl md:text-5xl font-black text-white mb-4 leading-tight tracking-tight drop-shadow-2xl">İstədiyiniz hər şeyi <br>icarəyə götürün</h2>
                    <p class="text-white/90 text-sm md:text-lg mb-8 max-w-md mx-auto font-medium drop-shadow-md">Elektronikadan daşınmaz əmlaka qədər hər şey bir kliklə.</p>
                    <button @click="document.querySelector('input[name=q]')?.focus()" class="px-8 py-4 bg-white text-primary font-black rounded-2xl shadow-xl hover:scale-105 transition-all">Axtarışa başla</button>
                </div>
            </div>
        </section>

        <?php if (!empty($premium_listings)): ?>
        <section class="w-full px-4 sm:px-6 lg:px-8 py-10">
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-3 w-3 relative"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-primary"></span></span>
                    <h3 class="text-2xl font-black text-slate-900 tracking-tight">VIP elanlar</h3>
                </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-3 sm:gap-6">
                <?php foreach ($premium_listings as $ad): ?>
                    <div class="rounded-[2rem] overflow-hidden transition-all duration-500 group flex flex-col h-full bg-white border border-slate-100 hover:-translate-y-2 cursor-pointer shadow-sm hover:shadow-2xl" onclick="window.location.href='/listing.php?id=<?= (int)$ad['id'] ?>'">
                        <div class="relative h-40 sm:h-56 w-full overflow-hidden">
                            <div class="absolute top-3 left-3 z-10"><div class="px-3 py-1 text-white text-[10px] font-black uppercase rounded-lg tracking-wider shadow-lg bg-primary">VIP</div></div>
                            <button class="absolute top-3 right-3 z-10 w-9 h-9 flex items-center justify-center bg-white/80 backdrop-blur-md rounded-full text-slate-400 hover:text-red-500 transition-all active:scale-90" onclick="event.stopPropagation(); toggleFavorite(<?= (int)$ad['id'] ?>, this)"><span class="material-symbols-outlined text-[20px] <?= (function_exists('is_favorite') && is_favorite($pdo, $ad['id'])) ? 'text-red-500 fill-icon' : '' ?>">favorite</span></button>
                            <div class="w-full h-full bg-cover bg-center group-hover:scale-110 transition-transform duration-700" style="background-image: url('<?= htmlspecialchars($ad['main_image'] ?? '/assets/img/no-image.jpg') ?>');"></div>
                        </div>
                        <div class="p-4 sm:p-6 flex flex-col flex-grow">
                            <h4 class="text-base font-bold text-slate-900 mb-2 line-clamp-1 group-hover:text-primary transition-colors"><?= htmlspecialchars($ad['title']) ?></h4>
                            <div class="flex items-center text-slate-500 text-[11px] mb-4 font-medium"><span class="material-symbols-outlined text-[16px] mr-1 text-primary">location_on</span> <?= htmlspecialchars($ad['city_name']) ?></div>
                            <div class="mt-auto flex items-center justify-between border-t border-slate-50 pt-4">
                                <div><p class="text-slate-900 font-black text-lg sm:text-xl tracking-tight"><?php echo number_format($ad['price'], 0, '.', ' '); ?> <span class="text-[12px] font-bold text-slate-400 uppercase"><?= htmlspecialchars($ad['symbol']) ?><?= $rent_labels[$ad['rent_period']] ?? '' ?></span></p></div>
                                <span class="w-10 h-10 rounded-full bg-primary/10 text-primary flex items-center justify-center group-hover:bg-primary group-hover:text-white transition-all"><span class="material-symbols-outlined text-[20px]">arrow_forward</span></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="w-full px-4 sm:px-6 lg:px-8 py-12 mb-12">
            <div class="flex items-center justify-between mb-10"><h3 class="text-2xl font-black text-slate-900 flex items-center gap-3"><span class="w-2 h-8 bg-slate-200 rounded-full"></span> Son elanlar</h3></div>
            <?php if(empty($normal_listings)): ?>
                <div class="w-full py-24 text-center rounded-3xl border-2 border-dashed border-slate-100">
                    <span class="material-symbols-outlined text-7xl text-slate-200 mb-4">inbox</span>
                    <h3 class="text-2xl font-black text-slate-400">Elan tapılmadı</h3>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                    <?php foreach ($normal_listings as $ad): ?>
                        <div class="p-2 rounded-[2rem] hover:shadow-2xl transition-all duration-500 group bg-white border border-slate-50 hover:-translate-y-1 cursor-pointer flex flex-col h-full" onclick="window.location.href='/listing.php?id=<?= $ad['id'] ?>'">
                            <div class="aspect-square rounded-[1.8rem] bg-cover bg-center mb-4 relative overflow-hidden shadow-inner flex-shrink-0" style="background-image: url('<?= htmlspecialchars($ad['main_image'] ?? '/assets/img/no-image.jpg') ?>');">
                                <div class="absolute bottom-3 right-3 bg-white/95 backdrop-blur-sm px-3 py-1.5 rounded-xl text-[12px] font-black text-slate-900 shadow-xl border border-slate-100/50">
                                    <?php echo number_format($ad['price'], 0, '.', ' '); ?> <?= htmlspecialchars($ad['symbol']) ?><?= $rent_labels[$ad['rent_period']] ?? '' ?>
                                </div>
                            </div>
                            <div class="px-2 pb-2 flex-grow flex flex-col">
                                <h5 class="text-sm font-bold text-slate-800 line-clamp-2 mb-2 group-hover:text-primary transition-colors"><?= htmlspecialchars($ad['title']) ?></h5>
                                <div class="mt-auto flex items-center text-[10px] text-slate-400 font-bold uppercase tracking-tight"><span class="material-symbols-outlined text-[14px] mr-1 text-primary/40">location_on</span> <?= htmlspecialchars($ad['city_name']) ?></div>
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
    const formData = new FormData(); formData.append('listing_id', listingId);
    fetch('/ajax/favorite.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            const icons = document.querySelectorAll(`button[onclick*="toggleFavorite(${listingId}"] span`);
            if(data.action === 'added') { icons.forEach(icon => { icon.classList.add('text-red-500'); icon.style.fontVariationSettings = "'FILL' 1"; }); }
            else { icons.forEach(icon => { icon.classList.remove('text-red-500'); icon.style.fontVariationSettings = "'FILL' 0"; }); }
        } else { if(data.message === 'Lütfən giriş edin.') window.location.href = '/login.php'; else alert(data.message); }
    })
}
</script>

<?php require_once 'includes/footer.php'; ?>
