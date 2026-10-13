<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/sync.php';
girisGerekli();
$uid = userId();
$activePage = 'hatirlaticilar';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    syncHatirlaticilar($pdo, $uid);
}

$hata = '';
$basari = '';

// --- Ekle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ekle'])) {
    $baslik        = trim($_POST['baslik'] ?? '');
    $aciklama      = trim($_POST['aciklama'] ?? '');
    $tarih         = $_POST['tarih'] ?? '';
    $saat          = $_POST['saat'] ?? '';
    $hatirlatma_dk = (int)($_POST['hatirlatma_dk'] ?? 0);
    $tutar         = $_POST['tutar'] !== '' ? (float)str_replace(',', '.', $_POST['tutar'] ?? '') : null;
    $odeme_tip     = in_array($_POST['odeme_tip'] ?? '', ['odeyecek','alacak']) ? $_POST['odeme_tip'] : null;
    if ($tutar !== null && $tutar <= 0) { $tutar = null; $odeme_tip = null; }
    if ($tutar === null) $odeme_tip = null;

    if (!$baslik || !$tarih || !$saat) {
        $hata = 'Başlık, tarih ve saat zorunludur.';
    } else {
        $hRes   = vpsYaz('post', '/hatirlaticilar', [
            'baslik' => $baslik, 'aciklama' => $aciklama ?: null,
            'tarih' => $tarih, 'saat' => $saat,
            'hatirlatma_dk' => $hatirlatma_dk, 'tutar' => $tutar, 'odeme_tip' => $odeme_tip,
        ]);
        $hApiId = $hRes['data']['id'] ?? $hRes['hatirlatici']['id'] ?? null;
        $pdo->prepare("INSERT INTO hatirlaticilar (user_id,baslik,aciklama,tarih,saat,hatirlatma_dk,tutar,odeme_tip,api_id) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$uid, $baslik, $aciklama ?: null, $tarih, $saat, $hatirlatma_dk, $tutar, $odeme_tip, $hApiId]);
        $basari = 'Hatırlatıcı kaydedildi.';
    }
}

// URL'den başarı mesajı
if (isset($_GET['basari']) && $_GET['basari'] === 'duzenlendi') {
    $basari = 'Hatırlatıcı güncellendi.';
}

// --- Filtre ---
$filtre = $_GET['filtre'] ?? 'aktif';

$sql = "SELECT * FROM hatirlaticilar WHERE user_id=?";
$params = [$uid];
if ($filtre === 'aktif') {
    $sql .= " AND durum='aktif'";
} elseif ($filtre === 'tamamlandi') {
    $sql .= " AND durum='tamamlandi'";
}
$sql .= " ORDER BY tarih ASC, saat ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$liste = $stmt->fetchAll();

// --- Bugün aktif hatırlatıcı sayısı ---
$yaklasan = $pdo->prepare("SELECT COUNT(*) FROM hatirlaticilar WHERE user_id=? AND durum='aktif' AND tarih = date('now','localtime')");
$yaklasan->execute([$uid]);
$yakCount = (int)$yaklasan->fetchColumn();

