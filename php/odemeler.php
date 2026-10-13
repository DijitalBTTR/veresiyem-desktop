<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'odemeler';
$uid = userId();

// ── Ödeme ekle ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ekle') {
    csrfKontrol();
    $baslik   = trim($_POST['baslik'] ?? '');
    $aciklama = trim($_POST['aciklama'] ?? '');
    $tutar    = (float)str_replace(',', '.', $_POST['tutar'] ?? '0');
    $tarih    = trim($_POST['tarih'] ?? date('Y-m-d'));
    $hesapId  = (int)($_POST['hesap_id'] ?? 0) ?: null;
    $tekrar   = in_array($_POST['tekrar'] ?? '', ['yok','gunluk','haftalik','aylik','yillik']) ? $_POST['tekrar'] : 'yok';
    $durum    = ($_POST['durum'] ?? '') === 'bekliyor' ? 'bekliyor' : 'odendi';

    if ($baslik && $tutar > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO odemeler (user_id, hesap_id, baslik, aciklama, tutar, tarih, tekrar, durum) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$uid, $hesapId, $baslik, $aciklama ?: null, $tutar, $tarih, $tekrar, $durum]);
            $odemeId = $pdo->lastInsertId();
            if ($durum === 'odendi' && $hesapId) {
                $ka = $baslik . ($aciklama ? ' — ' . $aciklama : '');
                $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih) VALUES (?,?,'cikis',?,?,'odeme',?,?)")
                    ->execute([$uid, $hesapId, $tutar, $ka, $odemeId, $tarih]);
            }
            $pdo->commit();
            // Hesap api_id'sini bul
            $hApiRow = $hesapId ? $pdo->prepare("SELECT api_id FROM kasa_hesaplari WHERE id=? AND user_id=?") : null;
            if ($hApiRow) { $hApiRow->execute([$hesapId, $uid]); $hApiRow = $hApiRow->fetch(); }
            $odRes = apiSync('post', '/odemeler', [
                'baslik' => $baslik, 'aciklama' => $aciklama ?: null, 'tutar' => $tutar,
                'tarih' => $tarih, 'tekrar' => $tekrar, 'durum' => $durum,
                'hesap_id' => $hApiRow['api_id'] ?? null,
            ]);
            $odApiId = $odRes['data']['id'] ?? $odRes['odeme']['id'] ?? null;
            if ($odApiId) {
                $pdo->prepare("UPDATE odemeler SET api_id=? WHERE id=?")->execute([$odApiId, $odemeId]);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('ODEME EKLE HATA: ' . $e->getMessage());
        }
    }
    header('Location: /odemeler'); exit;
}

// ── Ödeme sil ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sil') {
    csrfKontrol();
    $oid = (int)($_POST['odeme_id'] ?? 0);
    $odApiRow = $pdo->prepare("SELECT api_id FROM odemeler WHERE id=? AND user_id=?");
    $odApiRow->execute([$oid, $uid]);
    $odApiRow = $odApiRow->fetch();
    // Bağlı kasa çıkış kaydını da sil
    $pdo->prepare("DELETE FROM kasa WHERE kaynak='odeme' AND kaynak_id=? AND user_id=?")->execute([$oid, $uid]);
    $pdo->prepare("DELETE FROM odemeler WHERE id=? AND user_id=?")->execute([$oid, $uid]);
    if (!empty($odApiRow['api_id'])) apiSync('delete', '/odemeler/' . $odApiRow['api_id']);
    header('Location: /odemeler'); exit;
}

