<?php
// $pageTitle değişkeni çağıran dosyadan gelir
$demoAktif = function_exists('demoMod') && demoMod();

// Fiş ayarlarını oku (pdo ve uid mevcut sayfalarda zaten tanımlı)
$_fisAyar = ['boyut' => '80mm', 'sablon' => 'standart'];
if (isset($pdo) && function_exists('userId')) {
    $_uid = userId();
    $__fRow = $pdo->prepare("SELECT anahtar, deger FROM ayarlar WHERE user_id=? AND anahtar IN ('fis_boyut','fis_sablon')");
    $__fRow->execute([$_uid]);
    foreach ($__fRow->fetchAll() as $__r) {
        if ($__r['anahtar'] === 'fis_boyut')  $_fisAyar['boyut']  = $__r['deger'];
        if ($__r['anahtar'] === 'fis_sablon') $_fisAyar['sablon'] = $__r['deger'];
    }
}
// firma adı (bazı sayfalar zaten tanımlar, yoksa çek)
if (!isset($firmaAdi) && isset($pdo) && function_exists('userId')) {
    $_fRow2 = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='firma_adi'");
    $_fRow2->execute([userId()]);
    $firmaAdi = ($_fRow2->fetchColumn()) ?: 'Veresiyem';
}
?>
<div class="titlebar">
  <div class="titlebar-logo">veresi<em>yem</em></div>
  <div class="titlebar-title"><?= htmlspecialchars($pageTitle ?? 'Veresiyem') ?></div>
  <?php if ($demoAktif): ?>
  <div class="titlebar-demo-badge">DEMO</div>
  <?php endif; ?>
  <div class="titlebar-controls">
    <button class="titlebar-btn" id="tbMin" title="Küçült">
      <svg width="11" height="11" viewBox="0 0 12 12" fill="currentColor"><rect y="5.5" width="12" height="1"/></svg>
    </button>
    <button class="titlebar-btn" id="tbMax" title="Büyüt">
      <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.2"><rect x=".6" y=".6" width="10.8" height="10.8" rx="1"/></svg>
    </button>
    <button class="titlebar-btn close" id="tbClose" title="Kapat">
      <svg width="11" height="11" viewBox="0 0 12 12" fill="currentColor"><path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
    </button>
  </div>
</div>
<script>
(function(){
  var api = window.electronAPI;
  if (!api) return;
  document.getElementById('tbMin')?.addEventListener('click', function(){ api.minimize(); });
  document.getElementById('tbMax')?.addEventListener('click', function(){ api.maximize(); });
  document.getElementById('tbClose')?.addEventListener('click', function(){ api.close(); });

  // Pencere sürükleme — CSS -webkit-app-region yerine JS ile
  var tb = document.querySelector('.titlebar');
  if (!tb) return;
  var dragging = false, lastX = 0, lastY = 0;

  tb.addEventListener('mousedown', function(e) {
    // Sadece sürüklenebilir alan: buton ve kontroller değil
    if (e.target.closest('.titlebar-controls') || e.target.closest('button')) return;
    if (e.button !== 0) return;
    dragging = true;
    lastX = e.screenX;
    lastY = e.screenY;
    e.preventDefault();
  });

  document.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var dx = e.screenX - lastX;
    var dy = e.screenY - lastY;
    lastX = e.screenX;
    lastY = e.screenY;
    if (dx !== 0 || dy !== 0) api.drag(dx, dy);
  });

  document.addEventListener('mouseup', function() {
    dragging = false;
  });
})();
</script>

<!-- Global Confirm Modal -->
<div class="vrm-overlay" id="vrmOverlay">
  <div class="vrm-dialog">
    <div class="vrm-dialog-ico red" id="vrmIco">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
    </div>
    <div class="vrm-dialog-body">
      <div class="vrm-dialog-title" id="vrmTitle">Emin misiniz?</div>
      <div class="vrm-dialog-msg" id="vrmMsg"></div>
    </div>
    <div class="vrm-dialog-foot">
      <button class="btn btn-ghost" id="vrmCancel">İptal</button>
      <button class="btn btn-primary" id="vrmOk" style="background:var(--red);border-color:var(--red)">Sil</button>
    </div>
  </div>
