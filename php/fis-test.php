<?php
require __DIR__ . '/includes/auth.php';
girisGerekli();
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Fiş Önizleme Test</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
body { background:#f0f2f5; padding:2rem; }
.test-wrap { display:flex; gap:2rem; align-items:flex-start; flex-wrap:wrap; }
.preview-frame {
  background:#fff;
  box-shadow: 0 4px 24px rgba(0,0,0,.15);
  border-radius:4px;
  overflow:hidden;
}
.preview-frame iframe {
  display:block;
  border:none;
  width:220px;   /* 58mm ≈ 220px ekranda */
  height:500px;
}
.controls { background:#fff; border:1px solid #e2e6ea; border-radius:8px; padding:1.5rem; width:320px; }
h2 { font-size:1rem; font-weight:800; margin-bottom:1rem; }
.form-group { margin-bottom:.75rem; }
label { font-size:.75rem; font-weight:700; color:#4b5563; display:block; margin-bottom:.25rem; }
input, select, textarea { width:100%; border:1.5px solid #e2e6ea; border-radius:6px; padding:.4rem .65rem; font-size:.84rem; }
.btn { display:inline-flex;align-items:center;gap:.35rem;padding:.5rem 1rem;border-radius:6px;font-size:.84rem;font-weight:700;border:none;cursor:pointer;background:#16a34a;color:#fff; }
.btn:hover { filter:brightness(1.08); }
.note { font-size:.75rem; color:#9ca3af; margin-top:.5rem; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>

<h1 style="font-size:1.1rem;font-weight:800;margin-bottom:1.25rem">Fiş Önizleme — 58mm Test</h1>

<div class="test-wrap">
  <div>
    <div style="font-size:.75rem;color:#6b7280;margin-bottom:.5rem;font-weight:600">58mm Yazıcı Önizlemesi</div>
    <div class="preview-frame">
      <iframe id="fisFrame" src="about:blank"></iframe>
    </div>
    <div class="note">Gerçek yazıcı çıktısına yakın görünüm.<br>Ctrl+P ile de deneyebilirsiniz.</div>
  </div>

  <div class="controls">
    <h2>Test Verisi</h2>

    <div class="form-group">
      <label>Müşteri Adı</label>
      <input type="text" id="cari_ad" value="MESUT GÖKÇE">
    </div>
    <div class="form-group">
      <label>Satış Türü</label>
      <select id="tip">
        <option value="alacak">Nakit Satış</option>
        <option value="borc">Vadeli Satış</option>
      </select>
    </div>
    <div class="form-group">
      <label>Ürün 1 Adı</label>
      <input type="text" id="urun1" value="JETSİS">
    </div>
    <div class="form-group">
      <label>Ürün 1 Miktar</label>
      <input type="number" id="mik1" value="1" step="0.01">
    </div>
    <div class="form-group">
      <label>Ürün 1 Tutar (₺)</label>
      <input type="number" id="tutar1" value="1440" step="0.01">
    </div>
    <div class="form-group">
      <label>Ürün 2 Adı (boş bırakılabilir)</label>
      <input type="text" id="urun2" value="">
    </div>
    <div class="form-group">
      <label>Ürün 2 Tutar (₺)</label>
      <input type="number" id="tutar2" value="0" step="0.01">
    </div>
    <div class="form-group">
      <label>Toplam Veresiye Bakiyesi (₺)</label>
      <input type="number" id="kalan_borc" value="3250" step="0.01">
    </div>
    <div class="form-group">
      <label>Şablon</label>
      <select id="sablon">
        <option value="standart">Standart</option>
        <option value="detayli">Detaylı</option>
        <option value="minimal">Minimal</option>
      </select>
    </div>
    <div class="form-group">
      <label>Firma Adı</label>
      <input type="text" id="firma" value="veresiyem">
    </div>

    <button class="btn" onclick="goster()">Önizle</button>
    <button class="btn" style="background:#2563eb;margin-left:.5rem" onclick="yazdir()">Yazdır</button>
  </div>
</div>

<script src="/assets/js/fis.js"></script>
<script>
function buildData() {
  var kalemler = [];
  var t1 = parseFloat(document.getElementById('tutar1').value) || 0;
  var u1 = document.getElementById('urun1').value;
  var m1 = parseFloat(document.getElementById('mik1').value) || 1;
  if (u1 || t1) kalemler.push({aciklama: u1, tutar: t1, miktar: m1});

  var t2 = parseFloat(document.getElementById('tutar2').value) || 0;
  var u2 = document.getElementById('urun2').value;
  if (u2 && t2) kalemler.push({aciklama: u2, tutar: t2, miktar: 1});

  var kb = parseFloat(document.getElementById('kalan_borc').value);
  return {
    tip:        document.getElementById('tip').value,
    cari_ad:    document.getElementById('cari_ad').value,
    tarih:      new Date().toISOString().slice(0,10),
    kalemler:   kalemler,
    kalan_borc: isNaN(kb) ? null : kb
  };
}

function getFisHtml() {
  window._fisAyar  = { boyut: '58mm', sablon: document.getElementById('sablon').value };
  window._firmaAdi = document.getElementById('firma').value;
  return veresiyemFisHtml(buildData());
}

function goster() {
  var html = getFisHtml();
  if (!html) return;
  var frame = document.getElementById('fisFrame');
  frame.srcdoc = html;
}

function yazdir() {
  var html = getFisHtml();
  if (!html) return;
  var w = window.open('', '_blank', 'width=300,height=600,toolbar=0,menubar=0');
  if (w) { w.document.write(html); w.document.close(); setTimeout(function(){ w.print(); }, 500); }
}

// Sayfa açılınca otomatik önizle
window.addEventListener('load', function() { goster(); });
</script>
</body>
</html>