// --- Ödeme özet kartları (aktif hatırlatıcılar, tutar girilmiş olanlar) ---
$ozetSt = $pdo->prepare("
    SELECT odeme_tip, SUM(tutar) AS toplam
    FROM hatirlaticilar
    WHERE user_id=? AND durum='aktif' AND tutar IS NOT NULL AND odeme_tip IS NOT NULL
    GROUP BY odeme_tip
");
$ozetSt->execute([$uid]);
$ozetler = ['odeyecek' => 0.0, 'alacak' => 0.0];
foreach ($ozetSt->fetchAll() as $oz) {
    $ozetler[$oz['odeme_tip']] = (float)$oz['toplam'];
}

$bugun = date('Y-m-d');
$simdi = date('Y-m-d H:i');

$oncelikler = [
    0    => 'Yok',
    5    => '5 dakika',
    10   => '10 dakika',
    15   => '15 dakika',
    30   => '30 dakika',
    60   => '1 saat',
    120  => '2 saat',
    1440 => '1 gün önce',
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hatırlatıcılar — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.hat-kart {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--r-lg);
  padding: .9rem 1.1rem;
  display: flex;
  align-items: flex-start;
  gap: .9rem;
  transition: box-shadow .12s;
  position: relative;
}
.hat-kart:hover { box-shadow: var(--shadow); }
.hat-kart.gecmis { opacity: .65; }
.hat-kart.tamamlandi-kart { opacity: .5; }

.hat-onay {
  width: 22px; height: 22px; border-radius: 50%;
  border: 2px solid var(--border2);
  flex-shrink: 0; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: all .12s; margin-top: 2px;
  background: transparent;
}
.hat-onay:hover { border-color: var(--green); background: var(--green-lt); }
.hat-onay.tamamlandi { background: var(--green); border-color: var(--green); color: #fff; }

.hat-bilgi { flex: 1; min-width: 0; }
.hat-baslik {
  font-weight: 700; font-size: .92rem; color: var(--ink);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.hat-baslik.ustuzeri { text-decoration: line-through; color: var(--ink3); }
.hat-aciklama { font-size: .79rem; color: var(--ink3); margin-top: .15rem; }
.hat-meta {
  display: flex; align-items: center; gap: .6rem;
  margin-top: .4rem; flex-wrap: wrap;
}
.hat-zaman {
  display: inline-flex; align-items: center; gap: .28rem;
  font-size: .74rem; font-weight: 600;
}
.hat-zaman.gecmis-renk { color: var(--red); }
.hat-zaman.bugun-renk  { color: var(--gold); }
.hat-zaman.gelecek-renk{ color: var(--green); }
.hat-hatirlatma {
  font-size: .69rem; color: var(--ink3);
  background: var(--surface2); border: 1px solid var(--border);
  padding: .1rem .45rem; border-radius: 4px;
}
.hat-odeme-badge {
  display: inline-flex; align-items: center; gap: .3rem;
  font-size: .72rem; font-weight: 700;
  padding: .15rem .5rem; border-radius: 5px;
}
.hat-odeme-badge.odeyecek { background: var(--red-lt); color: var(--red); }
.hat-odeme-badge.alacak   { background: var(--green-lt); color: var(--green); }

/* Sağ aksiyon butonları */
.hat-aksiyonlar {
  display: flex; flex-direction: column; gap: .3rem;
  align-items: flex-end; flex-shrink: 0;
  opacity: 0; transition: opacity .1s;
}
.hat-kart:hover .hat-aksiyonlar { opacity: 1; }
.hat-btn {
  background: transparent; border: none; cursor: pointer;
  color: var(--ink3); padding: .22rem .3rem; border-radius: 4px;
  display: flex; align-items: center; gap: .3rem;
  font-size: .72rem; font-weight: 600; transition: all .1s;
  white-space: nowrap;
}
.hat-btn:hover.duzenle { color: var(--blue); background: var(--blue-lt); }
.hat-btn:hover.sil     { color: var(--red);  background: var(--red-lt); }

.filtre-tab {
  padding: .35rem .85rem; border-radius: 6px;
  font-size: .8rem; font-weight: 600;
  color: var(--ink3); border: 1.5px solid transparent;
  cursor: pointer; transition: all .1s; text-decoration: none;
}
.filtre-tab.aktif-tab {
  background: var(--surface); border-color: var(--border);
  color: var(--ink); box-shadow: var(--shadow-sm);
}

/* Özet kartlar */
.hat-ozet-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: .65rem; max-width: 480px; margin-bottom: 1.1rem;
}
.hat-ozet-kart {
  border-radius: var(--r); padding: .85rem 1rem;
  display: flex; align-items: center; gap: .75rem;
  border: 1.5px solid;
}
.hat-ozet-kart.red   { background: var(--red-lt);   border-color: rgba(220,38,38,.2); }
.hat-ozet-kart.green { background: var(--green-lt); border-color: rgba(22,163,74,.2); }
.hat-ozet-ico {
  width: 36px; height: 36px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.hat-ozet-kart.red   .hat-ozet-ico { background: rgba(220,38,38,.12); color: var(--red); }
.hat-ozet-kart.green .hat-ozet-ico { background: rgba(22,163,74,.12);  color: var(--green); }
.hat-ozet-lbl { font-size: .72rem; font-weight: 600; color: var(--ink3); }
.hat-ozet-val { font-size: 1.1rem; font-weight: 800; margin-top: .05rem; }
.hat-ozet-kart.red   .hat-ozet-val { color: var(--red); }
.hat-ozet-kart.green .hat-ozet-val { color: var(--green); }
</style>
</head>
<body>
<?php require 'includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require 'includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Hatırlatıcılar</div>
        <div class="page-sub">Görevler ve tarihli notlar</div>
      </div>
      <div class="page-header-center">
        <div style="display:flex;gap:.35rem">
          <a href="/hatirlaticilar?filtre=aktif"      class="filtre-tab <?= $filtre==='aktif'      ?'aktif-tab':'' ?>">Aktif</a>
          <a href="/hatirlaticilar?filtre=tamamlandi" class="filtre-tab <?= $filtre==='tamamlandi' ?'aktif-tab':'' ?>">Tamamlandı</a>
          <a href="/hatirlaticilar?filtre=hepsi"      class="filtre-tab <?= $filtre==='hepsi'      ?'aktif-tab':'' ?>">Hepsi</a>
        </div>
      </div>
      <div class="page-header-right">
        <button class="btn btn-primary btn-sm" onclick="formuAc()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Hatırlatıcı
        </button>
      </div>
    </div>

    <div class="page-body">

      <?php if ($hata): ?>
      <div class="alert alert-error" style="margin-bottom:1rem">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($hata) ?>
      </div>
      <?php endif; ?>
      <?php if ($basari): ?>
      <div class="alert alert-success" style="margin-bottom:1rem">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($basari) ?>
      </div>
      <?php endif; ?>

      <!-- Ödeme özet kartları (tutar girilmişse göster) -->
      <?php if ($ozetler['odeyecek'] > 0 || $ozetler['alacak'] > 0): ?>
      <div class="hat-ozet-grid">
        <?php if ($ozetler['odeyecek'] > 0): ?>
        <div class="hat-ozet-kart red">
          <div class="hat-ozet-ico">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
          </div>
          <div>
            <div class="hat-ozet-lbl">Ödeyeceğim (Borç)</div>
            <div class="hat-ozet-val">₺<?= number_format($ozetler['odeyecek'],2,',','.') ?></div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($ozetler['alacak'] > 0): ?>
        <div class="hat-ozet-kart green">
          <div class="hat-ozet-ico">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
          </div>
          <div>
            <div class="hat-ozet-lbl">Alacağım (Alacak)</div>
            <div class="hat-ozet-val">₺<?= number_format($ozetler['alacak'],2,',','.') ?></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Yeni Hatırlatıcı Formu -->
      <div id="yeniForm" style="display:none;margin-bottom:1.25rem">
        <div class="panel" style="padding:1.25rem;max-width:680px">
          <div style="font-weight:700;font-size:.9rem;margin-bottom:1rem;color:var(--ink)">Yeni Hatırlatıcı</div>
          <form method="post">
            <input type="hidden" name="ekle" value="1">
            <div style="display:grid;gap:.85rem">
              <div class="form-group">
                <label class="form-label">Başlık <span style="color:var(--red)">*</span></label>
                <input type="text" name="baslik" class="form-input" placeholder="Hatırlatıcı başlığı..." required>
              </div>
              <div class="form-group">
                <label class="form-label">Açıklama</label>
                <textarea name="aciklama" class="form-input" rows="2" placeholder="Opsiyonel detay..."></textarea>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
                <div class="form-group">
                  <label class="form-label">Tarih <span style="color:var(--red)">*</span></label>
                  <input type="date" name="tarih" class="form-input" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Saat <span style="color:var(--red)">*</span></label>
                  <input type="time" name="saat" class="form-input" value="<?= date('H:i', strtotime('+1 hour')) ?>" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Hatırlatma</label>
                  <select name="hatirlatma_dk" class="form-input">
                    <?php foreach ($oncelikler as $dk => $lbl): ?>
                    <option value="<?= $dk ?>" <?= $dk===15?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <!-- Ödeme alanı -->
              <div style="border:1.5px solid var(--border);border-radius:var(--r);padding:.85rem;display:grid;gap:.65rem;background:var(--surface2)">
                <div style="font-size:.78rem;font-weight:700;color:var(--ink2)">Ödeme <span style="font-weight:400;color:var(--ink3)">(İsteğe bağlı)</span></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                  <div class="form-group" style="margin:0">
                    <label class="form-label">Tutar (₺)</label>
                    <input type="number" name="tutar" class="form-input" step="0.01" min="0" placeholder="0,00">
                  </div>
                  <div class="form-group" style="margin:0">
                    <label class="form-label">Ödeme Tipi</label>
                    <select name="odeme_tip" class="form-input">
                      <option value="">— Seçiniz —</option>
                      <option value="odeyecek">Ödeme Yapacağım (Borç)</option>
                      <option value="alacak">Ödeme Alacağım (Alacak)</option>
                    </select>
                  </div>
                </div>
              </div>

              <div style="display:flex;gap:.6rem;justify-content:flex-end">
                <button type="button" class="btn btn-ghost btn-sm" onclick="formuKapat()">İptal</button>
                <button type="submit" class="btn btn-primary btn-sm">Kaydet</button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Liste -->
      <?php if (empty($liste)): ?>
        <div class="empty-state">
          <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <h3><?= $filtre==='tamamlandi' ? 'Tamamlanan hatırlatıcı yok' : 'Henüz hatırlatıcı eklenmedi' ?></h3>
          <?php if ($filtre==='aktif' || $filtre==='hepsi'): ?>
          <button class="btn btn-primary" onclick="formuAc()">İlk Hatırlatıcıyı Ekle</button>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.5rem;max-width:720px">
          <?php foreach ($liste as $h):
            $hatZaman = $h['tarih'] . ' ' . $h['saat'];
            $gecmisMi = $hatZaman < $simdi && $h['durum'] === 'aktif';
            $bugunMu  = $h['tarih'] === $bugun && $h['durum'] === 'aktif';
            $tamam    = $h['durum'] === 'tamamlandi';
            $zamRenk  = $gecmisMi ? 'gecmis-renk' : ($bugunMu ? 'bugun-renk' : 'gelecek-renk');
            $tutar    = $h['tutar'] !== null ? (float)$h['tutar'] : null;
            $otip     = $h['odeme_tip'] ?? null;
          ?>
          <div class="hat-kart <?= $gecmisMi?'gecmis':'' ?> <?= $tamam?'tamamlandi-kart':'' ?>">

            <!-- Tamamla -->
            <form method="post" action="/hatirlatici-durum" style="margin:0">
              <input type="hidden" name="id"    value="<?= $h['id'] ?>">
              <input type="hidden" name="durum" value="<?= $tamam ? 'aktif' : 'tamamlandi' ?>">
              <button type="submit" class="hat-onay <?= $tamam?'tamamlandi':'' ?>" title="<?= $tamam?'Geri al':'Tamamlandı olarak işaretle' ?>">
                <?php if ($tamam): ?>
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?php endif; ?>
              </button>
            </form>

            <div class="hat-bilgi">
              <div class="hat-baslik <?= $tamam?'ustuzeri':'' ?>"><?= htmlspecialchars($h['baslik']) ?></div>
              <?php if ($h['aciklama']): ?>
              <div class="hat-aciklama"><?= nl2br(htmlspecialchars($h['aciklama'])) ?></div>
              <?php endif; ?>
              <div class="hat-meta">
                <span class="hat-zaman <?= $zamRenk ?>">
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  <?= date('d.m.Y', strtotime($h['tarih'])) ?>
                </span>
                <span class="hat-zaman <?= $zamRenk ?>">
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <?= substr($h['saat'],0,5) ?>
                </span>
                <?php if ($h['hatirlatma_dk'] > 0 && !$tamam): ?>
                <span class="hat-hatirlatma">🔔 <?= $oncelikler[$h['hatirlatma_dk']] ?? $h['hatirlatma_dk'].' dk' ?> önce</span>
                <?php endif; ?>
                <?php if ($gecmisMi): ?>
                <span style="font-size:.69rem;font-weight:700;color:var(--red);background:var(--red-lt);padding:.1rem .45rem;border-radius:4px">Geçti</span>
                <?php endif; ?>
                <?php if ($tutar !== null && $otip): ?>
                <span class="hat-odeme-badge <?= $otip ?>">
                  <?php if ($otip === 'odeyecek'): ?>
                  <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                  Ödeyeceğim
                  <?php else: ?>
                  <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                  Alacağım
                  <?php endif; ?>
                  ₺<?= number_format($tutar,2,',','.') ?>
                </span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Düzenle + Sil -->
            <div class="hat-aksiyonlar">
              <a href="/hatirlatici-duzenle?id=<?= $h['id'] ?>" class="hat-btn duzenle" title="Düzenle">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Düzenle
              </a>
              <form method="post" action="/hatirlatici-sil" onsubmit="return hatSilOnay(this)" style="margin:0">
                <input type="hidden" name="id" value="<?= $h['id'] ?>">
                <button type="submit" class="hat-btn sil" title="Sil">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                  Sil
                </button>
              </form>
            </div>

          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </main>
</div>

<script>
function formuAc() {
  var f = document.getElementById('yeniForm');
  f.style.display = 'block';
  setTimeout(function() {
    f.querySelector('input[name=baslik]').focus();
    f.scrollIntoView({behavior:'smooth', block:'start'});
  }, 50);
}
function formuKapat() {
  document.getElementById('yeniForm').style.display = 'none';
}
function hatSilOnay(form) {
  if (window.veresiyemConfirm) {
    window.veresiyemConfirm({
      title: 'Hatırlatıcıyı Sil',
      msg: 'Bu hatırlatıcı kalıcı olarak silinecek. Devam edilsin mi?',
      okLabel: 'Evet, Sil',
      type: 'red'
    }).then(function(onay) {
      if (onay) form.submit();
    });
    return false;
  }
  form.submit();
  return false;
}
<?php if ($hata): ?>window.addEventListener('DOMContentLoaded', formuAc);<?php endif; ?>
</script>
</body>
</html>
