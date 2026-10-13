<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'barkod';

$uid = userId();
$stoklar = $pdo->prepare("SELECT id, ad, barkod FROM stoklar WHERE user_id=? ORDER BY ad ASC");
$stoklar->execute([$uid]);
$stoklar = $stoklar->fetchAll();

$pageTitle = 'Barkod Oluştur';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Barkod Oluştur — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.barkod-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 1.5rem;
  max-width: 560px;
}
.barkod-preview {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 1.5rem 1rem 1rem;
  text-align: center;
  min-height: 140px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: .5rem;
}
.barkod-preview svg { max-width: 100%; }
.barkod-placeholder {
  color: var(--ink3);
  font-size: .85rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .5rem;
}
.format-btns { display: flex; gap: .4rem; flex-wrap: wrap; }
.format-btn {
  padding: .3rem .65rem;
  border-radius: 5px;
  border: 1.5px solid var(--border2);
  background: var(--surface);
  color: var(--ink2);
  font-size: .78rem;
  font-weight: 600;
  cursor: pointer;
  transition: .1s;
}
.format-btn.active {
  border-color: var(--accent);
  background: rgba(37,99,235,.08);
  color: var(--accent);
}
/* Yazıcı önizleme */
.yazici-onizleme {
  background: #e8e8e8;
  border-radius: var(--r);
  padding: 1rem;
  display: none;
}
.yazici-onizleme-baslik {
  font-size: .75rem; font-weight:700; color:var(--ink3);
  text-transform:uppercase; letter-spacing:.05em; margin-bottom:.75rem;
  display:flex; align-items:center; gap:.5rem;
}
.yazici-kagit {
  background: #fff;
  box-shadow: 0 2px 12px rgba(0,0,0,.18);
  margin: 0 auto;
  padding: 4mm;
  position: relative;
  overflow: hidden;
}
.yazici-kagit-ic {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2mm;
  height: 100%;
}
.yazici-kagit-ic .item-ad {
  font-size: 7pt; font-weight:700; text-align:center;
  word-break: break-word; color:#000; line-height:1.2;
}
.yazici-kagit-ic svg { max-width:100%; height:auto; display:block; }

