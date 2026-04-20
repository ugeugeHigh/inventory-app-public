<?php
require_once __DIR__ . '/config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$url = trim($_POST['url'] ?? '');

if ($url === '') {
    echo json_encode([
        'ok' => false,
        'error' => 'URLが空です'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function http_get_html(string $url): string
{
    if (!function_exists('file_get_contents')) {
        throw new Exception('file_get_contents が使えません');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' =>
                "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari/537.36 XianDIYPartsManager/1.0\r\n" .
                "Accept-Language: ja,en-US;q=0.9,en;q=0.8\r\n" .
                "Cache-Control: no-cache\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]
    ]);

    $html = @file_get_contents($url, false, $context);

    if ($html === false || trim($html) === '') {
        $err = error_get_last();
        $msg = $err['message'] ?? 'unknown error';
        throw new Exception('秋月ページ取得失敗: ' . $msg);
    }

    return $html;
}

function clean_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function normalize_line_text(string $html): string
{
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r", "\n", "\t", '&nbsp;'], ' ', $text);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function extract_akizuki_name(string $html): string
{
    $candidates = [];

    // 1. og:title
    if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/iu', $html, $m)) {
        $candidates[] = clean_text($m[1]);
    }

    // 2. h1
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/isu', $html, $m)) {
        $candidates[] = clean_text($m[1]);
    }

    // 3. title
    if (preg_match('/<title>(.*?)<\/title>/isu', $html, $m)) {
        $candidates[] = clean_text($m[1]);
    }

    foreach ($candidates as $name) {
        // サイト名や説明を落とす
        $name = preg_replace('/\s*秋月電子通商.*$/u', '', $name);
        $name = preg_replace('/\s*-\s*電子部品・ネット通販.*$/u', '', $name);
        $name = preg_replace('/\s*\|\s*秋月電子通商.*$/u', '', $name);
        $name = preg_replace('/^\s*半導体\s+/u', '', $name);
        $name = preg_replace('/^\s*電子部品\s+/u', '', $name);
        $name = trim($name, " 　-｜|:");

        // 明らかにサイト名だけのものは除外
        if ($name === '' || mb_strpos($name, '秋月電子通商') !== false) {
            continue;
        }

        return $name;
    }

    return '';
}

function extract_akizuki_price(string $html): float
{
    $patterns = [
        '/税込[^\d¥￥]*[¥￥]\s*([0-9,]+)/u',
        '/[¥￥]\s*([0-9,]+)\s*税込/u',
        '/価格[^\d¥￥]*[¥￥]\s*([0-9,]+)/u',
        '/販売価格[^\d¥￥]*[¥￥]\s*([0-9,]+)/u',
        '/<span[^>]*class=["\'][^"\']*price[^"\']*["\'][^>]*>.*?[¥￥]\s*([0-9,]+).*?<\/span>/isu',
        '/[¥￥]\s*([0-9,]+)/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $price = str_replace(',', '', $m[1]);
            if (is_numeric($price)) {
                return (float)$price;
            }
        }
    }

    return 0.0;
}

function extract_akizuki_supplier_part_number(string $url, string $html): string
{
    $patterns = [
        '~/catalog/g/g([A-Z]-\d{5})/~i',
        '/商品コード[:：]?\s*([A-Z]-\d{5})/u',
        '/商品番号[:：]?\s*([A-Z]-\d{5})/u',
        '/品番[:：]?\s*([A-Z]-\d{5})/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $m)) {
            return strtoupper(trim($m[1]));
        }
        if (preg_match($pattern, normalize_line_text($html), $m)) {
            return strtoupper(trim($m[1]));
        }
    }

    return '';
}

function extract_akizuki_mpn(string $html): string
{
    $text = normalize_line_text($html);

    $patterns = [
        '/メーカー型番[:：]?\s*([A-Za-z0-9][A-Za-z0-9\-\._\/\+\(\)]{1,80})/u',
        '/型番[:：]?\s*([A-Za-z0-9][A-Za-z0-9\-\._\/\+\(\)]{1,80})/u',
        '/品名[:：]?\s*[^\s]*\s+([A-Za-z0-9][A-Za-z0-9\-\._\/\+\(\)]{2,80})/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $mpn = trim($m[1]);
            $mpn = preg_replace('/[。．、,]+$/u', '', $mpn);
            $mpn = preg_replace('/\s+/u', '', $mpn);
            if ($mpn !== '' && preg_match('/[A-Za-z]/', $mpn)) {
                return strtoupper($mpn);
            }
        }
    }

    return '';
}

function infer_akizuki_part_type(string $name): string
{
    $n = mb_strtolower($name, 'UTF-8');

    if (preg_match('/基板|pcb|プリント基板/u', $n)) {
        return 'board';
    }
    if (preg_match('/ケーブル|ワイヤ|配線/u', $n)) {
        return 'wire';
    }
    if (preg_match('/ケース|3d|3dp|樹脂/u', $n)) {
        return '3dp';
    }

    return 'electronic';
}

function parse_akizuki(string $url): array
{
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    if (stripos($host, 'akizukidenshi.com') === false) {
        throw new Exception('秋月電子のURLではありません');
    }

    $html = http_get_html($url);

    $name = extract_akizuki_name($html);
    $price = extract_akizuki_price($html);
    $supplierPartNumber = extract_akizuki_supplier_part_number($url, $html);
    $mpn = extract_akizuki_mpn($html);
    $partType = infer_akizuki_part_type($name);

    if ($name === '') {
        throw new Exception('商品名を取得できませんでした');
    }

    return [
        'supplier' => '秋月電子',
        'supplier_url' => $url,
        'name' => $name,
        'unit_price' => $price,
        'mpn' => function_exists('normalize_mpn') ? normalize_mpn($mpn) : strtoupper($mpn),
        'manufacturer' => '',
        'supplier_part_number' => $supplierPartNumber,
        'part_type' => $partType,
        'note' => '秋月URLから自動取得'
    ];
}

try {
    $data = parse_akizuki($url);

    echo json_encode([
        'ok' => true,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

?>