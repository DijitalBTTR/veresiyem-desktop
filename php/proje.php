<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'projeler';
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /projeler'); exit; }

$proje = $pdo->prepare("SELECT * FROM projeler WHERE id=? AND user_id=?");
$proje->execute([$id, $uid]);
$proje = $proje->fetch();
if (!$proje) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$mesaj = $hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'gorev_ekle') {
        $baslik   = trim($_POST['baslik'] ?? '');
        $aciklama = trim($_POST['aciklama'] ?? '');
        $durum    = in_array($_POST['durum'] ?? '', ['beklemede','devam','tamamlandi']) ? $_POST['durum'] : 'beklemede';
        if ($baslik === '') {
            $hata = 'Görev başlığı zorunludur.';
        } else {
            $sira = (int)$pdo->query("SELECT COALESCE(MAX(sira),0)+1 FROM proje_gorevler WHERE proje_id=$id")->fetchColumn();
            $pdo->prepare("INSERT INTO proje_gorevler (proje_id,baslik,aciklama,durum,sira) VALUES (?,?,?,?,?)")
                ->execute([$id,$baslik,$aciklama?:null,$durum,$sira]);
            header('Location: /proje?id='.$id.'#gorevler'); exit;
        }
    }

    if ($aksiyon === 'gorev_durum') {
        $gid   = (int)($_POST['gorev_id'] ?? 0);
        $durum = in_array($_POST['durum'] ?? '', ['beklemede','devam','tamamlandi']) ? $_POST['durum'] : 'beklemede';
        $pdo->prepare("UPDATE proje_gorevler SET durum=? WHERE id=? AND proje_id=?")->execute([$durum,$gid,$id]);
        header('Location: /proje?id='.$id.'#gorevler'); exit;
    }

    if ($aksiyon === 'gorev_sil') {
        $gid = (int)($_POST['gorev_id'] ?? 0);
        $pdo->prepare("DELETE FROM proje_gorevler WHERE id=? AND proje_id=?")->execute([$gid,$id]);
        header('Location: /proje?id='.$id.'#gorevler'); exit;
    }

    if ($aksiyon === 'harcama_guncelle') {
        $harcanan = (float)str_replace(',','.',trim($_POST['harcanan'] ?? '0'));
        $pdo->prepare("UPDATE projeler SET harcanan=? WHERE id=? AND user_id=?")->execute([$harcanan,$id,$uid]);
        header('Location: /proje?id='.$id); exit;
    }
}

// Görevleri çek
$gorevler = $pdo->prepare("SELECT * FROM proje_gorevler WHERE proje_id=? ORDER BY sira ASC, id ASC");
$gorevler->execute([$id]);
$gorevler = $gorevler->fetchAll();

$toplamGorev  = count($gorevler);
$tamamGorev   = count(array_filter($gorevler, fn($g) => $g['durum'] === 'tamamlandi'));
$gorevPct     = $toplamGorev > 0 ? round($tamamGorev / $toplamGorev * 100) : 0;

$durumLabel = ['devam'=>'Devam Ediyor','tamamlandi'=>'Tamamlandı','beklemede'=>'Beklemede'];
$gDurumLabel = ['beklemede'=>'Beklemede','devam'=>'Devam','tamamlandi'=>'Tamamlandı'];
$butcePct = ($proje['butce'] > 0) ? min(100, round($proje['harcanan']/$proje['butce']*100)) : 0;
$over = $proje['butce'] > 0 && $proje['harcanan'] > $proje['butce'];

