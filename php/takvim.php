<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/sync.php';
girisGerekli();
$uid = userId();
$activePage = 'takvim';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    syncHatirlaticilar($pdo, $uid);
}

// Ay/yıl parametresi
$yil = (int)($_GET['yil'] ?? date('Y'));
$ay  = (int)($_GET['ay']  ?? date('n'));
if ($ay < 1)  { $ay = 12; $yil--; }
if ($ay > 12) { $ay =  1; $yil++; }

$ayBaslangic = sprintf('%04d-%02d-01', $yil, $ay);
$ayBitis     = date('Y-m-t', strtotime($ayBaslangic));
$bugun       = date('Y-m-d');

// O aya ait tüm aktif hatırlatıcıları çek
$stmt = $pdo->prepare("
    SELECT tarih, odeme_tip, tutar, baslik, id, durum, saat
    FROM hatirlaticilar
    WHERE user_id = ?
      AND tarih BETWEEN ? AND ?
    ORDER BY tarih ASC, saat ASC
");
$stmt->execute([$uid, $ayBaslangic, $ayBitis]);
$hatirlaticilar = $stmt->fetchAll();

// Güne göre grupla
$gunMap = []; // ['2026-09-24' => ['items'=>[], 'borc'=>0, 'alacak'=>0]]
foreach ($hatirlaticilar as $h) {
    $g = $h['tarih'];
    if (!isset($gunMap[$g])) {
        $gunMap[$g] = ['items' => [], 'borc' => 0.0, 'alacak' => 0.0];
    }
    $gunMap[$g]['items'][] = $h;
    if ($h['tutar'] !== null && $h['durum'] === 'aktif') {
        if ($h['odeme_tip'] === 'odeyecek') $gunMap[$g]['borc']   += (float)$h['tutar'];
        if ($h['odeme_tip'] === 'alacak')   $gunMap[$g]['alacak'] += (float)$h['tutar'];
    }
}

// Takvim hesapları
$ilkGun      = (int)date('N', strtotime($ayBaslangic)); // 1=Pzt, 7=Paz
$gunSayisi   = (int)date('t', strtotime($ayBaslangic));
$oncekiAyGun = (int)date('t', strtotime($ayBaslangic . ' -1 month'));

$ayAdlari = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$gunAdlari = ['Pzt','Sal','Çar','Per','Cum','Cmt','Paz'];

// Önceki/sonraki ay linkleri
$oncekiYil = $ay === 1 ? $yil - 1 : $yil;
$oncekiAy  = $ay === 1 ? 12 : $ay - 1;
$sonrakiYil = $ay === 12 ? $yil + 1 : $yil;
$sonrakiAy  = $ay === 12 ? 1 : $ay + 1;

// Seçili gün detayı (GET ile)
$seciliGun = $_GET['gun'] ?? null;
$seciliItems = $seciliGun && isset($gunMap[$seciliGun]) ? $gunMap[$seciliGun]['items'] : [];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Takvim — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
/* ── Takvim layout ── */
.tak-wrap {
  display: grid;
  grid-template-columns: 1fr 280px;
  gap: 1rem;
  height: 100%;
}
@media (max-width: 900px) {
  .tak-wrap { grid-template-columns: 1fr; }
  .tak-detay { display: none; }
}

/* ── Takvim grid ── */
.tak-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}
.tak-gun-baslik {
  text-align: center;
  font-size: .7rem;
  font-weight: 700;
  color: var(--ink3);
  padding: .35rem 0;
  letter-spacing: .04em;
}
.tak-gun-baslik:last-child { color: var(--red); }

.tak-hucre {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--r);
  min-height: 80px;
  padding: .45rem .5rem;
  cursor: pointer;
  transition: border-color .1s, box-shadow .1s;
  display: flex;
  flex-direction: column;
  gap: .25rem;
  position: relative;
}
.tak-hucre:hover { border-color: var(--border2); box-shadow: var(--shadow-sm); }
.tak-hucre.baska-ay { background: var(--surface2); opacity: .45; cursor: default; }
.tak-hucre.baska-ay:hover { border-color: var(--border); box-shadow: none; }
.tak-hucre.bugun-hucre { border-color: var(--green); background: var(--green-lt); }
.tak-hucre.secili { border-color: var(--blue); box-shadow: 0 0 0 2px rgba(37,99,235,.15); }
.tak-hucre.hafta-sonu .tak-gun-no { color: var(--red); }

