<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /cariler');
    exit;
}

csrfKontrol();

$id  = (int)($_POST['id'] ?? 0);
$uid = userId();

if ($id) {
    $cariRow = $pdo->prepare("SELECT api_id FROM cariler WHERE id=? AND user_id=?");
    $cariRow->execute([$id, $uid]);
    $cariRow = $cariRow->fetch();
    $stmt = $pdo->prepare("DELETE FROM cariler WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $uid]);
    if ($stmt->rowCount() && !empty($cariRow['api_id'])) {
        apiSync('delete', '/cariler/' . $cariRow['api_id']);
    }
}

header('Location: /cariler');
exit;