/* Print */
.print-area { display: none; }
@media print {
  @page { margin: 0; padding: 0; }
  html, body { margin: 0 !important; padding: 0 !important; }
  body > * { display: none !important; }
  .print-area { display: block !important; margin: 0; padding: 0; }
  .print-grid { display: flex; flex-wrap: wrap; margin: 0; padding: 0; }
  .print-item {
    page-break-inside: avoid;
    box-sizing: border-box;
    padding: 2mm 2mm;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    overflow: hidden;
    background: #fff;
  }
  .print-item svg {
    display: block;
    width: 100% !important;
    height: auto !important;
    max-width: 100%;
  }
  .print-item .item-ad {
    font-size: 8pt;
    font-weight: 700;
    word-break: break-word;
    line-height: 1.3;
    color: #000;
    text-align: center;
    width: 100%;
    margin-bottom: 1mm;
  }
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
        <div class="page-title">Barkod Oluştur</div>
        <div class="page-sub">Stok ürünleri için barkod etiketi oluşturun ve yazdırın</div>
      </div>
    </div>

    <div class="page-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;max-width:1000px">

        <!-- Sol: Ayarlar -->
        <div style="display:flex;flex-direction:column;gap:1rem">

          <!-- Stok seç veya serbest giriş -->
          <div class="barkod-card">
            <div style="font-size:.8rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:1rem">Barkod Kaynağı</div>

            <div class="form-group">
              <label class="form-label">Stoktan Seç</label>
              <div style="position:relative">
                <input type="text" id="stokAra" class="form-input" placeholder="Stok adı veya barkod yazın / okutun..."
                  autocomplete="off" oninput="stokAra(this.value)" onfocus="stokAra(this.value)">
                <div id="stokListe" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:300;background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);box-shadow:var(--shadow);max-height:220px;overflow-y:auto;margin-top:2px"></div>
              </div>
              <div id="secilenStokBilgi" style="display:none;margin-top:.4rem;padding:.35rem .6rem;background:var(--surface2);border-radius:5px;font-size:.82rem;display:flex;align-items:center;justify-content:space-between">
                <span id="secilenStokAd" style="font-weight:600;color:var(--accent)"></span>
                <button type="button" onclick="stokSifirla()" style="background:none;border:none;color:var(--ink3);cursor:pointer;font-size:.85rem;padding:0 .2rem">✕</button>
              </div>
            </div>

            <div style="display:flex;align-items:center;gap:.75rem;margin:.25rem 0 .75rem;color:var(--ink3);font-size:.78rem">
              <div style="flex:1;height:1px;background:var(--border)"></div>
              veya
              <div style="flex:1;height:1px;background:var(--border)"></div>
            </div>

            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Serbest Barkod Kodu</label>
              <input type="text" id="barkodKod" class="form-input" placeholder="Örn: 8690123456789"
                     style="font-family:monospace;letter-spacing:.08em"
                     oninput="serbestGiris(this.value)" autocomplete="off">
            </div>
            <div class="form-group" style="margin-top:.75rem;margin-bottom:0">
              <label class="form-label">Etiket Başlığı (opsiyonel)</label>
              <input type="text" id="barkodEtiket" class="form-input" placeholder="Ürün adı / açıklama..."
                     oninput="onizlemeGuncelle()">
            </div>
          </div>

          <!-- Format -->
          <div class="barkod-card">
            <div style="font-size:.8rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Barkod Formatı</div>
            <div class="format-btns" id="formatBtnlar">
              <button class="format-btn active" data-fmt="CODE128" onclick="formatSec(this)">CODE128</button>
              <button class="format-btn" data-fmt="EAN13" onclick="formatSec(this)">EAN-13</button>
              <button class="format-btn" data-fmt="EAN8" onclick="formatSec(this)">EAN-8</button>
              <button class="format-btn" data-fmt="UPC" onclick="formatSec(this)">UPC-A</button>
              <button class="format-btn" data-fmt="CODE39" onclick="formatSec(this)">CODE39</button>
              <button class="format-btn" data-fmt="ITF14" onclick="formatSec(this)">ITF-14</button>
            </div>
            <div id="formatAciklama" style="font-size:.75rem;color:var(--ink3);margin-top:.6rem">
              CODE128: Harf ve rakam içeren her türlü barkod için uygundur.
            </div>
          </div>

          <!-- Boyut & Adet -->
          <div class="barkod-card">
            <div style="font-size:.8rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Yazdırma Ayarları</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Genişlik (mm)</label>
                <input type="number" id="etGenis" class="form-input" value="50" min="20" max="150" oninput="onizlemeGuncelle();if(mevcutKod)yaziciOnizlemeGuncelle(mevcutKod,document.getElementById('barkodEtiket').value.trim())">
              </div>
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Yükseklik (mm)</label>
                <input type="number" id="etYuksek" class="form-input" value="30" min="15" max="100" oninput="onizlemeGuncelle();if(mevcutKod)yaziciOnizlemeGuncelle(mevcutKod,document.getElementById('barkodEtiket').value.trim())">
              </div>
            </div>
            <div class="form-group" style="margin-top:.75rem;margin-bottom:0">
              <label class="form-label">Kaç adet yazdırılsın?</label>
              <input type="number" id="adetSayisi" class="form-input" value="1" min="1" max="100">
            </div>
          </div>

        </div>

        <!-- Sağ: Önizleme + Butonlar -->
        <div style="display:flex;flex-direction:column;gap:1rem;position:sticky;top:1rem">
          <div class="barkod-card" style="max-width:none">
            <div style="font-size:.8rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Önizleme</div>
            <div class="barkod-preview" id="onizlemeAlan">
              <div class="barkod-placeholder" id="barkodPlaceholder">
                <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.3" viewBox="0 0 24 24" style="opacity:.3"><rect x="2" y="4" width="3" height="16"/><rect x="6" y="4" width="1" height="16"/><rect x="8" y="4" width="2" height="16"/><rect x="12" y="4" width="1" height="16"/><rect x="14" y="4" width="3" height="16"/><rect x="19" y="4" width="1" height="16"/><rect x="21" y="4" width="1" height="16"/></svg>
                <span>Barkod kodu girildiğinde burada görünecek</span>
              </div>
              <svg id="barkodSvg" style="display:none"></svg>
              <div id="barkodAltYazi" style="display:none;font-size:.78rem;font-weight:600;color:#333;margin-top:.25rem"></div>
            </div>
            <div id="barkodHata" style="display:none;margin-top:.5rem;padding:.5rem .75rem;background:var(--red-lt);color:var(--red);border-radius:5px;font-size:.8rem"></div>
          </div>

          <!-- Yazıcı çıktı önizleme -->
          <div class="yazici-onizleme" id="yaziciOnizleme">
            <div class="yazici-onizleme-baslik">
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Yazıcı Çıktı Önizleme
            </div>
            <!-- Etiket kağıdı simülasyonu -->
            <div id="yaziciKagit" class="yazici-kagit">
              <div class="yazici-kagit-ic" id="yaziciKagitIc"></div>
            </div>
            <div style="text-align:center;margin-top:.5rem;font-size:.72rem;color:var(--ink3)" id="yaziciOlcuBilgi"></div>
          </div>

          <div style="display:flex;flex-direction:column;gap:.5rem">
            <button class="btn btn-primary" onclick="yazdir()" id="yazdirBtn" disabled>
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Yazdır
            </button>
            <button class="btn btn-secondary" onclick="pngIndir()" id="indirBtn" disabled>
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              PNG İndir
            </button>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Yazdırma alanı -->
