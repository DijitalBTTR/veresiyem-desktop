<?php
/**
 * Merkezi senkronizasyon — VPS önce, SQLite sonra.
 *
 * vpsYaz()  : önce VPS API'ye yazar, VPS id'sini döndürür (offline: null)
 * vpsCek()  : VPS'ten veri çekip SQLite'ı günceller (sayfa açılışında)
 * vpsSil()  : VPS'ten siler
 */

// ── VPS'e yaz, VPS id'sini döndür ────────────────────────────────────────────
function vpsYaz(string $method, string $path, array $data = []): ?array {
    $client = _getApiClient();
    if (!$client) return null;
    try {
        $res = match(strtolower($method)) {
            'post'   => $client->post($path, $data),
            'put'    => $client->put($path, $data),
            'delete' => $client->delete($path),
            default  => $client->get($path, $data),
        };
        return (!empty($res['ok'])) ? $res : null;
    } catch (Throwable $e) {
        return null;
    }
}

// ── VPS'ten sil ──────────────────────────────────────────────────────────────
function vpsSil(string $path): bool {
    $client = _getApiClient();
    if (!$client) return false;
    try {
        $res = $client->delete($path);
        return !empty($res['ok']);
    } catch (Throwable $e) {
        return false;
    }
}

// ── VPS'ten veri çek ─────────────────────────────────────────────────────────
function vpsCek(string $path, array $query = []): ?array {
    $client = _getApiClient();
    if (!$client) return null;
    try {
        $res = $client->get($path, $query);
        return (!empty($res['ok'])) ? $res : null;
    } catch (Throwable $e) {
        return null;
    }
}