// ── Durum güncelle (bekliyor → odendi) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'onayla') {
    csrfKontrol();
    $oid     = (int)($_POST['odeme_id'] ?? 0);
    $hesapId = (int)($_POST['hesap_id'] ?? 0) ?: null;

    $odQ = $pdo->prepare("SELECT * FROM odemeler WHERE id=? AND user_id=? AND durum='bekliyor'");
    $odQ->execute([$oid, $uid]);
    $od = $odQ->fetch();

    if ($od) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE odemeler SET durum='odendi', hesap_id=? WHERE id=? AND user_id=?")
                ->execute([$hesapId ?: $od['hesap_id'], $oid, $uid]);
            $kHesapId = $hesapId ?: $od['hesap_id'];
            if ($kHesapId) {
                $ka = $od['baslik'] . ($od['aciklama'] ? ' — ' . $od['aciklama'] : '');
                $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih) VALUES (?,?,'cikis',?,?,'odeme',?,?)")
                    ->execute([$uid, $kHesapId, $od['tutar'], $ka, $oid, $od['tarih']]);
            }
            $pdo->commit();
            // api_id ile VPS'e gönder
            $odApiQ = $pdo->prepare("SELECT api_id FROM odemeler WHERE id=? AND user_id=?");
            $odApiQ->execute([$oid, $uid]);
            $odApiRow = $odApiQ->fetch();
            // kasa hesabı api_id'sini bul
            $khApiQ = $kHesapId ? $pdo->prepare("SELECT api_id FROM kasa_hesaplari WHERE id=? AND user_id=?") : null;
            if ($khApiQ) { $khApiQ->execute([$kHesapId, $uid]); $khApiQ = $khApiQ->fetch(); }
            if (!empty($odApiRow['api_id'])) {
                apiSync('put', '/odemeler/' . $odApiRow['api_id'], ['durum' => 'odendi', 'hesap_id' => $khApiQ['api_id'] ?? null]);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    header('Location: /odemeler'); exit;
}

// ── VPS'ten ödeme sync ──────────────────────────────────────────────────────
require_once __DIR__ . '/includes/sync.php';
syncOdemeler($pdo, $uid);

// ── Veri ────────────────────────────────────────────────────────────────────
$kasaHesaplari = $pdo->prepare("SELECT kh.*, COALESCE(SUM(CASE WHEN k.tip='giris' THEN k.tutar ELSE 0 END),0) - COALESCE(SUM(CASE WHEN k.tip='cikis' THEN k.tutar ELSE 0 END),0) AS bakiye FROM kasa_hesaplari kh LEFT JOIN kasa k ON k.hesap_id=kh.id WHERE kh.user_id=? GROUP BY kh.id ORDER BY kh.id ASC");
$kasaHesaplari->execute([$uid]);
$kasaHesaplari = $kasaHesaplari->fetchAll();

$filtre   = $_GET['filtre'] ?? 'tumu';
$where    = "WHERE o.user_id=?";
$params   = [$uid];
if ($filtre === 'bekliyor') { $where .= " AND o.durum='bekliyor'"; }
if ($filtre === 'odendi')   { $where .= " AND o.durum='odendi'"; }
if ($filtre === 'bu_ay')    { $where .= " AND strftime('%Y-%m',o.tarih)=strftime('%Y-%m','now','localtime')"; }

$odemeler = $pdo->prepare("SELECT o.*, kh.ad AS hesap_ad, kh.renk AS hesap_renk FROM odemeler o LEFT JOIN kasa_hesaplari kh ON kh.id=o.hesap_id $where ORDER BY o.tarih DESC, o.id DESC LIMIT 200");
$odemeler->execute($params);
$odemeler = $odemeler->fetchAll();

// Özet
$ozet = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN durum='odendi' THEN tutar ELSE 0 END),0) AS odendi,
    COALESCE(SUM(CASE WHEN durum='bekliyor' THEN tutar ELSE 0 END),0) AS bekliyor,
    COALESCE(SUM(CASE WHEN durum='odendi' AND strftime('%Y-%m',tarih)=strftime('%Y-%m','now','localtime') THEN tutar ELSE 0 END),0) AS bu_ay
    FROM odemeler WHERE user_id=?");
$ozet->execute([$uid]);
$ozet = $ozet->fetch();

