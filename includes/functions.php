<?php
// includes/functions.php
session_start();

// Basic security headers
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");

// ==========================================
// SECURITY & CSRF
// ==========================================
function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token)
{
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        die("Təhlükəsizlik xətası (İcazəsiz əməliyyat). Formu yenidən yükləyib təkrar cəhd edin.");
    }
    return true;
}

function csrf_field()
{
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

// ==========================================
// INPUT SANITIZATION
// ==========================================
function sanitize_input($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Normalize phone numbers (e.g. 0701234567, 701234567 to +994701234567)
function normalize_phone($phone)
{
    $phone = preg_replace('/[^0-9\+]/', '', $phone); // Remove spaces, dashes

    // If starts with 0 (e.g. 070, 050)
    if (strpos($phone, '0') === 0 && strlen($phone) == 10) {
        return '+994' . substr($phone, 1);
    }
    // If starts without 0 but 9 digits (e.g. 701234567)
    if (strlen($phone) == 9 && (strpos($phone, '7') === 0 || strpos($phone, '5') === 0 || strpos($phone, '9') === 0)) {
        return '+994' . $phone;
    }
    // If it's 99470...
    if (strpos($phone, '994') === 0 && strlen($phone) == 12) {
        return '+' . $phone;
    }
    // If already correct
    if (strpos($phone, '+994') === 0 && strlen($phone) == 13) {
        return $phone;
    }

    return $phone; // Return as is if format unknown (let validation handle it)
}

// ==========================================
// RATE LIMITING (Login)
// ==========================================
function check_login_rate_limit($pdo, $ip_address)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempt_time > (NOW() - INTERVAL 15 MINUTE)");
    $stmt->execute([$ip_address]);
    $attempts = $stmt->fetchColumn();

    if ($attempts >= 5) {
        return false; // Rate limited
    }
    return true; // OK
}

function record_login_attempt($pdo, $ip_address, $username)
{
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, username_attempt) VALUES (?, ?)");
    $stmt->execute([$ip_address, $username]);
}

function clear_login_attempts($pdo, $ip_address)
{
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip_address]);
}

// ==========================================
// UTILITY
// ==========================================
function redirect($url)
{
    header("Location: " . $url);
    die();
}

function get_current_user_id()
{
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

function is_admin()
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// --- WHATSAPP OTP GÖNDƏRMƏ FUNKSİYASI ---
function send_whatsapp_otp($phone, $otp) {
    // Əgər nömrə 9 rəqəmlidirsə (və ya 0 ilə başlayırsa), 994 əlavə et
    $clean_phone = preg_replace('/\D/', '', $phone);
    if (strlen($clean_phone) == 9) {
        $clean_phone = '994' . $clean_phone;
    } elseif (str_starts_with($clean_phone, '0') && strlen($clean_phone) == 10) {
        $clean_phone = '994' . substr($clean_phone, 1);
    }

    // API Məlumatları
    $api_url = 'http://100huseyn.duckdns.org/send-otp';
    $api_key = 'Huseyn100sayt';

    $message = "🔐 *Elan Qeydiyyat*\n\nSizin təsdiq kodunuz: *{$otp}*\nKodu heç kimlə paylaşmayın!";

    $ch = curl_init($api_url);
    $payload = json_encode([
        'phone' => $clean_phone,
        'message' => $message,
        'api_key' => $api_key
    ]);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout artırıldı
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("OTP Error: URL: $api_url, Code: $http_code, Error: $curl_error, Result: $result");
    }

    return $http_code === 200;
}

