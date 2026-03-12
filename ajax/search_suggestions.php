<?php
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q) < 1) {
    echo json_encode([]);
} else {
    try {
        $q_lower = mb_strtolower($q, 'UTF-8');

        $replace_map = [
            'ə' => 'e', 'ç' => 'c', 'ş' => 's', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u', 'ı' => 'i',
            'e' => 'ə', 'c' => 'ç', 's' => 'ş', 'g' => 'ğ', 'o' => 'ö', 'u' => 'ü', 'i' => 'ı'
        ];
        $alt_query = strtr($q_lower, $replace_map);

        $vowels = ['a', 'e', 'ə', 'i', 'ı', 'o', 'ö', 'u', 'ü'];
        $fuzzy_query = str_replace($vowels, '_', $q_lower);

        $stmt = $pdo->prepare("SELECT id, title FROM listings WHERE status = 'active' AND (title LIKE ? OR title LIKE ? OR title LIKE ?) LIMIT 5");
        $stmt->execute(['%' . $q_lower . '%', '%' . $alt_query . '%', '%' . $fuzzy_query . '%']);
        $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($suggestions);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
