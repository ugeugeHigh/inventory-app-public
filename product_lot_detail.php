<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_lot_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_product_lot_schema($pdo);

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT pl.*, p.name AS product_name, p.switch_science_sku, p.xian_diy_id
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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>出荷ロット詳細</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body { margin: 0; font-family: Arial, sans-serif; background: #f5f7fb; color: #223; }
.wrapper { max-width: 780px; margin: 0 auto; padding: 24px; }
.top-link { display: inline-block; margin-bottom: 18px; color: #2d6cdf; text-decoration: none; }
.card { background: #fff; border: 1px solid #d9e1ec; border-radius: 14px; padding: 20px; }
.grid { display: grid; grid-template-columns: 160px 1fr; gap: 12px; }
.label { color: #667085; font-size: 13px; }
.value { font-weight: bold; }
.actions { margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap; }
.btn { display: inline-block; padding: 9px 13px; border-radius: 8px; background: #2d6cdf; color: #fff; text-decoration: none; font-size: 13px; font-weight: bold; }
@media (max-width: 640px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>
    <a class="top-link" href="product_detail.php?id=<?= h($lot['product_id']) ?>">← 製品詳細へ</a>
    <h1>出荷ロット詳細</h1>
    <div class="card">
        <div class="grid">
            <div class="label">Lot ID</div>
            <div class="value"><?= h($lot['lot_code']) ?></div>
            <div class="label">製品名</div>
            <div class="value"><?= h($lot['product_name']) ?></div>
            <div class="label">Switch Science SKD</div>
            <div class="value"><?= h($lot['switch_science_sku']) ?></div>
            <div class="label">XianDIY ID</div>
            <div class="value"><?= h($lot['xian_diy_id']) ?></div>
            <div class="label">ロット日付</div>
            <div class="value"><?= h($lot['lot_date']) ?></div>
            <div class="label">マニュアルURL</div>
            <div class="value">
                <?php if (trim((string)$lot['manual_url']) !== ''): ?>
                    <a href="<?= h($lot['manual_url']) ?>"><?= h($lot['manual_url']) ?></a>
                <?php else: ?>
                    -
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <a class="btn" href="product_lot_labels.php?batch=<?= h($lot['batch_token']) ?>">このバッチのラベル</a>
            <a class="btn" href="product_lot_labels.php?product_id=<?= h($lot['product_id']) ?>">ラベル作成</a>
        </div>
    </div>
</div>
</body>
</html>
