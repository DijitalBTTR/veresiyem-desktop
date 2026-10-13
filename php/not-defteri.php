<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sync.php';
girisGerekli();
$activePage = 'not-defteri';
$uid = userId();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    syncNotlar($pdo, $uid);
}

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'klasor-ekle') {
        $ad  = trim($_POST['ad'] ?? '');
        $renk = trim($_POST['renk'] ?? '#3B82F6');
        $not_bilgi = trim($_POST['not_bilgi'] ?? '');
        if (!$ad) { echo json_encode(['error' => 'Ad zorunlu']); exit; }
        $pdo->prepare("INSERT INTO not_klasorler (user_id,ad,renk,not_bilgi) VALUES (?,?,?,?)")
            ->execute([$uid, $ad, $renk, $not_bilgi ?: null]);
        apiSync('post', '/not-klasorler', ['ad' => $ad, 'renk' => $renk, 'not_bilgi' => $not_bilgi ?: null]);
        echo json_encode(['id' => $pdo->lastInsertId(), 'ad' => $ad, 'renk' => $renk, 'not_bilgi' => $not_bilgi]);
        exit;
    }

    if ($action === 'klasor-sil') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM not_klasorler WHERE id=? AND user_id=?");
        $stmt->execute([$id, $uid]);
        if ($stmt->rowCount()) apiSync('delete', '/not-klasorler/' . $id);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'klasor-duzenle') {
        $id  = (int)($_POST['id'] ?? 0);
        $ad  = trim($_POST['ad'] ?? '');
        $renk = trim($_POST['renk'] ?? '#3B82F6');
        $not_bilgi = trim($_POST['not_bilgi'] ?? '');
        $pdo->prepare("UPDATE not_klasorler SET ad=?, renk=?, not_bilgi=? WHERE id=? AND user_id=?")
            ->execute([$ad, $renk, $not_bilgi ?: null, $id, $uid]);
        apiSync('put', '/not-klasorler/' . $id, ['ad' => $ad, 'renk' => $renk, 'not_bilgi' => $not_bilgi ?: null]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'satir-ekle') {
        $kid   = (int)($_POST['klasor_id'] ?? 0);
        $urun  = trim($_POST['urun_adi'] ?? '');
        $birim = trim($_POST['birim'] ?? 'adet');
        $fiyat = (float)str_replace(',', '.', $_POST['fiyat'] ?? '0');
        $adet  = (float)str_replace(',', '.', $_POST['adet'] ?? '1');
        $not   = trim($_POST['not_'] ?? '');
        $chk = $pdo->prepare("SELECT id FROM not_klasorler WHERE id=? AND user_id=?");
        $chk->execute([$kid, $uid]);
        if (!$chk->fetch()) { echo json_encode(['error' => 'Yetkisiz']); exit; }
        $pdo->prepare("INSERT INTO not_satirlar (klasor_id,urun_adi,birim,fiyat,adet,not_) VALUES (?,?,?,?,?,?)")
            ->execute([$kid, $urun, $birim, $fiyat, $adet, $not ?: null]);
        apiSync('post', '/not-satirlar', ['klasor_id' => $kid, 'urun_adi' => $urun, 'birim' => $birim, 'fiyat' => $fiyat, 'adet' => $adet, 'not_' => $not ?: null]);
        echo json_encode(['id' => $pdo->lastInsertId(), 'urun_adi' => $urun, 'birim' => $birim, 'fiyat' => $fiyat, 'adet' => $adet, 'not_' => $not]);
        exit;
    }

    if ($action === 'satir-guncelle') {
        $sid   = (int)($_POST['id'] ?? 0);
        $urun  = trim($_POST['urun_adi'] ?? '');
        $birim = trim($_POST['birim'] ?? 'adet');
        $fiyat = (float)str_replace(',', '.', $_POST['fiyat'] ?? '0');
        $adet  = (float)str_replace(',', '.', $_POST['adet'] ?? '1');
        $not   = trim($_POST['not_'] ?? '');
        $pdo->prepare("UPDATE not_satirlar SET urun_adi=?, birim=?, fiyat=?, adet=?, not_=?
            WHERE id=? AND klasor_id IN (SELECT id FROM not_klasorler WHERE user_id=?)")
            ->execute([$urun, $birim, $fiyat, $adet, $not ?: null, $sid, $uid]);
        apiSync('put', '/not-satirlar/' . $sid, ['urun_adi' => $urun, 'birim' => $birim, 'fiyat' => $fiyat, 'adet' => $adet, 'not_' => $not ?: null]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'satir-sil') {
        $sid = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM not_satirlar WHERE id=? AND klasor_id IN (SELECT id FROM not_klasorler WHERE user_id=?)");
        $stmt->execute([$sid, $uid]);
        if ($stmt->rowCount()) apiSync('delete', '/not-satirlar/' . $sid);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'odeme-guncelle') {
        $kid   = (int)($_POST['id'] ?? 0);
        $tutar = (float)str_replace(',', '.', $_POST['tutar'] ?? '0');
        $pdo->prepare("UPDATE not_klasorler SET odeme_toplam=? WHERE id=? AND user_id=?")
            ->execute([$tutar, $kid, $uid]);
        echo json_encode(['ok' => true, 'odeme_toplam' => $tutar]); exit;
    }

    echo json_encode(['error' => 'Bilinmeyen işlem']); exit;
}

// ── VERİ ÇEK ─────────────────────────────────────────────────
$klasorler = $pdo->prepare("SELECT *, COALESCE(odeme_toplam,0) AS odeme_toplam FROM not_klasorler WHERE user_id=? ORDER BY sira ASC, created_at ASC");
$klasorler->execute([$uid]);
$klasorler = $klasorler->fetchAll();

$satirlar = [];
if ($klasorler) {
    $ids = implode(',', array_column($klasorler, 'id'));
    $s = $pdo->query("SELECT * FROM not_satirlar WHERE klasor_id IN ($ids) ORDER BY sira ASC, created_at ASC");
    foreach ($s->fetchAll() as $row) $satirlar[$row['klasor_id']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Not Defteri — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.nd-klasor { background:var(--surface);border:1px solid var(--border);border-radius:var(--r);margin-bottom:1rem;overflow:hidden; }
.nd-klasor-head { display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;cursor:pointer;user-select:none;border-bottom:1px solid var(--border);background:var(--surface2); }
.nd-klasor-renk { width:12px;height:12px;border-radius:3px;flex-shrink:0; }
.nd-klasor-ad { font-weight:700;font-size:.9rem;flex:1; }
.nd-klasor-toplam { font-size:.82rem;font-weight:700;color:var(--accent);white-space:nowrap; }
.nd-klasor-toggle { color:var(--ink3);transition:transform .2s; }
.nd-klasor.kapali .nd-klasor-toggle { transform:rotate(-90deg); }
.nd-klasor.kapali .nd-klasor-body { display:none; }
.nd-klasor-body { padding:.5rem 1rem 1rem; }
.nd-firma-not { font-size:.78rem;color:var(--ink3);padding:.3rem .5rem .5rem;border-bottom:1px solid var(--border);margin-bottom:.5rem;white-space:pre-wrap; }
.nd-tablo { width:100%;border-collapse:collapse;font-size:.84rem; }
.nd-tablo th { text-align:left;font-size:.7rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.04em;padding:.35rem .5rem;border-bottom:2px solid var(--border); }
.nd-tablo td { padding:.4rem .5rem;border-bottom:1px solid var(--border);vertical-align:middle; }
.nd-tablo tr:last-child td { border-bottom:none; }
.nd-tablo tr:hover td { background:var(--surface2); }
.nd-tablo .col-urun { min-width:120px; }
.nd-tablo .col-birim { width:60px;color:var(--ink3);font-size:.78rem; }
.nd-tablo .col-fiyat,.nd-tablo .col-adet { width:90px;text-align:right; }
.nd-tablo .col-toplam { width:100px;text-align:right;font-weight:700;color:var(--accent); }
.nd-tablo .col-not { color:var(--ink3);font-size:.78rem; }
.nd-tablo .col-islem { width:60px;text-align:right; }
.nd-satir-islem { display:flex;gap:.2rem;justify-content:flex-end;opacity:0;transition:.1s; }
.nd-tablo tr:hover .nd-satir-islem { opacity:1; }
.nd-alt-toplam { display:flex;justify-content:flex-end;align-items:center;gap:.5rem;padding:.6rem .5rem 0;border-top:2px solid var(--border);margin-top:.25rem;font-size:.85rem; }
.nd-ekle-form { display:none;background:var(--surface2);border-radius:var(--r);padding:.75rem;margin-top:.75rem;border:1px dashed var(--border2); }
.nd-ekle-form.acik { display:block; }
.nd-ekle-grid { display:grid;grid-template-columns:1fr 80px 80px 80px 1fr;gap:.4rem;align-items:end; }
.nd-stok-wrap { position:relative; }
.nd-stok-dropdown { position:absolute;top:100%;left:0;right:0;z-index:200;background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:200px;overflow-y:auto;display:none; }
.nd-stok-dropdown.acik { display:block; }
.nd-stok-item { padding:.45rem .65rem;cursor:pointer;font-size:.82rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem; }
.nd-stok-item:hover { background:var(--surface2); }
.nd-stok-item-ad { flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.nd-stok-item-fiyat { font-size:.75rem;color:var(--accent);font-weight:700;white-space:nowrap; }
.nd-stok-item-stok { font-size:.7rem;color:var(--ink3);white-space:nowrap; }
.nd-bos { text-align:center;color:var(--ink3);font-size:.83rem;padding:1.2rem 0; }
.renk-sec { display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.35rem; }
.renk-opt { width:22px;height:22px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:.12s; }
.renk-opt.secili { border-color:var(--ink);transform:scale(1.15); }
.nd-genel-toplam { background:var(--surface2);border:1px solid var(--border);border-radius:var(--r);padding:.75rem 1.25rem;display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem; }

/* Print */
.print-area { display:none; }
@media print {
  @page { margin:10mm; }
  body > * { display:none !important; }
  .print-area { display:block !important; }
  .print-firma-baslik { font-size:14pt;font-weight:900;margin-bottom:2mm; }
  .print-firma-not { font-size:8pt;color:#555;margin-bottom:3mm; }
  .print-tarih { font-size:8pt;color:#888;margin-bottom:5mm; }
  .print-tablo { width:100%;border-collapse:collapse;font-size:9pt; }
  .print-tablo th { border-bottom:2px solid #000;padding:2mm 1mm;text-align:left;font-size:8pt; }
  .print-tablo td { border-bottom:1px solid #ddd;padding:1.5mm 1mm; }
  .print-tablo .r { text-align:right; }
  .print-tablo .bold { font-weight:700; }
  .print-toplam-row td { border-top:2px solid #000;border-bottom:none;font-weight:700;padding-top:2mm; }
  .print-ayrac { border:none;border-top:1px dashed #bbb;margin:8mm 0; }
}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <h1 class="page-title">Not Defteri</h1>
        <div class="page-sub">Firma klasörleri oluşturun, ürün ve fiyat notları tutun</div>
      </div>
      <div class="page-header-right" style="display:flex;gap:.5rem">
        <button class="btn btn-secondary" onclick="tumunuYazdir()" id="tumYazdirBtn" <?= empty($klasorler) ? 'disabled' : '' ?>>
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Tümünü Yazdır
        </button>
        <button class="btn btn-primary" onclick="klasorEkleModal()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Klasör
        </button>
      </div>
    </div>

    <!-- Genel toplam -->
    <div class="nd-genel-toplam">
      <span style="font-size:.82rem;color:var(--ink3);font-weight:600">Tüm Klasörler Toplamı</span>
      <span style="font-size:1.2rem;font-weight:800;color:var(--accent)" id="genelToplamTutar">₺0,00</span>
    </div>

    <!-- Klasörler -->
    <div id="klasorListesi">
      <?php if (empty($klasorler)): ?>
      <div style="text-align:center;padding:3rem 1rem;color:var(--ink3)">
        <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24" style="opacity:.25;margin-bottom:.75rem;display:block;margin-left:auto;margin-right:auto"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        <div style="font-size:.9rem">Henüz klasör yok</div>
        <div style="font-size:.8rem;margin-top:.3rem">"Yeni Klasör" butonuyla firma ekleyin</div>
      </div>
      <?php else: ?>
      <?php foreach ($klasorler as $k): ?>
      <?php
        $kSatirlar  = $satirlar[$k['id']] ?? [];
        $kToplam    = array_sum(array_map(fn($s) => $s['fiyat'] * $s['adet'], $kSatirlar));
        $kOdeme     = (float)($k['odeme_toplam'] ?? 0);
        $kKalan     = max(0, $kToplam - $kOdeme);
      ?>
      <div class="nd-klasor" id="klasor-<?= $k['id'] ?>">
        <div class="nd-klasor-head" onclick="klasorToggle(<?= $k['id'] ?>)">
          <div class="nd-klasor-renk" style="background:<?= htmlspecialchars($k['renk']) ?>"></div>
          <div class="nd-klasor-ad"><?= htmlspecialchars($k['ad']) ?></div>
          <div class="nd-klasor-toplam" id="ktoplam-<?= $k['id'] ?>">₺<?= number_format($kToplam,2,',','.') ?><?php if($kOdeme>0): ?> <span style="font-size:.7rem;color:<?= $kKalan<=0?'var(--green)':'var(--red)' ?>;font-weight:600"><?= $kKalan<=0 ? '✓ Ödendi' : 'Kalan: ₺'.number_format($kKalan,2,',','.') ?></span><?php endif; ?></div>
          <button class="btn-icon" onclick="event.stopPropagation();klasorYazdir(<?= $k['id'] ?>)" title="Yazdır" style="opacity:.6">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          </button>
          <button class="btn-icon"
            title="Düzenle" style="opacity:.6"
            data-kid="<?= $k['id'] ?>"
            data-ad="<?= htmlspecialchars($k['ad'], ENT_QUOTES) ?>"
            data-renk="<?= htmlspecialchars($k['renk'], ENT_QUOTES) ?>"
            data-not="<?= htmlspecialchars($k['not_bilgi'] ?? '', ENT_QUOTES) ?>"
            onclick="event.stopPropagation();klasorDuzenleBtnClick(this)">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <button class="btn-icon" onclick="event.stopPropagation();klasorSil(<?= $k['id'] ?>)" title="Sil" style="color:var(--red);opacity:.6">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
          </button>
          <svg class="nd-klasor-toggle" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <?php if (!empty($k['not_bilgi'])): ?>
        <div class="nd-firma-not" id="firma-not-<?= $k['id'] ?>">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:3px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= nl2br(htmlspecialchars($k['not_bilgi'])) ?>
        </div>
        <?php else: ?>
        <div class="nd-firma-not" id="firma-not-<?= $k['id'] ?>" style="display:none"></div>
        <?php endif; ?>
        <div class="nd-klasor-body">
          <table class="nd-tablo">
            <thead>
              <tr>
                <th class="col-urun">Ürün / Açıklama</th>
                <th class="col-birim">Birim</th>
                <th class="col-fiyat">Birim Fiyat</th>
                <th class="col-adet">Adet</th>
                <th class="col-toplam">Toplam</th>
                <th class="col-not">Not</th>
                <th class="col-islem"></th>
              </tr>
            </thead>
            <tbody id="satirlar-<?= $k['id'] ?>">
              <?php if (empty($kSatirlar)): ?>
              <tr id="bos-<?= $k['id'] ?>"><td colspan="7" class="nd-bos">Henüz ürün eklenmedi</td></tr>
              <?php else: ?>
              <?php foreach ($kSatirlar as $s): ?>
              <tr id="satir-<?= $s['id'] ?>">
                <td class="col-urun"><?= htmlspecialchars($s['urun_adi']) ?></td>
                <td class="col-birim"><?= htmlspecialchars($s['birim'] ?? 'adet') ?></td>
                <td class="col-fiyat">₺<?= number_format($s['fiyat'],2,',','.') ?></td>
                <td class="col-adet"><?= number_format($s['adet'],2,',','.') ?></td>
                <td class="col-toplam">₺<?= number_format($s['fiyat']*$s['adet'],2,',','.') ?></td>
                <td class="col-not"><?= htmlspecialchars($s['not_'] ?? '') ?></td>
                <td class="col-islem">
                  <div class="nd-satir-islem">
                    <button class="btn-icon" title="Düzenle"
                      data-sid="<?= $s['id'] ?>" data-kid="<?= $k['id'] ?>"
                      data-urun="<?= htmlspecialchars($s['urun_adi'], ENT_QUOTES) ?>"
                      data-birim="<?= htmlspecialchars($s['birim'] ?? 'adet', ENT_QUOTES) ?>"
                      data-fiyat="<?= (float)$s['fiyat'] ?>"
                      data-adet="<?= (float)$s['adet'] ?>"
                      data-not="<?= htmlspecialchars($s['not_'] ?? '', ENT_QUOTES) ?>"
                      onclick="satirDuzenleBtnClick(this)">
                      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="btn-icon" title="Sil" style="color:var(--red)" onclick="satirSil(<?= $s['id'] ?>,<?= $k['id'] ?>)">
                      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>

          <div class="nd-alt-toplam">
            <span style="font-size:.78rem;color:var(--ink3);font-weight:600" id="satir-sayisi-<?= $k['id'] ?>"><?= count($kSatirlar) ?> ürün — Toplam:</span>
            <span style="font-size:1rem;font-weight:800;color:var(--accent)" id="alt-toplam-<?= $k['id'] ?>">₺<?= number_format($kToplam,2,',','.') ?></span>
            <button class="btn btn-sm" style="background:var(--green-lt);color:var(--green);border:1px solid var(--green);font-size:.75rem;padding:.25rem .65rem" onclick="odemeModal(<?= $k['id'] ?>)">
              <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              Ödeme Al
            </button>
          </div>
          <div id="odeme-bilgi-<?= $k['id'] ?>" style="<?= $kOdeme>0?'':'display:none;' ?>font-size:.75rem;color:var(--ink3);text-align:right;padding:.3rem .5rem 0">
            Ödenen: <span id="odeme-toplam-<?= $k['id'] ?>" style="color:var(--green);font-weight:700">₺<?= number_format($kOdeme,2,',','.') ?></span>
            &nbsp;|&nbsp; Kalan: <span id="odeme-kalan-<?= $k['id'] ?>" style="color:<?= $kKalan<=0?'var(--green)':'var(--red)' ?>;font-weight:700">₺<?= number_format($kKalan,2,',','.') ?></span>
          </div>

          <!-- Ürün ekle formu -->
          <div id="ekle-form-<?= $k['id'] ?>" class="nd-ekle-form">
            <div class="nd-ekle-grid">
              <div class="nd-stok-wrap">
                <label class="form-label" style="font-size:.7rem">Ürün / Açıklama *</label>
                <input type="text" class="form-input" id="yeni-urun-<?= $k['id'] ?>" placeholder="Stok adı yaz veya seç..." style="font-size:.83rem" autocomplete="off"
                  oninput="stokAra(this,<?= $k['id'] ?>)"
                  onkeydown="stokAraTus(event,<?= $k['id'] ?>)"
                  onblur="setTimeout(function(){stokDropKapat(<?= $k['id'] ?>)},200)">
                <div class="nd-stok-dropdown" id="stok-drop-<?= $k['id'] ?>"></div>
              </div>
              <div>
                <label class="form-label" style="font-size:.7rem">Birim</label>
                <select class="form-input form-select" id="yeni-birim-<?= $k['id'] ?>" style="font-size:.83rem">
                  <option value="adet">adet</option>
                  <option value="kutu">kutu</option>
                  <option value="şişe">şişe</option>
                  <option value="ampul">ampul</option>
                  <option value="tablet">tablet</option>
                  <option value="kapsül">kapsül</option>
                  <option value="tüp">tüp</option>
                  <option value="flakon">flakon</option>
                  <option value="kg">kg</option>
                  <option value="lt">lt</option>
                </select>
              </div>
              <div>
                <label class="form-label" style="font-size:.7rem">Birim Fiyat (₺)</label>
                <input type="number" class="form-input" id="yeni-fiyat-<?= $k['id'] ?>" placeholder="0,00" min="0" step="0.01" style="font-size:.83rem">
              </div>
              <div>
                <label class="form-label" style="font-size:.7rem">Adet</label>
                <input type="number" class="form-input" id="yeni-adet-<?= $k['id'] ?>" value="1" min="0.01" step="0.01" style="font-size:.83rem">
              </div>
              <div>
                <label class="form-label" style="font-size:.7rem">Not (opsiyonel)</label>
                <input type="text" class="form-input" id="yeni-not-<?= $k['id'] ?>" placeholder="Açıklama..." style="font-size:.83rem">
              </div>
            </div>
            <div style="display:flex;gap:.4rem;margin-top:.6rem">
              <button class="btn btn-primary btn-sm" onclick="satirKaydet(<?= $k['id'] ?>)">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Ekle
              </button>
              <button class="btn btn-ghost btn-sm" onclick="ekleFormKapat(<?= $k['id'] ?>)">İptal</button>
            </div>
          </div>

          <button class="btn btn-ghost btn-sm" style="margin-top:.65rem;font-size:.78rem" onclick="ekleFormAc(<?= $k['id'] ?>)">
            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Ürün Ekle
          </button>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Print alanı -->
<div class="print-area" id="printArea"></div>

<!-- Klasör Modal -->
<div class="vrm-overlay" id="klasorModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="vrm-dialog" style="max-width:420px">
    <div style="padding:1rem 1.5rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div style="font-weight:800;font-size:.95rem" id="klasorModalBaslik">Yeni Klasör</div>
      <button class="btn-icon" onclick="document.getElementById('klasorModal').classList.remove('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="padding:1.25rem 1.5rem">
      <input type="hidden" id="modal-klasor-id">
      <div class="form-group">
        <label class="form-label">Firma / Klasör Adı *</label>
        <input type="text" id="modal-klasor-ad" class="form-input" placeholder="Örn: Pfizer, Abdi İbrahim...">
      </div>
      <div class="form-group">
        <label class="form-label">Firma Notu <span style="color:var(--ink3);font-weight:400">(telefon, temsilci, adres...)</span></label>
        <textarea id="modal-klasor-not" class="form-input" rows="2" placeholder="Örn: Temsilci: Ahmet Bey — 0532 xxx xx xx" style="resize:vertical;font-size:.83rem"></textarea>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Renk</label>
        <div class="renk-sec" id="renkSec">
          <?php foreach (['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#06B6D4','#84CC16','#F97316','#6B7280'] as $r): ?>
          <div class="renk-opt <?= $r==='#3B82F6'?'secili':'' ?>" style="background:<?= $r ?>" data-renk="<?= $r ?>" onclick="renkSec(this)"></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="margin-top:1rem;display:flex;gap:.5rem">
        <button class="btn btn-primary" style="flex:1;justify-content:center" onclick="klasorKaydet()">Kaydet</button>
        <button class="btn btn-ghost" onclick="document.getElementById('klasorModal').classList.remove('open')">İptal</button>
      </div>
    </div>
  </div>
</div>

<!-- Satır Düzenle Modal -->
<div class="vrm-overlay" id="satirModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="vrm-dialog" style="max-width:440px">
    <div style="padding:1rem 1.5rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div style="font-weight:800;font-size:.95rem">Ürün Düzenle</div>
      <button class="btn-icon" onclick="document.getElementById('satirModal').classList.remove('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="padding:1.25rem 1.5rem">
      <input type="hidden" id="edit-satir-id">
      <input type="hidden" id="edit-klasor-id">
      <div class="form-group">
        <label class="form-label">Ürün / Açıklama *</label>
        <input type="text" id="edit-urun" class="form-input">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
        <div class="form-group">
          <label class="form-label">Birim</label>
          <select id="edit-birim" class="form-input form-select" style="font-size:.83rem">
            <option value="adet">adet</option>
            <option value="kutu">kutu</option>
            <option value="şişe">şişe</option>
            <option value="ampul">ampul</option>
            <option value="tablet">tablet</option>
            <option value="kapsül">kapsül</option>
            <option value="tüp">tüp</option>
            <option value="flakon">flakon</option>
            <option value="kg">kg</option>
            <option value="lt">lt</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Birim Fiyat (₺)</label>
          <input type="number" id="edit-fiyat" class="form-input" step="0.01" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Adet</label>
          <input type="number" id="edit-adet" class="form-input" step="0.01" min="0.01">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Not (opsiyonel)</label>
        <input type="text" id="edit-not" class="form-input" placeholder="Açıklama...">
      </div>
      <div style="margin-top:1rem;display:flex;gap:.5rem">
        <button class="btn btn-primary" style="flex:1;justify-content:center" onclick="satirGuncelle()">Güncelle</button>
        <button class="btn btn-ghost" onclick="document.getElementById('satirModal').classList.remove('open')">İptal</button>
      </div>
    </div>
  </div>
</div>

<!-- Ödeme Modal -->
<div class="vrm-overlay" id="odemeModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="vrm-dialog" style="max-width:380px">
    <div style="padding:1rem 1.5rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div style="font-weight:800;font-size:.95rem">Ödeme Al</div>
      <button class="btn-icon" onclick="document.getElementById('odemeModal').classList.remove('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="padding:1.25rem 1.5rem">
      <input type="hidden" id="odeme-kid">
      <div style="background:var(--surface2);border-radius:var(--r);padding:.75rem 1rem;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-size:.72rem;color:var(--ink3)">Klasör Toplamı</div>
          <div style="font-size:1.1rem;font-weight:800;color:var(--accent)" id="odeme-modal-toplam">₺0,00</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:.72rem;color:var(--ink3)">Kalan Borç</div>
          <div style="font-size:1.1rem;font-weight:800;color:var(--red)" id="odeme-modal-kalan">₺0,00</div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Alınan Ödeme (₺)</label>
        <input type="number" id="odeme-miktar" class="form-input" placeholder="0,00" min="0.01" step="0.01" style="font-size:1rem;font-weight:700" oninput="odemeHesapla()">
      </div>
      <div id="odeme-hesap" style="display:none;padding:.6rem .75rem;border-radius:var(--r);margin-bottom:.75rem;font-size:.83rem">
        <div style="display:flex;justify-content:space-between">
          <span>Alınan:</span><span id="odeme-alinan-str" style="font-weight:700;color:var(--green)"></span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:.3rem">
          <span>Yeni Kalan:</span><span id="odeme-yeni-kalan" style="font-weight:700;color:var(--red)"></span>
        </div>
      </div>
      <div style="display:flex;gap:.5rem">
        <button class="btn btn-primary" style="flex:1;justify-content:center" onclick="odemeKaydet()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          Kaydet
        </button>
        <button class="btn btn-ghost" onclick="document.getElementById('odemeModal').classList.remove('open')">İptal</button>
      </div>
    </div>
  </div>
</div>

<script>
var _csrf = <?= json_encode(csrf()) ?>;
var _klasorData = <?= json_encode(array_column($klasorler, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
var _satirData  = <?= json_encode($satirlar, JSON_UNESCAPED_UNICODE) ?>;

function ndPost(data) {
  var fd = new FormData();
  fd.append('csrf', _csrf);
  for (var k in data) fd.append(k, data[k]);
  return fetch('/not-defteri', { method:'POST', body:fd }).then(function(r){ return r.json(); });
}
function fmtPara(v) { return '₺' + parseFloat(v||0).toFixed(2).replace('.',','); }
function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── ÖDEME ───────────────────────────────────────────────────
function _klasorSatirToplam(kid) {
  var t = 0;
  document.querySelectorAll('#satirlar-' + kid + ' [id^="satir-"]').forEach(function(tr) {
    var c = tr.querySelectorAll('td');
    t += c[4] ? (parseFloat(c[4].textContent.replace('₺','').replace(/\./g,'').replace(',','.')) || 0) : 0;
  });
  return t;
}

function odemeModal(kid) {
  var toplam = _klasorSatirToplam(kid);
  var odenen = parseFloat((_klasorData[kid] && _klasorData[kid].odeme_toplam) || 0);
  var kalan  = Math.max(0, toplam - odenen);
  document.getElementById('odeme-kid').value = kid;
  document.getElementById('odeme-modal-toplam').textContent = fmtPara(toplam);
  document.getElementById('odeme-modal-kalan').textContent  = fmtPara(kalan);
  document.getElementById('odeme-miktar').value = kalan > 0 ? kalan.toFixed(2) : '';
  odemeHesapla();
  document.getElementById('odemeModal').classList.add('open');
  setTimeout(function(){ document.getElementById('odeme-miktar').select(); }, 100);
}

function odemeHesapla() {
  var kid    = document.getElementById('odeme-kid').value;
  var miktar = parseFloat(document.getElementById('odeme-miktar').value) || 0;
  var toplam = _klasorSatirToplam(kid);
  var odenen = parseFloat((_klasorData[kid] && _klasorData[kid].odeme_toplam) || 0);
  var kalan  = Math.max(0, toplam - odenen);
  var yeniKalan = Math.max(0, kalan - miktar);
  var hesapEl = document.getElementById('odeme-hesap');
  if (miktar > 0) {
    document.getElementById('odeme-alinan-str').textContent = fmtPara(miktar);
    document.getElementById('odeme-yeni-kalan').textContent = fmtPara(yeniKalan);
    hesapEl.style.display = 'block';
    hesapEl.style.background = yeniKalan <= 0 ? 'var(--green-lt)' : 'var(--surface2)';
  } else {
    hesapEl.style.display = 'none';
  }
}

function odemeKaydet() {
  var kid    = document.getElementById('odeme-kid').value;
  var miktar = parseFloat(document.getElementById('odeme-miktar').value) || 0;
  if (miktar <= 0) { vrmToast('Geçerli bir tutar girin', 'warn'); return; }

  var eskiOdeme = parseFloat((_klasorData[kid] && _klasorData[kid].odeme_toplam) || 0);
  var yeniOdeme = eskiOdeme + miktar;
  var toplam    = _klasorSatirToplam(kid);
  var yeniKalan = Math.max(0, toplam - yeniOdeme);

  ndPost({ action:'odeme-guncelle', id:kid, tutar:yeniOdeme }).then(function(d) {
    if (d.error) { vrmToast(d.error, 'error'); return; }
    if (_klasorData[kid]) _klasorData[kid].odeme_toplam = yeniOdeme;

    var bilgiEl    = document.getElementById('odeme-bilgi-' + kid);
    var toplamOdEl = document.getElementById('odeme-toplam-' + kid);
    var kalanEl    = document.getElementById('odeme-kalan-'  + kid);
    if (bilgiEl)    bilgiEl.style.display = 'block';
    if (toplamOdEl) toplamOdEl.textContent = fmtPara(yeniOdeme);
    if (kalanEl) {
      kalanEl.textContent = fmtPara(yeniKalan);
      kalanEl.style.color = yeniKalan <= 0 ? 'var(--green)' : 'var(--red)';
    }
    var kToplamEl = document.getElementById('ktoplam-' + kid);
    if (kToplamEl) {
      kToplamEl.innerHTML = fmtPara(toplam)
        + (yeniKalan > 0
          ? ' <span style="font-size:.7rem;color:var(--red);font-weight:600">Kalan: ' + fmtPara(yeniKalan) + '</span>'
          : ' <span style="font-size:.7rem;color:var(--green);font-weight:600">&#10003; Ödendi</span>');
    }
    document.getElementById('odemeModal').classList.remove('open');
    vrmToast(yeniKalan <= 0 ? 'Tamami odendi!' : 'Odeme kaydedildi — Kalan: ' + fmtPara(yeniKalan), 'success');
  });
}

// ── GENEL TOPLAM ────────────────────────────────────────────
function genelToplamGuncelle() {
  var toplam = 0;
  Object.keys(_klasorData).forEach(function(kid) {
    var satirToplam = _klasorSatirToplam(parseInt(kid));
    var odeme = parseFloat((_klasorData[kid] && _klasorData[kid].odeme_toplam) || 0);
    toplam += Math.max(0, satirToplam - odeme);
  });
  document.getElementById('genelToplamTutar').textContent = fmtPara(toplam);
}

// ── KLASÖR TOGGLE ───────────────────────────────────────────
function klasorToggle(id) {
  document.getElementById('klasor-' + id).classList.toggle('kapali');
}

// ── RENK SEÇ ────────────────────────────────────────────────
var _secilenRenk = '#3B82F6';
function renkSec(el) {
  document.querySelectorAll('.renk-opt').forEach(function(r){ r.classList.remove('secili'); });
  el.classList.add('secili');
  _secilenRenk = el.dataset.renk;
}

// ── KLASÖR MODAL ────────────────────────────────────────────
function klasorEkleModal() {
  document.getElementById('modal-klasor-id').value  = '';
  document.getElementById('modal-klasor-ad').value  = '';
  document.getElementById('modal-klasor-not').value = '';
  document.getElementById('klasorModalBaslik').textContent = 'Yeni Klasör';
  _secilenRenk = '#3B82F6';
  document.querySelectorAll('.renk-opt').forEach(function(r){ r.classList.toggle('secili', r.dataset.renk === _secilenRenk); });
  document.getElementById('klasorModal').classList.add('open');
  setTimeout(function(){ document.getElementById('modal-klasor-ad').focus(); }, 100);
}

function klasorDuzenleBtnClick(btn) {
  klasorDuzenleModal(btn.dataset.kid, btn.dataset.ad, btn.dataset.renk, btn.dataset.not);
}
function klasorDuzenleModal(id, ad, renk, notBilgi) {
  document.getElementById('modal-klasor-id').value  = id;
  document.getElementById('modal-klasor-ad').value  = ad;
  document.getElementById('modal-klasor-not').value = notBilgi || '';
  document.getElementById('klasorModalBaslik').textContent = 'Klasörü Düzenle';
  _secilenRenk = renk;
  document.querySelectorAll('.renk-opt').forEach(function(r){ r.classList.toggle('secili', r.dataset.renk === renk); });
  document.getElementById('klasorModal').classList.add('open');
  setTimeout(function(){ document.getElementById('modal-klasor-ad').focus(); }, 100);
}

function klasorKaydet() {
  var id  = document.getElementById('modal-klasor-id').value;
  var ad  = document.getElementById('modal-klasor-ad').value.trim();
  var not_ = document.getElementById('modal-klasor-not').value.trim();
  if (!ad) { vrmToast('Klasör adı zorunlu', 'warn'); return; }

  if (id) {
    ndPost({ action:'klasor-duzenle', id:id, ad:ad, renk:_secilenRenk, not_bilgi:not_ }).then(function() {
      var el = document.getElementById('klasor-' + id);
      el.querySelector('.nd-klasor-ad').textContent = ad;
      el.querySelector('.nd-klasor-renk').style.background = _secilenRenk;
      var notEl = document.getElementById('firma-not-' + id);
      if (notEl) {
        if (not_) {
          notEl.innerHTML = '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:3px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' + escHtml(not_).replace(/\n/g,'<br>');
          notEl.style.display = '';
        } else {
          notEl.innerHTML = '';
          notEl.style.display = 'none';
        }
      }
      if (_klasorData[id]) { _klasorData[id].ad = ad; _klasorData[id].renk = _secilenRenk; _klasorData[id].not_bilgi = not_; }
      document.getElementById('klasorModal').classList.remove('open');
      vrmToast('Güncellendi', 'success');
    });
  } else {
    ndPost({ action:'klasor-ekle', ad:ad, renk:_secilenRenk, not_bilgi:not_ }).then(function(d) {
      if (d.error) { vrmToast(d.error, 'error'); return; }
      document.getElementById('klasorModal').classList.remove('open');
      location.reload();
    });
  }
}

function klasorSil(id) {
  var onay = function() {
    ndPost({ action:'klasor-sil', id:id }).then(function() {
      var el = document.getElementById('klasor-' + id);
      if (el) el.remove();
      genelToplamGuncelle();
      vrmToast('Klasör silindi', 'success');
    });
  };
  if (window.veresiyemConfirm) {
    window.veresiyemConfirm({ title:'Klasörü Sil', msg:'Klasörü ve içindeki tüm ürünleri silmek istediğinize emin misiniz?', okLabel:'Evet, Sil', type:'red' }).then(function(ok){ if(ok) onay(); });
  } else onay();
}

// ── STOK ARAMA ──────────────────────────────────────────────
var _stokTimer = null;
var _stokAktifKid = null;
var _stokSecIdx = -1;

function stokAra(input, kid) {
  var q = input.value.trim();
  var drop = document.getElementById('stok-drop-' + kid);
  _stokAktifKid = kid;
  _stokSecIdx = -1;
  clearTimeout(_stokTimer);
  if (q.length < 1) { drop.innerHTML=''; drop.classList.remove('acik'); return; }
  _stokTimer = setTimeout(function() {
    fetch('/stok-json?q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (!data.length) { drop.innerHTML=''; drop.classList.remove('acik'); return; }
        drop.innerHTML = data.slice(0,10).map(function(s, i) {
          var fiyatStr = s.fiyat ? '₺' + parseFloat(s.fiyat).toFixed(2).replace('.',',') : '';
          var stokStr  = 'Stok: ' + parseFloat(s.miktar||0).toFixed(0) + ' ' + (s.birim||'adet');
          return '<div class="nd-stok-item" data-idx="' + i + '" data-ad="' + escHtml(s.ad) + '" data-fiyat="' + (s.fiyat||0) + '" data-birim="' + escHtml(s.birim||'adet') + '" data-miktar="' + (s.miktar||0) + '" onmousedown="stokSec(this,' + kid + ')">'
            + '<span class="nd-stok-item-ad">' + escHtml(s.ad) + '</span>'
            + '<span class="nd-stok-item-stok">' + stokStr + '</span>'
            + (fiyatStr ? '<span class="nd-stok-item-fiyat">' + fiyatStr + '</span>' : '')
            + '</div>';
        }).join('');
        drop.classList.add('acik');
      });
  }, 200);
}

function stokSec(el, kid) {
  document.getElementById('yeni-urun-'  + kid).value  = el.dataset.ad;
  document.getElementById('yeni-birim-' + kid).value  = el.dataset.birim;
  if (el.dataset.fiyat && parseFloat(el.dataset.fiyat) > 0)
    document.getElementById('yeni-fiyat-' + kid).value = parseFloat(el.dataset.fiyat).toFixed(2);
  stokDropKapat(kid);
  setTimeout(function(){ document.getElementById('yeni-adet-' + kid).focus(); }, 50);
}

function stokDropKapat(kid) {
  var drop = document.getElementById('stok-drop-' + kid);
  if (drop) { drop.innerHTML=''; drop.classList.remove('acik'); }
  _stokSecIdx = -1;
}

function stokAraTus(e, kid) {
  var drop = document.getElementById('stok-drop-' + kid);
  var items = drop ? drop.querySelectorAll('.nd-stok-item') : [];
  if (!items.length) return;
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    _stokSecIdx = Math.min(_stokSecIdx + 1, items.length - 1);
    items.forEach(function(it,i){ it.style.background = i===_stokSecIdx ? 'var(--surface2)' : ''; });
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    _stokSecIdx = Math.max(_stokSecIdx - 1, 0);
    items.forEach(function(it,i){ it.style.background = i===_stokSecIdx ? 'var(--surface2)' : ''; });
  } else if (e.key === 'Enter' && _stokSecIdx >= 0) {
    e.preventDefault();
    stokSec(items[_stokSecIdx], kid);
  } else if (e.key === 'Escape') {
    stokDropKapat(kid);
  }
}

// ── ÜRÜN EKLE ───────────────────────────────────────────────
function ekleFormAc(kid) {
  document.getElementById('ekle-form-' + kid).classList.add('acik');
  setTimeout(function(){ document.getElementById('yeni-urun-' + kid).focus(); }, 50);
}
function ekleFormKapat(kid) {
  document.getElementById('ekle-form-' + kid).classList.remove('acik');
  ['yeni-urun-','yeni-fiyat-','yeni-not-'].forEach(function(p){ var el=document.getElementById(p+kid); if(el) el.value=''; });
  var aEl = document.getElementById('yeni-adet-' + kid); if(aEl) aEl.value = '1';
  stokDropKapat(kid);
}

function satirKaydet(kid) {
  var urun  = document.getElementById('yeni-urun-'  + kid).value.trim();
  var birim = document.getElementById('yeni-birim-' + kid).value;
  var fiyat = document.getElementById('yeni-fiyat-' + kid).value || '0';
  var adet  = document.getElementById('yeni-adet-'  + kid).value || '1';
  var not_  = document.getElementById('yeni-not-'   + kid).value.trim();
  if (!urun) { vrmToast('Ürün adı zorunlu', 'warn'); return; }

  ndPost({ action:'satir-ekle', klasor_id:kid, urun_adi:urun, birim:birim, fiyat:fiyat, adet:adet, not_:not_ })
    .then(function(d) {
      if (d.error) { vrmToast(d.error,'error'); return; }
      var tbody = document.getElementById('satirlar-' + kid);
      var bos = document.getElementById('bos-' + kid); if(bos) bos.remove();
      var f = parseFloat(fiyat.replace(',','.')); var a = parseFloat(adet.replace(',','.'));
      var tr = document.createElement('tr');
      tr.id = 'satir-' + d.id;
      tr.innerHTML = '<td class="col-urun">' + escHtml(urun) + '</td>'
        + '<td class="col-birim">' + escHtml(birim) + '</td>'
        + '<td class="col-fiyat">₺' + f.toFixed(2).replace('.',',') + '</td>'
        + '<td class="col-adet">' + a.toFixed(2).replace('.',',') + '</td>'
        + '<td class="col-toplam">₺' + (f*a).toFixed(2).replace('.',',') + '</td>'
        + '<td class="col-not">' + escHtml(not_) + '</td>'
        + '<td class="col-islem"><div class="nd-satir-islem">'
        + '<button class="btn-icon" title="D\xfczenle" data-sid="' + d.id + '" data-kid="' + kid + '" data-urun="' + escHtml(urun) + '" data-birim="' + escHtml(birim) + '" data-fiyat="' + f + '" data-adet="' + a + '" data-not="' + escHtml(not_) + '" onclick="satirDuzenleBtnClick(this)">'
        + '<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
        + '<button class="btn-icon" title="Sil" style="color:var(--red)" onclick="satirSil(' + d.id + ',' + kid + ')">'
        + '<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>'
        + '</div></td>';
      tbody.appendChild(tr);
      if (!_satirData[kid]) _satirData[kid] = [];
      _satirData[kid].push({ id:d.id, urun_adi:urun, birim:birim, fiyat:f, adet:a, not_:not_ });
      klasorToplamGuncelle(kid);
      ekleFormKapat(kid);
      vrmToast('Ürün eklendi', 'success');
    });
}

// ── SATIR DÜZENLE ───────────────────────────────────────────
function satirDuzenleBtnClick(btn) {
  satirDuzenle(btn.dataset.sid, btn.dataset.kid, btn.dataset.urun, btn.dataset.birim, parseFloat(btn.dataset.fiyat), parseFloat(btn.dataset.adet), btn.dataset.not);
}
function satirDuzenle(sid, kid, urun, birim, fiyat, adet, not_) {
  document.getElementById('edit-satir-id').value = sid;
  document.getElementById('edit-klasor-id').value = kid;
  document.getElementById('edit-urun').value  = urun;
  document.getElementById('edit-birim').value = birim;
  document.getElementById('edit-fiyat').value = fiyat;
  document.getElementById('edit-adet').value  = adet;
  document.getElementById('edit-not').value   = not_ || '';
  document.getElementById('satirModal').classList.add('open');
  setTimeout(function(){ document.getElementById('edit-urun').focus(); }, 100);
}

function satirGuncelle() {
  var sid   = document.getElementById('edit-satir-id').value;
  var kid   = document.getElementById('edit-klasor-id').value;
  var urun  = document.getElementById('edit-urun').value.trim();
  var birim = document.getElementById('edit-birim').value;
  var fiyat = parseFloat(document.getElementById('edit-fiyat').value) || 0;
  var adet  = parseFloat(document.getElementById('edit-adet').value)  || 1;
  var not_  = document.getElementById('edit-not').value.trim();
  if (!urun) { vrmToast('Ürün adı zorunlu', 'warn'); return; }

  ndPost({ action:'satir-guncelle', id:sid, urun_adi:urun, birim:birim, fiyat:fiyat, adet:adet, not_:not_ })
    .then(function(d) {
      if (d.error) { vrmToast(d.error,'error'); return; }
      var tr = document.getElementById('satir-' + sid);
      if (tr) {
        var cells = tr.querySelectorAll('td');
        cells[0].textContent = urun;
        cells[1].textContent = birim;
        cells[2].textContent = '₺' + fiyat.toFixed(2).replace('.',',');
        cells[3].textContent = adet.toFixed(2).replace('.',',');
        cells[4].textContent = '₺' + (fiyat*adet).toFixed(2).replace('.',',');
        cells[5].textContent = not_;
        var duzBtn = tr.querySelector('[title="D\xfczenle"]');
        if (duzBtn) {
          duzBtn.dataset.urun  = urun;
          duzBtn.dataset.birim = birim;
          duzBtn.dataset.fiyat = fiyat;
          duzBtn.dataset.adet  = adet;
          duzBtn.dataset.not   = not_;
          duzBtn.setAttribute('onclick','satirDuzenleBtnClick(this)');
        }
      }
      klasorToplamGuncelle(parseInt(kid));
      document.getElementById('satirModal').classList.remove('open');
      vrmToast('Güncellendi', 'success');
    });
}

function satirSil(sid, kid) {
  ndPost({ action:'satir-sil', id:sid }).then(function() {
    var tr = document.getElementById('satir-' + sid); if(tr) tr.remove();
    var tbody = document.getElementById('satirlar-' + kid);
    if (tbody && tbody.querySelectorAll('[id^="satir-"]').length === 0) {
      var tr2 = document.createElement('tr'); tr2.id = 'bos-' + kid;
      tr2.innerHTML = '<td colspan="7" class="nd-bos">Henüz ürün eklenmedi</td>';
      tbody.appendChild(tr2);
    }
    klasorToplamGuncelle(kid);
    vrmToast('Silindi', 'success');
  });
}

// ── TOPLAM ──────────────────────────────────────────────────
function klasorToplamGuncelle(kid) {
  var toplam = 0; var sayac = 0;
  document.querySelectorAll('#satirlar-' + kid + ' [id^="satir-"]').forEach(function(tr) {
    sayac++;
    var cells = tr.querySelectorAll('td');
    var t = cells[4] ? (parseFloat(cells[4].textContent.replace('₺','').replace(/\./g,'').replace(',','.')) || 0) : 0;
    toplam += t;
  });
  var fmt = fmtPara(toplam);
  var e1 = document.getElementById('ktoplam-' + kid);
  var e2 = document.getElementById('alt-toplam-' + kid);
  var e3 = document.getElementById('satir-sayisi-' + kid);
  if(e2) e2.textContent = fmt;
  if(e3) e3.textContent = sayac + ' ürün — Toplam:';
  // ktoplam: ödeme varsa kalan bilgisini de göster
  if(e1) {
    var odeme = parseFloat((_klasorData[kid] && _klasorData[kid].odeme_toplam) || 0);
    var kalan = Math.max(0, toplam - odeme);
    if (odeme > 0) {
      e1.innerHTML = fmt + (kalan <= 0
        ? ' <span style="font-size:.7rem;color:var(--green);font-weight:600">&#10003; Ödendi</span>'
        : ' <span style="font-size:.7rem;color:var(--red);font-weight:600">Kalan: ' + fmtPara(kalan) + '</span>');
    } else {
      e1.textContent = fmt;
    }
    // ödeme bilgi satırını güncelle
    var odemeEl = document.getElementById('odeme-toplam-' + kid);
    var kalanEl = document.getElementById('odeme-kalan-' + kid);
    if (odemeEl) odemeEl.textContent = fmtPara(odeme);
    if (kalanEl) { kalanEl.textContent = fmtPara(kalan); kalanEl.style.color = kalan<=0?'var(--green)':'var(--red)'; }
    var bilgiEl = document.getElementById('odeme-bilgi-' + kid);
    if (bilgiEl && odeme > 0) bilgiEl.style.display = 'block';
  }
  genelToplamGuncelle();
}

// ── YAZDIR ──────────────────────────────────────────────────
function _printHtml(klasorIds) {
  var tarih = new Date().toLocaleDateString('tr-TR');
  var html  = '';
  var genelToplam = 0;

  klasorIds.forEach(function(kid, idx) {
    var k = _klasorData[kid];
    if (!k) return;
    var satirlar = _satirData[kid] || [];
    // DOM'dan güncel değerleri al
    var domSatirlar = [];
    document.querySelectorAll('#satirlar-' + kid + ' [id^="satir-"]').forEach(function(tr) {
      domSatirlar.push({
        urun:  tr.cells[0].textContent,
        birim: tr.cells[1].textContent,
        fiyat: tr.cells[2].textContent,
        adet:  tr.cells[3].textContent,
        toplam:tr.cells[4].textContent,
        not_:  tr.cells[5].textContent,
      });
    });

    var ktoplam = 0;
    var satirHtml = '';
    domSatirlar.forEach(function(s, i) {
      var t = parseFloat(s.toplam.replace('₺','').replace(/\./g,'').replace(',','.')) || 0;
      ktoplam += t;
      satirHtml += '<tr>'
        + '<td>' + (i+1) + '</td>'
        + '<td>' + escHtml(s.urun) + '</td>'
        + '<td>' + escHtml(s.birim) + '</td>'
        + '<td class="r">' + s.fiyat + '</td>'
        + '<td class="r">' + s.adet + '</td>'
        + '<td class="r bold">' + s.toplam + '</td>'
        + '<td>' + escHtml(s.not_) + '</td>'
        + '</tr>';
    });
    genelToplam += ktoplam;

    if (idx > 0) html += '<hr class="print-ayrac">';
    html += '<div class="print-firma-baslik" style="color:' + escHtml(k.renk) + '">' + escHtml(k.ad) + '</div>';
    if (k.not_bilgi) html += '<div class="print-firma-not">' + escHtml(k.not_bilgi).replace(/\n/g,'<br>') + '</div>';
    html += '<div class="print-tarih">Tarih: ' + tarih + ' &nbsp;|&nbsp; ' + domSatirlar.length + ' kalem</div>';
    html += '<table class="print-tablo"><thead><tr>'
      + '<th>#</th><th>Ürün / Açıklama</th><th>Birim</th>'
      + '<th class="r">Birim Fiyat</th><th class="r">Adet</th><th class="r">Toplam</th><th>Not</th>'
      + '</tr></thead><tbody>' + satirHtml + '</tbody>'
      + '<tfoot><tr class="print-toplam-row"><td colspan="5" style="text-align:right;font-weight:700">Firma Toplamı:</td>'
      + '<td class="r bold">₺' + ktoplam.toFixed(2).replace('.',',') + '</td><td></td></tr></tfoot></table>';
  });

  if (klasorIds.length > 1) {
    html += '<hr class="print-ayrac"><div style="text-align:right;font-size:11pt;font-weight:900">GENEL TOPLAM: ₺' + genelToplam.toFixed(2).replace('.',',') + '</div>';
  }

  document.getElementById('printArea').innerHTML = html;
  window.print();
}

function klasorYazdir(kid) { _printHtml([kid]); }
function tumunuYazdir() {
  var ids = Object.keys(_klasorData).map(Number);
  if (ids.length === 0) { vrmToast('Yazdırılacak klasör yok', 'warn'); return; }
  _printHtml(ids);
}

genelToplamGuncelle();
</script>
</body>
</html>
