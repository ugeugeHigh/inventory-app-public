<?php
require_once __DIR__ . '/config.php';

$partsCount = $pdo->query("SELECT COUNT(*) FROM parts")->fetchColumn();
$productsCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$lowStockCount = $pdo->query("SELECT COUNT(*) FROM parts WHERE quantity <= minimum_stock")->fetchColumn();
$boardCount = $pdo->query("SELECT COUNT(*) FROM parts WHERE part_type='board'")->fetchColumn();
$locatedCount = $pdo->query("
    SELECT COUNT(DISTINCT p.id)
    FROM parts p
    LEFT JOIN part_storage_locations psl ON psl.part_id = p.id
    WHERE (p.location IS NOT NULL AND p.location <> '') OR psl.id IS NOT NULL
")->fetchColumn();

$sections = [
    '部品管理' => [
        ['href' => 'parts.php', 'title' => '部品一覧', 'desc' => '検索、編集、在庫確認、QRラベル表示'],
        ['href' => 'part_form.php', 'title' => '部品追加', 'desc' => '分類、タグ、保管場所つきで登録'],
        ['href' => 'storage_locations.php', 'title' => '保管場所一覧', 'desc' => '部品箱、小部品箱、チャック袋の中身を確認'],
    ],
    '製品・BOM' => [
        ['href' => 'products.php', 'title' => '製品一覧', 'desc' => '製品詳細、構成編集、作れる数の確認'],
        ['href' => 'product_form.php', 'title' => '製品追加', 'desc' => '新しい商品、SKU、XianDIY IDを登録'],
        ['href' => 'bom_import.php', 'title' => 'KiCad BOM取込', 'desc' => 'MPNで基板BOMを製品へ登録'],
        ['href' => 'unmatched_mpn.php', 'title' => '未一致MPN一覧', 'desc' => 'BOM未一致MPNを既存部品へ紐付け'],
    ],
    '印刷・出荷' => [
        ['href' => 'product_lot_labels.php', 'title' => '出荷ラベル', 'desc' => 'Lot ID、Lot QR、manual QRつきラベルを作成'],
    ],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>Xian DIY 部品管理システム v2</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --bg: #f5f7fb;
    --card: #fff;
    --border: #d9e1ec;
    --text: #223;
    --sub: #667085;
    --primary: #2d6cdf;
    --primary-soft: #eef4ff;
    --warn: #d97706;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, "Noto Sans CJK JP", "Noto Sans JP", sans-serif;
    background: var(--bg);
    color: var(--text);
}

.wrapper {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 24px 40px;
}

.hero {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 18px;
}

.eyebrow {
    margin-bottom: 8px;
    color: var(--primary);
    font-size: 13px;
    font-weight: bold;
}

h1 {
    margin: 0;
    font-size: 32px;
    letter-spacing: 0;
}

.subtitle {
    margin-top: 10px;
    color: var(--sub);
    font-size: 14px;
    line-height: 1.7;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin: 18px 0 24px;
}

.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
}

.stat-label {
    color: var(--sub);
    font-size: 12px;
    font-weight: bold;
}

.stat-value {
    margin-top: 8px;
    font-size: 30px;
    font-weight: bold;
}

.stat-card.warn .stat-value {
    color: var(--warn);
}

.section {
    margin-top: 22px;
}

.section-title {
    margin: 0 0 12px;
    font-size: 20px;
}

.links {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 12px;
}

.link-card {
    display: block;
    min-height: 112px;
    padding: 18px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
    color: var(--text);
    text-decoration: none;
    transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease;
}

.link-card:hover {
    transform: translateY(-1px);
    border-color: #a9c4f5;
    box-shadow: 0 8px 22px rgba(45, 108, 223, 0.10);
}

.link-title {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
    font-size: 17px;
    font-weight: bold;
}

.link-title::after {
    content: ">";
    color: var(--primary);
    font-size: 15px;
}

.link-desc {
    margin-top: 10px;
    color: var(--sub);
    font-size: 13px;
    line-height: 1.6;
}

.quick-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.quick-btn {
    display: inline-block;
    padding: 10px 13px;
    border-radius: 10px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    font-size: 13px;
    font-weight: bold;
}

.quick-btn.secondary {
    background: #64748b;
}

@media (max-width: 720px) {
    .wrapper {
        padding: 18px 14px 32px;
    }

    .hero {
        padding: 18px;
    }

    h1 {
        font-size: 26px;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <section class="hero">
        <div class="eyebrow">Xian DIY Inventory</div>
        <h1>部品/BOM管理システム v2</h1>
        <div class="subtitle">
            部品の保管場所、QRラベル、製品BOM、出荷ロットをまとめて管理します。
            まずは部品一覧や製品一覧から、日々の登録・在庫確認・印刷へ進めます。
        </div>
        <div class="quick-row">
            <a class="quick-btn" href="part_form.php">部品を追加</a>
            <a class="quick-btn" href="product_form.php">製品を追加</a>
            <a class="quick-btn secondary" href="storage_locations.php">保管場所を見る</a>
        </div>
    </section>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-label">登録部品数</div>
            <div class="stat-value"><?= h($partsCount) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">登録製品数</div>
            <div class="stat-value"><?= h($productsCount) ?></div>
        </div>
        <div class="stat-card warn">
            <div class="stat-label">在庫不足</div>
            <div class="stat-value"><?= h($lowStockCount) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">登録基板数</div>
            <div class="stat-value"><?= h($boardCount) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">保管場所設定済み</div>
            <div class="stat-value"><?= h($locatedCount) ?></div>
        </div>
    </section>

    <?php foreach ($sections as $sectionTitle => $links): ?>
        <section class="section">
            <h2 class="section-title"><?= h($sectionTitle) ?></h2>
            <div class="links">
                <?php foreach ($links as $link): ?>
                    <a class="link-card" href="<?= h($link['href']) ?>">
                        <div class="link-title"><?= h($link['title']) ?></div>
                        <div class="link-desc"><?= h($link['desc']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
</body>
</html>
