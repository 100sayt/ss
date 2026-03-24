<?php
// add_listing.php
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/language.php';
require_once 'includes/image_compressor.php';
require_once 'includes/telegram_bot.php';

// Must be logged in
if (!is_logged_in()) {
    $_SESSION['redirect_after_login'] = '/add_listing.php';
    redirect('/login.php');
}

// Temporary Auto-Fix for Duplicate entry '0' for key 'listing_images.PRIMARY'
try {
    $pdo->exec("UPDATE IGNORE listing_images SET id = NULL WHERE id = 0");
    $pdo->exec("ALTER TABLE listing_images MODIFY id INT AUTO_INCREMENT");
} catch (Exception $e) {
    // Already fixed or other issue, ignore
}

$user_id = get_current_user_id();
$error = '';
$success = '';

// Check monthly category limits
function check_user_category_limit($pdo, $user_id, $category_id) {
    if (!$category_id) return ['allowed' => true];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM listings
                           WHERE user_id = ? AND category_id = ?
                           AND MONTH(CURRENT_DATE()) = MONTH(created_at)
                           AND YEAR(CURRENT_DATE()) = YEAR(created_at)");
    $stmt->execute([$user_id, $category_id]);
    $user_ads_this_month = $stmt->fetchColumn();

    $stmt2 = $pdo->prepare("SELECT max_free_ads, extra_ad_price FROM category_limits WHERE category_id = ?");
    $stmt2->execute([$category_id]);
    $limit_rule = $stmt2->fetch();

    if ($limit_rule && $user_ads_this_month >= $limit_rule['max_free_ads']) {
        return ['allowed' => false, 'price' => $limit_rule['extra_ad_price']];
    }
    return ['allowed' => true];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Birinci növbədə Təhlükəsizlik (CSRF) yoxlanılır
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $error = "Təhlükəsizlik xətası.";
    } else {
        // CSRF keçərlidir, məlumatları qəbul edirik
        $category_id = (int)($_POST['category_id'] ?? 0);
        $subcategory_id = isset($_POST['subcategory_id']) && $_POST['subcategory_id'] > 0 ? (int)$_POST['subcategory_id'] : null;

        $final_cat_id = $subcategory_id ? $subcategory_id : $category_id;

        if (!$final_cat_id) {
            $error = "Zəhmət olmasa kateqoriya seçin.";
        }

        $city_id = $_POST['city_id'];
        if ($city_id === 'other' && !empty($_POST['new_city'])) {
            $new_city = sanitize_input($_POST['new_city']);
            $stmt_new_city = $pdo->prepare("INSERT INTO cities (".lang_col('name').") VALUES (?)");
            $stmt_new_city->execute([$new_city]);
            $city_id = $pdo->lastInsertId();
        } else {
            $city_id = (int)$city_id;
        }

        $item_type_id = (int)($_POST['item_type_id'] ?? 0);

        $title = sanitize_input($_POST['title'] ?? '');
        $content = sanitize_input($_POST['content'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $currency_id = 1; // Always AZN

        $contact_name = sanitize_input($_POST['contact_name'] ?? '');
        $contact_phone = normalize_phone($_POST['contact_phone'] ?? '');

        $is_sale_possible = isset($_POST['is_sale_possible']) ? 1 : 0;
        $rent_period = sanitize_input($_POST['rent_period'] ?? 'day');

        // --- DEPOZİT MƏNTİQİ BURADA QƏBUL EDİLİR ---
        $has_deposit = isset($_POST['has_deposit']) ? 1 : 0;
        $deposit_amount = NULL;

        if ($has_deposit === 1) {
            $input_amount = filter_input(INPUT_POST, 'deposit_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            if (is_numeric($input_amount) && $input_amount >= 0) {
                $deposit_amount = (float)$input_amount;
            } else {
                $has_deposit = 0;
            }
        }
        // ------------------------------------------

        if (!isset($_FILES['images']) || empty($_FILES['images']['name'][0])) {
            $error = "Zəhmət olmasa ən azı 1 şəkil yükləyin.";
        }

        if (!$error) {
            $limit_check = check_user_category_limit($pdo, $user_id, $final_cat_id);

            if (!$limit_check['allowed']) {
                 $stmt_bal = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
                 $stmt_bal->execute([$user_id]);
                 $balance = $stmt_bal->fetchColumn();

                 if ($balance < $limit_check['price']) {
                     $error = "Bu kateqoriya üzrə aylıq pulsuz elan limitinizi aşmısınız. Yeni elan üçün {$limit_check['price']} AZN tələb olunur. Sizin balansınız: {$balance} AZN. Zəhmət olmasa <a href='/profile.php?tab=top_up'>balansınızı artırın</a>.";
                 } else {
                     $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$limit_check['price'], $user_id]);
                 }
            }
        }

        if (!$error) {
            if(mb_strlen($content) > 3000) {
                 $error = "Məzmun maksimum 3000 simvol olmalıdır.";
            } else {
                $seo_url = uniqid('elan-') . '-' . substr(preg_replace('/[^a-z0-9]/i', '-', strtolower($title)), 0, 50);

                $stmt_mod = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'auto_moderation'");
                $stmt_mod->execute();
                $auto_mod = $stmt_mod->fetchColumn();
                $status = ($auto_mod == '1') ? 'active' : 'pending';

                $listing_type = 'rent';

                try {
                    // BÜTÜN MƏLUMATLAR (DEPOZİT DƏ DAXİL) TƏK BİR SQL İLƏ BAZAYA YAZILIR
                    $stmt = $pdo->prepare("INSERT INTO listings
                        (user_id, category_id, listing_type, is_sale_possible, has_deposit, deposit_amount, city_id, item_type_id, title, content, price, currency_id, rent_period,
                         contact_name, contact_phone, status, seo_url, expires_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");

                    if ($stmt->execute([
                        $user_id, $final_cat_id, $listing_type, $is_sale_possible, $has_deposit, $deposit_amount, $city_id, $item_type_id, $title, $content, $price, $currency_id, $rent_period,
                        $contact_name, $contact_phone, $status, $seo_url
                    ])) {
                        $listing_id = $pdo->lastInsertId();
                        $main_image_idx = isset($_POST['main_image_index']) ? (int)$_POST['main_image_index'] : 0;

                        if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                            $files = $_FILES['images'];
                            $count = count($files['name']);
                            $count = min($count, 5);

                            for ($i = 0; $i < $count; $i++) {
                                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                                    $pseudo_file = [
                                        'name' => $files['name'][$i],
                                        'type' => $files['type'][$i],
                                        'tmp_name' => $files['tmp_name'][$i],
                                        'error' => $files['error'][$i],
                                        'size' => $files['size'][$i]
                                    ];

                                    $is_valid = validate_image_upload($pseudo_file);
                                    if ($is_valid === true) {
                                        $dest = 'uploads/listings/img_' . uniqid() . '_' . $i . '.jpg';
                                        $full_dest = __DIR__ . '/' . $dest;

                                        if(compress_image($pseudo_file, $full_dest, 75, 1200)) {
                                            $is_main = ($i === $main_image_idx) ? 1 : 0;
                                            $pdo->prepare("INSERT INTO listing_images (listing_id, image_path, is_main, display_order) VALUES (?, ?, ?, ?)")
                                                ->execute([$listing_id, '/' . $dest, $is_main, $i]);
                                        }
                                    }
                                }
                            }
                        }

                        $bot_msg = "<b>Yeni Elan Gözləyir!</b>\nID: {$listing_id}\nBaşlıq: {$title}\nQiymət: {$price}\n<a href='https://".$_SERVER['HTTP_HOST']."/admin/listings.php'>Admin Panelə Keç</a>";
                        @send_telegram_notification($pdo, $bot_msg);

                        $success = true;
                    } else {
                        $error = "Elan əlavə edilərkən xəta baş verdi.";
                    }
                } catch (PDOException $e) {
                    $error = "Verilənlər bazası xətası: " . $e->getMessage();
                }
            }
        }
    }
}

