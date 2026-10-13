<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'tahsilat-al';
$uid = userId();

// ── Tahsilat kaydet (AJAX / POST) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'genel-tahsilat') {
    header('Content-Type: application/json');
    $cariId      = (int)($_POST['cari_id'] ?? 0);
    $tutar       = (float)($_POST['tutar'] ?? 0);
    $tarih       = trim($_POST['tarih'] ?? '');
    $acikl       = trim($_POST['aciklama'] ?? '');
    $kasaHesapId = (int)($_POST['kasa_hesap_id'] ?? 0) ?: null;

    if (!$cariId || $tutar <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
        echo json_encode(['ok' => false, 'msg' => 'Eksik veya hatalı veri.']); exit;
    }
    // Carinin bu kullanıcıya ait olup olmadığını doğrula
    $chk = $pdo->prepare("SELECT id FROM cariler WHERE id=? AND user_id=?");
    $chk->execute([$cariId, $uid]);
    if (!$chk->fetch()) {
        echo json_encode(['ok' => false, 'msg' => 'Cari bulunamadı.']); exit;
    }

    // En eski ödenmemiş vadeli hareketlere sırayla uygula
    $hStmt = $pdo->prepare("
        SELECT h.id, h.tutar,
               COALESCE((SELECT SUM(t.tutar) FROM tahsilatlar t WHERE t.hareket_id = h.id),0) AS tahsil_edilen
        FROM hareketler h
        WHERE h.cari_id = ? AND h.tip = 'borc'
        ORDER BY h.tarih ASC, h.id ASC
    ");
    $hStmt->execute([$cariId]);
    $vadeli = array_filter($hStmt->fetchAll(), function($v){ return ($v['tutar'] - $v['tahsil_edilen']) > 0.001; });

    $kalan = $tutar;
    $pdo->beginTransaction();
    try {
        foreach ($vadeli as $v) {
            if ($kalan <= 0) break;
            $borc = $v['tutar'] - $v['tahsil_edilen'];
            $uygulanan = min($kalan, $borc);
            $pdo->prepare("INSERT INTO tahsilatlar (cari_id, hareket_id, tutar, tarih, aciklama) VALUES (?,?,?,?,?)")
               ->execute([$cariId, $v['id'], $uygulanan, $tarih, $acikl]);
            $kalan -= $uygulanan;
        }
        // Kasaya giriş kaydı (gerçekten uygulanan tutar)
        $uygulanandi = round($tutar - $kalan, 2);
        if ($uygulanandi > 0) {
            $cariAdQ = $pdo->prepare("SELECT ad FROM cariler WHERE id=?");
            $cariAdQ->execute([$cariId]);
            $cariAdQ = $cariAdQ->fetch();
            $ka = ($acikl ?: 'Tahsilat') . ' — ' . ($cariAdQ['ad'] ?? 'Cari #'.$cariId);
            $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih) VALUES (?,?,'giris',?,?,'tahsilat',?,?)")
                ->execute([$uid, $kasaHesapId, $uygulanandi, $ka, $cariId, $tarih]);
        }
        $pdo->commit();
        apiSync('post', '/tahsilat/genel', [
            'cari_id' => $cariId, 'tutar' => $tutar,
            'tarih' => $tarih, 'aciklama' => $acikl ?: null, 'hesap_id' => $kasaHesapId,
        ]);
        echo json_encode(['ok' => true, 'kalan_odeme' => round($kalan, 2)]); exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Kayıt hatası.']); exit;
    }
}

// ── Cari listesi ─────────────────────────────────────────────
$cariler = $pdo->prepare("SELECT id, ad, telefon FROM cariler WHERE user_id=? ORDER BY ad ASC");
$cariler->execute([$uid]);
$cariler = $cariler->fetchAll();

// ── Seçili carinin vadeli borçları ───────────────────────────
$secilenCariId = (int)($_GET['cari_id'] ?? 0);
$secilenCari   = null;
$vadelivadeler = [];
$toplamVadeli  = 0;
$toplamTahsil  = 0;
$kalanBorc     = 0;

