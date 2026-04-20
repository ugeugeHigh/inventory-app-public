<?php
require_once __DIR__ . '/config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errorMessage = '';

$product = [
    'name' => '',
    'switch_science_sku' => '',
    'xian_diy_id' => '',
    'note' => ''
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$id]);
    $loaded = $stmt->fetch();
    if (!$loaded) {
        die('製品が見つかりません');
    }
    $product = array_merge($product, $loaded);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product['name'] = trim($_POST['name'] ?? '');
    $product['switch_science_sku'] = trim($_POST['switch_science_sku'] ?? '');
    $product['xian_diy_id'] = trim($_POST['xian_diy_id'] ?? '');
    $product['note'] = trim($_POST['note'] ?? '');

    if ($product['name'] === '') {
        $errorMessage = '製品名は必須です。';
    }

    if ($errorMessage === '') {
        if ($isEdit) {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? AND id <> ?");
            $stmt->execute([$product['name'], $id]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ?");
            $stmt->execute([$product['name']]);
        }

        if ($stmt->fetch()) {
            $errorMessage = '同じ製品名が既に登録されています。';
        }
    }

    if ($errorMessage === '' && $product['switch_science_sku'] !== '') {
        if ($isEdit) {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE switch_science_sku = ? AND id <> ?");
            $stmt->execute([$product['switch_science_sku'], $id]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE switch_science_sku = ?");
            $stmt->execute([$product['switch_science_sku']]);
        }

        if ($stmt->fetch()) {
            $errorMessage = '同じSwitch Science SKDが既に登録されています。';
        }
    }

    if ($errorMessage === '' && $product['xian_diy_id'] !== '') {
        if ($isEdit) {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE xian_diy_id = ? AND id <> ?");
            $stmt->execute([$product['xian_diy_id'], $id]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE xian_diy_id = ?");
            $stmt->execute([$product['xian_diy_id']]);
        }

        if ($stmt->fetch()) {
            $errorMessage = '同じXianDIY IDが既に登録されています。';
        }
    }

    if ($errorMessage === '') {
        try {
            if ($isEdit) {
                $pdo->prepare("
                    UPDATE products
                    SET name=?, switch_science_sku=?, xian_diy_id=?, note=?
                    WHERE id=?
                ")->execute([
                    $product['name'],
                    $product['switch_science_sku'] !== '' ? $product['switch_science_sku'] : null,
                    $product['xian_diy_id'] !== '' ? $product['xian_diy_id'] : null,
                    $product['note'],
                    $id
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO products (name, switch_science_sku, xian_diy_id, note)
                    VALUES (?,?,?,?)
                ")->execute([
                    $product['name'],
                    $product['switch_science_sku'] !== '' ? $product['switch_science_sku'] : null,
                    $product['xian_diy_id'] !== '' ? $product['xian_diy_id'] : null,
                    $product['note']
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            redirect_to("product_detail.php?id={$id}");
        } catch (Throwable $e) {
            $errorMessage = '保存中にエラーが発生しました: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title><?= $isEdit ? '製品編集' : '製品追加' ?></title>
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
    --muted-bg: #f8fafc;
    --focus: rgba(45, 108, 223, 0.16);
    --error-bg: #fff0f0;
    --error-border: #f0b0b0;
    --error-text: #9b1c1c;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, "Noto Sans CJK JP", "Noto Sans JP", sans-serif;
    background: var(--bg);
    color: var(--text);
}

.wrapper {
    max-width: 1040px;
    margin: 0 auto;
    padding: 24px;
}

.top-link {
    display: inline-block;
    margin-bottom: 18px;
    color: var(--primary);
    text-decoration: none;
}

.top-link:hover {
    text-decoration: underline;
}

.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 18px;
}

.page-title {
    margin: 0 0 8px 0;
    font-size: 28px;
}

.page-subtitle {
    margin: 0;
    color: var(--sub);
    font-size: 14px;
    line-height: 1.6;
}

.status-pill {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 7px 11px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: #fff;
    color: var(--sub);
    font-size: 13px;
    font-weight: bold;
}

.form-card,
.side-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(20, 40, 80, 0.05);
}

.layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 18px;
    align-items: start;
}

.form-card {
    padding: 22px;
}

.side-card {
    padding: 18px;
}

.section-title {
    margin: 0 0 16px;
    font-size: 18px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.field {
    min-width: 0;
}

.field.full {
    grid-column: 1 / -1;
}

label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-weight: bold;
    font-size: 14px;
}

.required {
    display: inline-flex;
    align-items: center;
    min-height: 20px;
    padding: 2px 7px;
    border-radius: 999px;
    background: #eef4ff;
    color: #1f3b75;
    font-size: 11px;
}

input,
textarea {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
}

textarea {
    min-height: 150px;
    resize: vertical;
}

input:focus,
textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px var(--focus);
}

.note {
    color: var(--sub);
    font-size: 12px;
    margin-top: 6px;
    line-height: 1.5;
}

.form-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid var(--border);
}

a.btn,
button {
    display: inline-block;
    padding: 10px 14px;
    border-radius: 10px;
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

a.btn.secondary {
    background: #eef4ff;
    color: #1f3b75;
}

a.btn.secondary:hover {
    background: #dfeaff;
}

.error-box {
    margin-bottom: 18px;
    padding: 14px 16px;
    border: 1px solid var(--error-border);
    background: var(--error-bg);
    color: var(--error-text);
    border-radius: 12px;
    font-size: 14px;
}

.help-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.help-list li {
    padding: 12px 0;
    border-bottom: 1px solid #edf0f5;
}

.help-list li:last-child {
    border-bottom: 0;
}

.help-title {
    margin-bottom: 4px;
    font-size: 13px;
    font-weight: bold;
}

.help-text {
    color: var(--sub);
    font-size: 12px;
    line-height: 1.6;
}

@media (max-width: 860px) {
    .layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .wrapper {
        padding: 16px;
    }

    .page-header {
        display: block;
    }

    .status-pill {
        margin-top: 12px;
    }

    .form-card {
        padding: 18px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-actions {
        justify-content: stretch;
    }

    .form-actions a,
    .form-actions button {
        width: 100%;
        text-align: center;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>
    <a class="top-link" href="products.php">← 製品一覧</a>

    <div class="page-header">
        <div>
            <h1 class="page-title"><?= $isEdit ? '製品編集' : '製品追加' ?></h1>
            <p class="page-subtitle">
                製品名、販売SKU、XianDIY IDを登録して、BOMや在庫確認の起点にします。
            </p>
        </div>
        <div class="status-pill"><?= $isEdit ? '製品ID: ' . h($id) : '新規登録' ?></div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="error-box"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <div class="layout">
        <form method="post" class="form-card">
            <h2 class="section-title">製品情報</h2>

            <div class="form-grid">
                <div class="field full">
                    <label for="name">
                        製品名
                        <span class="required">必須</span>
                    </label>
                    <input
                        id="name"
                        type="text"
                        name="name"
                        value="<?= h($product['name']) ?>"
                        required
                        autocomplete="off"
                        placeholder="例: USBasp Programmer Kit"
                    >
                    <div class="note">同じ製品名は重複登録できません。</div>
                </div>

                <div class="field">
                    <label for="switch_science_sku">Switch Science SKD</label>
                    <input
                        id="switch_science_sku"
                        type="text"
                        name="switch_science_sku"
                        value="<?= h($product['switch_science_sku']) ?>"
                        autocomplete="off"
                        placeholder="例: 123456"
                    >
                    <div class="note">空欄可。入力した場合は重複不可です。</div>
                </div>

                <div class="field">
                    <label for="xian_diy_id">XianDIY ID</label>
                    <input
                        id="xian_diy_id"
                        type="text"
                        name="xian_diy_id"
                        value="<?= h($product['xian_diy_id']) ?>"
                        autocomplete="off"
                        placeholder="例: XD-USBASP-001"
                    >
                    <div class="note">空欄可。入力した場合は重複不可です。</div>
                </div>

                <div class="field full">
                    <label for="note">備考</label>
                    <textarea
                        id="note"
                        name="note"
                        placeholder="製造メモ、販売ページの補足、リビジョン情報など"
                    ><?= h($product['note']) ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <?php if ($isEdit): ?>
                    <a class="btn secondary" href="product_detail.php?id=<?= h($id) ?>">詳細へ戻る</a>
                <?php else: ?>
                    <a class="btn secondary" href="products.php">キャンセル</a>
                <?php endif; ?>
                <button type="submit"><?= $isEdit ? '更新する' : '登録する' ?></button>
            </div>
        </form>

        <aside class="side-card">
            <h2 class="section-title">登録後の流れ</h2>
            <ul class="help-list">
                <li>
                    <div class="help-title">1. 製品を保存</div>
                    <div class="help-text">製品IDが作成され、詳細画面へ移動します。</div>
                </li>
                <li>
                    <div class="help-title">2. BOMを登録</div>
                    <div class="help-text">KiCad BOM取込または構成編集から必要部品を紐付けます。</div>
                </li>
                <li>
                    <div class="help-title">3. 在庫を確認</div>
                    <div class="help-text">製品詳細で作れる数、不足部品、概算原価を確認できます。</div>
                </li>
            </ul>
        </aside>
    </div>
</div>
</body>
</html>