try {
    $stmt_cats = $pdo->query("SELECT id, ".lang_col('name')." as name, icon_path FROM categories WHERE parent_id = 0 ORDER BY ".lang_col('name'));
    $main_categories = $stmt_cats->fetchAll();
    $cities = $pdo->query("SELECT id, ".lang_col('name')." as name FROM cities ORDER BY ".lang_col('name'))->fetchAll();
    $item_types = $pdo->query("SELECT id, ".lang_col('name')." as name FROM item_types ORDER BY id")->fetchAll();

    $stmt_user = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user_info = $stmt_user->fetch();
} catch (PDOException $e) {
    die("Xəta: " . $e->getMessage());
}

require_once 'includes/header.php';
?>

<style>
    :root {
        --primary: #ff6b6b;
        --primary-hover: #ee5253;
    }
    .bg-primary { background-color: var(--primary) !important; }
    .text-primary { color: var(--primary) !important; }
    .border-primary { border-color: var(--primary) !important; }

    .cat-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
        padding: 12px;
    }
    @media (min-width: 640px) {
        .cat-grid {
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 16px;
        }
    }
    .cat-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        cursor: pointer;
    }
    .cat-icon-wrapper {
        width: 56px;
        height: 56px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 6px;
        color: #475569;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .cat-item:hover .cat-icon-wrapper {
        border-color: var(--primary);
        color: var(--primary);
    }
    .cat-label {
        font-size: 11px;
        font-weight: 600;
        color: #334155;
        line-height: 1.2;
    }

    .cat-list-item {
        display: flex;
        align-items: center;
        padding: 16px;
        background: white;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer;
    }
    .cat-list-item .cat-name {
        flex: 1;
        font-weight: 600;
        color: #1e293b;
    }

    .search-input-wrapper {
        position: relative;
        margin-bottom: 1rem;
        padding: 0 16px;
    }
    .search-input-wrapper input {
        width: 100%;
        height: 48px;
        border: none;
        background-color: #f1f5f9;
        border-radius: 12px;
        padding: 0 16px 0 44px;
        font-weight: 500;
    }
    .search-input-wrapper .material-symbols-outlined {
        position: absolute;
        left: 28px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
    }

    .step-container {
        max-width: 800px;
        margin: 0 auto;
    }

    .step-header {
        display: flex;
        align-items: center;
        padding: 12px;
        border-bottom: 1px solid #f1f5f9;
        background: white;
    }
    .step-header-title {
        flex: 1;
        text-align: center;
        font-weight: 700;
        font-size: 16px;
    }
    .back-btn {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .hidden { display: none !important; }
    .main-form-section { display: none; }

    /* Custom Dropdown Styles */
    .custom-dropdown-container { position: relative; }
    .custom-dropdown-list {
        position: absolute; top: 100%; left: 0; right: 0;
        background: white; border: 1px solid #e2e8f0; border-radius: 12px;
        margin-top: 4px; max-height: 300px; overflow-y: auto; z-index: 50;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); display: none;
    }
    .custom-dropdown-list.show { display: block; }
    .custom-dropdown-item {
        padding: 12px 16px; cursor: pointer; font-weight: 600; font-size: 14px; color: #1e293b;
    }
    .custom-dropdown-item:hover { background: #f8fafc; color: var(--primary); }

    @media (max-width: 640px) {
        #page_title_container { display: none !important; }
    }
	.cat-icon-wrapper {
    width: 64px !important;  /* Ölçünü özünə görə dəyişə bilərsən */
    height: 64px !important;
    border-radius: 9999px !important; /* Məcburi tam dairə */
    overflow: hidden !important;
    display: flex !important;
    align-items: center;
    justify-content: center;
    position: relative;
    -webkit-mask-image: -webkit-radial-gradient(white, black); /* Bəzi brauzerlərdə kənarların kvadrat qalmaması üçün */
}

.cat-icon-wrapper img {
    width: 100% !important;
    height: 100% !important;
    border-radius: 50% !important;
    object-fit: cover !important; /* Şəkli əzmədən dairəyə yayır */
}
</style>

<div id="loadingOverlay" style="display:none; position:fixed; inset:0; background:rgba(255,255,255,0.9); z-index:9999; flex-direction:column; align-items:center; justify-content:center;">
    <div class="loader-spinner mb-4 w-12 h-12 border-4 border-primary border-t-transparent rounded-full animate-spin"></div>
    <p class="text-slate-900 font-black text-xl">Elan yüklənir...</p>
</div>

<?php if($success): ?>
<div class="w-full max-w-lg mx-auto py-20 px-4 text-center">
    <div class="mx-auto flex items-center justify-center w-20 h-20 rounded-full bg-green-50 mb-6">
        <span class="material-symbols-outlined text-5xl text-green-500">check_circle</span>
    </div>
    <h2 class="text-2xl font-black text-slate-900 mb-2">Elanınız qəbul edildi!</h2>
    <p class="text-slate-500 mb-8 font-medium">Elan təsdiq olunduqdan sonra saytda yayımlanacaq.</p>
    <div class="flex flex-col sm:flex-row gap-4 justify-center">
        <a href="/index.php" class="px-8 py-3 rounded-xl bg-primary text-white font-bold">Ana səhifə</a>
        <a href="/profile.php" class="px-8 py-3 rounded-xl bg-slate-100 text-slate-900 font-bold">Profilim</a>
    </div>
</div>
<?php else: ?>
<!-- Form Screen Wrapper (NO MAIN TAG HERE) -->
<div class="w-full max-w-4xl mx-auto py-0 sm:py-8">
    <div id="page_title_container" class="mb-4 text-center px-4 sm:px-0">
        <h1 id="page_title" class="text-xl sm:text-3xl font-black text-slate-900 tracking-tight">Yeni elan</h1>
        <?php if($error): ?><p class="text-red-500 font-bold mt-2"><?= $error ?></p><?php endif; ?>
    </div>

    <!-- Step 1: Main Category -->
<div id="step_category" class="step-container">

    <!-- Back Button -->
    <div class="mb-4">
        <a href="index.php"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold transition-all duration-200 shadow-sm">
            <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            Ana səhifəyə qayıt
        </a>
    </div>

    <div class="search-input-wrapper">
        <span class="material-symbols-outlined">search</span>
        <input type="text"
               id="cat_search"
               placeholder="Kateqoriya axtar..."
               onkeyup="filterCategories()">
    </div>

    <div class="cat-grid" id="main_cats_list">
        <?php foreach($main_categories as $c): ?>
            <div class="cat-item group"
                 onclick="selectMainCategory(<?= (int)$c['id'] ?>, '<?= addslashes($c['name']) ?>')"
                 data-name="<?= strtolower(htmlspecialchars($c['name'])) ?>">

<div class="cat-icon-wrapper relative w-16 h-16 mx-auto flex items-center justify-center rounded-full overflow-hidden border border-slate-100 bg-slate-50 group-hover:border-primary/30 transition-all duration-300">
    <?php if(!empty($c['icon_path'])): ?>
        <?php if(strpos($c['icon_path'], '/') !== false): ?>
            <img src="<?= htmlspecialchars($c['icon_path']) ?>"
                 alt="<?= htmlspecialchars($c['name']) ?>"
                 class="absolute inset-0 w-full h-full object-cover rounded-full group-hover:scale-110 transition-transform duration-500">
        <?php else: ?>
            <span class="material-symbols-outlined cat-icon text-slate-500 group-hover:text-primary !text-[32px]">
                <?= htmlspecialchars($c['icon_path']) ?>
            </span>
        <?php endif; ?>
    <?php else: ?>
        <span class="material-symbols-outlined cat-icon text-slate-300 !text-[32px]">category</span>
    <?php endif; ?>
</div>

                <span class="cat-label">
                    <?= htmlspecialchars($c['name']) ?>
                </span>

            </div>
        <?php endforeach; ?>
    </div>

</div>

    <!-- Step 2: Sub Category -->
    <div id="step_subcategory" class="step-container hidden bg-white sm:rounded-2xl overflow-hidden border border-slate-100">
        <div class="step-header">
            <div class="back-btn" onclick="showStep('category')">
                <span class="material-symbols-outlined">arrow_back</span>
            </div>
            <h2 class="step-header-title" id="sub_category_title">Alt kateqoriya</h2>
        </div>
        <div id="subcategories_list" class="flex flex-col"></div>
        <div class="p-4">
            <button type="button" onclick="showStep('form')" class="w-full py-3 bg-slate-100 text-slate-600 font-bold rounded-xl text-xs uppercase tracking-widest">
                Bu kateqoriya üzrə davam et
            </button>
        </div>
    </div>

<div id="step_form" class="main-form-section">
    <form id="addListingForm" method="POST" enctype="multipart/form-data" class="space-y-0 sm:space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="category_id" id="form_category_id">
        <input type="hidden" name="subcategory_id" id="form_subcategory_id">

        <div class="bg-white sm:rounded-xl mb-0 sm:mb-4 border-b sm:border border-slate-100 p-4 relative flex items-center">

            <button type="button" onclick="goBack()" class="text-slate-500 hover:text-slate-900 font-bold text-[11px] sm:text-sm flex items-center gap-1 relative z-10">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                <span>Kateqoriyanı dəyiş</span>
            </button>

            <h2 class="sm:hidden text-lg font-black text-slate-900 absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-max text-center">Yeni elan</h2>

        </div>

        <div class="bg-white sm:rounded-2xl sm:border border-slate-100 p-4 sm:p-8">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

        <div class="relative group custom-dropdown-container sm:col-span-1" id="city_dropdown_container">
            <input type="hidden" name="city_id" id="city_id_hidden" required>
            <div id="city_dropdown_trigger" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 text-slate-900 font-semibold text-sm bg-white cursor-pointer flex items-center justify-between hover:border-border-primary transition-colors">
                <span id="city_selected_label" class="block truncate"></span>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 group-hover:text-slate-600 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Şəhər</label>

            <div class="custom-dropdown-list" id="city_dropdown_list">
                <?php foreach($cities as $c): ?>
                    <div class="custom-dropdown-item" data-value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></div>
                <?php endforeach; ?>
                <div class="custom-dropdown-item font-bold text-primary" data-value="other">+ Digər...</div>
            </div>
        </div>

        <div class="relative hidden sm:col-span-1" id="new_city_wrapper">
            <input type="text" id="new_city_input" name="new_city" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-semibold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" placeholder="Şəhər adı"/>
        </div>

        <div class="relative sm:col-span-1 w-full">
            <input type="number" step="0.01" name="price" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-bold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" placeholder="" required/>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Qiymət (AZN)</label>
        </div>

        <div class="relative group custom-dropdown-container sm:col-span-1" id="rent_period_dropdown_container">
    <input type="hidden" name="rent_period" id="rent_period_hidden" value="" required>

    <div id="rent_period_dropdown_trigger" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 text-slate-900 font-semibold text-sm cursor-pointer flex items-center justify-between bg-white hover:border-primary transition-colors">
        <span id="rent_period_selected_label" class="block truncate text-slate-400"></span>

        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 group-hover:text-slate-600 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </div>

    <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">İcarə müddəti</label>

    <div class="custom-dropdown-list" id="rent_period_dropdown_list">
        <div class="custom-dropdown-item" data-value="hourly">Saatlıq</div>
        <div class="custom-dropdown-item" data-value="day">Günlük</div>
        <div class="custom-dropdown-item" data-value="weekly">Həftəlik</div>
        <div class="custom-dropdown-item" data-value="month">Aylıq</div>
        <div class="custom-dropdown-item" data-value="year">İllik</div>
    </div>
</div>

        <div class="relative group custom-dropdown-container sm:col-span-1" id="item_type_dropdown_container">
            <input type="hidden" name="item_type_id" id="item_type_id_hidden" required>
            <div id="item_type_dropdown_trigger" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-semibold text-sm cursor-pointer flex items-center justify-between bg-white hover:border-primary transition-colors">
                <span id="item_type_selected_label" class="block truncate"></span>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 group-hover:text-slate-600 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Vəziyyəti</label>

            <div class="custom-dropdown-list" id="item_type_dropdown_list">
                <?php foreach($item_types as $c): ?>
                    <div class="custom-dropdown-item" data-value="<?= (int)$c['id'] ?>">
                        <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="relative sm:col-span-2">
            <input type="text" name="title" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-semibold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" placeholder="" required maxlength="100"/>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Elanın başlığı</label>
        </div>

        <div class="relative sm:col-span-2">
            <textarea name="content" class="w-full rounded-xl border border-slate-200 px-4 pt-7 pb-4 text-sm resize-none hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" rows="4" required maxlength="3000"></textarea>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Ətraflı məlumat</label>
            <p class="text-[10px] text-right mt-1 font-bold text-slate-400"><span id="char_count">0</span> / 3000</p>
        </div>

        <div class="sm:col-span-2 p-6 border-2 border-dashed border-slate-200 rounded-2xl bg-slate-50 text-center cursor-pointer hover:border-primary hover:bg-primary/5 transition-colors" onclick="document.getElementById('listing_images_premium').click()">
            <span class="material-symbols-outlined text-3xl text-primary">cloud_upload</span>
            <p class="font-bold text-slate-900 mt-2">Şəkilləri əlavə et (Maks 5)</p>
        </div>
        <input type="file" id="listing_images_premium" accept=".jpg,.jpeg,.png" multiple class="hidden">
        <div id="file_inputs_container" class="hidden"></div>
        <input type="hidden" name="main_image_index" id="main_image_index" value="0">
        <div id="image_preview_premium_container" class="grid grid-cols-2 sm:grid-cols-5 gap-4 sm:col-span-2"></div>

<div class="relative sm:col-span-2 space-y-3" x-data="{ depositChecked: false }">
    <label class="block cursor-pointer">
        <input type="checkbox" name="has_deposit" value="1" class="sr-only" x-model="depositChecked">
        <div :class="depositChecked ? 'border-primary bg-primary/5' : 'border-slate-200 bg-white'" class="flex items-center justify-between h-14 px-4 border rounded-xl transition-all duration-200 hover:bg-slate-50">
            <div class="flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" :class="depositChecked ? 'text-primary' : 'text-slate-400'" class="h-5 w-5 transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <span class="text-sm font-bold text-slate-700">Depozit tələb olunur</span>
            </div>
            <div :class="depositChecked ? 'bg-primary border-primary' : 'border-slate-300'" class="w-5 h-5 rounded border flex items-center justify-center transition-all">
                <span x-show="depositChecked" class="material-symbols-outlined text-white text-[14px]">check</span>
            </div>
        </div>
    </label>

    <div x-show="depositChecked"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 -translate-y-3"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-3"
         class="relative bg-slate-50 border border-slate-100 rounded-xl p-4">

        <label class="block text-sm font-semibold text-slate-700 mb-2">Depozit məbləği</label>
        <div class="relative">
            <input type="number"
                   name="deposit_amount"
                   step="0.01"
                   min="0"
                   placeholder="0.00"
                   class="w-full h-12 px-4 border border-slate-200 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all outline-none pr-16 text-slate-800 font-semibold bg-white placeholder-slate-400">
            <div class="absolute right-0 top-0 bottom-0 flex items-center pr-4 pointer-events-none">
                <span class="text-slate-500 font-bold text-sm bg-slate-100 px-2 py-1 rounded">AZN</span>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
            </svg>
            Nümunə: Qəpik yazmaq üçün nöqtədən istifadə edin (Məsələn: 0.50 və ya 15.00)
        </p>
    </div>
</div>

<div class="relative sm:col-span-2 mt-4" x-data="{ checked: false }">
    <label class="block cursor-pointer">
        <input type="checkbox" name="is_sale_possible" value="1" class="sr-only" x-model="checked">
        <div :class="checked ? 'border-primary bg-primary/5' : 'border-slate-200 bg-white'" class="flex items-center justify-between h-14 px-4 border rounded-xl transition-all duration-200 hover:bg-slate-50">
            <div class="flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" :class="checked ? 'text-primary' : 'text-slate-400'" class="h-5 w-5 transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <span class="text-sm font-bold text-slate-700">Satış mümkündür</span>
            </div>
            <div :class="checked ? 'bg-primary border-primary' : 'border-slate-300'" class="w-5 h-5 rounded border flex items-center justify-center transition-all">
                <span x-show="checked" class="material-symbols-outlined text-white text-[14px]">check</span>
            </div>
        </div>
    </label>
</div>

        <div class="relative sm:col-span-1">
            <input type="text" name="contact_name" value="<?= htmlspecialchars($user_info['full_name'] ?? '') ?>" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-bold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all"/>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Əlaqədar şəxs</label>
        </div>

        <div class="relative sm:col-span-1">
            <input type="text" name="contact_phone" value="<?= htmlspecialchars($user_info['phone'] ?? '') ?>" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-bold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all"/>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Mobil nömrə</label>
        </div>

        <div class="sm:col-span-2 pt-2">
            <button type="submit" class="w-full h-14 bg-primary hover:bg-primary/90 text-white font-black rounded-xl shadow-lg transition-colors">Elanı Yerləşdir</button>
        </div>

    </div>
</div>
<?php endif; ?>

<script>
let currentStep = 'category';
let hasSubcategories = false;

function showStep(step) {
    document.querySelectorAll('.step-container, .main-form-section').forEach(el => {
        el.classList.add('hidden');
        el.style.display = 'none';
    });

    const target = document.getElementById('step_' + step);
    if(target) {
        target.classList.remove('hidden');
        target.style.display = 'block';
    }
    currentStep = step;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goBack() {
    if(currentStep === 'form') {
        if(hasSubcategories) showStep('subcategory');
        else showStep('category');
    } else if(currentStep === 'subcategory') {
        showStep('category');
    }
}

function filterCategories() {
    const query = document.getElementById('cat_search').value.toLowerCase();
    document.querySelectorAll('#main_cats_list .cat-item').forEach(c => {
        if (c.dataset.name.includes(query)) c.classList.remove('hidden');
        else c.classList.add('hidden');
    });
}

function selectMainCategory(id, name) {
    document.getElementById('form_category_id').value = id;
    document.getElementById('form_subcategory_id').value = '';
    document.getElementById('sub_category_title').innerText = name;

    fetch('/ajax/get_subcategories.php?parent_id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                hasSubcategories = true;
                const list = document.getElementById('subcategories_list');
                list.innerHTML = '';
                data.data.forEach(sub => {
                    list.innerHTML += `
                        <div class="cat-list-item" onclick="selectSubCategory(${sub.id})">
                            <span class="cat-name">${sub.name}</span>
                            <span class="material-symbols-outlined">chevron_right</span>
                        </div>
                    `;
                });
                showStep('subcategory');
            } else {
                hasSubcategories = false;
                showStep('form');
            }
        });
}

