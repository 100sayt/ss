<?php
// edit_listing.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/language.php';
require_once 'includes/image_compressor.php';
require_once 'includes/telegram_bot.php';

// Must be logged in
if (!is_logged_in()) {
    redirect('/login.php');
}

$user_id = get_current_user_id();
$listing_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

// Fetch existing listing
$stmt = $pdo->prepare("SELECT * FROM listings WHERE id = ? AND user_id = ?");
$stmt->execute([$listing_id, $user_id]);
$ad = $stmt->fetch();

if (!$ad) {
    redirect('/profile.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $error = "Təhlükəsizlik xətası.";
    } else {
        $category_id = (int)($_POST['category_id'] ?? $ad['category_id']);
        $subcategory_id = isset($_POST['subcategory_id']) && $_POST['subcategory_id'] > 0 ? (int)$_POST['subcategory_id'] : null;

        $final_cat_id = $subcategory_id ? $subcategory_id : $category_id;

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

        // --- DEPOZİT MƏNTİQİ ---
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
        // ----------------------

        if (!$error) {
            if(mb_strlen($content) > 3000) {
                 $error = "Məzmun maksimum 3000 simvol olmalıdır.";
            } else {
                try {
                    $stmt_update = $pdo->prepare("UPDATE listings SET
                        category_id = ?, is_sale_possible = ?, has_deposit = ?, deposit_amount = ?, city_id = ?, item_type_id = ?, title = ?, content = ?, price = ?, currency_id = ?, rent_period = ?,
                        contact_name = ?, contact_phone = ?, status = 'pending'
                        WHERE id = ? AND user_id = ?");

                    if ($stmt_update->execute([
                        $final_cat_id, $is_sale_possible, $has_deposit, $deposit_amount, $city_id, $item_type_id, $title, $content, $price, $currency_id, $rent_period,
                        $contact_name, $contact_phone, $listing_id, $user_id
                    ])) {
                        // Handle image deletions
                        if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                            foreach ($_POST['delete_images'] as $img_id) {
                                $stmt_img = $pdo->prepare("SELECT image_path FROM listing_images WHERE id = ? AND listing_id = ?");
                                $stmt_img->execute([(int)$img_id, $listing_id]);
                                $img_path = $stmt_img->fetchColumn();
                                if ($img_path) {
                                    if (file_exists(__DIR__ . $img_path)) @unlink(__DIR__ . $img_path);
                                    $pdo->prepare("DELETE FROM listing_images WHERE id = ?")->execute([(int)$img_id]);
                                }
                            }
                        }

                        // Handle new image uploads
                        if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                            $files = $_FILES['images'];
                            $count = count($files['name']);

                            $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM listing_images WHERE listing_id = ?");
                            $stmt_count->execute([$listing_id]);
                            $current_count = $stmt_count->fetchColumn();
                            $allowed_new = 5 - $current_count;

                            if ($allowed_new > 0) {
                                for ($i = 0; $i < min($count, $allowed_new); $i++) {
                                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                                        $pseudo_file = [
                                            'name' => $files['name'][$i],
                                            'type' => $files['type'][$i],
                                            'tmp_name' => $files['tmp_name'][$i],
                                            'error' => $files['error'][$i],
                                            'size' => $files['size'][$i]
                                        ];

                                        if (validate_image_upload($pseudo_file) === true) {
                                            $dest = 'uploads/listings/img_edit_' . uniqid() . '_' . $i . '.jpg';
                                            if(compress_image($pseudo_file, __DIR__ . '/' . $dest, 75, 1200)) {
                                                $pdo->prepare("INSERT INTO listing_images (listing_id, image_path, is_main, display_order) VALUES (?, ?, 0, ?)")
                                                    ->execute([$listing_id, '/' . $dest, $current_count + $i]);
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        // Update main image
                        if (isset($_POST['set_main_image_id']) && !empty($_POST['set_main_image_id'])) {
                            $main_id = (int)$_POST['set_main_image_id'];
                            $pdo->prepare("UPDATE listing_images SET is_main = 0 WHERE listing_id = ?")->execute([$listing_id]);
                            $pdo->prepare("UPDATE listing_images SET is_main = 1 WHERE id = ? AND listing_id = ?")->execute([$main_id, $listing_id]);
                        } else {
                            $stmt_main_check = $pdo->prepare("SELECT id FROM listing_images WHERE listing_id = ? AND is_main = 1");
                            $stmt_main_check->execute([$listing_id]);
                            if (!$stmt_main_check->fetch()) {
                                $pdo->prepare("UPDATE listing_images SET is_main = 1 WHERE listing_id = ? LIMIT 1")->execute([$listing_id]);
                            }
                        }

                        $bot_msg = "<b>Elan Redaktə Olundu!</b>\nID: {$listing_id}\nBaşlıq: {$title}\nQiymət: {$price}\n<a href='https://".$_SERVER['HTTP_HOST']."/admin/listings.php'>Admin Panelə Keç</a>";
                        @send_telegram_notification($pdo, $bot_msg);

                        $success = true;
                    } else {
                        $error = "Elan yenilənərkən xəta baş verdi.";
                    }
                } catch (PDOException $e) {
                    $error = "Verilənlər bazası xətası: " . $e->getMessage();
                }
            }
        }
    }
}

try {
    $stmt_imgs = $pdo->prepare("SELECT * FROM listing_images WHERE listing_id = ? ORDER BY is_main DESC, display_order ASC");
    $stmt_imgs->execute([$listing_id]);
    $current_images = $stmt_imgs->fetchAll();

    $stmt_cats = $pdo->query("SELECT id, ".lang_col('name')." as name, icon_path FROM categories WHERE parent_id = 0 ORDER BY ".lang_col('name'));
    $main_categories = $stmt_cats->fetchAll();
    $cities = $pdo->query("SELECT id, ".lang_col('name')." as name FROM cities ORDER BY ".lang_col('name'))->fetchAll();
    $item_types = $pdo->query("SELECT id, ".lang_col('name')." as name FROM item_types ORDER BY id")->fetchAll();

    $current_parent_cat = null;
    $current_sub_cat = null;
    $stmt_cur_cat = $pdo->prepare("SELECT id, parent_id, ".lang_col('name')." as name FROM categories WHERE id = ?");
    $stmt_cur_cat->execute([$ad['category_id']]);
    $cat_data = $stmt_cur_cat->fetch();

    if ($cat_data && $cat_data['parent_id'] != 0) {
        $current_sub_cat = $cat_data;
        $stmt_p = $pdo->prepare("SELECT id, ".lang_col('name')." as name FROM categories WHERE id = ?");
        $stmt_p->execute([$cat_data['parent_id']]);
        $current_parent_cat = $stmt_p->fetch();
    } else {
        $current_parent_cat = $cat_data;
    }
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
    <p class="text-slate-900 font-black text-xl">Məlumatlar yenilənir...</p>
</div>

<?php if($success): ?>
<div class="w-full max-w-lg mx-auto py-20 px-4 text-center">
    <div class="mx-auto flex items-center justify-center w-20 h-20 rounded-full bg-green-50 mb-6">
        <span class="material-symbols-outlined text-5xl text-green-500">check_circle</span>
    </div>
    <h2 class="text-2xl font-black text-slate-900 mb-2">Elanınız yeniləndi!</h2>
    <p class="text-slate-500 mb-8 font-medium">Elan təsdiq olunduqdan sonra saytda yenidən yayımlanacaq.</p>
    <div class="flex flex-col sm:flex-row gap-4 justify-center">
        <a href="/index.php" class="px-8 py-3 rounded-xl bg-primary text-white font-bold">Ana səhifə</a>
        <a href="/profile.php" class="px-8 py-3 rounded-xl bg-slate-100 text-slate-900 font-bold">Profilim</a>
    </div>
</div>
<?php else: ?>
<div class="w-full max-w-4xl mx-auto py-0 sm:py-8">
    <div id="page_title_container" class="mb-4 text-center px-4 sm:px-0">
        <h1 id="page_title" class="text-xl sm:text-3xl font-black text-slate-900 tracking-tight">Elanı redaktə et</h1>
        <?php if($error): ?><p class="text-red-500 font-bold mt-2"><?= $error ?></p><?php endif; ?>
    </div>

    <!-- Step 1: Main Category -->
<div id="step_category" class="step-container hidden">

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
    <form id="editListingForm" method="POST" enctype="multipart/form-data" class="space-y-0 sm:space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="category_id" id="form_category_id" value="<?= $ad['category_id'] ?>">
        <input type="hidden" name="subcategory_id" id="form_subcategory_id" value="<?= ($current_sub_cat ? $current_sub_cat['id'] : '') ?>">

        <div class="bg-white sm:rounded-xl mb-0 sm:mb-4 border-b sm:border border-slate-100 p-4 relative flex items-center">

            <button type="button" onclick="goBack()" class="text-slate-500 hover:text-slate-900 font-bold text-[11px] sm:text-sm flex items-center gap-1 relative z-10">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                <span>Kateqoriyanı dəyiş</span>
            </button>

            <h2 class="sm:hidden text-lg font-black text-slate-900 absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-max text-center">Elanı redaktə et</h2>

            <div class="ml-auto hidden sm:block text-[11px] font-bold text-slate-400 uppercase tracking-widest">
                <?= ($current_parent_cat ? htmlspecialchars($current_parent_cat['name']) : '') . ($current_sub_cat ? ' / ' . htmlspecialchars($current_sub_cat['name']) : '') ?>
            </div>
        </div>

        <div class="bg-white sm:rounded-2xl sm:border border-slate-100 p-4 sm:p-8">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

        <div class="relative group custom-dropdown-container sm:col-span-1" id="city_dropdown_container">
            <input type="hidden" name="city_id" id="city_id_hidden" value="<?= $ad['city_id'] ?>" required>
            <div id="city_dropdown_trigger" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 text-slate-900 font-semibold text-sm bg-white cursor-pointer flex items-center justify-between hover:border-primary transition-colors">
                <span id="city_selected_label" class="block truncate">
                    <?php
                    $city_name = '';
                    foreach($cities as $c) if($c['id'] == $ad['city_id']) $city_name = $c['name'];
                    echo htmlspecialchars($city_name);
                    ?>
                </span>
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
            <input type="number" step="0.01" name="price" value="<?= $ad['price'] ?>" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-bold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" placeholder="" required/>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Qiymət (AZN)</label>
        </div>

        <div class="relative group custom-dropdown-container sm:col-span-1" id="rent_period_dropdown_container">
            <input type="hidden" name="rent_period" id="rent_period_hidden" value="<?= $ad['rent_period'] ?>" required>
            <div id="rent_period_dropdown_trigger" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 text-slate-900 font-semibold text-sm cursor-pointer flex items-center justify-between bg-white hover:border-primary transition-colors">
                <span id="rent_period_selected_label" class="block truncate">
                    <?php
                    $periods = ['hourly'=>'Saatlıq', 'day'=>'Günlük', 'weekly'=>'Həftəlik', 'month'=>'Aylıq', 'year'=>'İllik'];
                    echo $periods[$ad['rent_period']] ?? 'Günlük';
                    ?>
                </span>
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
            <input type="hidden" name="item_type_id" id="item_type_id_hidden" value="<?= $ad['item_type_id'] ?>" required>
            <div id="item_type_dropdown_trigger" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-semibold text-sm cursor-pointer flex items-center justify-between bg-white hover:border-primary transition-colors">
                <span id="item_type_selected_label" class="block truncate">
                    <?php
                    $it_name = '';
                    foreach($item_types as $t) if($t['id'] == $ad['item_type_id']) $it_name = $t['name'];
                    echo htmlspecialchars($it_name);
                    ?>
                </span>
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
            <input type="text" name="title" value="<?= htmlspecialchars($ad['title']) ?>" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-semibold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" placeholder="" required maxlength="100"/>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Elanın başlığı</label>
        </div>

        <div class="relative sm:col-span-2">
            <textarea name="content" class="w-full rounded-xl border border-slate-200 px-4 pt-7 pb-4 text-sm resize-none hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" rows="4" required maxlength="3000"><?= htmlspecialchars($ad['content']) ?></textarea>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Ətraflı məlumat</label>
            <p class="text-[10px] text-right mt-1 font-bold text-slate-400"><span id="char_count">0</span> / 3000</p>
        </div>

        <!-- Images Section -->
        <div class="sm:col-span-2 space-y-4">
            <div class="p-6 border-2 border-dashed border-slate-200 rounded-2xl bg-slate-50 text-center cursor-pointer hover:border-primary hover:bg-primary/5 transition-colors" onclick="document.getElementById('listing_images_premium').click()">
                <span class="material-symbols-outlined text-3xl text-primary">cloud_upload</span>
                <p class="font-bold text-slate-900 mt-2">Şəkilləri əlavə et (Maks 5)</p>
            </div>
            <input type="file" id="listing_images_premium" accept=".jpg,.jpeg,.png" multiple class="hidden">
            <div id="file_inputs_container" class="hidden"></div>
            <input type="hidden" name="set_main_image_id" id="set_main_image_id" value="">

            <div id="image_preview_premium_container" class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                <?php foreach($current_images as $img): ?>
                    <div class="relative aspect-[4/3] rounded-xl overflow-hidden border-2 <?= $img['is_main'] ? 'border-primary' : 'border-slate-100' ?>" id="img_wrap_<?= $img['id'] ?>">
                        <img src="<?= htmlspecialchars($img['image_path']) ?>" class="w-full h-full object-cover">
                        <div class="absolute inset-0 cursor-pointer" onclick="setExistingMain(<?= $img['id'] ?>)"></div>
                        <button type="button" onclick="markDelete(<?= $img['id'] ?>)" class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 shadow-lg hover:scale-110 transition-transform z-10">
                            <span class="material-symbols-outlined text-xs">close</span>
                        </button>
                        <input type="checkbox" name="delete_images[]" id="del_input_<?= $img['id'] ?>" value="<?= $img['id'] ?>" class="hidden">
                        <?php if($img['is_main']): ?>
                            <div class="absolute bottom-1 left-1/2 -translate-x-1/2 bg-primary text-white text-[8px] font-black px-2 py-0.5 rounded-full uppercase tracking-widest shadow-lg">ƏSAS</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="new_images_preview_container" class="grid grid-cols-2 sm:grid-cols-5 gap-4"></div>
        </div>

<div class="relative sm:col-span-2 space-y-3" x-data="{ depositChecked: <?= $ad['has_deposit'] ? 'true' : 'false' ?> }">
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
                   value="<?= $ad['deposit_amount'] ?>"
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

        <div class="relative sm:col-span-2 mt-4" x-data="{ checked: <?= $ad['is_sale_possible'] ? 'true' : 'false' ?> }">
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
            <input type="text" name="contact_name" value="<?= htmlspecialchars($ad['contact_name']) ?>" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-bold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all"/>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Əlaqədar şəxs</label>
        </div>

        <div class="relative sm:col-span-1">
            <input type="text" name="contact_phone" value="<?= htmlspecialchars($ad['contact_phone']) ?>" class="w-full h-14 rounded-xl border border-slate-200 px-4 pt-6 pb-2 font-bold text-sm hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all"/>
            <label class="absolute left-4 top-2 text-[11px] font-bold text-slate-400 pointer-events-none">Telefon</label>
        </div>

        <div class="sm:col-span-2 pt-2 flex flex-col sm:flex-row gap-4">
            <a href="/profile.php" class="flex-1 h-14 bg-slate-100 hover:bg-slate-200 text-slate-900 font-black rounded-xl flex items-center justify-center transition-colors">Ləğv et</a>
            <button type="submit" class="flex-[2] h-14 bg-primary hover:bg-primary/90 text-white font-black rounded-xl shadow-lg transition-colors">Yadda Saxla</button>
        </div>

    </div>
</div>
<?php endif; ?>

<script>
let currentStep = 'form';
let hasSubcategories = <?= ($current_sub_cat ? 'true' : 'false') ?>;

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
    } else if(currentStep === 'category') {
        showStep('form');
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
const newPreviewContainer = document.getElementById('new_images_preview_container');
const fileInputsContainer = document.getElementById('file_inputs_container');
let combinedFiles = [];

if (premiumImgInput) {
    premiumImgInput.addEventListener('change', function(e) {
        const currentCount = document.querySelectorAll('#image_preview_premium_container > div:not(.opacity-30)').length;
        Array.from(e.target.files).forEach(file => {
            if (combinedFiles.length + currentCount < 5) combinedFiles.push(file);
        });
        updateFormFiles();
        renderNewPreviews();
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

function renderNewPreviews() {
    newPreviewContainer.innerHTML = '';
    combinedFiles.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const div = document.createElement('div');
            div.className = `relative aspect-[4/3] rounded-xl overflow-hidden border-2 border-slate-100`;
            div.innerHTML = `
                <img src="${e.target.result}" class="w-full h-full object-cover">
                <button type="button" onclick="removeNewImage(${index})" class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 z-10"><span class="material-symbols-outlined text-xs">close</span></button>
            `;
            newPreviewContainer.appendChild(div);
        }
        reader.readAsDataURL(file);
    });
}

function removeNewImage(idx) {
    combinedFiles.splice(idx, 1);
    updateFormFiles(); renderNewPreviews();
}

function markDelete(id) {
    const wrap = document.getElementById('img_wrap_' + id);
    const input = document.getElementById('del_input_' + id);
    input.checked = !input.checked;
    wrap.classList.toggle('opacity-30', input.checked);
    wrap.classList.toggle('grayscale', input.checked);
    wrap.querySelector('button').innerHTML = input.checked ? '<span class="material-symbols-outlined text-xs">undo</span>' : '<span class="material-symbols-outlined text-xs">close</span>';
}

function setExistingMain(id) {
    document.getElementById('set_main_image_id').value = id;
    document.querySelectorAll('#image_preview_premium_container > div').forEach(el => {
        el.classList.remove('border-primary');
        el.classList.add('border-slate-100');
        const badge = el.querySelector('.absolute.bottom-1');
        if(badge) badge.remove();
    });
    const selected = document.getElementById('img_wrap_' + id);
    selected.classList.add('border-primary');
    selected.classList.remove('border-slate-100');
    const badge = document.createElement('div');
    badge.className = 'absolute bottom-1 left-1/2 -translate-x-1/2 bg-primary text-white text-[8px] font-black px-2 py-0.5 rounded-full uppercase tracking-widest shadow-lg';
    badge.innerText = 'ƏSAS';
    selected.appendChild(badge);
}

document.getElementById('editListingForm')?.addEventListener('submit', function(e) {
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

document.getElementById('char_count').textContent = document.querySelector('textarea[name="content"]').value.length;

showStep('form');
</script>

<?php require_once 'includes/footer.php'; ?>
