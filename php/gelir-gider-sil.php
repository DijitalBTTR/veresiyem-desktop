<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM gelir_gider WHERE id=? AND user_id=?");
$st->execute([$id, $uid]);
$kayit = $st->fetch();

if ($kayit) {
    // Bağlı kasa hareketini geri al
    if ($kayit['kasa_id'] && $kayit['hesap_id']) {
        $geriTip = $kayit['tip'] === 'gelir' ? 'cikis' : 'giris';
        if ($geriTip === 'giris') {
            $pdo->prepare("UPDATE kasa_hesaplari SET bakiye = bakiye + ? WHERE id=? AND user_id=?")->execute([$kayit['tutar'], $kayit['hesap_id'], $uid]);
        } else {
            $pdo->prepare("UPDATE kasa_hesaplari SET bakiye = bakiye - ? WHERE id=? AND user_id=?")->execute([$kayit['tutar'], $kayit['hesap_id'], $uid]);
        }
        $pdo->prepare("DELETE FROM kasa WHERE id=? AND user_id=?")->execute([$kayit['kasa_id'], $uid]);
    }

    $pdo->prepare("DELETE FROM gelir_gider WHERE id=? AND user_id=?")->execute([$id, $uid]);
}

header('Location: /gelir-gider');
exit;
