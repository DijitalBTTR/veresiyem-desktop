<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sync.php';
girisGerekli();
$activePage = 'projeler';
$uid = userId();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    syncProjeler($pdo, $uid);
}

$mesaj = $hata = '';

// POST işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'ekle') {
        $ad        = trim($_POST['ad'] ?? '');
        $musteri   = trim($_POST['musteri'] ?? '');
        $aciklama  = trim($_POST['aciklama'] ?? '');
        $baslangic = trim($_POST['baslangic'] ?? '') ?: null;
        $bitis     = trim($_POST['bitis'] ?? '') ?: null;
        $durum     = in_array($_POST['durum'] ?? '', ['devam','tamamlandi','beklemede']) ? $_POST['durum'] : 'devam';
        $butce     = ($_POST['butce'] ?? '') !== '' ? (float)str_replace(',','.',trim($_POST['butce'])) : null;
        if ($ad === '') {
            $hata = 'Proje adı zorunludur.';
        } else {
            $pdo->prepare("INSERT INTO projeler (user_id,ad,musteri,aciklama,baslangic,bitis,durum,butce) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$uid,$ad,$musteri?:null,$aciklama?:null,$baslangic,$bitis,$durum,$butce]);
            $yeniId = $pdo->lastInsertId();
            $vpsRes = apiSync('post', '/projeler', [
                'ad' => $ad, 'musteri' => $musteri ?: null, 'aciklama' => $aciklama ?: null,
                'baslangic' => $baslangic, 'bitis' => $bitis, 'durum' => $durum, 'butce' => $butce,
            ]);
            $vpsApiId = $vpsRes['id'] ?? $vpsRes['data']['id'] ?? null;
            if ($vpsApiId) $pdo->prepare("UPDATE projeler SET api_id=? WHERE id=?")->execute([$vpsApiId, $yeniId]);
            header('Location: /projeler?ok=eklendi'); exit;
        }
    }

    if ($aksiyon === 'guncelle') {
        $pid       = (int)($_POST['proje_id'] ?? 0);
        $ad        = trim($_POST['ad'] ?? '');
        $musteri   = trim($_POST['musteri'] ?? '');
        $aciklama  = trim($_POST['aciklama'] ?? '');
        $baslangic = trim($_POST['baslangic'] ?? '') ?: null;
        $bitis     = trim($_POST['bitis'] ?? '') ?: null;
        $durum     = in_array($_POST['durum'] ?? '', ['devam','tamamlandi','beklemede']) ? $_POST['durum'] : 'devam';
        $butce     = ($_POST['butce'] ?? '') !== '' ? (float)str_replace(',','.',trim($_POST['butce'])) : null;
        $harcanan  = ($_POST['harcanan'] ?? '') !== '' ? (float)str_replace(',','.',trim($_POST['harcanan'])) : 0;
        if ($ad === '') {
            $hata = 'Proje adı zorunludur.';
        } else {
            $pdo->prepare("UPDATE projeler SET ad=?,musteri=?,aciklama=?,baslangic=?,bitis=?,durum=?,butce=?,harcanan=? WHERE id=? AND user_id=?")
                ->execute([$ad,$musteri?:null,$aciklama?:null,$baslangic,$bitis,$durum,$butce,$harcanan,$pid,$uid]);
            $pApiRow = $pdo->prepare("SELECT api_id FROM projeler WHERE id=? AND user_id=?");
            $pApiRow->execute([$pid,$uid]); $pApiRow = $pApiRow->fetch();
            if (!empty($pApiRow['api_id'])) {
                apiSync('put', '/projeler/' . $pApiRow['api_id'], [
                    'ad' => $ad, 'musteri' => $musteri ?: null, 'aciklama' => $aciklama ?: null,
                    'baslangic' => $baslangic, 'bitis' => $bitis, 'durum' => $durum, 'butce' => $butce, 'harcanan' => $harcanan,
                ]);
            }
            header('Location: /projeler?ok=guncellendi'); exit;
        }
    }

    if ($aksiyon === 'sil') {
        $pid = (int)($_POST['proje_id'] ?? 0);
        $pApiRow = $pdo->prepare("SELECT api_id FROM projeler WHERE id=? AND user_id=?");
        $pApiRow->execute([$pid,$uid]); $pApiRow = $pApiRow->fetch();
        $pdo->prepare("DELETE FROM projeler WHERE id=? AND user_id=?")->execute([$pid,$uid]);
        if (!empty($pApiRow['api_id'])) apiSync('delete', '/projeler/' . $pApiRow['api_id']);
        header('Location: /projeler?ok=silindi'); exit;
    }
}