$pageTitle = 'Ödemeler';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ödemeler — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.od-filtre-bar { display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem }
.od-filtre { padding:.35rem .9rem;border-radius:var(--r);font-size:.8rem;font-weight:600;border:1.5px solid var(--border);color:var(--ink2);text-decoration:none;transition:.12s }
.od-filtre:hover { background:var(--surface2) }
.od-filtre.aktif { background:var(--green-lt);border-color:var(--green);color:var(--green) }
.od-ozet { display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1.25rem }
@media(max-width:640px){ .od-ozet { grid-template-columns:1fr 1fr } }
.od-badge { display:inline-block;padding:.15rem .55rem;border-radius:4px;font-size:.72rem;font-weight:700 }
.od-odendi   { background:var(--green-lt);color:var(--green) }
.od-bekliyor { background:#FEF3C7;color:#D97706 }
.tekrar-badge { display:inline-block;padding:.12rem .45rem;border-radius:4px;font-size:.68rem;font-weight:600;background:var(--surface2);color:var(--ink3);border:1px solid var(--border) }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Ödemeler</div>
        <div class="page-sub">Gider ve ödeme takibi</div>
      </div>
      <button class="btn btn-primary btn-sm" onclick="document.getElementById('modalOdemeEkle').style.display='flex'">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Yeni Ödeme
      </button>
    </div>

    <div class="page-body">

      <!-- Özet kartlar -->
      <div class="od-ozet">
        <div class="panel" style="padding:.75rem 1rem;border-left:3px solid var(--red)">
          <div class="stat-label">Bu Ay Ödenen</div>
          <div class="stat-val" style="font-size:1.1rem;color:var(--red);font-weight:800;margin:.1rem 0">₺<?= number_format($ozet['bu_ay'],2,',','.') ?></div>
        </div>
        <div class="panel" style="padding:.75rem 1rem;border-left:3px solid #D97706">
          <div class="stat-label">Bekleyen Toplam</div>
          <div class="stat-val" style="font-size:1.1rem;color:#D97706;font-weight:800;margin:.1rem 0">₺<?= number_format($ozet['bekliyor'],2,',','.') ?></div>
        </div>
        <div class="panel" style="padding:.75rem 1rem;border-left:3px solid var(--ink3)">
          <div class="stat-label">Toplam Ödenen</div>
          <div class="stat-val" style="font-size:1.1rem;font-weight:800;margin:.1rem 0">₺<?= number_format($ozet['odendi'],2,',','.') ?></div>
        </div>
      </div>

      <!-- Filtre bar -->
      <div class="od-filtre-bar">
        <a href="/odemeler" class="od-filtre <?= $filtre==='tumu' ? 'aktif' : '' ?>">Tümü</a>
        <a href="/odemeler?filtre=bekliyor" class="od-filtre <?= $filtre==='bekliyor' ? 'aktif' : '' ?>">Bekleyenler</a>
        <a href="/odemeler?filtre=odendi" class="od-filtre <?= $filtre==='odendi' ? 'aktif' : '' ?>">Ödenenler</a>
        <a href="/odemeler?filtre=bu_ay" class="od-filtre <?= $filtre==='bu_ay' ? 'aktif' : '' ?>">Bu Ay</a>
      </div>

      <!-- Liste -->
      <div class="panel">
        <?php if (empty($odemeler)): ?>
        <div style="padding:2rem;text-align:center;color:var(--ink3)">
          <?= $filtre !== 'tumu' ? 'Seçili filtreyle ödeme bulunamadı.' : 'Henüz ödeme kaydı yok. Sağ üstten "Yeni Ödeme" ekleyin.' ?>
        </div>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Tarih</th>
              <th>Başlık</th>
              <th>Hesap</th>
              <th>Tekrar</th>
              <th>Durum</th>
              <th class="text-right">Tutar</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($odemeler as $od): ?>
          <tr>
            <td style="font-size:.78rem;white-space:nowrap;color:var(--ink3)"><?= date('d.m.Y', strtotime($od['tarih'])) ?></td>
            <td>
              <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($od['baslik']) ?></div>
              <?php if ($od['aciklama']): ?>
              <div style="font-size:.75rem;color:var(--ink3);margin-top:.1rem"><?= htmlspecialchars($od['aciklama']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($od['hesap_ad']): ?>
              <span style="display:inline-block;padding:.12rem .5rem;border-radius:4px;font-size:.72rem;font-weight:700;background:<?= htmlspecialchars($od['hesap_renk']??'#3B82F6') ?>22;color:<?= htmlspecialchars($od['hesap_renk']??'#3B82F6') ?>"><?= htmlspecialchars($od['hesap_ad']) ?></span>
              <?php else: ?>
              <span style="color:var(--ink3);font-size:.78rem">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($od['tekrar'] !== 'yok'): ?>
              <span class="tekrar-badge"><?= ['gunluk'=>'Günlük','haftalik'=>'Haftalık','aylik'=>'Aylık','yillik'=>'Yıllık'][$od['tekrar']] ?></span>
              <?php else: ?>
              <span style="color:var(--ink3);font-size:.78rem">Tek Seferlik</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($od['durum'] === 'odendi'): ?>
              <span class="od-badge od-odendi">Ödendi</span>
              <?php else: ?>
              <span class="od-badge od-bekliyor">Bekliyor</span>
              <?php endif; ?>
            </td>
            <td class="text-right" style="font-weight:700;white-space:nowrap;color:var(--red)">
              -₺<?= number_format($od['tutar'],2,',','.') ?>
            </td>
            <td style="white-space:nowrap;text-align:right">
              <?php if ($od['durum'] === 'bekliyor'): ?>
              <button type="button" class="btn btn-sm btn-primary" style="font-size:.72rem;padding:.2rem .6rem"
                onclick="onaylaAc(<?= $od['id'] ?>,<?= json_encode($od['baslik'],JSON_UNESCAPED_UNICODE) ?>,<?= $od['tutar'] ?>,<?= $od['hesap_id'] ?: 'null' ?>)">
                Öde
              </button>
              <?php endif; ?>
              <form method="post" action="/odemeler" style="display:inline" id="silOdForm<?= $od['id'] ?>">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="sil">
                <input type="hidden" name="odeme_id" value="<?= $od['id'] ?>">
                <button type="button" class="btn btn-sm btn-ghost" style="font-size:.72rem;padding:.2rem .5rem;color:var(--red)"
                  onclick="odemeSilOnayla('silOdForm<?= $od['id'] ?>')">
                  Sil
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>

<!-- Yeni Ödeme Modal -->
<div id="modalOdemeEkle" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.45);align-items:center;justify-content:center;overflow-y:auto;padding:1rem" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--surface);border-radius:12px;padding:1.5rem;width:380px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.2)" onclick="event.stopPropagation()">
    <div style="font-weight:700;font-size:1rem;margin-bottom:1rem">Yeni Ödeme Ekle</div>
    <form method="post" action="/odemeler">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="ekle">

      <div class="form-group">
        <label class="form-label">Başlık <span style="color:var(--red)">*</span></label>
        <input type="text" name="baslik" class="form-input" placeholder="Kira, Elektrik, İnternet…" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Açıklama</label>
        <input type="text" name="aciklama" class="form-input" placeholder="İsteğe bağlı">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
        <div class="form-group">
          <label class="form-label">Tutar (₺) <span style="color:var(--red)">*</span></label>
          <input type="number" name="tutar" class="form-input" min="0.01" step="0.01" placeholder="0,00" required>
        </div>
        <div class="form-group">
          <label class="form-label">Tarih</label>
          <input type="date" name="tarih" class="form-input" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Kasa Hesabı <span style="color:var(--ink3);font-weight:400">(bakiye düşülür)</span></label>
        <select name="hesap_id" class="form-input form-select" id="ekleHesapSec">
          <option value="">— Hesap seç —</option>
          <?php foreach ($kasaHesaplari as $kh): ?>
          <option value="<?= $kh['id'] ?>">
            <?= htmlspecialchars($kh['ad']) ?> — ₺<?= number_format($kh['bakiye'],2,',','.') ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div id="ekleHesapUyari" style="display:none;font-size:.75rem;color:#D97706;margin-top:.3rem">
          Hesap seçilmezse kasaya yansımaz.
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Tekrar</label>
        <select name="tekrar" class="form-input form-select">
          <option value="yok">Tek Seferlik</option>
          <option value="aylik">Aylık</option>
          <option value="haftalik">Haftalık</option>
          <option value="gunluk">Günlük</option>
          <option value="yillik">Yıllık</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Durum</label>
        <div style="display:flex;gap:.5rem">
          <label style="flex:1;cursor:pointer">
            <input type="radio" name="durum" value="odendi" checked style="display:none" id="durumOdendi">
            <span id="lblOdendi" style="display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--green);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600;background:var(--green-lt);color:var(--green)">✓ Hemen Öde</span>
          </label>
          <label style="flex:1;cursor:pointer">
            <input type="radio" name="durum" value="bekliyor" style="display:none" id="durumBekliyor">
            <span id="lblBekliyor" style="display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--border);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600">⏳ Sonra Öde</span>
          </label>
        </div>
      </div>

      <div style="display:flex;gap:.5rem;margin-top:.75rem">
        <button type="submit" class="btn btn-primary" style="flex:1">Kaydet</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalOdemeEkle').style.display='none'">İptal</button>
      </div>
    </form>
  </div>
</div>

<!-- Ödeme Onayla Modal (bekliyor → odendi) -->
<div id="modalOnayla" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.45);align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--surface);border-radius:12px;padding:1.5rem;width:340px;max-width:90vw;box-shadow:0 8px 32px rgba(0,0,0,.2)" onclick="event.stopPropagation()">
    <div style="font-weight:700;font-size:1rem;margin-bottom:.25rem">Ödemeyi Onayla</div>
    <div id="onaylaBaslik" style="font-size:.85rem;color:var(--ink2);margin-bottom:1rem"></div>
    <form method="post" action="/odemeler" id="formOnayla">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="onayla">
      <input type="hidden" name="odeme_id" id="onaylaId">
      <div class="form-group">
        <label class="form-label">Kasa Hesabı <span style="color:var(--red)">*</span></label>
        <select name="hesap_id" class="form-input form-select" id="onaylaHesap" required>
          <option value="">— Hesap seç —</option>
          <?php foreach ($kasaHesaplari as $kh): ?>
          <option value="<?= $kh['id'] ?>">
            <?= htmlspecialchars($kh['ad']) ?> — ₺<?= number_format($kh['bakiye'],2,',','.') ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:.75rem;color:var(--ink3);margin-top:.3rem">Seçilen hesaptan bakiye düşülür.</div>
      </div>
      <div style="display:flex;gap:.5rem;margin-top:.75rem">
        <button type="submit" class="btn btn-primary" style="flex:1">Ödendi Olarak İşaretle</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalOnayla').style.display='none'">İptal</button>
      </div>
    </form>
  </div>