<div class="print-area" id="printArea"></div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
var secilenFormat = 'CODE128';
var mevcutKod = '';
var _stoklar  = <?= json_encode(array_values($stoklar), JSON_UNESCAPED_UNICODE) ?>;

var formatAciklamalar = {
  'CODE128': 'CODE128: Harf ve rakam içeren her türlü barkod için uygundur.',
  'EAN13':   'EAN-13: 13 haneli sayısal barkod. Perakende ürünlerde yaygın.',
  'EAN8':    'EAN-8: 8 haneli sayısal barkod. Küçük ambalajlar için.',
  'UPC':     'UPC-A: 12 haneli sayısal barkod. ABD market standartı.',
  'CODE39':  'CODE39: Büyük harf, rakam ve bazı özel karakterler.',
  'ITF14':   'ITF-14: 14 haneli sayısal barkod. Koli/palet etiketleri için.'
};

function formatSec(btn) {
  document.querySelectorAll('.format-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  secilenFormat = btn.dataset.fmt;
  document.getElementById('formatAciklama').textContent = formatAciklamalar[secilenFormat] || '';
  onizlemeGuncelle();
}

// ── STOK ARAMA ──────────────────────────────────────────────
function stokAra(q) {
  var liste   = document.getElementById('stokListe');
  var araStr  = q.trim().toLowerCase();
  var eslesen = araStr === ''
    ? _stoklar.slice(0, 12)
    : _stoklar.filter(function(s) {
        return s.ad.toLowerCase().indexOf(araStr) !== -1
            || (s.barkod && s.barkod.toLowerCase().indexOf(araStr) !== -1);
      });

  liste.innerHTML = '';
  if (eslesen.length === 0) { liste.style.display = 'none'; return; }

  eslesen.forEach(function(s) {
    var item = document.createElement('div');
    item.style.cssText = 'padding:.4rem .7rem;cursor:pointer;font-size:.83rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center';
    item.innerHTML = '<span>' + escHtml(s.ad) + '</span>'
      + (s.barkod ? '<span style="font-size:.72rem;color:var(--ink3);font-family:monospace">' + escHtml(s.barkod) + '</span>' : '<span style="font-size:.72rem;color:var(--ink3)">barkod yok</span>');
    item.addEventListener('mousedown', function(e) {
      e.preventDefault();
      stokSec(s);
    });
    liste.appendChild(item);
  });
  liste.style.display = 'block';
}

function stokSec(s) {
  document.getElementById('stokAra').value = s.ad;
  document.getElementById('stokListe').style.display = 'none';
  document.getElementById('secilenStokAd').textContent = s.ad + (s.barkod ? ' — ' + s.barkod : ' (barkod yok)');
  document.getElementById('secilenStokBilgi').style.display = 'flex';
  document.getElementById('barkodEtiket').value = s.ad;
  if (s.barkod) {
    document.getElementById('barkodKod').value = s.barkod;
    mevcutKod = s.barkod;
  } else {
    document.getElementById('barkodKod').value = '';
    mevcutKod = '';
  }
  onizlemeGuncelle();
}

function stokSifirla() {
  document.getElementById('stokAra').value = '';
  document.getElementById('secilenStokBilgi').style.display = 'none';
  document.getElementById('stokAra').focus();
}

// Barkod okuyucu desteği: Enter ile tamamla
var _barkodTimer2 = null;
document.addEventListener('DOMContentLoaded', function() {
  var araEl = document.getElementById('stokAra');
  araEl.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(_barkodTimer2);
      _barkodTimer2 = null;
      var liste = document.getElementById('stokListe');
      if (liste.style.display !== 'none') {
        var ilk = liste.querySelector('div');
        if (ilk) ilk.dispatchEvent(new MouseEvent('mousedown', {bubbles:true}));
      } else {
        // Eşleşme yok — barkod kodu olarak serbest giriş yap
        var q = araEl.value.trim();
        if (q) {
          document.getElementById('barkodKod').value = q;
          mevcutKod = q;
          onizlemeGuncelle();
        }
      }
      return;
    }
    clearTimeout(_barkodTimer2);
    _barkodTimer2 = setTimeout(function() { _barkodTimer2 = null; }, 500);
  });

  document.addEventListener('click', function(e) {
    var ara = document.getElementById('stokAra');
    var lst = document.getElementById('stokListe');
    if (ara && lst && !ara.contains(e.target) && !lst.contains(e.target)) lst.style.display = 'none';
  });
});

