<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_lot_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_product_lot_schema($pdo);

$productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
$batchToken = trim($_GET['batch'] ?? '');
$errorMessage = '';

function load_product(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    return $stmt->fetch() ?: [];
}

function product_label_title(array $product): string
{
    return trim((string)($product['name'] ?? '')) ?: '製品';
}

function format_label_date(string $date): string
{
    return str_replace('-', '/', $date);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $product = load_product($pdo, $productId);
    if (!$product) {
        $errorMessage = '製品が見つかりません。';
    }

    $lotDate = trim($_POST['lot_date'] ?? date('Y-m-d'));
    $quantity = max(1, min(200, (int)($_POST['quantity'] ?? 1)));
    $lotPrefix = strtoupper(trim($_POST['lot_prefix'] ?? ''));
    $manualUrl = trim($_POST['manual_url'] ?? '');
    $startSequenceInput = trim($_POST['start_sequence'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($errorMessage === '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lotDate)) {
        $errorMessage = '日付は YYYY-MM-DD 形式で入力してください。';
    }

    if ($errorMessage === '' && $lotPrefix === '') {
        $lotPrefix = default_lot_prefix($product);
    }

    if ($errorMessage === '') {
        $lotMonth = date('m', strtotime($lotDate));
        $startSequence = $startSequenceInput !== ''
            ? max(1, (int)$startSequenceInput)
            : next_lot_sequence($pdo, $lotPrefix, $lotMonth);
        $batchToken = bin2hex(random_bytes(16));

        try {
            $pdo->beginTransaction();
            save_product_label_settings($pdo, $productId, $lotPrefix, $manualUrl);

            $stmt = $pdo->prepare("
                INSERT INTO product_lots (
                    product_id, lot_code, lot_prefix, lot_month, lot_sequence,
                    lot_date, manual_url, batch_token, note
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            for ($i = 0; $i < $quantity; $i++) {
                $sequence = $startSequence + $i;
                $lotCode = build_lot_code($lotPrefix, $lotMonth, $sequence);
                $stmt->execute([
                    $productId,
                    $lotCode,
                    $lotPrefix,
                    $lotMonth,
                    $sequence,
                    $lotDate,
                    $manualUrl !== '' ? $manualUrl : null,
                    $batchToken,
                    $note !== '' ? $note : null,
                ]);
            }

            $pdo->commit();
            redirect_to('product_lot_labels.php?batch=' . rawurlencode($batchToken));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'ロット作成に失敗しました: ' . $e->getMessage();
        }
    }
}

$labels = [];
$product = [];

if ($batchToken !== '') {
    $stmt = $pdo->prepare("
        SELECT pl.*, p.name AS product_name, p.switch_science_sku, p.xian_diy_id
        FROM product_lots pl
        JOIN products p ON p.id = pl.product_id
        WHERE pl.batch_token = ?
        ORDER BY pl.lot_sequence
    ");
    $stmt->execute([$batchToken]);
    $labels = $stmt->fetchAll();

    if ($labels) {
        $productId = (int)$labels[0]['product_id'];
        $product = load_product($pdo, $productId);
    }
} elseif ($productId > 0) {
    $product = load_product($pdo, $productId);
}

if (!$product && $productId > 0) {
    http_response_code(404);
    die('製品が見つかりません');
}

$products = $pdo->query("SELECT id, name, switch_science_sku, xian_diy_id FROM products ORDER BY id DESC")->fetchAll();
$settings = $product ? load_product_label_settings($pdo, (int)$product['id']) : [];
$defaultPrefix = $settings['lot_prefix'] ?? ($product ? default_lot_prefix($product) : '');
$defaultManualUrl = $settings['manual_url'] ?? '';
$defaultDate = date('Y-m-d');
$defaultMonth = date('m');
$defaultSequence = $defaultPrefix !== '' ? next_lot_sequence($pdo, $defaultPrefix, $defaultMonth) : 1;
$recentLots = [];

if ($product) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM product_lots
        WHERE product_id = ?
        ORDER BY id DESC
        LIMIT 20
    ");
    $stmt->execute([(int)$product['id']]);
    $recentLots = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>出荷ロットラベル</title>
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
    --error-bg: #fff0f0;
    --error-border: #f0b0b0;
    --error-text: #9b1c1c;
}

* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, "Noto Sans CJK JP", "Noto Sans JP", sans-serif; background: var(--bg); color: var(--text); }
.wrapper { max-width: 1100px; margin: 0 auto; padding: 24px; }
.top-link { display: inline-block; margin-bottom: 18px; color: var(--primary); text-decoration: none; }
.page-title { margin: 0 0 8px; font-size: 28px; }
.page-subtitle { margin: 0 0 22px; color: var(--sub); font-size: 14px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-bottom: 18px; }
.form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.form-full { grid-column: 1 / -1; }
label { display: block; margin-bottom: 7px; font-size: 13px; font-weight: bold; }
input, select, textarea { width: 100%; padding: 10px 11px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; background: #fff; }
.btn, button { display: inline-block; padding: 9px 13px; border-radius: 8px; background: var(--primary); color: #fff; border: 0; text-decoration: none; font-size: 13px; font-weight: bold; cursor: pointer; }
.btn:hover, button:hover { background: var(--primary-hover); }
.actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.note { margin-top: 6px; color: var(--sub); font-size: 12px; line-height: 1.5; }
.error-box { margin-bottom: 16px; padding: 12px 14px; border: 1px solid var(--error-border); background: var(--error-bg); color: var(--error-text); border-radius: 10px; }
.label-sheet { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-start; }
.shipment-label {
    width: 40mm;
    height: 30mm;
    overflow: hidden;
    background: #fff;
    color: #000;
    border: 0.25mm solid #000;
    border-radius: 1.2mm;
    padding: 1.5mm;
    display: grid;
    grid-template-columns: 24mm 12mm;
    grid-template-rows: 7.5mm 12.5mm 6mm;
    column-gap: 1mm;
    row-gap: 0.4mm;
}
.label-title { grid-column: 1 / -1; text-align: center; line-height: 1.08; overflow: hidden; }
.label-title .name { font-size: 6pt; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-title .subtitle { margin-top: 0.7mm; font-size: 5.5pt; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-info { font-size: 5pt; line-height: 1.18; overflow: hidden; }
.label-info .sku { font-size: 5.6pt; margin-bottom: 0.7mm; }
.label-info .xian-id { font-size: 5.6pt; margin-bottom: 0.7mm; }
.label-info .date { font-size: 5.6pt; }
.label-info .lot { font-size: 5.5pt; white-space: nowrap; }
.manual-area { text-align: left; font-size: 5pt; line-height: 1; }
.manual-area img { width: 10.5mm; height: 10.5mm; display: block; image-rendering: pixelated; margin-top: 0.5mm; }
.lot-qr { display: flex; align-items: flex-end; justify-content: center; }
.lot-qr img { width: 8mm; height: 8mm; image-rendering: pixelated; }
.brand { display: flex; align-items: center; justify-content: center; font-size: 8pt; font-style: italic; font-weight: bold; white-space: nowrap; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 8px; border-bottom: 1px solid var(--border); text-align: left; }
th { background: #eef3fb; }

@page { size: 40mm 30mm; margin: 0; }
@media print {
    html, body { width: 40mm; margin: 0; padding: 0; background: #fff; }
    .wrapper { width: 40mm; padding: 0; margin: 0; }
    .top-link, .page-title, .page-subtitle, .card, .screen-only { display: none !important; }
    .label-sheet { display: block; }
    .shipment-label {
        border-radius: 0;
        page-break-after: always;
        break-after: page;
        margin: 0;
    }
    .shipment-label:last-child { page-break-after: auto; break-after: auto; }
}

@media (max-width: 760px) {
    .wrapper { padding: 16px; }
    .form-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>
    <a class="top-link" href="<?= $product ? 'product_detail.php?id=' . h($product['id']) : 'products.php' ?>">← <?= $product ? '製品詳細へ' : '製品一覧へ' ?></a>
    <h1 class="page-title">出荷ロットラベル</h1>
    <p class="page-subtitle">出荷数を入力すると、Lot IDを連番で作成してM110S 40x30mm用ラベルをまとめて印刷できます。</p>

    <?php if ($errorMessage !== ''): ?>
        <div class="error-box"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post">
            <div class="form-grid">
                <div class="form-full">
                    <label>対象製品</label>
                    <select name="product_id" required onchange="if (this.value) location.href='product_lot_labels.php?product_id=' + encodeURIComponent(this.value);">
                        <option value="">製品を選択</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= h($p['id']) ?>" <?= (int)$p['id'] === (int)$productId ? 'selected' : '' ?>>
                                <?= h($p['name'] . ' / SKU ' . ($p['switch_science_sku'] ?? '') . ' / ' . ($p['xian_diy_id'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>出荷日</label>
                    <input type="date" name="lot_date" value="<?= h($defaultDate) ?>" required>
                </div>
                <div>
                    <label>出荷数</label>
                    <input type="number" name="quantity" value="1" min="1" max="200" required>
                    <div class="note">10個なら10枚のラベルと10個のLot IDを作ります。</div>
                </div>
                <div>
                    <label>開始番号</label>
                    <input type="number" name="start_sequence" value="<?= h($defaultSequence) ?>" min="1">
                    <div class="note">空欄でも次の番号を自動採番できます。</div>
                </div>
                <div>
                    <label>Lot接頭辞</label>
                    <input type="text" name="lot_prefix" value="<?= h($defaultPrefix) ?>" placeholder="例: SW011">
                </div>
                <div class="form-full">
                    <label>マニュアルURL</label>
                    <input type="url" name="manual_url" value="<?= h($defaultManualUrl) ?>" placeholder="https://example.com/manual">
                    <div class="note">manual QRに入れるURLです。空欄の場合は製品詳細ページへのQRになります。入力内容は製品ごとに保存されます。</div>
                </div>
                <div class="form-full">
                    <label>メモ</label>
                    <textarea name="note" rows="2" placeholder="任意。出荷先や検査メモなど"></textarea>
                </div>
            </div>
            <div class="actions" style="margin-top:16px;">
                <button type="submit">Lot IDを作成してラベル表示</button>
                <?php if ($labels): ?>
                    <button type="button" onclick="window.print()">このバッチを印刷</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($labels): ?>
        <div class="card screen-only">
            <div class="actions">
                <button type="button" onclick="window.print()">印刷</button>
                <a class="btn" href="product_lot_labels.php?product_id=<?= h($productId) ?>">同じ製品で追加作成</a>
            </div>
            <div class="note">作成枚数: <?= h(count($labels)) ?> / Lot ID: <?= h($labels[0]['lot_code']) ?> - <?= h($labels[count($labels) - 1]['lot_code']) ?></div>
        </div>

        <div class="label-sheet">
            <?php foreach ($labels as $label): ?>
                <?php
                    $manualUrl = trim((string)($label['manual_url'] ?? ''));
                    $sku = trim((string)($label['switch_science_sku'] ?? ''));
                    $xianId = trim((string)($label['xian_diy_id'] ?? ''));
                ?>
                <div class="shipment-label">
                    <div class="label-title">
                        <div class="name"><?= h(product_label_title(['name' => $label['product_name']])) ?></div>
                        <div class="subtitle">出荷ロットラベル</div>
                    </div>
                    <div class="label-info">
                        <div class="sku">SKU <?= h($sku !== '' ? $sku : '-') ?></div>
                        <div class="xian-id"><?= h($xianId !== '' ? $xianId : '-') ?></div>
                        <div class="date"><?= h(format_label_date($label['lot_date'])) ?></div>
                        <div class="lot">Lot ID : <?= h($label['lot_code']) ?></div>
                    </div>
                    <div class="manual-area">
                        <div>manual</div>
                        <img src="product_lot_qr.php?id=<?= h($label['id']) ?>&type=manual&size=220" alt="manual QR">
                    </div>
                    <div class="lot-qr">
                        <img src="product_lot_qr.php?id=<?= h($label['id']) ?>&type=lot&size=180" alt="lot QR">
                    </div>
                    <div class="brand">Xian DIY</div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($recentLots): ?>
        <div class="card screen-only">
            <h2 style="margin-top:0;">最近作成したLot</h2>
            <table>
                <tr>
                    <th>Lot ID</th>
                    <th>日付</th>
                    <th>作成日時</th>
                    <th>操作</th>
                </tr>
                <?php foreach ($recentLots as $lot): ?>
                    <tr>
                        <td><?= h($lot['lot_code']) ?></td>
                        <td><?= h($lot['lot_date']) ?></td>
                        <td><?= h($lot['created_at']) ?></td>
                        <td>
                            <a class="btn" href="product_lot_detail.php?id=<?= h($lot['id']) ?>">詳細</a>
                            <a class="btn" href="product_lot_labels.php?batch=<?= h($lot['batch_token']) ?>">同じバッチ</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