if ($secilenCariId) {
    $cs = $pdo->prepare("SELECT * FROM cariler WHERE id=? AND user_id=?");
    $cs->execute([$secilenCariId, $uid]);
    $secilenCari = $cs->fetch();

    if ($secilenCari) {
        $hStmt = $pdo->prepare("
            SELECT h.id, h.tutar, h.tarih, h.vade_tarihi, h.aciklama, h.grup_id,
                   COALESCE((SELECT SUM(t.tutar) FROM tahsilatlar t WHERE t.hareket_id = h.id),0) AS tahsil_edilen,
                   s.ad AS stok_ad, s.birim AS stok_birim, h.stok_miktar
            FROM hareketler h
            LEFT JOIN stoklar s ON s.id = h.stok_id
            WHERE h.cari_id = ? AND h.tip = 'borc'
            ORDER BY h.tarih ASC, h.id ASC
        ");
        $hStmt->execute([$secilenCariId]);
        $tumVadeli     = $hStmt->fetchAll();
        $vadelivadeler = array_values(array_filter($tumVadeli, function($v){ return ($v['tutar'] - $v['tahsil_edilen']) > 0.001; }));

        // Kalan borç: TÜM vadeli hareketler üzerinden hesapla (tamamen ödenenler dahil)
        foreach ($tumVadeli as $v) {
            $toplamVadeli += $v['tutar'];
            $toplamTahsil += $v['tahsil_edilen'];
        }
        $kalanBorc = $toplamVadeli - $toplamTahsil;
    }
}

$kasaHesaplari = $pdo->prepare("SELECT id, ad FROM kasa_hesaplari WHERE user_id=? AND aktif=1 ORDER BY id ASC");
$kasaHesaplari->execute([$uid]);
$kasaHesaplari = $kasaHesaplari->fetchAll();

$bugun     = date('Y-m-d');
$pageTitle = 'Tahsilat Al';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tahsilat Al — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.tahsilat-layout { display:grid; grid-template-columns:340px 1fr; gap:1.25rem; align-items:start; }
@media(max-width:900px){ .tahsilat-layout { grid-template-columns:1fr; } }

.tah-panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--r2); padding:1.25rem; }
.tah-panel-title { font-weight:700; font-size:.95rem; margin-bottom:1rem; }

.borc-ozet { display:grid; grid-template-columns:1fr 1fr; gap:.65rem; margin-bottom:1.25rem; }
.borc-kart { background:var(--surface2); border:1px solid var(--border); border-radius:var(--r); padding:.75rem 1rem; }
.borc-kart .bk-lbl { font-size:.7rem; color:var(--ink3); margin-bottom:.2rem; }
.borc-kart .bk-val { font-size:1rem; font-weight:800; font-variant-numeric:tabular-nums; }

.tutar-hizli { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.5rem; }
.tutar-hizli button { padding:.3rem .75rem; border-radius:var(--r); border:1.5px solid var(--border2);
  background:var(--surface2); color:var(--ink2); font-size:.78rem; font-weight:600; cursor:pointer; transition:.12s; }
.tutar-hizli button:hover { border-color:var(--green); color:var(--green); background:var(--green-lt); }

.vade-listesi table { width:100%; border-collapse:collapse; font-size:.82rem; }
.vade-listesi th { padding:.5rem .75rem; text-align:left; border-bottom:1.5px solid var(--border2);
  font-size:.72rem; color:var(--ink3); font-weight:600; white-space:nowrap; }
.vade-listesi td { padding:.5rem .75rem; border-bottom:1px solid var(--border); }
.vade-listesi tr:last-child td { border-bottom:none; }

.cari-arama-wrap { position:relative; }
.cari-dropdown { position:absolute; top:100%; left:0; right:0; z-index:99; background:var(--surface);
  border:1.5px solid var(--border2); border-radius:var(--r); box-shadow:var(--shadow); max-height:220px; overflow-y:auto; }
