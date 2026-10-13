<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
girisGerekli();
$uid = userId();
$id  = (int)($_POST['id'] ?? 0);
if ($id) {
    require_once 'includes/sync.php';
    $row = $pdo->prepare("SELECT api_id FROM hatirlaticilar WHERE id=? AND user_id=?");
    $row->execute([$id, $uid]);
    $row = $row->fetch();
    $stmt = $pdo->prepare("DELETE FROM hatirlaticilar WHERE id=? AND user_id=?");
    $stmt->execute([$id, $uid]);
    if ($stmt->rowCount() && !empty($row['api_id'])) vpsSil('/hatirlaticilar/' . $row['api_id']);
}
$filtre = $_SERVER['HTTP_REFERER'] ?? '/hatirlaticilar';
header('Location: /hatirlaticilar');
exit;