.tak-gun-no {
  font-size: .78rem;
  font-weight: 700;
  color: var(--ink2);
  line-height: 1;
}
.tak-hucre.bugun-hucre .tak-gun-no {
  color: var(--green);
}

/* Hat nokta listesi */
.tak-hatlar { display: flex; flex-direction: column; gap: 2px; flex: 1; }
.tak-hat-satir {
  font-size: .65rem;
  font-weight: 600;
  padding: .12rem .3rem;
  border-radius: 3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}
.tak-hat-satir.aktif   { background: rgba(37,99,235,.1); color: var(--blue); }
.tak-hat-satir.tamam   { background: var(--surface2); color: var(--ink3); text-decoration: line-through; }
.tak-hat-satir.gecmis  { background: rgba(220,38,38,.08); color: var(--red); }
.tak-hat-satir.daha    { color: var(--ink3); font-size: .62rem; }

/* Ödeme badge'leri */
.tak-odeme-row {
  display: flex; gap: 3px; flex-wrap: wrap; margin-top: 2px;
}
.tak-odeme-chip {
  font-size: .6rem; font-weight: 800;
  padding: .1rem .35rem; border-radius: 3px;
  display: inline-flex; align-items: center; gap: 2px;
}
.tak-odeme-chip.borc   { background: var(--red-lt);   color: var(--red); }
.tak-odeme-chip.alacak { background: var(--green-lt); color: var(--green); }

