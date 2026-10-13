<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
girisGerekli();
$uid   = userId();
$id    = (int)($_POST['id'] ?? 0);
$durum = $_POST['durum'] ?? '';
if ($id && in_array($durum, ['aktif', 'tamamlandi', 'sessiz'])) {
    $pdo->prepare("UPDATE hatirlaticilar SET durum=? WHERE id=? AND user_id=?")->execute([$durum, $id, $uid]);
    apiSync('put', '/hatirlaticilar/' . $id, ['durum' => $durum]);
}
header('Location: /hatirlaticilar');
exit;