function selectSubCategory(id) {
    document.getElementById('form_subcategory_id').value = id;
    showStep('form');
}

// Image Handling
const premiumImgInput = document.getElementById('listing_images_premium');
const premiumPreviewContainer = document.getElementById('image_preview_premium_container');
const mainImageIdxInput = document.getElementById('main_image_index');
const fileInputsContainer = document.getElementById('file_inputs_container');
let combinedFiles = [];

if (premiumImgInput) {
    premiumImgInput.addEventListener('change', function(e) {
        Array.from(e.target.files).forEach(file => {
            if (combinedFiles.length < 5) combinedFiles.push(file);
        });
        updateFormFiles();
        renderPreviews();
    });
}

function updateFormFiles() {
    fileInputsContainer.innerHTML = '';
    const dt = new DataTransfer();
    combinedFiles.forEach(file => dt.items.add(file));
    const realInput = document.createElement('input');
    realInput.type = 'file'; realInput.name = 'images[]';
    realInput.multiple = true; realInput.files = dt.files;
    fileInputsContainer.appendChild(realInput);
}

function renderPreviews() {
    premiumPreviewContainer.innerHTML = '';
    combinedFiles.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const div = document.createElement('div');
            div.className = `relative aspect-[4/3] rounded-xl overflow-hidden border-2 ${mainImageIdxInput.value == index ? 'border-primary' : 'border-slate-100'}`;
            div.innerHTML = `
                <img src="${e.target.result}" class="w-full h-full object-cover">
                <div class="absolute inset-0 cursor-pointer" onclick="setMain(${index})"></div>
                <button type="button" onclick="removeImage(${index})" class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 z-10"><span class="material-symbols-outlined text-xs">close</span></button>
            `;
            premiumPreviewContainer.appendChild(div);
        }
        reader.readAsDataURL(file);
    });
}
function setMain(idx) { mainImageIdxInput.value = idx; renderPreviews(); }
function removeImage(idx) {
    combinedFiles.splice(idx, 1);
    if(mainImageIdxInput.value >= combinedFiles.length) mainImageIdxInput.value = 0;
    updateFormFiles(); renderPreviews();
}

