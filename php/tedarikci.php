<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'tedarikciler';
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM tedarikciler WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $uid]);
$t = $stmt->fetch();
if (!$t) { header('Location: /tedarikciler'); exit; }

// Faturalar
$fStmt = $pdo->prepare("SELECT * FROM faturalar WHERE tedarikci_id = ? ORDER BY tarih DESC, id DESC");
$fStmt->execute([$id]);
$faturalar = $fStmt->fetchAll();

// Özet
$ozetStmt = $pdo->prepare("
    SELECT
        COUNT(*) as toplam_fatura,
        COALESCE(SUM(genel_toplam),0) as toplam_tutar,
        COALESCE(SUM(CASE WHEN durum='odenmedi' THEN genel_toplam ELSE 0 END),0) as bekleyen_tutar,
        COALESCE(SUM(CASE WHEN durum='odendi' THEN genel_toplam ELSE 0 END),0) as odenen_tutar
    FROM faturalar WHERE tedarikci_id = ?
");
$ozetStmt->execute([$id]);
$ozet = $ozetStmt->fetch();

// Kasa hesapları
$kasaStmt = $pdo->prepare("SELECT id, ad, renk FROM kasa_hesaplari WHERE user_id = ? AND aktif = 1 ORDER BY varsayilan DESC, ad ASC");
$kasaStmt->execute([$uid]);
$kasaHesaplari = $kasaStmt->fetchAll();

// Kasa bakiyeleri
$kasaBakiye = [];
foreach ($kasaHesaplari as $kh) {
    $bStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE -tutar END),0) as bakiye FROM kasa WHERE hesap_id = ? AND user_id = ?");
    $bStmt->execute([$kh['id'], $uid]);
    $kasaBakiye[$kh['id']] = (float)$bStmt->fetchColumn();
}

