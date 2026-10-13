<?php
/**
 * veresiyem.com.tr/stats  (veya /app-stats, erişimi koruyun!)
 * Admin istatistik paneli — tarayıcıdan açılır.
 *
 * Basit şifre koruması: URL'ye ?key=GIZLI_SIFRE ekleyin
 * ya da sunucu seviyesinde Basic Auth kullanın.
 */

define('STATS_KEY', 'vrm_stats_2024_gizli'); // Değiştirin!

if (($_GET['key'] ?? '') !== STATS_KEY) {
    http_response_code(403);
    die('<h2>403 Yetkisiz Erişim</h2>');
}

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'veresiyem_stats';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die('Veritabanı bağlantı hatası');
}

// ---- Sorgular ----
$toplam_kurulum  = $pdo->query("SELECT COUNT(*) FROM app_pings WHERE event='install'")->fetchColumn();
$toplam_acilis   = $pdo->query("SELECT COUNT(*) FROM app_pings WHERE event='open'")->fetchColumn();
$tekil_cihaz     = $pdo->query("SELECT COUNT(DISTINCT uuid) FROM app_pings")->fetchColumn();
$bugun_acilis    = $pdo->query("SELECT COUNT(*) FROM app_pings WHERE event='open' AND DATE(created_at)=CURDATE()")->fetchColumn();
$bu_hafta        = $pdo->query("SELECT COUNT(*) FROM app_pings WHERE event='open' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// Lisans istatistikleri
$toplam_lisans      = $pdo->query("SELECT COUNT(*) FROM app_pings WHERE event='lisans'")->fetchColumn();
$tekil_lisans       = $pdo->query("SELECT COUNT(DISTINCT uuid) FROM app_pings WHERE event='lisans'")->fetchColumn();
$bu_hafta_lisans    = $pdo->query("SELECT COUNT(DISTINCT uuid) FROM app_pings WHERE event='lisans' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$bu_ay_lisans       = $pdo->query("SELECT COUNT(DISTINCT uuid) FROM app_pings WHERE event='lisans' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
// Dönüşüm oranı: kuranlar içinden lisanslayanlar
$donusum = $toplam_kurulum > 0 ? round(($tekil_lisans / $toplam_kurulum) * 100, 1) : 0;

// Son 30 gün günlük lisanslama
$lisans_gun_verisi = $pdo->query("
    SELECT DATE(created_at) AS gun, COUNT(DISTINCT uuid) AS sayi
    FROM app_pings WHERE event='lisans' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY gun ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Son lisanslamalar
$son_lisanslar = $pdo->query("
    SELECT uuid, version, platform, created_at
    FROM app_pings WHERE event='lisans'
    ORDER BY created_at DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Son 30 gün günlük açılış
$gun_verisi = $pdo->query("
    SELECT DATE(created_at) AS gun, COUNT(*) AS sayi
    FROM app_pings WHERE event='open' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY gun ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Versiyon dağılımı
$versiyonlar = $pdo->query("
    SELECT version, COUNT(DISTINCT uuid) AS cihaz
    FROM app_pings WHERE event IN ('install','open') AND version IS NOT NULL
    GROUP BY version ORDER BY cihaz DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Platform dağılımı
$platformlar = $pdo->query("
    SELECT platform, COUNT(DISTINCT uuid) AS cihaz
    FROM app_pings WHERE platform IS NOT NULL
    GROUP BY platform ORDER BY cihaz DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Son 20 kurulum
$son_kurulumlar = $pdo->query("
    SELECT uuid, version, platform, country, created_at
    FROM app_pings WHERE event='install'
    ORDER BY created_at DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Sparkline için veri
$sparkDays   = array_column($gun_verisi, 'gun');
$sparkCounts = array_column($gun_verisi, 'sayi');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Veresiyem · Uygulama İstatistikleri</title>
<style>
:root {
  --bg:#0f1117; --surface:#1a1d27; --surface2:#22263a; --border:rgba(255,255,255,.08);
  --text:#f1f5f9; --muted:rgba(255,255,255,.45); --green:#4ade80; --blue:#60a5fa;
  --gold:#fbbf24; --red:#f87171; --r:10px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;min-height:100vh;padding:2rem}
h1{font-size:1.4rem;font-weight:800;margin-bottom:.25rem}
.sub{font-size:.78rem;color:var(--muted);margin-bottom:2rem}
.sub em{color:var(--green);font-style:normal}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:2rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:1.25rem}
.card-val{font-size:2rem;font-weight:900;margin-bottom:.2rem}
.card-val.green{color:var(--green)}
.card-val.blue{color:var(--blue)}
.card-val.gold{color:var(--gold)}
.card-val.red{color:var(--red)}
.card-lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);font-weight:600}
.section{margin-bottom:2rem}
.section-title{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:700;margin-bottom:.75rem}
canvas{display:block;width:100%;height:160px;border-radius:var(--r);background:var(--surface)}
table{width:100%;border-collapse:collapse;font-size:.82rem}
th{text-align:left;padding:.5rem .75rem;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:600;border-bottom:1px solid var(--border)}
td{padding:.55rem .75rem;border-bottom:1px solid rgba(255,255,255,.04)}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:.15rem .5rem;border-radius:4px;font-size:.65rem;font-weight:700}
.badge-green{background:rgba(74,222,128,.12);color:var(--green)}
.badge-blue{background:rgba(96,165,250,.12);color:var(--blue)}
.badge-gold{background:rgba(251,191,36,.12);color:var(--gold)}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:640px){.two-col{grid-template-columns:1fr}}
.tbl-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.uuid{font-family:monospace;font-size:.72rem;color:var(--muted)}
.updated{font-size:.68rem;color:rgba(255,255,255,.2);text-align:right;margin-top:1.5rem}
.section-divider{height:1px;background:rgba(255,255,255,.06);margin:2rem 0}
.section-head{display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem}
.section-head-icon{width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.section-head-icon.gold{background:rgba(251,191,36,.15);color:var(--gold)}
.card-sub{font-size:.68rem;color:var(--muted);margin-top:.2rem}
.donusum-bar{height:6px;background:rgba(255,255,255,.08);border-radius:3px;margin-top:.6rem;overflow:hidden}
.donusum-bar-fill{height:100%;background:var(--gold);border-radius:3px;transition:width .6s}
</style>
</head>
<body>
<h1>Veresiyem · Uygulama İstatistikleri</h1>
<div class="sub">Canlı veriler — <em><?= date('d.m.Y H:i') ?></em> itibarıyla</div>

<div class="grid">
  <div class="card">
    <div class="card-val green"><?= number_format($toplam_kurulum) ?></div>
    <div class="card-lbl">Toplam Kurulum</div>
  </div>
  <div class="card">
    <div class="card-val blue"><?= number_format($tekil_cihaz) ?></div>
    <div class="card-lbl">Tekil Cihaz</div>
  </div>
  <div class="card">
    <div class="card-val gold"><?= number_format($bugun_acilis) ?></div>
    <div class="card-lbl">Bugün Açılış</div>
  </div>
  <div class="card">
    <div class="card-val"><?= number_format($bu_hafta) ?></div>
    <div class="card-lbl">Bu Hafta Açılış</div>
  </div>
  <div class="card">
    <div class="card-val red"><?= number_format($toplam_acilis) ?></div>
    <div class="card-lbl">Toplam Açılış</div>
  </div>
</div>

<!-- LİSANS BÖLÜMÜ -->
<div class="section-divider"></div>
<div class="section-head">
  <div class="section-head-icon gold">
    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
  </div>
  <div class="section-title" style="margin-bottom:0">Lisans İstatistikleri</div>
</div>
<div class="grid">
  <div class="card">
    <div class="card-val gold"><?= number_format($tekil_lisans) ?></div>
    <div class="card-lbl">Lisanslı Kullanıcı</div>
  </div>
  <div class="card">
    <div class="card-val"><?= number_format($bu_hafta_lisans) ?></div>
    <div class="card-lbl">Bu Hafta Yeni Lisans</div>
  </div>
  <div class="card">
    <div class="card-val"><?= number_format($bu_ay_lisans) ?></div>
    <div class="card-lbl">Bu Ay Yeni Lisans</div>
  </div>
  <div class="card">
    <div class="card-val <?= $donusum >= 20 ? 'green' : ($donusum >= 5 ? 'gold' : 'red') ?>"><?= $donusum ?>%</div>
    <div class="card-lbl">Dönüşüm Oranı</div>
    <div class="card-sub">Kuranlar içinden lisanslayanlar</div>
    <div class="donusum-bar"><div class="donusum-bar-fill" style="width:<?= min($donusum, 100) ?>%"></div></div>
  </div>
</div>

<div class="section">
  <div class="section-title">Son 30 Gün — Günlük Lisanslama</div>
  <canvas id="lisansCanvas"></canvas>
</div>

<div class="section">
  <div class="section-title">Son Lisanslamalar</div>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Tarih</th><th>UUID</th><th>Versiyon</th><th>Platform</th></tr></thead>
      <tbody>
      <?php foreach ($son_lisanslar as $l): ?>
        <tr>
          <td><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
          <td><span class="uuid"><?= substr($l['uuid'], 0, 16) ?>…</span></td>
          <td><?= $l['version'] ? '<span class="badge badge-gold">'.htmlspecialchars($l['version']).'</span>' : '—' ?></td>
          <td><?= htmlspecialchars($l['platform'] ?: '—') ?></td>
        </tr>
      <?php endforeach; if (!$son_lisanslar): ?>
        <tr><td colspan="4" style="color:var(--muted);text-align:center;padding:1.5rem">Henüz lisans kaydı yok</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="section-divider"></div>

<div class="section">
  <div class="section-title">Son 30 Gün — Günlük Açılış</div>
  <canvas id="sparkCanvas"></canvas>
</div>

<div class="two-col" style="margin-bottom:2rem">
  <div>
    <div class="section-title">Versiyon Dağılımı</div>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Versiyon</th><th>Cihaz</th></tr></thead>
        <tbody>
        <?php foreach ($versiyonlar as $v): ?>
          <tr>
            <td><span class="badge badge-blue"><?= htmlspecialchars($v['version']) ?></span></td>
            <td><?= $v['cihaz'] ?></td>
          </tr>
        <?php endforeach; if (!$versiyonlar): ?>
          <tr><td colspan="2" style="color:var(--muted);text-align:center;padding:1rem">Veri yok</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div>
    <div class="section-title">Platform Dağılımı</div>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Platform</th><th>Cihaz</th></tr></thead>
        <tbody>
        <?php foreach ($platformlar as $p): ?>
          <tr>
            <td><span class="badge badge-gold"><?= htmlspecialchars($p['platform'] ?: '—') ?></span></td>
            <td><?= $p['cihaz'] ?></td>
          </tr>
        <?php endforeach; if (!$platformlar): ?>
          <tr><td colspan="2" style="color:var(--muted);text-align:center;padding:1rem">Veri yok</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="section">
  <div class="section-title">Son Kurulumlar</div>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Tarih</th><th>UUID</th><th>Versiyon</th><th>Platform</th><th>Ülke</th></tr></thead>
      <tbody>
      <?php foreach ($son_kurulumlar as $k): ?>
        <tr>
          <td><?= date('d.m.Y H:i', strtotime($k['created_at'])) ?></td>
          <td><span class="uuid"><?= substr($k['uuid'], 0, 16) ?>…</span></td>
          <td><?= $k['version'] ? '<span class="badge badge-blue">'.htmlspecialchars($k['version']).'</span>' : '—' ?></td>
          <td><?= htmlspecialchars($k['platform'] ?: '—') ?></td>
          <td><?= htmlspecialchars($k['country'] ?: '—') ?></td>
        </tr>
      <?php endforeach; if (!$son_kurulumlar): ?>
        <tr><td colspan="5" style="color:var(--muted);text-align:center;padding:1.5rem">Henüz kurulum kaydı yok</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="updated">Sayfa yenilenerek güncellenir · Şifre: URL'deki ?key= parametresi</div>

<script>
const days   = <?= json_encode($sparkDays) ?>;
const counts = <?= json_encode(array_map('intval', $sparkCounts)) ?>;
const lisansDays   = <?= json_encode(array_column($lisans_gun_verisi, 'gun')) ?>;
const lisansCounts = <?= json_encode(array_map('intval', array_column($lisans_gun_verisi, 'sayi'))) ?>;

const canvas = document.getElementById('sparkCanvas');
const dpr    = window.devicePixelRatio || 1;
canvas.width  = canvas.offsetWidth  * dpr;
canvas.height = canvas.offsetHeight * dpr;
const ctx = canvas.getContext('2d');
ctx.scale(dpr, dpr);

const W = canvas.offsetWidth;
const H = canvas.offsetHeight;
const pad = { top:20, right:20, bottom:32, left:44 };

if (counts.length > 0) {
  const maxV = Math.max(...counts, 1);
  const minV = 0;
  const xStep = (W - pad.left - pad.right) / Math.max(counts.length - 1, 1);

  // Grid lines
  ctx.strokeStyle = 'rgba(255,255,255,.06)';
  ctx.lineWidth = 1;
  for (let i = 0; i <= 4; i++) {
    const y = pad.top + (H - pad.top - pad.bottom) * (i / 4);
    ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
    ctx.fillStyle = 'rgba(255,255,255,.25)';
    ctx.font = '10px sans-serif';
    ctx.fillText(Math.round(maxV * (1 - i/4)), 4, y + 4);
  }

  // Area fill
  const grad = ctx.createLinearGradient(0, pad.top, 0, H - pad.bottom);
  grad.addColorStop(0, 'rgba(74,222,128,.25)');
  grad.addColorStop(1, 'rgba(74,222,128,.02)');
  ctx.beginPath();
  counts.forEach((v, i) => {
    const x = pad.left + i * xStep;
    const y = pad.top + (H - pad.top - pad.bottom) * (1 - (v - minV) / (maxV - minV));
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  });
  ctx.lineTo(pad.left + (counts.length - 1) * xStep, H - pad.bottom);
  ctx.lineTo(pad.left, H - pad.bottom);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  // Line
  ctx.beginPath();
  ctx.strokeStyle = '#4ade80';
  ctx.lineWidth = 2;
  counts.forEach((v, i) => {
    const x = pad.left + i * xStep;
    const y = pad.top + (H - pad.top - pad.bottom) * (1 - (v - minV) / (maxV - minV));
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  });
  ctx.stroke();

  // X-axis labels (ilk, orta, son)
  ctx.fillStyle = 'rgba(255,255,255,.3)';
  ctx.font = '10px sans-serif';
  const showIdx = [0, Math.floor((days.length-1)/2), days.length-1];
  showIdx.forEach(i => {
    if (!days[i]) return;
    const x = pad.left + i * xStep;
    const lbl = days[i].slice(5); // MM-DD
    ctx.fillText(lbl, x - 14, H - 8);
  });
} else {
  ctx.fillStyle = 'rgba(255,255,255,.2)';
  ctx.font = '14px sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText('Henüz veri yok', W/2, H/2);
}

// --- Lisans grafiği ---
function drawChart(canvasId, daysArr, countsArr, color, gradColor) {
  const cv  = document.getElementById(canvasId);
  if (!cv) return;
  const dpr2 = window.devicePixelRatio || 1;
  cv.width  = cv.offsetWidth  * dpr2;
  cv.height = cv.offsetHeight * dpr2;
  const cx = cv.getContext('2d');
  cx.scale(dpr2, dpr2);
  const W2 = cv.offsetWidth, H2 = cv.offsetHeight;
  const p = { top:20, right:20, bottom:32, left:44 };
  if (countsArr.length > 0) {
    const maxV2 = Math.max(...countsArr, 1);
    const xS2   = (W2 - p.left - p.right) / Math.max(countsArr.length - 1, 1);
    cx.strokeStyle = 'rgba(255,255,255,.06)'; cx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const y2 = p.top + (H2 - p.top - p.bottom) * (i / 4);
      cx.beginPath(); cx.moveTo(p.left, y2); cx.lineTo(W2 - p.right, y2); cx.stroke();
      cx.fillStyle = 'rgba(255,255,255,.25)'; cx.font = '10px sans-serif';
      cx.fillText(Math.round(maxV2 * (1 - i/4)), 4, y2 + 4);
    }
    const g2 = cx.createLinearGradient(0, p.top, 0, H2 - p.bottom);
    g2.addColorStop(0, gradColor); g2.addColorStop(1, 'rgba(0,0,0,0)');
    cx.beginPath();
    countsArr.forEach((v, i) => {
      const x2 = p.left + i * xS2;
      const y2 = p.top + (H2 - p.top - p.bottom) * (1 - v / maxV2);
      i === 0 ? cx.moveTo(x2, y2) : cx.lineTo(x2, y2);
    });
    cx.lineTo(p.left + (countsArr.length - 1) * xS2, H2 - p.bottom);
    cx.lineTo(p.left, H2 - p.bottom); cx.closePath();
    cx.fillStyle = g2; cx.fill();
    cx.beginPath(); cx.strokeStyle = color; cx.lineWidth = 2;
    countsArr.forEach((v, i) => {
      const x2 = p.left + i * xS2;
      const y2 = p.top + (H2 - p.top - p.bottom) * (1 - v / maxV2);
      i === 0 ? cx.moveTo(x2, y2) : cx.lineTo(x2, y2);
    });
    cx.stroke();
    cx.fillStyle = 'rgba(255,255,255,.3)'; cx.font = '10px sans-serif';
    [0, Math.floor((daysArr.length-1)/2), daysArr.length-1].forEach(i => {
      if (!daysArr[i]) return;
      cx.fillText(daysArr[i].slice(5), p.left + i * xS2 - 14, H2 - 8);
    });
  } else {
    cx.fillStyle = 'rgba(255,255,255,.2)'; cx.font = '14px sans-serif';
    cx.textAlign = 'center';
    cx.fillText('Henüz veri yok', W2/2, H2/2);
  }
}

drawChart('lisansCanvas', lisansDays, lisansCounts, '#fbbf24', 'rgba(251,191,36,.25)');
</script>
</body>
</html>