document.getElementById('addListingForm')?.addEventListener('submit', function(e) {
    if (combinedFiles.length < 1) { alert('Ən azı 1 şəkil əlavə edin.'); e.preventDefault(); return; }
    document.getElementById('loadingOverlay').style.display = 'flex';
});

document.querySelector('textarea[name="content"]')?.addEventListener('input', function() {
    document.getElementById('char_count').textContent = this.value.length;
});

function setupCustomDropdown(containerId, hiddenInputId, triggerId, labelId, listId, callback) {
    const trigger = document.getElementById(triggerId);
    const hiddenInput = document.getElementById(hiddenInputId);
    const label = document.getElementById(labelId);
    const list = document.getElementById(listId);
    if (!trigger || !list) return;
    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.custom-dropdown-list').forEach(l => { if (l !== list) l.classList.remove('show'); });
        list.classList.toggle('show');
    });
    list.querySelectorAll('.custom-dropdown-item').forEach(item => {
        item.addEventListener('click', (e) => {
            hiddenInput.value = item.dataset.value;
            label.innerText = item.innerText;
            list.classList.remove('show');
            if (callback) callback(item.dataset.value);
        });
    });
    document.addEventListener('click', () => list.classList.remove('show'));
}

setupCustomDropdown('city_dropdown_container', 'city_id_hidden', 'city_dropdown_trigger', 'city_selected_label', 'city_dropdown_list', (val) => {
    const wrap = document.getElementById('new_city_wrapper');
    wrap.classList.toggle('hidden', val !== 'other');
});
setupCustomDropdown('rent_period_dropdown_container', 'rent_period_hidden', 'rent_period_dropdown_trigger', 'rent_period_selected_label', 'rent_period_dropdown_list');
setupCustomDropdown('item_type_dropdown_container', 'item_type_id_hidden', 'item_type_dropdown_trigger', 'item_type_selected_label', 'item_type_dropdown_list');

showStep('category');
</script>

<?php require_once 'includes/footer.php'; ?>