// ==========================================
// AUTHENTICATION HELPERS (Remember Me)
// ==========================================
function generate_remember_token($pdo, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+5 months'));
    $stmt = $pdo->prepare("INSERT INTO user_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $token, $expires]);

    setcookie('remember_me', $token, time() + (86400 * 30 * 5), "/", "", false, true); // 5 months
}

function check_remember_me($pdo) {
    if (isset($_SESSION['user_id'])) return true;
    if (isset($_COOKIE['remember_me'])) {
        $token = $_COOKIE['remember_me'];
        $stmt = $pdo->prepare("
            SELECT u.* FROM users u
            JOIN user_tokens ut ON u.id = ut.user_id
            WHERE ut.token = ? AND ut.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['full_name'];
            return true;
        }
    }
    return false;
}

/**
 * Render an advertisement or its placeholder
 * @param PDO $pdo Database connection
 * @param string $position Slot ID (header_16_9, sidebar_left_9_16, etc)
 * @param string $classes Optional CSS classes for the container
 */
function render_ad($pdo, $position, $classes = '') {
    // 1. Fetch Active Ad
    $stmt = $pdo->prepare("SELECT * FROM ads WHERE position = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$position]);
    $ad = $stmt->fetch();

    // 2. Fetch Global Settings
    $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('ad_contact_phone', 'ad_placeholder_system')");
    $settings = [];
    while($s = $stmt_set->fetch()) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }

    $placeholder_enabled = ($settings['ad_placeholder_system'] ?? '1') === '1';
    $contact_phone = $settings['ad_contact_phone'] ?? '+994 50 123 45 67';

    // Determine dimensions/ratio based on position
    $ratio = 'aspect-video';
    if(strpos($position, 'sidebar') !== false) {
        $ratio = 'aspect-[9/16]';
    }

    if ($ad) {
        // Render Active Ad
        echo '<div class="ad-container overflow-hidden rounded-2xl shadow-sm ' . $classes . '">';
        echo '<a href="' . htmlspecialchars($ad['target_url'] ?: '#') . '" target="_blank" rel="nofollow" class="block w-full h-full">';
        if ($ad['media_type'] === 'mp4') {
            echo '<video class="w-full h-full object-cover" autoplay muted loop playsinline><source src="' . htmlspecialchars($ad['media_path']) . '" type="video/mp4"></video>';
        } else {
            echo '<img src="' . htmlspecialchars($ad['media_path']) . '" alt="Reklam" class="w-full h-full object-cover">';
        }
        echo '</a>';
        echo '</div>';
    } elseif ($placeholder_enabled) {
        // Render Placeholder
        $label = function_exists('t') ? t('your_ad_here') : 'Sizin Reklamınız Burada';
        echo '<div class="ad-placeholder-container ' . $classes . '">';
        echo '<a href="tel:' . htmlspecialchars($contact_phone) . '" class="block w-full h-full">';
        echo '<div class="w-full h-full ' . $ratio . ' rounded-2xl border-2 border-dashed border-slate-300 flex flex-col items-center justify-center bg-slate-50 hover:bg-slate-100 transition-colors cursor-pointer group relative overflow-hidden">';
        echo '<div class="absolute inset-0 opacity-5" style="background-image: radial-gradient(#000 1px, transparent 1px); background-size: 20px 20px;"></div>';
        echo '<span class="material-symbols-outlined text-4xl text-slate-300 group-hover:text-primary mb-2 transition-colors">campaign</span>';
        echo '<p class="text-slate-500 font-bold text-sm px-4 text-center">' . htmlspecialchars($label) . '</p>';
        echo '<p class="text-slate-400 text-[10px] mt-1">' . htmlspecialchars($contact_phone) . '</p>';
        echo '</div>';
        echo '</a>';
        echo '</div>';
    }
}

/**
 * Automatically deactivate expired boosts
 */
function check_expired_boosts($pdo) {
    // Only run this occasionally or check a flag to avoid excessive DB load if needed,
    // but for this scale, a simple update is fine.
    $pdo->prepare("UPDATE listings SET boost_type = 'none' WHERE boost_type != 'none' AND boost_end_time < NOW()")->execute();
}

/**
 * Ensure database tables exist (Self-healing)
 */
function ensure_database_schema($pdo) {
    // Ensure the connection itself is utf8mb4
    $pdo->exec("SET NAMES utf8mb4");

    // 1. Transactions Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        type ENUM('topup', 'spent') NOT NULL,
        description VARCHAR(255) NOT NULL,
        status ENUM('pending', 'success', 'failed') DEFAULT 'success',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Fix existing transactions table encoding if necessary
    $pdo->exec("ALTER TABLE transactions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2. Boost Packages Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS boost_packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        description TEXT,
        price DECIMAL(10, 2) NOT NULL,
        duration_days INT NOT NULL,
        type ENUM('premium', 'vip', 'bump') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Fix existing boost_packages table encoding if necessary
    $pdo->exec("ALTER TABLE boost_packages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 3. Ensure listings table has necessary columns
    $check_reason = $pdo->query("SHOW COLUMNS FROM listings LIKE 'rejection_reason'")->fetch();
    if (!$check_reason) {
        $pdo->exec("ALTER TABLE listings ADD COLUMN rejection_reason TEXT AFTER status");
    }

    $check_type = $pdo->query("SHOW COLUMNS FROM listings LIKE 'listing_type'")->fetch();
    if (!$check_type) {
        $pdo->exec("ALTER TABLE listings ADD COLUMN listing_type ENUM('rent', 'sale') DEFAULT 'rent' AFTER category_id");
    }

    $check_rent = $pdo->query("SHOW COLUMNS FROM listings LIKE 'rent_period'")->fetch();
    if (!$check_rent) {
        $pdo->exec("ALTER TABLE listings ADD COLUMN rent_period VARCHAR(50) DEFAULT 'day' AFTER currency_id");
    }

    // 4. Ensure favorites table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        listing_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY user_listing (user_id, listing_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 5. Ensure expires_at is nullable
    try {
        $pdo->exec("ALTER TABLE listings MODIFY COLUMN expires_at DATETIME NULL");
    } catch (PDOException $e) { }

    // Seed default packages if empty
    $count = $pdo->query("SELECT COUNT(*) FROM boost_packages")->fetchColumn();
    if ($count == 0) {
        $packages = [
            ['İrəli çək (BUMP)', 'Elanınızı axtarış nəticələrində dərhal ən başa gətirir.', 1.00, 1, 'bump'],
            ['VIP Elan', 'Elanınız xüsusi VIP bölməsində və qırmızı rəngdə görünür.', 5.00, 15, 'vip'],
            ['Premium Elan', 'Elanınız Premium bölməsində, ana səhifədə və ən üst sıralarda görünür.', 10.00, 30, 'premium']
        ];
        $stmt = $pdo->prepare("INSERT INTO boost_packages (name, description, price, duration_days, type) VALUES (?, ?, ?, ?, ?)");
        foreach ($packages as $pkg) { $stmt->execute($pkg); }
    }

    // AUTH TOKENS TABLE
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (token)
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

/**
 * Checks if a listing is favorited by the current user
 */
function is_favorite($pdo, $listing_id) {
    if (!isset($_SESSION['user_id'])) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ? AND listing_id = ?");
    $stmt->execute([$_SESSION['user_id'], $listing_id]);
    return $stmt->fetchColumn() > 0;
}
