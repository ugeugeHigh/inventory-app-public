<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_lot_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_product_lot_schema($pdo);

function app_base_url_for_lot(): string {
    if (defined('APP_BASE_URL') && trim((string)APP_BASE_URL) !== '') {
        return rtrim((string)APP_BASE_URL, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . ($scriptDir === '' ? '' : $scriptDir);
}

function render_qr_png(string $payload, int $size = 240): void
{
    $script = __DIR__ . '/tools/m110s_label_png.py';
    if (!is_file($script)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'QR生成スクリプトが見つかりません';
        return;
    }

    $input = json_encode([
        'mode' => 'qr',
        'payload' => $payload,
        'size' => $size,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(['python3', $script], $descriptorSpec, $pipes, __DIR__);
    if (!is_resource($process)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'QR生成プロセスを開始できません';
        return;
    }

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $png = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0 || $png === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'QR生成に失敗しました';
        if ($error !== '') {
            echo "\n" . $error;
        }
        return;
    }

    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    echo $png;
}

$id = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'lot';

$stmt = $pdo->prepare("
    SELECT pl.*, p.name AS product_name
    FROM product_lots pl
    JOIN products p ON p.id = pl.product_id
    WHERE pl.id = ?
");
$stmt->execute([$id]);
$lot = $stmt->fetch();

if (!$lot) {
    http_response_code(404);
    die('ロットが見つかりません');
}

if ($type === 'manual') {
    $payload = trim((string)($lot['manual_url'] ?? ''));
    if ($payload === '') {
        $payload = app_base_url_for_lot() . '/product_detail.php?id=' . rawurlencode((string)$lot['product_id']);
    }
} else {
    $payload = app_base_url_for_lot() . '/product_lot_detail.php?id=' . rawurlencode((string)$lot['id']);
}

render_qr_png($payload, (int)($_GET['size'] ?? 240));