// Ödenmemiş faturalar (modal için)
$odenmemisStmt = $pdo->prepare("SELECT id, fatura_no, tarih, genel_toplam FROM faturalar WHERE tedarikci_id = ? AND durum = 'odenmedi' ORDER BY tarih ASC");
$odenmemisStmt->execute([$id]);
$odenmemisFaturalar = $odenmemisStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($t['ad']) ?> — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <a href="/tedarikciler" class="btn btn-ghost btn-sm" style="margin-right:.5rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <div class="page-title"><?= htmlspecialchars($t['ad']) ?></div>
      </div>
      <div class="page-header-right" style="gap:.5rem;display:flex">
        <a href="/tedarikci-duzenle?id=<?= $id ?>" class="btn btn-ghost btn-sm">Düzenle</a>
        <?php if ($ozet['bekleyen_tutar'] > 0 && !empty($kasaHesaplari)): ?>
        <button class="btn btn-sm" style="color:#16a34a;border:1.5px solid #16a34a;background:transparent;cursor:pointer;border-radius:var(--r);padding:.3rem .75rem;font-size:.8rem;font-weight:600" onclick="odemeModalAc()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:.25rem"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
          Ödeme Yap
        </button>
        <?php endif; ?>
        <a href="/fatura-ekle?tedarikci_id=<?= $id ?>" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Fatura Ekle
        </a>
      </div>
    </div>

    <!-- Tedarikçi Bilgileri + Özet -->
    <div style="display:flex;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap">
      <!-- Bilgi Kartı -->
      <div class="panel" style="padding:1.25rem;min-width:240px;flex:1">
        <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Tedarikçi Bilgileri</div>
        <?php if ($t['telefon']): ?>
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;font-size:.85rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--ink3);flex-shrink:0"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13 19.79 19.79 0 0 1 1.61 4.18 2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.06 6.06l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          <span style="color:var(--ink)"><?= htmlspecialchars($t['telefon']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($t['email']): ?>
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;font-size:.85rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--ink3);flex-shrink:0"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <span style="color:var(--ink)"><?= htmlspecialchars($t['email']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($t['adres']): ?>
        <div style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.5rem;font-size:.85rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--ink3);flex-shrink:0;margin-top:.15rem"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span style="color:var(--ink);line-height:1.4"><?= nl2br(htmlspecialchars($t['adres'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($t['not_']): ?>
        <div style="margin-top:.75rem;padding:.6rem .75rem;background:var(--bg);border-radius:6px;font-size:.82rem;color:var(--ink2);line-height:1.5"><?= nl2br(htmlspecialchars($t['not_'])) ?></div>
        <?php endif; ?>
        <?php if (!$t['telefon'] && !$t['email'] && !$t['adres'] && !$t['not_']): ?>
        <div style="color:var(--ink3);font-size:.82rem">Ek bilgi girilmemiş.</div>
        <?php endif; ?>
      </div>

      <!-- Özet Kartları -->
      <div style="display:flex;flex-direction:column;gap:.75rem;min-width:180px">
        <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.75rem 1.25rem">
          <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Toplam Fatura</div>
          <div style="font-size:1.5rem;font-weight:800;color:var(--ink);line-height:1.3"><?= $ozet['toplam_fatura'] ?></div>
        </div>
        <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.75rem 1.25rem">
          <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Bekleyen</div>
          <div style="font-size:1.3rem;font-weight:800;color:<?= $ozet['bekleyen_tutar'] > 0 ? 'var(--red,#dc2626)' : 'var(--ink3)' ?>;line-height:1.3">
            <?= $ozet['bekleyen_tutar'] > 0 ? '₺' . number_format($ozet['bekleyen_tutar'], 2, ',', '.') : '—' ?>
          </div>
        </div>
        <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.75rem 1.25rem">
          <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Ödenen</div>
          <div style="font-size:1.3rem;font-weight:800;color:var(--green,#16a34a);line-height:1.3">
            <?= $ozet['odenen_tutar'] > 0 ? '₺' . number_format($ozet['odenen_tutar'], 2, ',', '.') : '—' ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Fatura Listesi -->
    <div class="page-body">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
        <h3 style="font-size:.95rem;font-weight:700;color:var(--ink);margin:0">Faturalar</h3>
        <a href="/fatura-ekle?tedarikci_id=<?= $id ?>" class="btn btn-primary btn-sm">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Fatura
        </a>
      </div>

      <?php if (empty($faturalar)): ?>
        <div class="empty-state" style="padding:2rem">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <p style="margin:.5rem 0 0">Henüz fatura eklenmemiş.</p>
          <a href="/fatura-ekle?tedarikci_id=<?= $id ?>" class="btn btn-primary btn-sm" style="margin-top:.75rem">İlk Faturayı Ekle</a>
        </div>
      <?php else: ?>
        <div class="panel">
          <table class="data-table">
            <thead>
              <tr>
                <th>Fatura No</th>
                <th>Tarih</th>
                <th>Vade</th>
                <th style="text-align:right">Genel Toplam</th>
                <th style="text-align:center">Durum</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($faturalar as $f): ?>
              <tr class="clickable" onclick="location.href='/fatura?id=<?= $f['id'] ?>'">
                <td style="font-weight:600;color:var(--ink)"><?= htmlspecialchars($f['fatura_no'] ?: '—') ?></td>
                <td class="text-muted" style="font-size:.82rem"><?= date('d.m.Y', strtotime($f['tarih'])) ?></td>
                <td class="text-muted" style="font-size:.82rem">
                  <?php if ($f['vade_tarihi']): ?>
                    <?php $vadeGecti = strtotime($f['vade_tarihi']) < time() && $f['durum'] !== 'odendi'; ?>
                    <span style="color:<?= $vadeGecti ? 'var(--red,#dc2626)' : 'var(--ink2)' ?>"><?= date('d.m.Y', strtotime($f['vade_tarihi'])) ?></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td style="text-align:right;font-family:var(--font-display);font-weight:800;font-variant-numeric:tabular-nums">
                  ₺<?= number_format($f['genel_toplam'], 2, ',', '.') ?>
                </td>
                <td style="text-align:center">
                  <?php if ($f['durum'] === 'odendi'): ?>
                    <span class="badge badge-green">Ödendi</span>
                  <?php elseif ($f['vade_tarihi'] && strtotime($f['vade_tarihi']) < time()): ?>
                    <span class="badge badge-red">Vadesi Geçti</span>
                  <?php else: ?>
                    <span class="badge badge-yellow">Bekliyor</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right" onclick="event.stopPropagation()">
                  <a href="/fatura?id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm">Detay</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<?php if (!empty($kasaHesaplari)): ?>
