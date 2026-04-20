<?php
$host = getenv('DB_HOST') ?: 'db';
$dbname = getenv('DB_NAME') ?: 'xian_parts_v2';
$user = getenv('DB_USER') ?: 'inventory';
$pass = getenv('DB_PASSWORD') ?: 'inventory_password';

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

define('DIGIKEY_CLIENT_ID', getenv('DIGIKEY_CLIENT_ID') ?: '');
define('DIGIKEY_CLIENT_SECRET', getenv('DIGIKEY_CLIENT_SECRET') ?: '');
define('DIGIKEY_TOKEN_URL', 'https://api.digikey.com/v1/oauth2/token');
define('DIGIKEY_PRODUCT_URL', 'https://api.digikey.com/products/v4/search/{part}/productdetails');

define('MOUSER_API_KEY', getenv('MOUSER_API_KEY') ?: '');
define('MOUSER_SEARCH_URL', 'https://api.mouser.com/api/v2/search/partnumber');

$appBaseUrl = trim((string)(getenv('APP_BASE_URL') ?: ''));
if ($appBaseUrl !== '') {
    define('APP_BASE_URL', rtrim($appBaseUrl, '/'));
}

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