function serbestGiris(val) {
  // Serbest barkod girilince stok seçimini temizle
  document.getElementById('stokAra').value = '';
  document.getElementById('secilenStokBilgi').style.display = 'none';
  mevcutKod = val.trim();
  onizlemeGuncelle();
}

function onizlemeGuncelle() {
  var kod    = document.getElementById('barkodKod').value.trim() || mevcutKod;
  var etiket = document.getElementById('barkodEtiket').value.trim();
  var hataEl = document.getElementById('barkodHata');
  hataEl.style.display = 'none';

  if (!kod) { temizle(); return; }

  try {
    JsBarcode('#barkodSvg', kod, {
      format:       secilenFormat,
      displayValue: true,
      fontSize:     12,
      margin:       8,
      width:        1.6,
      height:       60,
      lineColor:    '#000',
      background:   '#fff',
    });
    document.getElementById('barkodSvg').style.display = 'block';
    document.getElementById('barkodPlaceholder').style.display = 'none';
    if (etiket) {
      var ay = document.getElementById('barkodAltYazi');
      ay.textContent = etiket;
      ay.style.display = 'block';
    } else {
      document.getElementById('barkodAltYazi').style.display = 'none';
    }
    document.getElementById('yazdirBtn').disabled = false;
    document.getElementById('indirBtn').disabled  = false;
    mevcutKod = kod;
    yaziciOnizlemeGuncelle(kod, etiket);
  } catch(e) {
    hataEl.textContent = 'Geçersiz barkod: ' + (e.message || 'Kod bu format için uygun değil.');
    hataEl.style.display = 'block';
    temizle();
  }
}

function temizle() {
  document.getElementById('barkodSvg').style.display = 'none';
  document.getElementById('barkodPlaceholder').style.display = 'flex';
  document.getElementById('barkodAltYazi').style.display = 'none';
  document.getElementById('yazdirBtn').disabled = true;
  document.getElementById('indirBtn').disabled  = true;
  document.getElementById('yaziciOnizleme').style.display = 'none';
}

// ── YAZICI ÖNİZLEME ───────────────────────────────────────────
function yaziciOnizlemeGuncelle(kod, etiket) {
  var genis  = parseInt(document.getElementById('etGenis').value)  || 50;
  var yuksek = parseInt(document.getElementById('etYuksek').value) || 30;

  // Ekranda mm → px: 1mm ≈ 3.78px @ 96dpi, önizleme için 2.5x ölçek
  var scale  = 2.5;
  var pxW    = genis  * scale;
  var pxH    = yuksek * scale;
  // Kart max genişlik = panel içi (yaklaşık 260px)
  var maxW   = 260;
  if (pxW > maxW) { scale = maxW / (genis * scale) * scale; pxW = genis * scale; pxH = yuksek * scale; }

  var kagit = document.getElementById('yaziciKagit');
  kagit.style.width  = pxW + 'px';
  kagit.style.height = pxH + 'px';

  // Barkod yüksekliği: etiketten başlık ve padding düş
  var adY    = etiket ? 14 : 0;
  var bkH    = Math.max(20, pxH - adY - 16);
  var bkW    = Math.max(1.0, 1.2);

  var ic = document.getElementById('yaziciKagitIc');
  ic.innerHTML = (etiket ? '<div class="item-ad">' + escHtml(etiket) + '</div>' : '')
               + '<svg id="yaziciSvg"></svg>';

  try {
    JsBarcode('#yaziciSvg', kod, {
      format:       secilenFormat,
      displayValue: true,
      fontSize:     8,
      margin:       2,
      width:        bkW,
      height:       bkH,
      lineColor:    '#000',
      background:   '#fff',
    });
    // SVG max-width ile taşmayı engelle
    var svgEl = document.getElementById('yaziciSvg');
    if (svgEl) { svgEl.style.maxWidth = '100%'; svgEl.style.height = 'auto'; }
  } catch(_) {}

  document.getElementById('yaziciOnizleme').style.display = 'block';
  document.getElementById('yaziciOlcuBilgi').textContent = genis + ' × ' + yuksek + ' mm etiket';
}