<!-- Ödeme Yap Modalı -->
<div class="vrm-overlay" id="odemeModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="vrm-dialog" style="max-width:460px">
    <div style="padding:1.25rem 1.5rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div>
        <div style="font-family:var(--font-display);font-weight:800;font-size:.95rem">Tedarikçiye Ödeme Yap</div>
        <div style="font-size:.72rem;color:var(--ink3);margin-top:.1rem"><?= htmlspecialchars($t['ad']) ?></div>
      </div>
      <button class="btn-icon" onclick="document.getElementById('odemeModal').classList.remove('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="post" action="/tedarikci-odeme" id="odemeForm">
      <input type="hidden" name="tedarikci_id" value="<?= $id ?>">
      <div style="padding:1.25rem 1.5rem">

        <!-- Kasa Hesabı Seç -->
        <div class="form-group">
          <label class="form-label">Kasa Hesabı <span style="color:var(--red)">*</span></label>
          <select name="hesap_id" class="form-input" required id="kasaSecim" onchange="kasaBakiyeGuncelle()">
            <option value="">Hesap seçin...</option>
            <?php foreach ($kasaHesaplari as $kh):
              $bakiye = $kasaBakiye[$kh['id']] ?? 0;
            ?>
            <option value="<?= $kh['id'] ?>" data-bakiye="<?= $bakiye ?>">
              <?= htmlspecialchars($kh['ad']) ?> — ₺<?= number_format($bakiye, 2, ',', '.') ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="kasaBakiyeInfo" style="margin-top:.35rem;font-size:.78rem;color:var(--ink3);display:none">
            Mevcut bakiye: <strong id="kasaBakiyeGoster" style="color:var(--ink)"></strong>
          </div>
        </div>

        <!-- Fatura Seç (opsiyonel) -->
        <?php if (!empty($odenmemisFaturalar)): ?>
        <div class="form-group">
          <label class="form-label">Fatura <span style="color:var(--ink3)">(opsiyonel — seçilmezse genel ödeme)</span></label>
          <select name="fatura_id" class="form-input" id="faturaSecim" onchange="faturaSecildi()">
            <option value="">Genel ödeme (fatura seçme)</option>
            <?php foreach ($odenmemisFaturalar as $of): ?>
            <option value="<?= $of['id'] ?>" data-tutar="<?= $of['genel_toplam'] ?>">
              <?= htmlspecialchars($of['fatura_no'] ?: 'Fatura #'.$of['id']) ?>
              — <?= date('d.m.Y', strtotime($of['tarih'])) ?>
              — ₺<?= number_format($of['genel_toplam'], 2, ',', '.') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <!-- Tutar -->
        <div class="form-group">
          <label class="form-label">Ödeme Tutarı (₺) <span style="color:var(--red)">*</span></label>
          <input type="number" name="tutar" id="odemeTutar" class="form-input" step="0.01" min="0.01" required placeholder="0,00"
            style="font-size:1.15rem;font-weight:700;text-align:right;font-variant-numeric:tabular-nums">
          <div style="margin-top:.35rem;font-size:.78rem;color:var(--ink3)">
            Toplam bekleyen: <strong style="color:var(--red,#dc2626)">₺<?= number_format($ozet['bekleyen_tutar'], 2, ',', '.') ?></strong>
          </div>
        </div>

        <!-- Açıklama -->
        <div class="form-group">
          <label class="form-label">Açıklama <span style="color:var(--ink3)">(opsiyonel)</span></label>
          <input type="text" name="aciklama" class="form-input" placeholder="Ödeme notu...">
        </div>

        <!-- Tarih -->
        <div class="form-group">
          <label class="form-label">Tarih</label>
          <input type="date" name="tarih" class="form-input" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="vrm-dialog-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('odemeModal').classList.remove('open')">İptal</button>
        <button type="submit" class="btn btn-primary">Ödemeyi Kaydet</button>
      </div>
    </form>
  </div>
</div>

<script>
function odemeModalAc() {
  document.getElementById('odemeModal').classList.add('open');
  setTimeout(function() { document.getElementById('odemeTutar').focus(); }, 150);
}
function kasaBakiyeGuncelle() {
  var sel = document.getElementById('kasaSecim');
  var opt = sel.options[sel.selectedIndex];
  var info = document.getElementById('kasaBakiyeInfo');
  if (!opt.value) { info.style.display = 'none'; return; }
  var bakiye = parseFloat(opt.dataset.bakiye) || 0;
  document.getElementById('kasaBakiyeGoster').textContent = '₺' + bakiye.toFixed(2).replace('.', ',');
  info.style.display = 'block';
}
function faturaSecildi() {
  var sel = document.getElementById('faturaSecim');
  if (!sel) return;
  var opt = sel.options[sel.selectedIndex];
  if (opt.value && opt.dataset.tutar) {
    document.getElementById('odemeTutar').value = parseFloat(opt.dataset.tutar).toFixed(2);
  }
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') document.getElementById('odemeModal').classList.remove('open');
});
</script>
<?php endif; ?>

</body>
</html>
