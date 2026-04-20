<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_storage_schema($pdo);

$id = (int)($_GET['id'] ?? 0);

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
if (!$storageCodes) {
    $storageCodes = parse_storage_codes($part['location'] ?? '');
}
$storageDisplay = $storageCodes ? implode(', ', $storageCodes) : '-';

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

function part_value(array $part, string $key): string {
    return trim((string)($part[$key] ?? ''));
}

function display_value(array $part, string $key): string {
    $value = part_value($part, $key);
    return $value === '' ? '-' : $value;
}

$fields = [
    '基本情報' => [
        'id' => 'ID',
        'part_code' => '部品コード',
        'name' => '部品名',
        'part_type' => '種別',
        'location' => '保管場所',
    ],
    '型番・分類' => [
        'manufacturer' => 'メーカー',
        'mpn' => 'MPN',
        'supplier_part_number' => '仕入先品番',
        'category' => 'カテゴリ',
        'subcategory' => 'サブカテゴリ',
        'tags' => 'タグ',
        'footprint' => 'フットプリント',
    ],
    '在庫・購入' => [
        'quantity' => '在庫数',
        'minimum_stock' => '最低在庫',
        'unit_price' => '単価',
        'supplier' => '仕入先',
        'supplier_url' => '仕入先URL',
    ],
    'メモ' => [
        'note' => 'メモ',
    ],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>部品詳細: <?= h($part['name'] ?? '') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --bg: #f5f7fb;
    --card: #fff;
    --border: #d9e1ec;
    --text: #223;
    --sub: #667085;
    --primary: #2d6cdf;
    --primary-hover: #1f57c5;
    --warn-bg: #fff8e6;
    --warn-border: #f2d28b;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, "Noto Sans CJK JP", "Noto Sans JP", sans-serif;
    background: var(--bg);
    color: var(--text);
}

.wrapper {
    max-width: 980px;
    margin: 0 auto;
    padding: 24px;
}

.top-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 18px;
}

a.btn {
    display: inline-block;
    padding: 9px 13px;
    border-radius: 8px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    font-size: 13px;
    font-weight: bold;
}

a.btn:hover {
    background: var(--primary-hover);
}

.hero,
.section {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 16px;
}

.part-name {
    margin: 0 0 8px;
    font-size: 28px;
}

.meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    color: var(--sub);
    font-size: 14px;
}

.stock-warning {
    margin-top: 14px;
    padding: 12px;
    border: 1px solid var(--warn-border);
    border-radius: 10px;
    background: var(--warn-bg);
    font-weight: bold;
}

.section-title {
    margin: 0 0 12px;
    font-size: 18px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 12px;
}

.item {
    border-bottom: 1px solid #edf0f5;
    padding-bottom: 10px;
    min-width: 0;
}

.label {
    color: var(--sub);
    font-size: 12px;
    margin-bottom: 4px;
}

.value {
    font-size: 15px;
    font-weight: bold;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.note-value {
    font-weight: normal;
    line-height: 1.6;
}

@media (max-width: 640px) {
    .wrapper {
        padding: 16px;
    }

    .part-name {
        font-size: 24px;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <div class="top-actions">
        <a class="btn" href="index.php">← ダッシュボード</a>
        <a class="btn" href="parts.php">← 部品一覧</a>
        <a class="btn" href="part_form.php?id=<?= h($part['id'] ?? '') ?>">編集</a>
        <a class="btn" href="part_qr.php?id=<?= h($part['id'] ?? '') ?>">QRラベル</a>
    </div>

    <div class="hero">
        <h1 class="part-name"><?= h($part['name'] ?? '') ?></h1>
        <div class="meta">
            <span>部品コード: <?= h(display_value($part, 'part_code')) ?></span>
            <span>MPN: <?= h(display_value($part, 'mpn')) ?></span>
            <span>保管場所: <?= h($storageDisplay) ?></span>
        </div>
        <?php if ((int)($part['quantity'] ?? 0) <= (int)($part['minimum_stock'] ?? 0)): ?>
            <div class="stock-warning">
                在庫が最低在庫以下です。在庫: <?= h($part['quantity'] ?? 0) ?> / 最低在庫: <?= h($part['minimum_stock'] ?? 0) ?>
            </div>
        <?php endif; ?>
    </div>

    <?php foreach ($fields as $sectionTitle => $sectionFields): ?>
        <div class="section">
            <h2 class="section-title"><?= h($sectionTitle) ?></h2>
            <div class="detail-grid">
                <?php foreach ($sectionFields as $key => $label): ?>
                    <?php if (!array_key_exists($key, $part)) continue; ?>
                    <?php
                        if ($key === 'part_type') {
                            $value = part_type_label($part[$key] ?? '');
                        } elseif ($key === 'location') {
                            $value = $storageDisplay;
                        } else {
                            $value = display_value($part, $key);
                        }
                    ?>
                    <div class="item">
                        <div class="label"><?= h($label) ?></div>
                        <div class="value <?= $key === 'note' ? 'note-value' : '' ?>">
                            <?php if ($key === 'supplier_url' && part_value($part, $key) !== ''): ?>
                                <a href="<?= h($part[$key]) ?>" target="_blank"><?= h($part[$key]) ?></a>
                            <?php else: ?>
                                <?= h($value) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