/* ── Detay paneli ── */
.tak-detay {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.tak-detay-head {
  padding: .85rem 1rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface2);
  font-weight: 700;
  font-size: .88rem;
  color: var(--ink);
}
.tak-detay-bos {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  color: var(--ink3); font-size: .82rem; gap: .5rem;
  padding: 2rem;
  text-align: center;
}
.tak-detay-liste { flex: 1; overflow-y: auto; padding: .65rem; display: flex; flex-direction: column; gap: .4rem; }
.tak-detay-item {
  border: 1.5px solid var(--border);
  border-radius: var(--r);
  padding: .6rem .75rem;
  background: var(--surface);
}
.tak-detay-item.tamam { opacity: .5; }
.tak-detay-item-baslik {
  font-size: .84rem; font-weight: 700; color: var(--ink);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tak-detay-item.tamam .tak-detay-item-baslik { text-decoration: line-through; color: var(--ink3); }
.tak-detay-item-meta { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-top: .3rem; }
.tak-detay-saat { font-size: .72rem; font-weight: 600; color: var(--ink2); }
.tak-detay-odeme {
  font-size: .72rem; font-weight: 700;
  padding: .1rem .4rem; border-radius: 4px;
}
.tak-detay-odeme.borc   { background: var(--red-lt);   color: var(--red); }
.tak-detay-odeme.alacak { background: var(--green-lt); color: var(--green); }
.tak-detay-ozet {
  padding: .65rem 1rem;
  border-top: 1px solid var(--border);
  display: flex; gap: .5rem; flex-wrap: wrap;
}
.tak-detay-ozet-chip {
  flex: 1; min-width: 90px;
  border-radius: var(--r);
  padding: .5rem .65rem;
}
.tak-detay-ozet-chip.borc   { background: var(--red-lt); }
.tak-detay-ozet-chip.alacak { background: var(--green-lt); }
.tak-detay-ozet-lbl { font-size: .65rem; font-weight: 600; }
.tak-detay-ozet-chip.borc   .tak-detay-ozet-lbl { color: var(--red); }
.tak-detay-ozet-chip.alacak .tak-detay-ozet-lbl { color: var(--green); }
.tak-detay-ozet-val { font-size: .9rem; font-weight: 800; margin-top: .1rem; }
.tak-detay-ozet-chip.borc   .tak-detay-ozet-val { color: var(--red); }
.tak-detay-ozet-chip.alacak .tak-detay-ozet-val { color: var(--green); }

/* Ay navigasyonu */
.tak-nav {
  display: flex; align-items: center; gap: .75rem;
}
.tak-nav-btn {
  background: var(--surface); border: 1.5px solid var(--border);
  border-radius: var(--r); padding: .35rem .65rem;
  cursor: pointer; color: var(--ink2); font-size: .8rem;
  transition: all .1s; display: flex; align-items: center;
}
.tak-nav-btn:hover { border-color: var(--border2); background: var(--surface2); }
.tak-ay-baslik { font-size: 1rem; font-weight: 800; color: var(--ink); }
</style>
</head>
<body>
<?php require 'includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require 'includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Takvim</div>
        <div class="page-sub">Hatırlatıcılar ve ödemeler</div>
      </div>
      <div class="page-header-center">
        <div class="tak-nav">
          <a href="/takvim?yil=<?= $oncekiYil ?>&ay=<?= $oncekiAy ?>" class="tak-nav-btn">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          </a>
          <div class="tak-ay-baslik"><?= $ayAdlari[$ay] ?> <?= $yil ?></div>
          <a href="/takvim?yil=<?= $sonrakiYil ?>&ay=<?= $sonrakiAy ?>" class="tak-nav-btn">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
          <a href="/takvim?yil=<?= date('Y') ?>&ay=<?= date('n') ?>" class="tak-nav-btn" style="font-size:.75rem;font-weight:700;padding:.3rem .6rem">Bugün</a>
        </div>
      </div>
      <div class="page-header-right">
        <a href="/hatirlaticilar" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Hatırlatıcı
        </a>
      </div>
    </div>

    <div class="page-body" style="display:flex;flex-direction:column;gap:1rem;height:calc(100% - 64px);overflow:hidden">
      <div class="tak-wrap" style="flex:1;overflow:hidden">

        <!-- Sol: Takvim -->
        <div style="display:flex;flex-direction:column;gap:.5rem;overflow:hidden">
          <!-- Gün başlıkları -->
          <div class="tak-grid" style="min-height:0">
            <?php foreach ($gunAdlari as $i => $gad): ?>
            <div class="tak-gun-baslik" style="<?= $i===6?'color:var(--red)':'' ?>"><?= $gad ?></div>
            <?php endforeach; ?>
          </div>

          <!-- Hücreler -->
          <div class="tak-grid" style="flex:1;overflow-y:auto;align-content:start">
            <?php
            // Önceki aydan dolgu
            for ($d = $ilkGun - 1; $d >= 1; $d--):
                $dolguGun = $oncekiAyGun - $d + 1;
            ?>
            <div class="tak-hucre baska-ay">
              <div class="tak-gun-no"><?= $dolguGun ?></div>
            </div>
            <?php endfor; ?>

            <?php for ($g = 1; $g <= $gunSayisi; $g++):
              $tarih    = sprintf('%04d-%02d-%02d', $yil, $ay, $g);
              $haftaGun = (int)date('N', strtotime($tarih)); // 1=Pzt 7=Paz
              $veri     = $gunMap[$tarih] ?? null;
              $bugunMu  = $tarih === $bugun;
              $seciliMi = $tarih === $seciliGun;
              $hafSonu  = $haftaGun >= 6;
              $items    = $veri ? $veri['items'] : [];
              $borc     = $veri ? $veri['borc']   : 0;
              $alacak   = $veri ? $veri['alacak'] : 0;
              $maxGorun = 2;
            ?>
            <div class="tak-hucre <?= $bugunMu?'bugun-hucre':'' ?> <?= $seciliMi?'secili':'' ?> <?= $hafSonu?'hafta-sonu':'' ?>"
                 onclick="gunSec('<?= $tarih ?>')"
                 id="hucre-<?= $tarih ?>">
              <div class="tak-gun-no"><?= $g ?></div>

              <?php if (!empty($items)): ?>
              <div class="tak-hatlar">
                <?php foreach (array_slice($items, 0, $maxGorun) as $item):
                  $itamam  = $item['durum'] === 'tamamlandi';
                  $igeçmiş = !$itamam && $item['tarih'].' '.$item['saat'] < date('Y-m-d H:i');
                  $cls = $itamam ? 'tamam' : ($igeçmiş ? 'gecmis' : 'aktif');
                ?>
                <div class="tak-hat-satir <?= $cls ?>">
                  <?= htmlspecialchars($item['baslik']) ?>
                </div>
                <?php endforeach; ?>
                <?php if (count($items) > $maxGorun): ?>
                <div class="tak-hat-satir daha">+<?= count($items) - $maxGorun ?> daha</div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if ($borc > 0 || $alacak > 0): ?>
              <div class="tak-odeme-row">
                <?php if ($borc > 0): ?>
                <span class="tak-odeme-chip borc">↓ ₺<?= number_format($borc,0,',','.') ?></span>
                <?php endif; ?>
                <?php if ($alacak > 0): ?>
                <span class="tak-odeme-chip alacak">↑ ₺<?= number_format($alacak,0,',','.') ?></span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endfor; ?>

            <?php
            // Sonraki aydan dolgu
            $toplamHucre = ($ilkGun - 1) + $gunSayisi;
            $sonDolgu = (7 - ($toplamHucre % 7)) % 7;
            for ($d = 1; $d <= $sonDolgu; $d++): ?>
            <div class="tak-hucre baska-ay">
              <div class="tak-gun-no"><?= $d ?></div>
            </div>
            <?php endfor; ?>
          </div>
        </div>

        <!-- Sağ: Detay paneli -->
        <div class="tak-detay" id="takDetay">
          <div class="tak-detay-head" id="takDetayBaslik">
            <?php if ($seciliGun): ?>
            <?= date('d F Y', strtotime($seciliGun)) ?>
            <?php else: ?>
            Bir gün seçin
            <?php endif; ?>
          </div>

          <?php if ($seciliGun && !empty($seciliItems)): ?>
            <div class="tak-detay-liste" id="takDetayListe">
              <?php foreach ($seciliItems as $item):
                $itamam = $item['durum'] === 'tamamlandi';
                $tutar  = $item['tutar'] !== null ? (float)$item['tutar'] : null;
                $otip   = $item['odeme_tip'] ?? null;
              ?>
              <div class="tak-detay-item <?= $itamam?'tamam':'' ?>">
                <div class="tak-detay-item-baslik"><?= htmlspecialchars($item['baslik']) ?></div>
                <div class="tak-detay-item-meta">
                  <span class="tak-detay-saat">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?= substr($item['saat'],0,5) ?>
                  </span>
                  <?php if ($tutar !== null && $otip): ?>
                  <span class="tak-detay-odeme <?= $otip==='odeyecek'?'borc':'alacak' ?>">
                    <?= $otip==='odeyecek' ? '↓ Ödeyeceğim' : '↑ Alacağım' ?>
                    ₺<?= number_format($tutar,2,',','.') ?>
                  </span>
                  <?php endif; ?>
                  <?php if ($itamam): ?>
                  <span style="font-size:.68rem;color:var(--green);font-weight:700">✓ Tamamlandı</span>
                  <?php endif; ?>
                </div>
                <?php if ($item['durum'] === 'aktif'): // düzenle linki sadece aktif olanlarda ?>
                <div style="margin-top:.4rem">
                  <a href="/hatirlatici-duzenle?id=<?= $item['id'] ?>" style="font-size:.7rem;color:var(--blue);font-weight:600">Düzenle →</a>
                </div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>

            <?php
            $gunBorc   = $gunMap[$seciliGun]['borc']   ?? 0;
            $gunAlacak = $gunMap[$seciliGun]['alacak'] ?? 0;
            if ($gunBorc > 0 || $gunAlacak > 0): ?>
            <div class="tak-detay-ozet">
              <?php if ($gunBorc > 0): ?>
              <div class="tak-detay-ozet-chip borc">
                <div class="tak-detay-ozet-lbl">Ödeyeceğim</div>
                <div class="tak-detay-ozet-val">₺<?= number_format($gunBorc,2,',','.') ?></div>
              </div>
              <?php endif; ?>
              <?php if ($gunAlacak > 0): ?>
              <div class="tak-detay-ozet-chip alacak">
                <div class="tak-detay-ozet-lbl">Alacağım</div>
                <div class="tak-detay-ozet-val">₺<?= number_format($gunAlacak,2,',','.') ?></div>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          <?php elseif ($seciliGun): ?>
            <div class="tak-detay-bos">
              <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <div>Bu günde hatırlatıcı yok</div>
              <a href="/hatirlaticilar" class="btn btn-primary btn-sm" style="margin-top:.25rem">Ekle</a>
            </div>
          <?php else: ?>
            <div class="tak-detay-bos" id="takDetayBos">
              <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <div>Detayları görmek için<br>takvimden bir gün seçin</div>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </main>
</div>

<script>
function gunSec(tarih) {
  var url = new URL(window.location.href);
  url.searchParams.set('gun', tarih);
  window.location.href = url.toString();
}
// Seçili hücreye scroll et (sayfa yüklenince)
<?php if ($seciliGun): ?>
document.addEventListener('DOMContentLoaded', function() {
  var el = document.getElementById('hucre-<?= $seciliGun ?>');
  if (el) el.scrollIntoView({block:'center', behavior:'smooth'});
});
<?php endif; ?>
</script>
</body>
</html>