</div>
<script>
(function(){
  var overlay  = document.getElementById('vrmOverlay');
  var btnOk    = document.getElementById('vrmOk');
  var btnCancel= document.getElementById('vrmCancel');
  var _resolve = null;

  function close(val) {
    overlay.classList.remove('open');
    if (_resolve) { _resolve(val); _resolve = null; }
  }

  btnOk.addEventListener('click', function(){ close(true); });
  btnCancel.addEventListener('click', function(){ close(false); });
  overlay.addEventListener('click', function(e){ if(e.target===overlay) close(false); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(false); });

  // Sayfa yüklenince form ve link event'lerini bağla
  document.addEventListener('DOMContentLoaded', function() {

    // Stok / Cari sil formları
    document.querySelectorAll('[data-confirm-sil]').forEach(function(form) {
      form.querySelector('button[type=submit], input[type=submit]')?.addEventListener('click', async function(e) {
        e.preventDefault();
        var ok = await window.veresiyemConfirm({
          title: 'Kaydı Sil',
          msg: 'Bu işlem geri alınamaz. Silmek istediğinize emin misiniz?',
          okLabel: 'Evet, Sil',
          type: 'red'
        });
        if (ok) form.submit();
      });
    });

    // Hareket sil formları
    document.querySelectorAll('[data-confirm-hareket]').forEach(function(form) {
      form.querySelector('button[type=submit], input[type=submit]')?.addEventListener('click', async function(e) {
        e.preventDefault();
        var ok = await window.veresiyemConfirm({
          title: 'Hareketi Sil',
          msg: 'Bu hareketi silmek istediğinize emin misiniz?',
          okLabel: 'Evet, Sil',
          type: 'red'
        });
        if (ok) form.submit();
      });
    });

    // Çıkış linki
    var cikisBtn = document.getElementById('cikisBtn');
    if (cikisBtn) {
      cikisBtn.addEventListener('click', async function(e) {
        e.preventDefault();
        var ok = await window.veresiyemConfirm({
          title: 'Çıkış',
          msg: 'Oturumu kapatmak istediğinize emin misiniz?',
          okLabel: 'Çıkış Yap',
          type: 'gold'
        });
        if (ok) window.location.href = '/cikis';
      });
    }
  });

  // Fiş ayarları ve firma adı — tüm sayfalarda erişilebilir
  window._fisAyar  = <?= json_encode($_fisAyar, JSON_UNESCAPED_UNICODE) ?>;
  window._firmaAdi = <?= json_encode($firmaAdi ?? 'Veresiyem', JSON_UNESCAPED_UNICODE) ?>;
  window._firmaAdiHtml = String(window._firmaAdi).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
  // PDF'lerin en üstüne konan marka (firma adı) başlığı — Profil > Firma Bilgileri'nden gelir
  window.pdfMarkaHtml = function() {
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:800;color:#111;'
      + 'border-bottom:2px solid #111;padding-bottom:6px;margin-bottom:10px">' + window._firmaAdiHtml + '</div>';
  };

  window.veresiyemConfirm = function(opts) {
    var title   = opts.title   || 'Emin misiniz?';
    var msg     = opts.msg     || '';
    var okLabel = opts.okLabel || 'Onayla';
    var type    = opts.type    || 'red';   // red | gold | green

    document.getElementById('vrmTitle').textContent = title;
    document.getElementById('vrmMsg').textContent   = msg;
    btnOk.textContent = okLabel;

    // İkon ve renk
    var ico = document.getElementById('vrmIco');
    ico.className = 'vrm-dialog-ico ' + type;
    var icons = {
      red:  '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
      gold: '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
      green:'<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>'
    };
    ico.innerHTML = icons[type] || icons.red;

    var btnColors = { red:'var(--red)', gold:'var(--gold)', green:'var(--green)' };
    btnOk.style.background   = btnColors[type] || btnColors.red;
    btnOk.style.borderColor  = btnColors[type] || btnColors.red;

    overlay.classList.add('open');
    setTimeout(function(){ btnOk.focus(); }, 150);

    return new Promise(function(resolve){ _resolve = resolve; });
  };
})();
</script>
<script src="/assets/js/fis.js"></script>
<style>
#vrmToastWrap{position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;align-items:center;gap:.4rem;pointer-events:none}
.vrm-toast{background:#1e2128;color:#fff;font-size:.84rem;font-weight:600;padding:.55rem 1.1rem;border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,.35);opacity:0;transform:translateY(6px);transition:opacity .18s,transform .18s;pointer-events:none;white-space:nowrap;max-width:90vw;overflow:hidden;text-overflow:ellipsis}
.vrm-toast.show{opacity:1;transform:translateY(0)}
.vrm-toast.error{background:#dc2626}
.vrm-toast.success{background:#16a34a}
.vrm-toast.warn{background:#d97706}
</style>
<div id="vrmToastWrap"></div>
<script>
window.vrmToast = function(msg, type, dur) {
  var wrap = document.getElementById('vrmToastWrap');
  var el = document.createElement('div');
  el.className = 'vrm-toast' + (type ? ' ' + type : '');
  el.textContent = msg;
  wrap.appendChild(el);
  requestAnimationFrame(function(){ requestAnimationFrame(function(){ el.classList.add('show'); }); });
  setTimeout(function(){
    el.classList.remove('show');
    setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, 200);
  }, dur || 3000);
};
</script>
