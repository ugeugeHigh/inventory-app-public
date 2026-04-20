<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_storage_schema($pdo);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$id = (int)($_GET['id'] ?? 0);
$format = strtolower(trim($_GET['format'] ?? 'html'));

if ($id <= 0) {
    http_response_code(400);
    die('部品IDが不正です');
}

$stmt = $pdo->prepare("SELECT * FROM parts WHERE id = ?");
$stmt->execute([$id]);
$part = $stmt->fetch();

if (!$part) {
    http_response_code(404);
    die('部品が見つかりません');
}

$storageCodes = get_part_storage_codes($pdo, $id);
$storageDisplay = $storageCodes ? implode(',', $storageCodes) : (string)($part['location'] ?? '');
$part['location'] = $storageDisplay;

if (!function_exists('part_type_label')) {
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
}

function app_base_url(): string {
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

function build_part_detail_url(array $part): string {
    return app_base_url() . '/part_detail.php?id=' . rawurlencode((string)($part['id'] ?? 0));
}

function render_m110s_png(array $part, string $payload, string $mode = 'label'): void {
    $script = __DIR__ . '/tools/m110s_label_png.py';

    if (!is_file($script)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PNG生成スクリプトが見つかりません';
        return;
    }

    $input = json_encode([
        'mode' => $mode,
        'name' => (string)($part['name'] ?? ''),
        'location' => (string)($part['location'] ?? ''),
        'payload' => $payload,
        'size' => 240,
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
        echo 'PNG生成プロセスを開始できません';
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
        echo 'PNG生成に失敗しました';
        if ($error !== '') {
            echo "\n" . $error;
        }
        return;
    }

    $filename = $mode === 'qr'
        ? 'part_' . (int)($part['id'] ?? 0) . '_qr.png'
        : 'part_' . (int)($part['id'] ?? 0) . '_m110s_40x30.png';
    header('Content-Type: image/png');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $png;
}

$payload = build_part_detail_url($part);

if ($format === 'png') {
    render_m110s_png($part, $payload);
    exit;
}

if ($format === 'qr') {
    render_m110s_png($part, $payload, 'qr');
    exit;
}

$storageWarningJson = $_SESSION['storage_warning'] ?? '';
if ($storageWarningJson !== '') {
    unset($_SESSION['storage_warning']);
}
$isSaved = ($_GET['saved'] ?? '') === '1';

$qrUrl = 'part_qr.php?id=' . rawurlencode((string)$id) . '&format=qr';
$pngUrl = 'part_qr.php?id=' . rawurlencode((string)$id) . '&format=png';
$printCommand = "lp -d M110S \\\n   -o zeMediaTracking=Gap \\\n   -o scaling=100 \\\n   -o fit-to-page=false \\\n   part_" . $id . "_m110s_40x30.png";
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>Phomemo M110S 部品QRラベル</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --bg: #f5f7fb;
    --card: #fff;
    --border: #d9e1ec;
    --text: #111;
    --sub: #667085;
    --primary: #2d6cdf;
    --primary-hover: #1f57c5;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, "Noto Sans CJK JP", "Noto Sans JP", sans-serif;
    background: var(--bg);
    color: var(--text);
}

.wrapper {
    max-width: 920px;
    margin: 0 auto;
    padding: 24px;
}

.toolbar,
.info-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 18px;
}

.toolbar {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

a.btn,
button {
    display: inline-block;
    padding: 9px 13px;
    border-radius: 8px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    border: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
}

a.btn:hover,
button:hover {
    background: var(--primary-hover);
}

.page-title {
    margin: 0 0 6px;
    font-size: 24px;
}

.small {
    color: var(--sub);
    font-size: 13px;
}

.notice-card,
.warning-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 18px;
    font-size: 13px;
}

.notice-card {
    border-color: #b7d7c0;
    background: #f0fff4;
    color: #166534;
}

.warning-card {
    border-color: #f0b0b0;
    background: #fff7f7;
    color: #9b1c1c;
}

