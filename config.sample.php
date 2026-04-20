<?php
$host = 'localhost';
$dbname = 'xian_parts_v2';
$user = 'YOUR_DB_USER';
$pass = 'YOUR_DB_PASSWORD';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = new PDO(
    "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
    $user,
    $pass,
    $options
);

define('DIGIKEY_CLIENT_ID', 'YOUR_DIGIKEY_CLIENT_ID');
define('DIGIKEY_CLIENT_SECRET', 'YOUR_DIGIKEY_CLIENT_SECRET');
define('DIGIKEY_TOKEN_URL', 'https://api.digikey.com/v1/oauth2/token');
define('DIGIKEY_PRODUCT_URL', 'https://api.digikey.com/products/v4/search/{part}/productdetails');

define('MOUSER_API_KEY', 'YOUR_MOUSER_API_KEY');
define('MOUSER_SEARCH_URL', 'https://api.mouser.com/api/v2/search/partnumber');

// QRラベルから開くURLを固定したい場合に設定してください。
// 未設定の場合はアクセス中のホスト名から自動判定します。
// define('APP_BASE_URL', 'http://localhost/xian_inventory_app');

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirect_to($path) {
    header("Location: {$path}");
    exit;
}

function normalize_mpn($mpn) {
    $mpn = trim((string)$mpn);
    $mpn = preg_replace('/\s+/', '', $mpn);
    return strtoupper($mpn);
}

function part_type_label($type) {
    $map = [
        'electronic' => '電子部品',
        'board' => '基板',
        'wire' => '配線',
        '3dp' => '3DP品',
        'mechanical' => '機構部品',
        'other' => 'その他',
    ];
    return $map[$type] ?? $type;
}