$pageTitle = htmlspecialchars($proje['ad']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($proje['ad']) ?> — Proje</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.proje-info { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin-bottom:1.25rem; }
@media(max-width:560px){ .proje-info { grid-template-columns:1fr; } }
.pi-card { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:.85rem 1rem; }
.pi-lbl { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ink3); margin-bottom:.25rem; }
.pi-val { font-size:.9rem; font-weight:600; color:var(--ink1); }
.butce-bar { height:7px; background:var(--border); border-radius:99px; overflow:hidden; margin-top:.5rem; }
.butce-fill { height:100%; border-radius:99px; background:#2563eb; transition:.3s; }
.butce-fill.over { background:#dc2626; }
.gorev-list { display:flex; flex-direction:column; gap:.5rem; }
.gorev-item { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:.75rem 1rem; display:flex; align-items:center; gap:.75rem; }
.gorev-item.tamamlandi { opacity:.65; }
.gorev-check { width:18px; height:18px; border-radius:4px; border:2px solid var(--border); cursor:pointer; flex-shrink:0; display:flex; align-items:center; justify-content:center; transition:.15s; }
.gorev-check.tamam { background:#16a34a; border-color:#16a34a; }
.gorev-check.devam { background:#2563eb; border-color:#2563eb; }
.gorev-baslik { flex:1; font-size:.85rem; font-weight:600; color:var(--ink1); }
.gorev-item.tamamlandi .gorev-baslik { text-decoration:line-through; }
.gorev-aciklama { font-size:.75rem; color:var(--ink3); }
.gorev-badge { font-size:.65rem; font-weight:700; padding:.15rem .45rem; border-radius:99px; white-space:nowrap; }
.gorev-badge.beklemede  { background:#f1f5f9; color:#64748b; }
.gorev-badge.devam      { background:#dbeafe; color:#1d4ed8; }
.gorev-badge.tamamlandi { background:#dcfce7; color:#15803d; }
.progress-wrap { background:var(--border); border-radius:99px; height:8px; overflow:hidden; margin:.5rem 0; }
.progress-fill { height:100%; border-radius:99px; background:#2563eb; }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal-box { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:1.5rem; width:min(480px,96vw); max-height:92vh; overflow-y:auto; }
.modal-title { font-size:.95rem; font-weight:700; margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <a href="/projeler" class="breadcrumb">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          Projelerim
        </a>
        <div class="page-title"><?= htmlspecialchars($proje['ad']) ?></div>
      </div>
      <div class="page-header-actions">
        <a href="/projeler" class="btn btn-ghost">← Geri</a>
      </div>
    </div>

    <div class="page-body">
      <?php if ($mesaj): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
      <?php if ($hata):  ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

      <!-- Proje Bilgileri -->
      <div class="proje-info">
        <div class="pi-card">
          <div class="pi-lbl">Müşteri</div>
          <div class="pi-val"><?= $proje['musteri'] ? htmlspecialchars($proje['musteri']) : '<span style="color:var(--ink3)">—</span>' ?></div>
        </div>
        <div class="pi-card">
          <div class="pi-lbl">Durum</div>
          <div class="pi-val">
            <?php
            $dc = ['devam'=>'#2563eb','tamamlandi'=>'#16a34a','beklemede'=>'#d97706'];
            echo '<span style="color:'.($dc[$proje['durum']]??'#64748b').'">'.($durumLabel[$proje['durum']]??$proje['durum']).'</span>';
            ?>
          </div>
        </div>
        <?php if ($proje['baslangic'] || $proje['bitis']): ?>
        <div class="pi-card">
          <div class="pi-lbl">Tarih Aralığı</div>
          <div class="pi-val">
            <?= $proje['baslangic'] ? date('d.m.Y', strtotime($proje['baslangic'])) : '—' ?>
            <?= ($proje['baslangic'] && $proje['bitis']) ? ' → ' : '' ?>
            <?= $proje['bitis'] ? date('d.m.Y', strtotime($proje['bitis'])) : '' ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($proje['aciklama']): ?>
        <div class="pi-card">
          <div class="pi-lbl">Açıklama</div>
          <div class="pi-val" style="font-weight:400;font-size:.83rem;line-height:1.5"><?= nl2br(htmlspecialchars($proje['aciklama'])) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Bütçe Paneli -->
      <div class="panel" style="margin-bottom:1.25rem">
        <div class="panel-head">
          <div class="panel-title">Bütçe Takibi</div>
          <div class="panel-head-actions">
            <button class="btn btn-sm btn-ghost" onclick="document.getElementById('harcamaModal').classList.add('open')">Güncelle</button>
          </div>
        </div>
        <div style="padding:1rem 1.25rem">
          <?php if ($proje['butce'] > 0): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem">
            <span style="font-size:.8rem;color:var(--ink3)">Harcanan / Bütçe</span>
            <span style="font-size:.88rem;font-weight:700;color:<?= $over ? '#dc2626' : 'var(--ink1)' ?>">
              <?= number_format($proje['harcanan'],2,',','.') ?> / <?= number_format($proje['butce'],2,',','.') ?> ₺
              (<?= $butcePct ?>%)
            </span>
          </div>
          <div class="butce-bar"><div class="butce-fill <?= $over ? 'over' : '' ?>" style="width:<?= $butcePct ?>%"></div></div>
          <?php if ($over): ?>
          <div style="color:#dc2626;font-size:.75rem;font-weight:600;margin-top:.4rem">⚠ Bütçe aşıldı! <?= number_format($proje['harcanan']-$proje['butce'],2,',','.') ?> ₺ fazla harcandı.</div>
          <?php else: ?>
          <div style="color:var(--ink3);font-size:.75rem;margin-top:.4rem">Kalan: <?= number_format($proje['butce']-$proje['harcanan'],2,',','.') ?> ₺</div>
          <?php endif; ?>
          <?php else: ?>
          <div style="color:var(--ink3);font-size:.83rem">Bütçe tanımlanmamış. <button class="btn btn-sm btn-ghost" onclick="document.getElementById('harcamaModal').classList.add('open')" style="margin-left:.5rem">Harcama Gir</button></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Görevler -->
      <div class="panel" id="gorevler">
        <div class="panel-head">
          <div class="panel-title">
            Görevler
            <?php if ($toplamGorev > 0): ?>
            <span style="font-size:.72rem;font-weight:400;color:var(--ink3);margin-left:.5rem"><?= $tamamGorev ?>/<?= $toplamGorev ?> tamamlandı</span>
            <?php endif; ?>
          </div>
          <div class="panel-head-actions">
            <button class="btn btn-sm btn-primary" onclick="document.getElementById('gorevModal').classList.add('open')">+ Görev Ekle</button>
          </div>
        </div>
        <div style="padding:1rem 1.25rem">
          <?php if ($toplamGorev > 0): ?>
          <div class="progress-wrap"><div class="progress-fill" style="width:<?= $gorevPct ?>%"></div></div>
          <?php endif; ?>

          <?php if (empty($gorevler)): ?>
          <div style="text-align:center;padding:2rem;color:var(--ink3);font-size:.83rem">Henüz görev yok. + Görev Ekle butonuna tıklayın.</div>
          <?php else: ?>
          <div class="gorev-list" style="margin-top:.75rem">
            <?php foreach ($gorevler as $g): ?>
            <div class="gorev-item <?= $g['durum'] === 'tamamlandi' ? 'tamamlandi' : '' ?>">
              <form method="post" action="/proje?id=<?= $id ?>" style="display:contents">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="aksiyon" value="gorev_durum">
                <input type="hidden" name="gorev_id" value="<?= $g['id'] ?>">
                <input type="hidden" name="durum" value="<?= $g['durum'] === 'tamamlandi' ? 'beklemede' : 'tamamlandi' ?>">
                <button type="submit" class="gorev-check <?= $g['durum'] === 'tamamlandi' ? 'tamam' : ($g['durum'] === 'devam' ? 'devam' : '') ?>" title="<?= $g['durum'] === 'tamamlandi' ? 'Geri al' : 'Tamamlandı işaretle' ?>">
                  <?php if ($g['durum'] === 'tamamlandi'): ?>
                  <svg width="10" height="10" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                  <?php elseif ($g['durum'] === 'devam'): ?>
                  <svg width="8" height="8" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3" fill="#fff"/></svg>
                  <?php endif; ?>
                </button>
              </form>
              <div style="flex:1;min-width:0">
                <div class="gorev-baslik"><?= htmlspecialchars($g['baslik']) ?></div>
                <?php if ($g['aciklama']): ?>
                <div class="gorev-aciklama"><?= htmlspecialchars($g['aciklama']) ?></div>
                <?php endif; ?>
              </div>
              <span class="gorev-badge <?= $g['durum'] ?>"><?= $gDurumLabel[$g['durum']] ?></span>
              <form method="post" action="/proje?id=<?= $id ?>" style="display:inline" data-confirm-sil>
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="aksiyon" value="gorev_sil">
                <input type="hidden" name="gorev_id" value="<?= $g['id'] ?>">
                <button type="submit" class="btn btn-sm" style="color:#dc2626;border:none;background:none;padding:.2rem .3rem" title="Sil">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </button>
              </form>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Görev Ekle Modal -->
<div class="modal-bg" id="gorevModal">
  <div class="modal-box">
    <div class="modal-title">
      <span>Görev Ekle</span>
      <button onclick="document.getElementById('gorevModal').classList.remove('open')" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--ink3)">✕</button>
    </div>
    <form method="post" action="/proje?id=<?= $id ?>">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="aksiyon" value="gorev_ekle">
      <div class="form-group">
        <label class="form-label">Görev Başlığı <span style="color:var(--red)">*</span></label>
        <input type="text" name="baslik" class="form-input" required autofocus placeholder="Görev adı">
      </div>
      <div class="form-group">
        <label class="form-label">Açıklama</label>
        <textarea name="aciklama" class="form-input form-textarea" rows="2" placeholder="İsteğe bağlı..."></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Durum</label>
        <select name="durum" class="form-input">
          <option value="beklemede">Beklemede</option>
          <option value="devam">Devam Ediyor</option>
          <option value="tamamlandi">Tamamlandı</option>
        </select>
      </div>
      <div style="display:flex;gap:.75rem;margin-top:1.25rem">
        <button type="submit" class="btn btn-primary">Ekle</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('gorevModal').classList.remove('open')">İptal</button>
      </div>
    </form>
  </div>
</div>

<!-- Harcama Güncelle Modal -->
<div class="modal-bg" id="harcamaModal">
  <div class="modal-box" style="max-width:360px">
    <div class="modal-title">
      <span>Harcama Güncelle</span>
      <button onclick="document.getElementById('harcamaModal').classList.remove('open')" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--ink3)">✕</button>
    </div>
    <form method="post" action="/proje?id=<?= $id ?>">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="aksiyon" value="harcama_guncelle">
      <div class="form-group">
        <label class="form-label">Toplam Harcanan (₺)</label>
        <input type="number" name="harcanan" class="form-input" value="<?= htmlspecialchars($proje['harcanan']) ?>" min="0" step="0.01" autofocus>
      </div>
      <div style="display:flex;gap:.75rem;margin-top:1.25rem">
        <button type="submit" class="btn btn-primary">Kaydet</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('harcamaModal').classList.remove('open')">İptal</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.modal-bg').forEach(function(m){
  m.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
});
document.addEventListener('keydown', function(e){
  if(e.key==='Escape') document.querySelectorAll('.modal-bg.open').forEach(function(m){ m.classList.remove('open'); });
});
</script>
</body>
</html>