function yazdir() {
  var adet   = Math.max(1, Math.min(100, parseInt(document.getElementById('adetSayisi').value) || 1));
  var genis  = parseInt(document.getElementById('etGenis').value)  || 50;
  var yuksek = parseInt(document.getElementById('etYuksek').value) || 30;
  var etiket = document.getElementById('barkodEtiket').value.trim();
  var kod    = mevcutKod;
  if (!kod) return;

  // Barkod yüksekliği: etiket mm → pt (1mm ≈ 2.835pt), padding ve başlık düş
  var adPt   = etiket ? 10 : 0;
  var padPt  = 2 * 2.835 * 2; // 2mm üst+alt padding
  var bkHPt  = Math.max(20, (yuksek * 2.835) - adPt - padPt - 4);

  var items = '';
  for (var i = 0; i < adet; i++) {
    items += '<div class="print-item" style="width:' + genis + 'mm;height:' + yuksek + 'mm;">'
           + (etiket ? '<div class="item-ad">' + escHtml(etiket) + '</div>' : '')
           + '<svg id="pbk_' + i + '"></svg>'
           + '</div>';
  }

  var printDiv = document.getElementById('printArea');
  printDiv.innerHTML = '<div class="print-grid">' + items + '</div>';

  for (var j = 0; j < adet; j++) {
    try {
      JsBarcode('#pbk_' + j, kod, {
        format:       secilenFormat,
        displayValue: true,
        fontSize:     14,
        textMargin:   4,
        margin:       0,
        width:        2,
        height:       bkHPt,
        lineColor:    '#000',
        background:   '#fff',
        font:         'monospace',
        fontOptions:  'bold',
      });
      // SVG genişliğini etiket genişliğine tam oturт
      var svgEl = document.querySelector('#pbk_' + j);
      if (svgEl) {
        svgEl.removeAttribute('width');
        svgEl.removeAttribute('height');
        svgEl.style.cssText = 'width:100%;height:auto;display:block;';
      }
    } catch(_) {}
  }
  window.print();
}

function pngIndir() {
  var kod    = mevcutKod;
  var etiket = document.getElementById('barkodEtiket').value.trim();
  if (!kod) return;

  var canvas = document.createElement('canvas');
  var padding = 20;
  var barH = 80;
  var fontSize = 13;

  // Geçici SVG'den boyut al
  var svgEl = document.getElementById('barkodSvg');
  var svgW  = svgEl.getBoundingClientRect().width || 280;
  var svgH  = svgEl.getBoundingClientRect().height || 110;

  canvas.width  = Math.max(280, svgW + padding * 2);
  canvas.height = svgH + (etiket ? fontSize + 10 : 0) + padding;
  var ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  // SVG → img
  var svgData = new XMLSerializer().serializeToString(svgEl);
  var blob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
  var url  = URL.createObjectURL(blob);
  var img  = new Image();
  img.onload = function() {
    ctx.drawImage(img, padding, etiket ? fontSize + 10 : padding);
    if (etiket) {
      ctx.fillStyle = '#111';
      ctx.font = 'bold ' + fontSize + 'px sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(etiket, canvas.width / 2, fontSize + 2);
    }
    URL.revokeObjectURL(url);
    var a = document.createElement('a');
    a.download = 'barkod_' + kod + '.png';
    a.href = canvas.toDataURL('image/png');
    a.click();
  };
  img.src = url;
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
