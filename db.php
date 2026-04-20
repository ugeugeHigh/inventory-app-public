<?php
require_once __DIR__ . '/config.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function yen($v) {
    if ($v === null || $v === '') return '';
    return '¥' . number_format((float)$v, 0);
}

function getProduct($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
?>
