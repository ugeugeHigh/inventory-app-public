<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_bom_schema.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
ensure_product_bom_schema($pdo);
ensure_storage_schema($pdo);

// 製品選択
$product_id = (int)($_GET['product_id'] ?? 0);

// 製品一覧
$products = $pdo->query("SELECT id, name FROM products ORDER BY name")->fetchAll();

$product = null;
$rows = [];

if ($product_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    $sql = "
    SELECT 
        pc.*, 
        pb.name AS bom_name,
        pb.qty_per_product AS bom_qty_per_product,
        p.name,
        p.mpn,
        p.quantity,
        p.location,
        p.part_type,
        (
            SELECT GROUP_CONCAT(sl.code ORDER BY sl.code SEPARATOR ', ')
            FROM part_storage_locations psl
            JOIN storage_locations sl ON sl.id = psl.storage_location_id
            WHERE psl.part_id = p.id
        ) AS storage_codes
    FROM product_components pc
    JOIN product_boms pb ON pb.id = pc.product_bom_id
    JOIN parts p ON pc.part_id = p.id
    WHERE pc.product_id = ?
    ORDER BY pb.id, pc.component_role, p.name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$product_id]);
    $rows = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>BOM一覧</title>
<style>
body { font-family: Arial; max-width: 1200px; margin: 0 auto; padding: 20px; }
.top-link { display: inline-block; margin-bottom: 16px; color: #2d6cdf; text-decoration: none; }
table { border-collapse: collapse; width: 100%; margin-top: 20px; }
th, td { border: 1px solid #ddd; padding: 8px; }
th { background: #2d6cdf; color: #fff; }
.bad { background: #ffe8e8; }
.good { background: #e8ffe8; }
</style>
</head>
<body>

<a class="top-link" href="index.php">← ダッシュボード</a>
<h1>BOM一覧</h1>

<form method="get">
<select name="product_id">
<option value="">製品を選択</option>
<?php foreach ($products as $p): ?>
<option value="<?= $p['id'] ?>" <?= $product_id == $p['id'] ? 'selected' : '' ?>>
<?= h($p['name']) ?>
</option>
<?php endforeach; ?>
</select>
<button type="submit">表示</button>
</form>

<?php if ($product): ?>
<h2><?= h($product['name']) ?></h2>

<table>
<tr>
<th>種別</th>
<th>BOM</th>
<th>部品名</th>
<th>MPN</th>
<th>必要数</th>
<th>在庫</th>
<th>保管場所</th>
<th>作れる数</th>
<th>状態</th>
<th>登録元</th>
</tr>

<?php foreach ($rows as $r): 
    $requiredQty = (float)$r['qty_per_unit'] * (float)$r['bom_qty_per_product'];
    $can = ($requiredQty > 0) ? floor($r['quantity'] / $requiredQty) : 0;
    $bad = $r['quantity'] < $requiredQty;
?>
<tr class="<?= $bad ? 'bad' : 'good' ?>">
<td><?= h(part_type_label($r['component_role'])) ?></td>
<td><?= h($r['bom_name']) ?> × <?= h($r['bom_qty_per_product']) ?></td>
<td><?= h($r['name']) ?></td>
<td><?= h($r['mpn']) ?></td>
<td><?= h($requiredQty) ?></td>
<td><?= h($r['quantity']) ?></td>
<td><?= h($r['storage_codes'] ?: $r['location']) ?></td>
<td><?= h($can) ?></td>
<td><?= $bad ? '不足' : 'OK' ?></td>
<td><?= h($r['source_type']) ?></td>
</tr>
<?php endforeach; ?>

</table>
<?php endif; ?>

</body>
</html>
