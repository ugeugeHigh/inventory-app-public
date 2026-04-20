<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_bom_schema.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_product_bom_schema($pdo);
ensure_storage_schema($pdo);

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

$id = (int)($_GET['id'] ?? 0);
$showShortageOnly = isset($_GET['shortage']) && $_GET['shortage'] === '1';
$exportMode = $_GET['export'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    die('製品が見つかりません');
}

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
    p.part_code,
    p.supplier,
    p.supplier_url,
    p.unit_price
FROM product_components pc
JOIN product_boms pb ON pb.id = pc.product_bom_id
JOIN parts p ON pc.part_id = p.id
WHERE pc.product_id = ?
ORDER BY pb.id, pc.component_role, p.name
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$allRows = $stmt->fetchAll();
$rowStorageMap = get_parts_storage_codes($pdo, array_column($allRows, 'part_id'));

$maxUnits = null;
$totalUnitCost = 0.0;
$shortageRows = [];
$actionMessage = '';
$actionError = '';

if (isset($_GET['consumed'])) {
    $actionMessage = h((int)$_GET['consumed']) . '個分の部品在庫を減らしました。';
}

foreach ($allRows as $row) {
    $requiredQty = (float)$row['qty_per_unit'] * (float)$row['bom_qty_per_product'];
    $can = ($requiredQty > 0) ? floor($row['quantity'] / $requiredQty) : 0;
    if ($maxUnits === null || $can < $maxUnits) {
        $maxUnits = $can;
    }

    $totalUnitCost += (float)$row['unit_price'] * $requiredQty;

    if ((float)$row['quantity'] < $requiredQty) {
        $shortageRows[] = $row;
    }
}

if ($maxUnits === null) {
    $maxUnits = 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'consume_stock') {
    $buildQuantity = (int)($_POST['build_quantity'] ?? 0);

    if ($buildQuantity <= 0) {
        $actionError = '製作数は1以上で入力してください。';
    } elseif (!$allRows) {
        $actionError = 'この製品には構成部品が登録されていません。';
    } else {
        $requirements = [];
        foreach ($allRows as $row) {
            $partId = (int)$row['part_id'];
            $required = (float)$row['qty_per_unit'] * (float)$row['bom_qty_per_product'] * $buildQuantity;
            if ($required <= 0) {
                continue;
            }
            if (!isset($requirements[$partId])) {
                $requirements[$partId] = [
                    'name' => $row['name'],
                    'required' => 0,
                ];
            }
            $requirements[$partId]['required'] += $required;
        }

        try {
            $pdo->beginTransaction();
            $shortages = [];
            $lockedParts = [];

            foreach ($requirements as $partId => $requirement) {
                $consumeQty = (int)ceil($requirement['required']);
                $stmt = $pdo->prepare("SELECT id, name, quantity FROM parts WHERE id = ? FOR UPDATE");
                $stmt->execute([$partId]);
                $part = $stmt->fetch();

                if (!$part) {
                    $shortages[] = $requirement['name'] . ' が見つかりません';
                    continue;
                }

                if ((int)$part['quantity'] < $consumeQty) {
                    $shortages[] = $part['name'] . ' 在庫 ' . (int)$part['quantity'] . ' / 必要 ' . $consumeQty;
                    continue;
                }

                $lockedParts[$partId] = $consumeQty;
            }

            if ($shortages) {
                $pdo->rollBack();
                $actionError = '在庫不足のため反映しませんでした: ' . implode(' / ', $shortages);
            } else {
                $update = $pdo->prepare("UPDATE parts SET quantity = quantity - ? WHERE id = ?");
                foreach ($lockedParts as $partId => $consumeQty) {
                    $update->execute([$consumeQty, $partId]);
                }
                $pdo->commit();
                redirect_to('product_detail.php?id=' . rawurlencode((string)$id) . '&consumed=' . rawurlencode((string)$buildQuantity));
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $actionError = '在庫更新中にエラーが発生しました: ' . $e->getMessage();
        }
    }
}

$rows = $showShortageOnly ? $shortageRows : $allRows;

function export_product_csv(array $rows, array $product, bool $shortageOnly = false): void
{
    $safeName = preg_replace('/[^\w\-]+/u', '_', (string)$product['name']);
    $filename = $shortageOnly
        ? 'product_bom_shortage_' . $safeName . '.csv'
        : 'product_bom_' . $safeName . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $fp = fopen('php://output', 'w');
    fwrite($fp, "\xEF\xBB\xBF");

    fputcsv($fp, [
        '製品ID',
        '製品名',
        'Switch Science SKD',
        'XianDIY ID',
        '部品コード',
        'BOM',
        'BOM数量/商品',
        '種別',
        '部品名',
        'MPN',
        '必要数/1BOM',
        '必要数/商品',
        '現在在庫',
        '不足数',
        '作れる数',
        '保管場所',
        '購入先',
        '購入URL',
        '単価',
        '小計',
        '登録元',
        'RefDes'
    ]);

    foreach ($rows as $row) {
        $requiredQty = (float)$row['qty_per_unit'] * (float)$row['bom_qty_per_product'];
        $can = ($requiredQty > 0) ? floor($row['quantity'] / $requiredQty) : 0;
        $subtotal = (float)$row['unit_price'] * $requiredQty;
        $shortageQty = max(0, $requiredQty - (float)$row['quantity']);

        fputcsv($fp, [
            $product['id'],
            $product['name'],
            $product['switch_science_sku'],
            $product['xian_diy_id'],
            $row['part_code'],
            $row['bom_name'],
            $row['bom_qty_per_product'],
            part_type_label($row['component_role']),
            $row['name'],
            $row['mpn'],
            $row['qty_per_unit'],
            $requiredQty,
            $row['quantity'],
            $shortageQty,
            $can,
            $row['location'],
            $row['supplier'],
            $row['supplier_url'],
            $row['unit_price'],
            $subtotal,
            $row['source_type'],
            $row['reference_designators'],
        ]);
    }

    fclose($fp);
    exit;
}

// CSV出力
if ($exportMode === 'csv') {
    export_product_csv($allRows, $product, false);
}
if ($exportMode === 'shortage_csv') {
    export_product_csv($shortageRows, $product, true);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>製品詳細</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --bg: #f5f7fb;
    --card: #ffffff;
    --border: #d9e1ec;
    --text: #223;
    --sub: #667085;
    --primary: #2d6cdf;
    --primary-hover: #1f57c5;
    --info-bg: #f3f7ff;
    --info-border: #dbe7ff;
    --bad-bg: #ffe8e8;
    --good-bg: #e8ffe8;
    --warn-bg: #fff7e8;
    --warn-border: #f3d08a;
    --warn-text: #8a5a00;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
}

.wrapper {
    max-width: 1380px;
    margin: 0 auto;
    padding: 24px;
}

.top-link {
    display: inline-block;
    margin-bottom: 18px;
    color: var(--primary);
    text-decoration: none;
}

.page-title {
    margin: 0 0 10px 0;
    font-size: 28px;
}

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 18px 20px;
    box-shadow: 0 8px 24px rgba(20, 40, 80, 0.05);
    margin-bottom: 18px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 12px;
}