// Mesaj
$ok = $_GET['ok'] ?? '';
if ($ok === 'eklendi')     $mesaj = 'Proje eklendi.';
if ($ok === 'guncellendi') $mesaj = 'Proje güncellendi.';
if ($ok === 'silindi')     $mesaj = 'Proje silindi.';

// Filtre
$filDurum = trim($_GET['durum'] ?? '');

$where = "WHERE user_id=?";
$params = [$uid];
if ($filDurum) { $where .= " AND durum=?"; $params[] = $filDurum; }

$projeler = $pdo->prepare("SELECT * FROM projeler $where ORDER BY created_at DESC");
$projeler->execute($params);
$projeler = $projeler->fetchAll();

// Görev sayıları
$gorevMap = [];
if ($projeler) {
    $ids = implode(',', array_column($projeler, 'id'));
    $gRows = $pdo->query("SELECT proje_id, COUNT(*) as toplam, SUM(durum='tamamlandi') as tamam FROM proje_gorevler WHERE proje_id IN ($ids) GROUP BY proje_id");
    foreach ($gRows->fetchAll() as $g) $gorevMap[$g['proje_id']] = $g;
}

// Özet
$ozet = $pdo->prepare("SELECT
    COUNT(*) as toplam,
    SUM(durum='devam') as devam,
    SUM(durum='tamamlandi') as tamamlandi,
    SUM(durum='beklemede') as beklemede,
    SUM(butce) as toplam_butce,
    SUM(harcanan) as toplam_harcanan
FROM projeler WHERE user_id=?");
$ozet->execute([$uid]);
$ozet = $ozet->fetch();

$pageTitle = 'Projelerim';
$durumLabel = ['devam'=>'Devam Ediyor','tamamlandi'=>'Tamamlandı','beklemede'=>'Beklemede'];
$durumRenk  = ['devam'=>'#2563eb','tamamlandi'=>'#16a34a','beklemede'=>'#d97706'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Projelerim</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.pr-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:1rem; }
.pr-card { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:1.1rem 1.25rem; display:flex; flex-direction:column; gap:.6rem; position:relative; transition:.15s; }
.pr-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.pr-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:.5rem; }
.pr-card-ad { font-size:.93rem; font-weight:700; color:var(--ink1); line-height:1.3; }
.pr-card-musteri { font-size:.75rem; color:var(--ink3); margin-top:.1rem; }
.pr-badge { display:inline-flex; align-items:center; gap:.3rem; font-size:.68rem; font-weight:700; padding:.2rem .55rem; border-radius:99px; white-space:nowrap; }
.pr-badge.devam       { background:#dbeafe; color:#1d4ed8; }
.pr-badge.tamamlandi  { background:#dcfce7; color:#15803d; }
.pr-badge.beklemede   { background:#fef3c7; color:#b45309; }
.pr-meta { font-size:.75rem; color:var(--ink3); display:flex; gap:1rem; flex-wrap:wrap; }
.pr-butce { display:flex; gap:.5rem; font-size:.78rem; align-items:center; }
.pr-butce-bar { flex:1; height:5px; background:var(--border); border-radius:99px; overflow:hidden; }
.pr-butce-fill { height:100%; border-radius:99px; background:#2563eb; }
.pr-butce-fill.over { background:#dc2626; }
.pr-gorev { font-size:.73rem; color:var(--ink3); }
.pr-actions { display:flex; gap:.4rem; margin-top:.25rem; }
.pr-aciklama { font-size:.78rem; color:var(--ink2); line-height:1.45; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal-box { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:1.5rem; width:min(560px,96vw); max-height:92vh; overflow-y:auto; }
.modal-title { font-size:.95rem; font-weight:700; margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center; }
.form-2col { display:grid; grid-template-columns:1fr 1fr; gap:0 1rem; }
@media(max-width:520px){ .form-2col { grid-template-columns:1fr; } }
.pr-ozet { display:grid; grid-template-columns:repeat(4,1fr); gap:.75rem; margin-bottom:1.25rem; }
@media(max-width:600px){ .pr-ozet { grid-template-columns:repeat(2,1fr); } }
.pr-oz-card { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:.75rem 1rem; }
.pr-oz-lbl { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ink3); margin-bottom:.2rem; }
.pr-oz-val { font-size:1.1rem; font-weight:700; color:var(--ink1); }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Projelerim</div>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick="modalAc()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Proje
        </button>
      </div>
    </div>

    <div class="page-body">
      <?php if ($mesaj): ?>
      <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($mesaj) ?></div>
      <?php endif; ?>
      <?php if ($hata): ?>
      <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($hata) ?></div>
      <?php endif; ?>

      <!-- Özet -->
      <div class="pr-ozet">
        <div class="pr-oz-card">
          <div class="pr-oz-lbl">Toplam Proje</div>
          <div class="pr-oz-val"><?= (int)$ozet['toplam'] ?></div>
        </div>
        <div class="pr-oz-card">
          <div class="pr-oz-lbl">Devam Ediyor</div>
          <div class="pr-oz-val" style="color:#2563eb"><?= (int)$ozet['devam'] ?></div>
        </div>
        <div class="pr-oz-card">
          <div class="pr-oz-lbl">Tamamlandı</div>
          <div class="pr-oz-val" style="color:#16a34a"><?= (int)$ozet['tamamlandi'] ?></div>
        </div>
        <div class="pr-oz-card">
          <div class="pr-oz-lbl">Beklemede</div>
          <div class="pr-oz-val" style="color:#d97706"><?= (int)$ozet['beklemede'] ?></div>
        </div>
      </div>

      <!-- Filtre -->
      <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
        <a href="/projeler" class="btn <?= !$filDurum ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:.78rem;padding:.3rem .75rem">Tümü</a>
        <a href="/projeler?durum=devam" class="btn <?= $filDurum==='devam' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:.78rem;padding:.3rem .75rem">Devam Ediyor</a>
        <a href="/projeler?durum=beklemede" class="btn <?= $filDurum==='beklemede' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:.78rem;padding:.3rem .75rem">Beklemede</a>
        <a href="/projeler?durum=tamamlandi" class="btn <?= $filDurum==='tamamlandi' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:.78rem;padding:.3rem .75rem">Tamamlandı</a>
      </div>

      <!-- Proje Kartları -->
      <?php if (empty($projeler)): ?>
      <div style="text-align:center;padding:3rem 1rem;color:var(--ink3)">
        <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom:.75rem;opacity:.35"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
        <div style="font-size:.88rem">Henüz proje yok</div>
        <div style="font-size:.78rem;margin-top:.3rem">Yeni Proje butonuna tıklayın</div>
      </div>
      <?php else: ?>
      <div class="pr-grid">
        <?php foreach ($projeler as $p):
          $g = $gorevMap[$p['id']] ?? ['toplam'=>0,'tamam'=>0];
          $butcePct = ($p['butce'] > 0) ? min(100, round($p['harcanan']/$p['butce']*100)) : 0;
          $over = $p['butce'] > 0 && $p['harcanan'] > $p['butce'];
          $bugun = date('Y-m-d');
          $gecikti = $p['bitis'] && $p['bitis'] < $bugun && $p['durum'] !== 'tamamlandi';
        ?>
        <div class="pr-card">
          <div class="pr-card-head">
            <div>
              <div class="pr-card-ad"><?= htmlspecialchars($p['ad']) ?></div>
              <?php if ($p['musteri']): ?>
              <div class="pr-card-musteri">👤 <?= htmlspecialchars($p['musteri']) ?></div>
              <?php endif; ?>
            </div>
            <span class="pr-badge <?= $p['durum'] ?>"><?= $durumLabel[$p['durum']] ?></span>
          </div>

          <?php if ($p['aciklama']): ?>
          <div class="pr-aciklama"><?= htmlspecialchars($p['aciklama']) ?></div>
          <?php endif; ?>

          <div class="pr-meta">
            <?php if ($p['baslangic']): ?>
            <span>📅 <?= date('d.m.Y', strtotime($p['baslangic'])) ?></span>
            <?php endif; ?>
            <?php if ($p['bitis']): ?>
            <span style="<?= $gecikti ? 'color:#dc2626;font-weight:600' : '' ?>">
              🏁 <?= date('d.m.Y', strtotime($p['bitis'])) ?><?= $gecikti ? ' ⚠ Gecikti' : '' ?>
            </span>
            <?php endif; ?>
          </div>

          <?php if ($p['butce'] > 0): ?>
          <div>
            <div class="pr-butce" style="margin-bottom:.3rem">
              <span style="color:var(--ink3)">Bütçe:</span>
              <span style="font-weight:600"><?= number_format($p['harcanan'],2,',','.') ?> / <?= number_format($p['butce'],2,',','.') ?> ₺</span>
              <span style="<?= $over ? 'color:#dc2626;font-weight:700' : 'color:var(--ink3)' ?>">(<?= $butcePct ?>%)</span>
            </div>
            <div class="pr-butce-bar"><div class="pr-butce-fill <?= $over ? 'over' : '' ?>" style="width:<?= $butcePct ?>%"></div></div>
          </div>
          <?php endif; ?>

          <?php if ($g['toplam'] > 0): ?>
          <div class="pr-gorev">📋 <?= (int)$g['tamam'] ?>/<?= (int)$g['toplam'] ?> görev tamamlandı</div>
          <?php endif; ?>

          <div class="pr-actions">
            <a href="/proje?id=<?= $p['id'] ?>" class="btn btn-sm btn-secondary" style="font-size:.75rem">Detay / Görevler</a>
            <button class="btn btn-sm btn-ghost" style="font-size:.75rem" onclick="modalDuzenle(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">Düzenle</button>
            <form method="post" style="display:inline" data-confirm-sil>
              <input type="hidden" name="aksiyon" value="sil">
              <input type="hidden" name="proje_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <button type="submit" class="btn btn-sm" style="font-size:.75rem;color:#dc2626;border-color:rgba(220,38,38,.3)">Sil</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Proje Modal -->
<div class="modal-bg" id="projeModal">
  <div class="modal-box">
    <div class="modal-title">
      <span id="modalBaslik">Yeni Proje</span>
      <button onclick="modalKapat()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--ink3)">✕</button>
    </div>
    <form method="post" action="/projeler" id="projeForm">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="aksiyon" id="fAksiyon" value="ekle">
      <input type="hidden" name="proje_id" id="fProjeId" value="">

      <div class="form-group">
        <label class="form-label">Proje Adı <span style="color:var(--red)">*</span></label>
        <input type="text" name="ad" id="fAd" class="form-input" required placeholder="Proje adı">
      </div>

      <div class="form-group">
        <label class="form-label">Müşteri</label>
        <input type="text" name="musteri" id="fMusteri" class="form-input" placeholder="Müşteri adı">
      </div>

      <div class="form-group">
        <label class="form-label">Açıklama</label>
        <textarea name="aciklama" id="fAciklama" class="form-input form-textarea" rows="2" placeholder="Proje hakkında kısa bilgi..."></textarea>
      </div>

      <div class="form-2col">
        <div class="form-group">
          <label class="form-label">Başlangıç</label>
          <input type="date" name="baslangic" id="fBaslangic" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Bitiş</label>
          <input type="date" name="bitis" id="fBitis" class="form-input">
        </div>
      </div>

      <div class="form-2col">
        <div class="form-group">
          <label class="form-label">Durum</label>
          <select name="durum" id="fDurum" class="form-input">
            <option value="devam">Devam Ediyor</option>
            <option value="beklemede">Beklemede</option>
            <option value="tamamlandi">Tamamlandı</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Bütçe (₺)</label>
          <input type="number" name="butce" id="fButce" class="form-input" placeholder="0.00" min="0" step="0.01">
        </div>
      </div>

      <div class="form-group" id="harcananGrup" style="display:none">
        <label class="form-label">Harcanan (₺)</label>
        <input type="number" name="harcanan" id="fHarcanan" class="form-input" placeholder="0.00" min="0" step="0.01">
      </div>

      <div style="display:flex;gap:.75rem;margin-top:1.25rem">
        <button type="submit" class="btn btn-primary">Kaydet</button>
        <button type="button" class="btn btn-ghost" onclick="modalKapat()">İptal</button>
      </div>
    </form>
  </div>
</div>

<script>
function modalAc() {
  document.getElementById('modalBaslik').textContent = 'Yeni Proje';
  document.getElementById('fAksiyon').value = 'ekle';
  document.getElementById('fProjeId').value = '';
  document.getElementById('fAd').value = '';
  document.getElementById('fMusteri').value = '';
  document.getElementById('fAciklama').value = '';
  document.getElementById('fBaslangic').value = '';
  document.getElementById('fBitis').value = '';
  document.getElementById('fDurum').value = 'devam';
  document.getElementById('fButce').value = '';
  document.getElementById('fHarcanan').value = '';
  document.getElementById('harcananGrup').style.display = 'none';
  document.getElementById('projeModal').classList.add('open');
  setTimeout(function(){ document.getElementById('fAd').focus(); }, 100);
}

function modalDuzenle(p) {
  document.getElementById('modalBaslik').textContent = 'Projeyi Düzenle';
  document.getElementById('fAksiyon').value = 'guncelle';
  document.getElementById('fProjeId').value = p.id;
  document.getElementById('fAd').value = p.ad || '';
  document.getElementById('fMusteri').value = p.musteri || '';
  document.getElementById('fAciklama').value = p.aciklama || '';
  document.getElementById('fBaslangic').value = p.baslangic || '';
  document.getElementById('fBitis').value = p.bitis || '';
  document.getElementById('fDurum').value = p.durum || 'devam';
  document.getElementById('fButce').value = p.butce || '';
  document.getElementById('fHarcanan').value = p.harcanan || '';
  document.getElementById('harcananGrup').style.display = 'block';
  document.getElementById('projeModal').classList.add('open');
}

function modalKapat() { document.getElementById('projeModal').classList.remove('open'); }
document.getElementById('projeModal').addEventListener('click', function(e){ if(e.target===this) modalKapat(); });
document.addEventListener('keydown', function(e){ if(e.key==='Escape') modalKapat(); });
</script>
</body>
</html>