</div>

<script>
function odemeSilOnayla(formId) {
  var modal = document.createElement('div');
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center';
  modal.innerHTML = '<div style="background:var(--bg);border-radius:var(--r);padding:1.5rem;max-width:300px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.3)">'
    + '<div style="font-weight:700;margin-bottom:.5rem">Ödemeyi Sil</div>'
    + '<div style="font-size:.88rem;color:var(--ink2);margin-bottom:1.25rem">Bu ödeme kaydı silinsin mi?</div>'
    + '<div style="display:flex;gap:.5rem;justify-content:flex-end">'
    + '<button onclick="this.closest(\'[data-modal]\').remove()" style="padding:.4rem .9rem;border:1.5px solid var(--border);border-radius:var(--r);background:transparent;cursor:pointer;font-family:inherit">İptal</button>'
    + '<button onclick="document.getElementById(\''+formId+'\').submit()" style="padding:.4rem .9rem;border:none;border-radius:var(--r);background:var(--red,#ef4444);color:#fff;cursor:pointer;font-family:inherit;font-weight:600">Sil</button>'
    + '</div></div>';
  modal.setAttribute('data-modal','1');
  document.body.appendChild(modal);
}

// Yeni ödeme modal — durum toggle
document.querySelectorAll('input[name="durum"]').forEach(function(r) {
  r.addEventListener('change', function() {
    var od = r.value === 'odendi';
    document.getElementById('lblOdendi').style.cssText   = od
      ? 'display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--green);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600;background:var(--green-lt);color:var(--green)'
      : 'display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--border);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600';
    document.getElementById('lblBekliyor').style.cssText = !od
      ? 'display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid #D97706;border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600;background:#FEF3C7;color:#D97706'
      : 'display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--border);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600';
    // Hesap uyarısı: sadece "hemen öde" ve hesap boşsa
    var hesapSec = document.getElementById('ekleHesapSec');
    var uyari    = document.getElementById('ekleHesapUyari');
    if (uyari) uyari.style.display = (od && hesapSec && !hesapSec.value) ? 'block' : 'none';
  });
});
document.getElementById('ekleHesapSec').addEventListener('change', function() {
  var uyari = document.getElementById('ekleHesapUyari');
  var od    = document.getElementById('durumOdendi').checked;
  if (uyari) uyari.style.display = (od && !this.value) ? 'block' : 'none';
});

// Bekliyor → Onayla modal
function onaylaAc(id, baslik, tutar, hesapId) {
  document.getElementById('onaylaId').value = id;
  document.getElementById('onaylaBaslik').textContent = baslik + ' — ₺' + parseFloat(tutar).toLocaleString('tr-TR', {minimumFractionDigits:2});
  var sel = document.getElementById('onaylaHesap');
  if (hesapId && sel) {
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value == hesapId) { sel.selectedIndex = i; break; }
    }
  }
  document.getElementById('modalOnayla').style.display = 'flex';
}
</script>
</body>
</html>