.info-item {
    background: var(--info-bg);
    border: 1px solid var(--info-border);
    border-radius: 12px;
    padding: 12px 14px;
}

.info-label {
    font-size: 12px;
    color: var(--sub);
    margin-bottom: 6px;
}

.info-value {
    font-weight: bold;
    font-size: 18px;
}

.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

a.btn {
    display: inline-block;
    padding: 9px 14px;
    border-radius: 10px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    font-weight: bold;
}

a.btn:hover {
    background: var(--primary-hover);
}

a.btn.secondary {
    background: #64748b;
}
a.btn.secondary:hover {
    background: #475569;
}

a.btn.warn {
    background: #d97706;
}
a.btn.warn:hover {
    background: #b45309;
}

button.btn {
    display: inline-block;
    padding: 9px 14px;
    border-radius: 10px;
    background: var(--primary);
    color: #fff;
    border: 0;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

button.btn:hover {
    background: var(--primary-hover);
}

.stock-form {
    display: flex;
    gap: 10px;
    align-items: end;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.stock-form label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: bold;
}

.stock-form input {
    width: 150px;
    padding: 9px 10px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 14px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    border-collapse: collapse;
    width: 100%;
}

th, td {
    border: 1px solid #e5e7eb;
    padding: 9px;
    vertical-align: top;
    text-align: left;
}

th {
    background: #eef4ff;
    color: #1f3b75;
}

.bad {
    background: var(--bad-bg);
}

.good {
    background: var(--good-bg);
}

.small {
    font-size: 12px;
    color: var(--sub);
}

.cost {
    font-weight: bold;
}

.notice {
    background: var(--warn-bg);
    border: 1px solid var(--warn-border);
    color: var(--warn-text);
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 18px;
}

.notice.success {
    background: #eaf8ee;
    border-color: #9fd5ab;
    color: #1f6b34;
}

.notice.error {
    background: #fff0f0;
    border-color: #f0b0b0;
    color: #9b1c1c;
}

@media (max-width: 720px) {
    .wrapper {
        padding: 16px;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>
    <a class="top-link" href="products.php">← 製品一覧</a>

    <h1 class="page-title"><?= h($product['name']) ?></h1>

    <div class="card">
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Switch Science SKD</div>
                <div class="info-value"><?= h($product['switch_science_sku']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">XianDIY ID</div>
                <div class="info-value"><?= h($product['xian_diy_id']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">この在庫で作れる数</div>
                <div class="info-value"><?= h($maxUnits) ?> 個</div>
            </div>
            <div class="info-item">
                <div class="info-label">1商品あたり部品原価合計</div>
                <div class="info-value">¥<?= h(number_format($totalUnitCost, 2)) ?></div>
            </div>
        </div>
    </div>

    <div class="actions">
        <a class="btn" href="product_form.php?id=<?= h($id) ?>">製品編集</a>
        <a class="btn" href="product_bom.php?product_id=<?= h($id) ?>">構成編集</a>
        <a class="btn" href="product_lot_labels.php?product_id=<?= h($id) ?>">出荷ラベル</a>
        <a class="btn secondary" href="product_detail.php?id=<?= h($id) ?>">全件表示</a>
        <a class="btn warn" href="product_detail.php?id=<?= h($id) ?>&shortage=1">不足部品のみ表示</a>
        <a class="btn" href="product_detail.php?id=<?= h($id) ?>&export=csv">CSV出力</a>
        <a class="btn warn" href="product_detail.php?id=<?= h($id) ?>&export=shortage_csv">不足部品CSV</a>
    </div>

    <?php if ($actionMessage !== ''): ?>
        <div class="notice success"><?= $actionMessage ?></div>
    <?php endif; ?>

    <?php if ($actionError !== ''): ?>
        <div class="notice error"><?= h($actionError) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0;">製作数ぶん在庫を減らす</h2>
        <form method="post" class="stock-form" onsubmit="return confirm('指定した製作数ぶん、構成部品の在庫を減らします。よろしいですか？');">
            <input type="hidden" name="action" value="consume_stock">
            <div>
                <label>製作数</label>
                <input type="number" name="build_quantity" min="1" max="<?= h(max(1, $maxUnits)) ?>" value="1" required>
            </div>
            <button type="submit" class="btn">在庫に反映</button>
            <div class="small">
                現在の在庫で作れる数: <?= h($maxUnits) ?> 個。BOM数量が小数の場合、消費数は切り上げます。
            </div>
        </form>
    </div>

    <?php if ($showShortageOnly): ?>
        <div class="notice">
            不足部品のみを表示しています。件数: <?= h(count($rows)) ?> 件
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0;">
            <?= $showShortageOnly ? '不足部品一覧' : '必要部品一覧' ?>
        </h2>
        <div class="small" style="margin-bottom:12px;">
            <?= $showShortageOnly
                ? 'この製品を1個作るために不足している部品のみを表示しています。'
                : 'この製品を1個作るために必要な部品一覧です。保管場所と購入先も確認できます。'
            ?>
        </div>

        <div class="table-wrap">
            <table>
                <tr>
                    <th>役割</th>
                    <th>BOM</th>
                    <th>部品コード</th>
                    <th>部品名</th>
                    <th>MPN</th>
                    <th>必要数/1BOM</th>
                    <th>必要数/商品</th>
                    <th>現在在庫</th>
                    <th>不足数</th>
                    <th>作れる数</th>
                    <th>保管場所</th>
                    <th>購入先</th>
                    <th>単価</th>
                    <th>小計</th>
                    <th>登録元</th>
                </tr>
                <?php foreach ($rows as $row):
                    $requiredQty = (float)$row['qty_per_unit'] * (float)$row['bom_qty_per_product'];
                    $can = ($requiredQty > 0) ? floor($row['quantity'] / $requiredQty) : 0;
                    $bad = (float)$row['quantity'] < $requiredQty;
                    $subtotal = (float)$row['unit_price'] * $requiredQty;
                    $shortageQty = max(0, $requiredQty - (float)$row['quantity']);
                ?>
                <tr class="<?= $bad ? 'bad' : 'good' ?>">
                    <td><?= h(part_type_label($row['component_role'])) ?></td>
                    <td>
                        <?= h($row['bom_name']) ?>
                        <div class="small">× <?= h($row['bom_qty_per_product']) ?></div>
                    </td>
                    <td><?= h($row['part_code']) ?></td>
                    <td>
                        <?= h($row['name']) ?>
                        <?php if (!empty($row['reference_designators'])): ?>
                            <div class="small"><?= h($row['reference_designators']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($row['mpn']) ?></td>
                    <td><?= h($row['qty_per_unit']) ?></td>
                    <td><?= h($requiredQty) ?></td>
                    <td><?= h($row['quantity']) ?></td>
                    <td><?= h($shortageQty) ?></td>
                    <td><?= h($can) ?></td>
                    <td><?= h(implode(', ', $rowStorageMap[(int)$row['part_id']] ?? parse_storage_codes($row['location'] ?? ''))) ?></td>
                    <td>
                        <?php if (!empty($row['supplier_url'])): ?>
                            <a href="<?= h($row['supplier_url']) ?>" target="_blank">
                                <?= h($row['supplier'] !== '' ? $row['supplier'] : '購入先リンク') ?>
                            </a>
                        <?php else: ?>
                            <?= h($row['supplier']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="cost">¥<?= h(number_format((float)$row['unit_price'], 2)) ?></td>
                    <td class="cost">¥<?= h(number_format($subtotal, 2)) ?></td>
                    <td><?= h($row['source_type']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
</body>
</html>