.preview-area {
    display: flex;
    gap: 24px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.label-frame {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
}

.m110s-label {
    width: 40mm;
    height: 30mm;
    overflow: hidden;
    background: #fff;
    color: #000;
    border: 0.2mm solid #000;
    display: grid;
    grid-template-columns: 18.5mm 1fr;
    gap: 1.4mm;
    align-items: center;
    padding: 1.4mm;
}

.qr {
    width: 18.5mm;
    height: 18.5mm;
    display: flex;
    align-items: center;
    justify-content: center;
}

.qr img {
    width: 18.5mm;
    height: 18.5mm;
    image-rendering: pixelated;
}

.label-text {
    min-width: 0;
    line-height: 1.08;
}

.label-caption {
    font-size: 5pt;
    font-weight: bold;
}

.part-name {
    margin: 0.5mm 0 1.4mm;
    font-size: 8pt;
    font-weight: bold;
    overflow-wrap: anywhere;
    word-break: break-word;
    max-height: 14mm;
    overflow: hidden;
}

.location {
    border-top: 0.2mm solid #000;
    padding-top: 1mm;
}

.location-value {
    margin-top: 0.5mm;
    font-size: 9pt;
    font-weight: bold;
    overflow-wrap: anywhere;
    word-break: break-word;
    max-height: 7mm;
    overflow: hidden;
}

.payload-box {
    margin-top: 12px;
    max-width: 580px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #fff;
    font-size: 12px;
    word-break: break-all;
}

pre {
    margin: 8px 0 0;
    padding: 10px;
    background: #111827;
    color: #f9fafb;
    border-radius: 8px;
    overflow-x: auto;
    font-size: 12px;
}

@page {
    size: 40mm 30mm;
    margin: 0;
}

@media print {
    html,
    body {
        width: 40mm;
        height: 30mm;
        margin: 0;
        padding: 0;
        background: #fff;
    }

    .wrapper {
        width: 40mm;
        height: 30mm;
        padding: 0;
        margin: 0;
    }

    .toolbar,
    .info-card,
    .notice-card,
    .warning-card,
    .payload-box {
        display: none;
    }

    .preview-area,
    .label-frame {
        display: block;
        padding: 0;
        margin: 0;
        border: 0;
        border-radius: 0;
        background: #fff;
    }

    .m110s-label {
        border: 0;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <div class="toolbar">
        <a class="btn" href="index.php">← ダッシュボード</a>
        <a class="btn" href="parts.php">← 部品一覧へ戻る</a>
        <button type="button" onclick="window.print()">ブラウザ印刷</button>
        <a class="btn" href="<?= h($pngUrl) ?>" target="_blank">PNG表示</a>
    </div>

    <div class="info-card">
        <h1 class="page-title">Phomemo M110S 部品QRラベル</h1>
        <div class="small">40x30mm / 表示文字: 部品名・保管場所 / QR: 部品詳細ページURL</div>
        <pre><?= h($printCommand) ?></pre>
    </div>

    <?php if ($isSaved): ?>
        <div class="notice-card">保存しました。必要ならこの画面からQRラベルを印刷できます。</div>
    <?php endif; ?>

    <?php if ($storageWarningJson !== ''): ?>
        <?php $storageWarning = json_decode($storageWarningJson, true) ?: []; ?>
        <?php if ($storageWarning): ?>
            <div class="warning-card">
                警告: 選択した保管場所には、すでに他の部品が登録されています。
                <?php foreach ($storageWarning as $code => $usedParts): ?>
                    <div style="margin-top:6px;">
                        <strong><?= h($code) ?></strong>:
                        <?= h(implode(', ', array_map(fn($p) => ($p['part_code'] ?? '') . ' ' . ($p['name'] ?? ''), $usedParts))) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="preview-area">
        <div class="label-frame">
            <div class="m110s-label">
                <div class="qr">
                    <img src="<?= h($qrUrl) ?>" alt="QRコード">
                </div>
                <div class="label-text">
                    <div class="label-caption">部品名</div>
                    <div class="part-name"><?= h($part['name'] ?? '') ?></div>
                    <div class="location">
                        <div class="label-caption">保管場所</div>
                        <div class="location-value"><?= h($storageDisplay) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="payload-box">
            <strong>QRリンク先</strong>
            <div><?= h($payload) ?></div>
        </div>
    </div>
</div>
<script>
function fitLabelText(selector, minPt) {
    document.querySelectorAll(selector).forEach((element) => {
        const original = Number(element.dataset.originalPt || parseFloat(getComputedStyle(element).fontSize) * 0.75);
        element.dataset.originalPt = String(original);
        element.style.fontSize = original + 'pt';

        let size = original;
        while (size > minPt && (element.scrollHeight > element.clientHeight || element.scrollWidth > element.clientWidth)) {
            size -= 0.5;
            element.style.fontSize = size + 'pt';
        }
    });
}

function fitM110sLabel() {
    fitLabelText('.part-name', 5);
    fitLabelText('.location-value', 5.5);
}

window.addEventListener('DOMContentLoaded', fitM110sLabel);
window.addEventListener('load', fitM110sLabel);
window.addEventListener('beforeprint', fitM110sLabel);
</script>
</body>
</html>