.cari-dropdown .cd-item { padding:.5rem .85rem; cursor:pointer; font-size:.85rem; border-bottom:1px solid var(--border); }
.cari-dropdown .cd-item:hover { background:var(--surface2); }
.cari-dropdown .cd-item:last-child { border-bottom:none; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Tahsilat Al</div>
        <div class="page-sub">Vadeli borcu olan carilerden tahsilat girin</div>
      </div>
    </div>

    <div class="page-body">
      <div class="tahsilat-layout">

        <!-- Sol: cari seç + tahsilat formu -->
        <div>
          <div class="tah-panel" style="margin-bottom:1rem">
            <div class="tah-panel-title">Cari Seç</div>
            <div class="cari-arama-wrap">
              <input type="text" id="cariAraInput" class="form-input" placeholder="Cari adı ara…"
                autocomplete="off" oninput="cariAra(this.value)" onfocus="cariAra(this.value)">
              <div id="cariDropdown" class="cari-dropdown" style="display:none"></div>
            </div>
            <?php if ($secilenCari): ?>
              <div style="margin-top:.75rem;padding:.65rem .85rem;background:var(--green-lt);border-radius:var(--r);font-size:.85rem">
                <strong style="color:var(--green)"><?= htmlspecialchars($secilenCari['ad']) ?></strong>
                <?php if ($secilenCari['telefon']): ?>
                  <span style="color:var(--ink3);margin-left:.4rem"><?= htmlspecialchars($secilenCari['telefon']) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($secilenCari): ?>
          <!-- Borç özeti -->
          <div class="tah-panel" style="margin-bottom:1rem">
            <div class="tah-panel-title">Borç Durumu</div>
            <div class="borc-ozet">
              <div class="borc-kart">
                <div class="bk-lbl">Toplam Vadeli</div>
                <div class="bk-val" style="color:var(--blue)">₺<?= number_format($toplamVadeli,2,',','.') ?></div>
              </div>
              <div class="borc-kart">
                <div class="bk-lbl">Tahsil Edilen</div>
                <div class="bk-val" style="color:#7C3AED">₺<?= number_format($toplamTahsil,2,',','.') ?></div>
              </div>
              <div class="borc-kart" style="grid-column:span 2">
                <div class="bk-lbl">Kalan Borç</div>
                <div class="bk-val" style="color:<?= $kalanBorc > 0 ? 'var(--red)' : 'var(--green)' ?>;font-size:1.2rem">
                  <?= $kalanBorc > 0 ? '₺'.number_format($kalanBorc,2,',','.') : '✓ Borç yok' ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Tahsilat formu -->
          <?php if ($kalanBorc > 0): ?>
          <div class="tah-panel">
            <div class="tah-panel-title">Tahsilat Gir</div>
            <div style="margin-bottom:.75rem">
              <label class="form-label">Tarih</label>
              <input type="date" id="tah_tarih" class="form-input" value="<?= $bugun ?>">
            </div>
            <div style="margin-bottom:.5rem">
              <label class="form-label">Tutar (₺)</label>
              <input type="number" id="tah_tutar" class="form-input" step="0.01" min="0.01"
                max="<?= $kalanBorc ?>" placeholder="0,00" oninput="tutarDegis()">
              <div class="tutar-hizli">
                <button type="button" onclick="setTutar(<?= $kalanBorc ?>)">Tamamı (₺<?= number_format($kalanBorc,2,',','.') ?>)</button>
                <?php if ($kalanBorc > 100): ?>
                  <button type="button" onclick="setTutar(<?= round($kalanBorc/2, 2) ?>)">Yarısı</button>
                <?php endif; ?>
                <button type="button" onclick="setTutar(100)">₺100</button>
                <button type="button" onclick="setTutar(500)">₺500</button>
              </div>
            </div>
            <div style="margin-bottom:1rem">
              <label class="form-label">Açıklama (isteğe bağlı)</label>
              <input type="text" id="tah_acikl" class="form-input" placeholder="Nakit, havale vb.">
            </div>
            <?php if (!empty($kasaHesaplari)): ?>
            <div style="margin-bottom:1rem">
              <label class="form-label">Kasa Hesabı</label>
              <select id="tah_kasa_hesap" class="form-input">
                <option value="">— Hesap seç —</option>
                <?php foreach ($kasaHesaplari as $kh): ?>
                <option value="<?= $kh['id'] ?>"><?= htmlspecialchars($kh['ad']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div id="tahsilatBilgi" style="display:none;margin-bottom:.75rem;padding:.6rem .85rem;border-radius:var(--r);font-size:.82rem"></div>
            <button class="btn btn-primary" style="width:100%;background:var(--green)" onclick="tahsilatKaydet()">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="margin-right:.4rem;vertical-align:middle"><polyline points="20 6 9 17 4 12"/></svg>
              Tahsilatı Kaydet
            </button>
          </div>
          <?php else: ?>
          <div class="tah-panel" style="text-align:center;color:var(--green);padding:2rem">
            <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto .75rem"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Bu carinin vadeli borcu bulunmuyor.
          </div>
          <?php endif; ?>
          <?php else: ?>
          <div class="tah-panel" style="text-align:center;color:var(--ink3);padding:2.5rem">
            <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24" style="display:block;margin:0 auto .75rem"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            Tahsilat almak için sol üstten bir cari seçin.
          </div>
          <?php endif; ?>
        </div>

        <!-- Sağ: vadeli borç listesi -->
        <div class="tah-panel">
          <div class="tah-panel-title">
            <?php if ($secilenCari): ?>
              <?= htmlspecialchars($secilenCari['ad']) ?> — Ödenmemiş Vadeli Kayıtlar
              <span style="font-weight:400;color:var(--ink3);font-size:.8rem;margin-left:.5rem">(<?= count($vadelivadeler) ?> kalem)</span>
            <?php else: ?>
              Vadeli Borç Listesi
            <?php endif; ?>
          </div>
          <?php if (empty($vadelivadeler)): ?>
            <div style="padding:2rem;text-align:center;color:var(--ink3)">
              <?= $secilenCari ? 'Tüm vadeli borçlar tahsil edilmiş.' : 'Cari seçilmedi.' ?>
            </div>
          <?php else: ?>
          <div class="vade-listesi">
            <table>
              <thead>
                <tr>
                  <th>Tarih</th>
                  <th>Ürün / Açıklama</th>
                  <th>Vade</th>
                  <th class="text-right">Borç</th>
                  <th class="text-right">Tahsil</th>
                  <th class="text-right">Kalan</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($vadelivadeler as $v):
                $kalan = $v['tutar'] - $v['tahsil_edilen'];
                $vadeStr = $v['vade_tarihi'] ? date('d.m.Y', strtotime($v['vade_tarihi'])) : '—';
                $vg = $v['vade_tarihi'] ? (int)((strtotime($v['vade_tarihi']) - strtotime($bugun)) / 86400) : 999;
                $vc = $vg < 0 ? 'var(--red)' : ($vg <= 7 ? 'var(--gold)' : 'var(--ink3)');
              ?>
              <tr>
                <td style="white-space:nowrap;color:var(--ink3)"><?= date('d.m.Y', strtotime($v['tarih'])) ?></td>
                <td>
                  <?php if ($v['stok_ad']): ?>
                    <strong><?= htmlspecialchars($v['stok_ad']) ?></strong>
                    <?php if ($v['stok_miktar']): ?>
                      <span style="color:var(--ink3);font-size:.75rem"> ×<?= number_format($v['stok_miktar'],2,',','.') ?> <?= htmlspecialchars($v['stok_birim']??'') ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="color:var(--ink3)"><?= htmlspecialchars($v['aciklama'] ?? '—') ?></span>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <?php if ($v['vade_tarihi']): ?>
                    <span style="color:<?= $vc ?>"><?= $vadeStr ?></span>
                    <?php if ($vg < 0): ?><span style="font-size:.7rem;color:var(--red)"> (<?= abs($vg) ?>g geçti)</span><?php endif; ?>
                  <?php else: ?>
                    <span style="color:var(--ink3)">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-right" style="font-weight:600;white-space:nowrap">₺<?= number_format($v['tutar'],2,',','.') ?></td>
                <td class="text-right" style="color:#7C3AED;white-space:nowrap">
                  <?= $v['tahsil_edilen'] > 0 ? '₺'.number_format($v['tahsil_edilen'],2,',','.') : '<span style="color:var(--ink3)">—</span>' ?>
                </td>
                <td class="text-right" style="font-weight:700;color:var(--red);white-space:nowrap">₺<?= number_format($kalan,2,',','.') ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="font-weight:700;border-top:2px solid var(--border2)">
                  <td colspan="3" style="padding:.5rem .75rem;color:var(--ink2);font-size:.8rem">TOPLAM KALAN</td>
                  <td class="text-right" style="padding:.5rem .75rem">₺<?= number_format($toplamVadeli,2,',','.') ?></td>
                  <td class="text-right" style="padding:.5rem .75rem;color:#7C3AED">₺<?= number_format($toplamTahsil,2,',','.') ?></td>
                  <td class="text-right" style="padding:.5rem .75rem;color:var(--red)">₺<?= number_format($kalanBorc,2,',','.') ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </main>
</div>

<script>
var _cariler = <?= json_encode(array_map(function($c){ return ['id'=>$c['id'],'ad'=>$c['ad'],'tel'=>$c['telefon']??'']; }, $cariler), JSON_UNESCAPED_UNICODE) ?>;
var _kalanBorc = <?= json_encode($kalanBorc) ?>;

function cariAra(q) {
  var dd = document.getElementById('cariDropdown');
  if (!q.trim()) { dd.style.display = 'none'; return; }
  var r = _cariler.filter(function(c){ return c.ad.toLowerCase().indexOf(q.toLowerCase()) >= 0; });
  if (!r.length) { dd.style.display = 'none'; return; }
  dd.innerHTML = r.slice(0,10).map(function(c){
    return '<div class="cd-item" onclick="cariSec('+c.id+')"><strong>'+c.ad+'</strong>'+(c.tel?' <span style="color:var(--ink3);font-size:.78rem">'+c.tel+'</span>':'')+'</div>';
  }).join('');
  dd.style.display = 'block';
}

function cariSec(id) {
  document.getElementById('cariDropdown').style.display = 'none';
  window.location.href = '/tahsilat-al?cari_id=' + id;
}

document.addEventListener('click', function(e) {
  if (!e.target.closest('.cari-arama-wrap')) {
    var dd = document.getElementById('cariDropdown');
    if (dd) dd.style.display = 'none';
  }
});

function setTutar(val) {
  var inp = document.getElementById('tah_tutar');
  if (inp) { inp.value = val; tutarDegis(); }
}

function tutarDegis() {
  var tutar  = parseFloat(document.getElementById('tah_tutar').value) || 0;
  var bilgi  = document.getElementById('tahsilatBilgi');
  if (!bilgi) return;
  if (tutar <= 0) { bilgi.style.display = 'none'; return; }
  bilgi.style.display = 'block';
  if (tutar >= _kalanBorc - 0.001) {
    bilgi.style.cssText = 'display:block;margin-bottom:.75rem;padding:.6rem .85rem;border-radius:var(--r);font-size:.82rem;background:var(--green-lt);color:var(--green)';
    bilgi.textContent = '✓ Tüm borç kapatılacak (₺' + _kalanBorc.toFixed(2).replace('.',',') + ')';
  } else {
    var kalan = _kalanBorc - tutar;
    bilgi.style.cssText = 'display:block;margin-bottom:.75rem;padding:.6rem .85rem;border-radius:var(--r);font-size:.82rem;background:var(--surface2);color:var(--ink2)';
    bilgi.textContent = 'Kısmi ödeme — kalan borç: ₺' + kalan.toFixed(2).replace('.',',');
  }
}

function tahsilatKaydet() {
  var tutar = parseFloat(document.getElementById('tah_tutar').value) || 0;
  var tarih = document.getElementById('tah_tarih').value;
  var acikl = document.getElementById('tah_acikl').value;

  if (tutar <= 0) { vrmToast('Geçerli bir tutar girin.', 'warn'); return; }
  if (!tarih)     { vrmToast('Tarih seçin.', 'warn'); return; }

  var kasaEl = document.getElementById('tah_kasa_hesap');
  var kasaId = kasaEl ? kasaEl.value : '';

  var fd = new FormData();
  fd.append('action',         'genel-tahsilat');
  fd.append('cari_id',        <?= json_encode($secilenCariId) ?>);
  fd.append('tutar',          tutar);
  fd.append('tarih',          tarih);
  fd.append('aciklama',       acikl);
  fd.append('kasa_hesap_id',  kasaId);

  fetch('/tahsilat-al', { method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.ok) {
        window.location.reload();
      } else {
        vrmToast(d.msg || 'Hata oluştu.', 'error');
      }
    });
}
</script>
</body>
</html>