// ── Carileri VPS'ten çekip SQLite'ı güncelle ─────────────────────────────────
function syncCariler(PDO $pdo, int $uid): void {
    $res = vpsCek('/cariler');
    if (!$res || !isset($res['data'])) return;

    $vpsIds = [];
    foreach ($res['data'] as $c) {
        $vpsIds[] = (int)$c['id'];
        $mevcut = $pdo->prepare("SELECT id FROM cariler WHERE api_id=? AND user_id=?");
        $mevcut->execute([$c['id'], $uid]);
        if ($mevcut->fetch()) {
            $pdo->prepare("UPDATE cariler SET ad=?,telefon=?,not_=?,varsayilan=?,musteri_kodu=?,il=?,ilce=?,adres=?,vergi_tc=?,vergi_dairesi=? WHERE api_id=? AND user_id=?")
                ->execute([$c['ad'],$c['telefon'],$c['not_'],$c['varsayilan']?1:0,$c['musteri_kodu'],$c['il'],$c['ilce'],$c['adres'],$c['vergi_tc'],$c['vergi_dairesi'],$c['id'],$uid]);
        } else {
            $pdo->prepare("INSERT OR IGNORE INTO cariler (user_id,ad,telefon,not_,varsayilan,musteri_kodu,il,ilce,adres,vergi_tc,vergi_dairesi,api_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$uid,$c['ad'],$c['telefon'],$c['not_'],$c['varsayilan']?1:0,$c['musteri_kodu'],$c['il'],$c['ilce'],$c['adres'],$c['vergi_tc'],$c['vergi_dairesi'],$c['id']]);
        }
    }

    // VPS'te olmayan yerel kayıtları temizle (api_id=NULL veya VPS'te silinmiş)
    if (!empty($vpsIds)) {
        $placeholders = implode(',', array_fill(0, count($vpsIds), '?'));
        $pdo->prepare("DELETE FROM cariler WHERE user_id=? AND (api_id IS NULL OR api_id NOT IN ($placeholders))")
            ->execute(array_merge([$uid], $vpsIds));
    } else {
        // VPS boşsa tüm yerel carileri sil
        $pdo->prepare("DELETE FROM cariler WHERE user_id=?")->execute([$uid]);
    }
}

// ── Hareketleri VPS'ten çekip SQLite'ı güncelle ──────────────────────────────
function syncHareketler(PDO $pdo, int $uid, int $cariApiId, int $cariLocalId): void {
    $res = vpsCek('/cariler/' . $cariApiId);
    if (!$res || !isset($res['hareketler'])) return;

    // Önce bu carinin tüm hareketlerini ve tahsilatlarını temizle
    $pdo->prepare("DELETE FROM tahsilatlar WHERE cari_id=?")->execute([$cariLocalId]);
    $pdo->prepare("DELETE FROM hareketler WHERE cari_id=?")->execute([$cariLocalId]);

    foreach ($res['hareketler'] as $h) {
        $pdo->prepare("INSERT INTO hareketler (cari_id,tip,tutar,aciklama,tarih,vade_tarihi,stok_id,stok_miktar,grup_id,api_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$cariLocalId,$h['tip'],$h['tutar'],$h['aciklama'],$h['tarih'],$h['vade_tarihi'] ?? null,$h['stok_id'] ?? null,$h['stok_miktar'] ?? null,$h['grup_id'] ?? null,$h['id']]);
        $localHId = $pdo->lastInsertId();

        // Tahsilatı varsa ekle
        if (!empty($h['tahsilat']) && $h['tahsilat'] > 0) {
            $pdo->prepare("INSERT OR IGNORE INTO tahsilatlar (cari_id,hareket_id,tutar,tarih,aciklama) VALUES (?,?,?,?,?)")
                ->execute([$cariLocalId, $localHId, $h['tahsilat'], $h['tarih'], 'VPS sync']);
        }
    }
}

// ── Stokları VPS'ten çekip SQLite'ı güncelle ─────────────────────────────────
function syncStoklar(PDO $pdo, int $uid): void {
    $res = vpsCek('/stok');
    if (!$res || !isset($res['data'])) return;

    $vpsIds = [];
    foreach ($res['data'] as $s) {
        $vpsIds[] = (int)$s['id'];
        $mevcut = $pdo->prepare("SELECT id FROM stoklar WHERE api_id=? AND user_id=?");
        $mevcut->execute([$s['id'], $uid]);
        if ($mevcut->fetch()) {
            $pdo->prepare("UPDATE stoklar SET ad=?,birim=?,miktar=?,fiyat=?,alis_fiyat=?,nakit_fiyat=?,vadeli_fiyat=?,aciklama=? WHERE api_id=? AND user_id=?")
                ->execute([$s['ad'],$s['birim'],$s['miktar'],$s['fiyat'],$s['alis_fiyat'],$s['nakit_fiyat'],$s['vadeli_fiyat'],$s['aciklama'],$s['id'],$uid]);
        } else {
            $pdo->prepare("INSERT OR IGNORE INTO stoklar (user_id,ad,birim,miktar,fiyat,alis_fiyat,nakit_fiyat,vadeli_fiyat,aciklama,api_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$uid,$s['ad'],$s['birim']??'adet',$s['miktar']??0,$s['fiyat'],$s['alis_fiyat'],$s['nakit_fiyat'],$s['vadeli_fiyat'],$s['aciklama'],$s['id']]);
        }
    }
    // VPS'te silinmiş stokları masaüstünden sil
    if (!empty($vpsIds)) {
        $placeholders = implode(',', array_fill(0, count($vpsIds), '?'));
        $pdo->prepare("DELETE FROM stoklar WHERE user_id=? AND (api_id IS NULL OR api_id NOT IN ($placeholders))")
            ->execute(array_merge([$uid], $vpsIds));
    } else {
        $pdo->prepare("DELETE FROM stoklar WHERE user_id=?")->execute([$uid]);
    }
}

// ── Kasa hesaplarını VPS'ten çekip SQLite'ı güncelle ─────────────────────────
function syncKasaHesaplar(PDO $pdo, int $uid): void {
    $res = vpsCek('/kasa/hesaplar');
    if (!$res || !isset($res['hesaplar'])) return;

    $vpsIds = [];
    foreach ($res['hesaplar'] as $h) {
        $vpsIds[] = (int)$h['id'];
        $mevcut = $pdo->prepare("SELECT id FROM kasa_hesaplari WHERE api_id=? AND user_id=?");
        $mevcut->execute([$h['id'], $uid]);
        if ($mevcut->fetch()) {
            $pdo->prepare("UPDATE kasa_hesaplari SET ad=?,aciklama=?,renk=?,aktif=?,varsayilan=? WHERE api_id=? AND user_id=?")
                ->execute([$h['ad'],$h['aciklama'],$h['renk']??'#3B82F6',$h['aktif']??1,$h['varsayilan']??0,$h['id'],$uid]);
        } else {
            $pdo->prepare("INSERT OR IGNORE INTO kasa_hesaplari (user_id,ad,aciklama,renk,aktif,varsayilan,api_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$uid,$h['ad'],$h['aciklama'],$h['renk']??'#3B82F6',$h['aktif']??1,$h['varsayilan']??0,$h['id']]);
        }
    }
    // VPS'te silinmiş hesapları masaüstünden sil (hareketi olmayanları)
    if (!empty($vpsIds)) {
        $placeholders = implode(',', array_fill(0, count($vpsIds), '?'));
        $pdo->prepare("DELETE FROM kasa_hesaplari WHERE user_id=? AND (api_id IS NULL OR api_id NOT IN ($placeholders)) AND id NOT IN (SELECT DISTINCT hesap_id FROM kasa WHERE hesap_id IS NOT NULL)")
            ->execute(array_merge([$uid], $vpsIds));
    }
}

// ── Ödemeleri VPS'ten çekip SQLite'ı güncelle ────────────────────────────────
function syncOdemeler(PDO $pdo, int $uid): void {
    $res = vpsCek('/odemeler');
    if (!$res || !isset($res['odemeler'])) return;

    $vpsIds = [];
    foreach ($res['odemeler'] as $o) {
        // VPS array ya da stdClass olabilir — her ikisini destekle
        $o = is_array($o) ? $o : (array)$o;
        $vpsIds[] = (int)$o['id'];
        // Hesap api_id → yerel id
        $hesapLocalId = null;
        if (!empty($o['hesap_id'])) {
            $hr = $pdo->prepare("SELECT id FROM kasa_hesaplari WHERE api_id=? AND user_id=?");
            $hr->execute([$o['hesap_id'], $uid]);
            $hr = $hr->fetch();
            $hesapLocalId = $hr['id'] ?? null;
        }
        $mevcut = $pdo->prepare("SELECT id FROM odemeler WHERE api_id=? AND user_id=?");
        $mevcut->execute([$o['id'], $uid]);
        if ($mevcut->fetch()) {
            $pdo->prepare("UPDATE odemeler SET baslik=?,aciklama=?,tutar=?,tarih=?,tekrar=?,durum=?,hesap_id=? WHERE api_id=? AND user_id=?")
                ->execute([$o['baslik'],$o['aciklama']??null,$o['tutar'],$o['tarih'],$o['tekrar']??'yok',$o['durum']??'bekliyor',$hesapLocalId,$o['id'],$uid]);
        } else {
            $pdo->prepare("INSERT OR IGNORE INTO odemeler (user_id,hesap_id,baslik,aciklama,tutar,tarih,tekrar,durum,api_id) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$uid,$hesapLocalId,$o['baslik'],$o['aciklama']??null,$o['tutar'],$o['tarih'],$o['tekrar']??'yok',$o['durum']??'bekliyor',$o['id']]);
        }
    }

    // VPS'te silinmiş ödemeleri yerel'den de sil
    if (!empty($vpsIds)) {
        $placeholders = implode(',', array_fill(0, count($vpsIds), '?'));
        $pdo->prepare("DELETE FROM odemeler WHERE user_id=? AND (api_id IS NULL OR api_id NOT IN ($placeholders))")
            ->execute(array_merge([$uid], $vpsIds));
    } else {
        $pdo->prepare("DELETE FROM odemeler WHERE user_id=?")->execute([$uid]);
    }
}

// ── Projeleri VPS'ten çekip SQLite'ı güncelle ────────────────────────────────
function syncProjeler(PDO $pdo, int $uid): void {
    $res = vpsCek('/projeler');
    if (!$res || !isset($res['projeler'])) return;

    // api_id kolonu yoksa ekle
    try { $pdo->exec("ALTER TABLE projeler ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}

    $vpsIds = [];
    foreach ($res['projeler'] as $p) {
        $p = is_array($p) ? $p : (array)$p;
        $vpsIds[] = (int)$p['id'];
        $mevcut = $pdo->prepare("SELECT id FROM projeler WHERE api_id=? AND user_id=?");
        $mevcut->execute([$p['id'], $uid]);
        if ($mevcut->fetch()) {
            $pdo->prepare("UPDATE projeler SET ad=?,musteri=?,aciklama=?,baslangic=?,bitis=?,durum=?,butce=?,harcanan=? WHERE api_id=? AND user_id=?")
                ->execute([$p['ad'],$p['musteri']??null,$p['aciklama']??null,$p['baslangic']??null,$p['bitis']??null,$p['durum']??'devam',$p['butce']??0,$p['harcanan']??0,$p['id'],$uid]);
        } else {
            $pdo->prepare("INSERT OR IGNORE INTO projeler (user_id,ad,musteri,aciklama,baslangic,bitis,durum,butce,harcanan,api_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$uid,$p['ad'],$p['musteri']??null,$p['aciklama']??null,$p['baslangic']??null,$p['bitis']??null,$p['durum']??'devam',$p['butce']??0,$p['harcanan']??0,$p['id']]);
        }
    }
    if (!empty($vpsIds)) {
        $pl = implode(',', array_fill(0, count($vpsIds), '?'));
        $pdo->prepare("DELETE FROM projeler WHERE user_id=? AND (api_id IS NULL OR api_id NOT IN ($pl))")
            ->execute(array_merge([$uid], $vpsIds));
    }
}

// ── Not klasörlerini VPS'ten çekip SQLite'ı güncelle ─────────────────────────
function syncNotlar(PDO $pdo, int $uid): void {
    $res = vpsCek('/not-klasorler');
    if (!$res || !isset($res['data'])) return;

    // api_id kolonu yoksa ekle
    try { $pdo->exec("ALTER TABLE not_klasorler ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}

    $vpsIds = [];
    foreach ($res['data'] as $k) {
        $k = is_array($k) ? $k : (array)$k;
        $vpsIds[] = (int)$k['id'];
        $mevcut = $pdo->prepare("SELECT id FROM not_klasorler WHERE api_id=? AND user_id=?");
        $mevcut->execute([$k['id'], $uid]);
        if ($mevcut->fetch()) {
            $pdo->prepare("UPDATE not_klasorler SET ad=?,renk=? WHERE api_id=? AND user_id=?")
                ->execute([$k['ad'],$k['renk']??'#3B82F6',$k['id'],$uid]);
        } else {
            $pdo->prepare("INSERT OR IGNORE INTO not_klasorler (user_id,ad,renk,api_id) VALUES (?,?,?,?)")
                ->execute([$uid,$k['ad'],$k['renk']??'#3B82F6',$k['id']]);
        }
    }
    if (!empty($vpsIds)) {
        $pl = implode(',', array_fill(0, count($vpsIds), '?'));
        $pdo->prepare("DELETE FROM not_klasorler WHERE user_id=? AND (api_id IS NULL OR api_id NOT IN ($pl))")
            ->execute(array_merge([$uid], $vpsIds));
    }
}

// ── Hatırlatıcıları VPS'ten çekip SQLite'ı güncelle ──────────────────────────
function syncHatirlaticilar(PDO $pdo, int $uid): void {
    $res = vpsCek('/hatirlaticilar');
    if (!$res || empty($res['data'])) return;
    foreach ($res['data'] as $h) {
        $mevcut = $pdo->prepare("SELECT id FROM hatirlaticilar WHERE api_id=? AND user_id=?");
        $mevcut->execute([$h['id'], $uid]);
        if ($mevcut->fetch()) {
            $pdo->prepare("UPDATE hatirlaticilar SET baslik=?,aciklama=?,tarih=?,saat=?,durum=?,tutar=?,odeme_tip=? WHERE api_id=? AND user_id=?")
                ->execute([$h['baslik'],$h['aciklama'],$h['tarih'],$h['saat'],$h['durum']??'aktif',$h['tutar'],$h['odeme_tip'],$h['id'],$uid]);
        } else {
            $pdo->prepare("INSERT OR IGNORE INTO hatirlaticilar (user_id,baslik,aciklama,tarih,saat,hatirlatma_dk,durum,tutar,odeme_tip,api_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$uid,$h['baslik'],$h['aciklama'],$h['tarih'],$h['saat'],$h['hatirlatma_dk']??0,$h['durum']??'aktif',$h['tutar'],$h['odeme_tip'],$h['id']]);
        }
    }
}

// ── Kasa hareketlerini VPS'ten çekip SQLite'ı güncelle ────────────────────────
function syncKasaHareketleri(PDO $pdo, int $uid): void {
    $res = vpsCek('/kasa/hareketler?limit=200');
    if (!$res || !isset($res['hareketler'])) return;

    // api_id kolonu yoksa ekle
    try { $pdo->exec("ALTER TABLE kasa ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}

    $vpsIds = [];
    foreach ($res['hareketler'] as $h) {
        $h = is_array($h) ? $h : (array)$h;
        $vpsIds[] = (int)$h['id'];

        // hesap_id: VPS api_id → yerel id
        $hesapLocalId = null;
        if (!empty($h['hesap_id'])) {
            $hr = $pdo->prepare("SELECT id FROM kasa_hesaplari WHERE api_id=? AND user_id=?");
            $hr->execute([$h['hesap_id'], $uid]);
            $hr = $hr->fetch();
            $hesapLocalId = $hr['id'] ?? null;
        }

        $mevcut = $pdo->prepare("SELECT id FROM kasa WHERE api_id=? AND user_id=?");
        $mevcut->execute([$h['id'], $uid]);
        if ($mevcut->fetch()) {
            $pdo->prepare("UPDATE kasa SET hesap_id=?,tip=?,tutar=?,aciklama=?,kaynak=?,kaynak_id=?,tarih=? WHERE api_id=? AND user_id=?")
                ->execute([$hesapLocalId,$h['tip'],$h['tutar'],$h['aciklama']??null,$h['kaynak']??'manuel',$h['kaynak_id']??null,$h['tarih'],$h['id'],$uid]);
        } else {
            $pdo->prepare("INSERT OR IGNORE INTO kasa (user_id,hesap_id,tip,tutar,aciklama,kaynak,kaynak_id,tarih,api_id) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$uid,$hesapLocalId,$h['tip'],$h['tutar'],$h['aciklama']??null,$h['kaynak']??'manuel',$h['kaynak_id']??null,$h['tarih'],$h['id']]);
        }
    }

    // VPS'te olmayan manuel hareketleri yerel'den sil (kaynak='manuel' olanlar)
    if (!empty($vpsIds)) {
        $placeholders = implode(',', array_fill(0, count($vpsIds), '?'));
        $pdo->prepare("DELETE FROM kasa WHERE user_id=? AND kaynak='manuel' AND (api_id IS NULL OR api_id NOT IN ($placeholders))")
            ->execute(array_merge([$uid], $vpsIds));
    }
}
