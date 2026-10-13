<?php
$activePage = $activePage ?? '';
$userAd     = $_SESSION['user_ad'] ?? 'Kullanıcı';
$demoAktif  = !empty($_SESSION['demo_mod']);
$lisansliMi = !$demoAktif && lisansAktif();
$avatarHarf = mb_strtoupper(mb_substr($userAd, 0, 1, 'UTF-8'), 'UTF-8');
$appVersion = '1.0.2';
?>
<aside class="sidebar">
  <!-- Logo alanı -->
  <div class="sb-brand">
    <div class="sb-logo-circle">V</div>
    <div class="sb-brand-text">
      <div class="sb-brand-name">Veresiyem</div>
      <div class="sb-brand-sub">Masaüstü Programı</div>
    </div>
  </div>

  <!-- Kullanıcı alanı -->
  <div class="sb-head">
    <div class="sb-user">
      <div class="sb-avatar"><?= $avatarHarf ?></div>
      <div>
        <div class="sb-user-name"><?= htmlspecialchars($userAd) ?></div>
        <div class="sb-user-sub"><?= $demoAktif ? 'Demo Modu' : ($lisansliMi ? 'Lisanslı ✓' : 'Lisans Gerekli') ?></div>
      </div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="sb-section-label">Ana Menü</div>

    <a href="/" class="sb-link <?= $activePage==='panel' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Panel
    </a>
    <a href="/hizli-satis" class="sb-link <?= $activePage==='hizli-satis' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
      Hızlı Satış
    </a>
    <a href="/cariler" class="sb-link <?= $activePage==='cariler' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Cariler
    </a>
    <a href="/cari-ekle" class="sb-link <?= $activePage==='cari-ekle' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
      Yeni Cari
    </a>
    <a href="/stok" class="sb-link <?= $activePage==='stok' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
      Stok
    </a>

    <a href="/tahsilat-al" class="sb-link <?= $activePage==='tahsilat-al' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      Tahsilat Al
    </a>
    <a href="/kasa" class="sb-link <?= $activePage==='kasa' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
      Kasa
    </a>
    <a href="/odemeler" class="sb-link <?= $activePage==='odemeler' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/></svg>
      Ödemeler
    </a>
    <?php
    // Yaklaşan hatırlatıcı sayısı (bugün ve yarın, aktif)
    try {
        $yakSt = $pdo->prepare("SELECT COUNT(*) FROM hatirlaticilar WHERE user_id=? AND durum='aktif' AND tarih = date('now','localtime')");
        $yakSt->execute([$_SESSION['user_id'] ?? 0]);
        $yakCount = (int)$yakSt->fetchColumn();
    } catch (Exception $e) { $yakCount = 0; }
    ?>
    <a href="/takvim" class="sb-link <?= $activePage==='takvim' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Takvim
    </a>
    <a href="/hatirlaticilar" class="sb-link <?= $activePage==='hatirlaticilar' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      Hatırlatıcılar
      <?php if ($yakCount > 0): ?>
      <span style="margin-left:auto;min-width:18px;height:18px;border-radius:99px;background:var(--red,#dc2626);color:#fff;font-size:.6rem;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px"><?= $yakCount ?></span>
      <?php endif; ?>
    </a>

    <a href="/projeler" class="sb-link <?= $activePage==='projeler' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
      Projelerim
    </a>
    <a href="/not-defteri" class="sb-link <?= $activePage==='not-defteri' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Not Defteri
    </a>
    <a href="/raporlar" class="sb-link <?= $activePage==='raporlar' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      Raporlar
    </a>
    <a href="/barkod" class="sb-link <?= $activePage==='barkod' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="4" width="3" height="16"/><rect x="7" y="4" width="1" height="16"/><rect x="10" y="4" width="2" height="16"/><rect x="14" y="4" width="1" height="16"/><rect x="17" y="4" width="3" height="16"/></svg>
      Barkod Oluştur
    </a>

    <a href="/gelir-gider" class="sb-link <?= in_array($activePage, ['gelir-gider','gelir-gider-ekle','gelir-gider-duzenle']) ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      Gelir / Gider
    </a>

    <div class="sb-section-label" style="margin-top:.75rem">Tedarik</div>
    <a href="/tedarikciler" class="sb-link <?= in_array($activePage, ['tedarikciler','tedarikci','tedarikci-ekle','tedarikci-duzenle']) ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Tedarikçiler
    </a>
    <a href="/faturalar" class="sb-link <?= in_array($activePage, ['faturalar','fatura','fatura-ekle']) ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Faturalar
    </a>

    <div class="sb-section-label" style="margin-top:.75rem">Dışa Aktar</div>
    <a href="/export-tumu" class="sb-link <?= $demoAktif ? 'disabled' : '' ?>" <?= $demoAktif ? 'onclick="return false" title=\'Demo modunda kullanılamaz\'' : '' ?>>
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Excel Dışa Aktar
      <?php if ($demoAktif): ?>
      <span style="margin-left:auto;font-size:.58rem;background:rgba(217,119,6,.15);color:#d97706;padding:.1rem .4rem;border-radius:3px">KİLİTLİ</span>
      <?php endif; ?>
    </a>
  </nav>

  <div class="sb-footer">
    <?php if ($demoAktif): ?>
    <a href="/lisans" class="sb-lisans-banner">
      <div class="sb-lisans-inner">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span>Lisans Gir &amp; Aktifleştir</span>
      </div>
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <?php else: ?>
    <a href="/profil" class="sb-link <?= $activePage==='profil' ? 'active' : '' ?>">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M6 20v-1a6 6 0 0 1 12 0v1"/></svg>
      Profil & Ayarlar
    </a>
    <?php endif; ?>
    <a href="/cikis" class="sb-link" style="color:rgba(255,255,255,.3)" id="cikisBtn">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Çıkış
    </a>
    <div class="sb-version">v<?= $appVersion ?></div>
  </div>
</aside>

<!-- Güncelleme Toast -->
<div id="update-toast">
  <div class="update-toast-head">
    <div class="update-toast-icon">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.49-4.49"/></svg>
    </div>
    <div class="update-toast-title">Güncelleme Hazır</div>
  </div>
  <div class="update-toast-sub" id="update-toast-sub">Yeni sürüm indiriliyor…</div>
  <div class="update-toast-btns" id="update-toast-btns" style="display:none">
    <button class="btn-primary" onclick="window.electronAPI && window.electronAPI.installNow()">Şimdi Yükle</button>
    <button class="btn-ghost"   onclick="window.electronAPI && window.electronAPI.installLater(); document.getElementById('update-toast').classList.remove('show')">Sonra</button>
  </div>
</div>
<script>
if (window.electronAPI) {
  window.electronAPI.onUpdateAvailable(function(info) {
    var t = document.getElementById('update-toast');
    document.getElementById('update-toast-sub').textContent = 'v' + info.version + ' indiriliyor, lütfen bekleyin…';
    document.getElementById('update-toast-btns').style.display = 'none';
    t.classList.add('show');
  });
  window.electronAPI.onUpdateDownloaded(function(info) {
    var t = document.getElementById('update-toast');
    document.getElementById('update-toast-sub').textContent = 'v' + info.version + ' indirildi. Şimdi yüklensin mi?';
    document.getElementById('update-toast-btns').style.display = 'flex';
    t.classList.add('show');
  });
}
</script>

<!-- Hatırlatıcı Toast Container (tüm sayfalarda) -->
<div id="hatirlaticiToastContainer" style="position:fixed;bottom:1.25rem;right:1.25rem;z-index:9998;display:flex;flex-direction:column;gap:.5rem;pointer-events:none"></div>

<!-- Hatırlatıcı Pop-up Modal (tüm sayfalarda) -->
<div id="hatirlaticiModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--surface);border-radius:12px;padding:1.5rem;max-width:380px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.2)">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem">
      <span style="font-size:1.3rem">⏰</span>
      <strong style="font-size:1rem;color:var(--text)">Hatırlatıcı</strong>
    </div>
    <div id="hatirlaticiModalIcerik" style="margin-bottom:1.2rem;color:var(--text-muted);font-size:.9rem;line-height:1.5"></div>
    <div style="display:flex;gap:.5rem;justify-content:flex-end">
      <a href="/hatirlaticilar" class="btn btn-sm btn-secondary">Hatırlatıcılara Git</a>
      <button id="hatirlaticiModalKapatBtn" class="btn btn-sm btn-primary">Tamam</button>
    </div>
  </div>
</div>

<style>
@keyframes hToastIn  { from { opacity:0; transform:translateX(20px) } to { opacity:1; transform:translateX(0) } }
@keyframes hToastOut { from { opacity:1; transform:translateX(0) } to { opacity:0; transform:translateX(20px) } }
</style>

<script>
(function() {
  // --- Toast bildirimi (sağ alt köşe, hatirlatma_dk öncesinde) ---
  var toastContainer = document.getElementById('hatirlaticiToastContainer');
  var toastGosterilen = {};

  function toastGoster(h) {
    var key = h.id + '_' + h.kalan_dk;
    if (toastGosterilen[key]) return;
    toastGosterilen[key] = true;

    var toast = document.createElement('div');
    toast.setAttribute('data-httoast', '1');
    toast.style.cssText = 'background:var(--surface);border:1.5px solid var(--border);border-left:4px solid #2563EB;border-radius:10px;padding:.85rem 1rem;min-width:260px;max-width:320px;box-shadow:0 4px 20px rgba(0,0,0,.15);pointer-events:all;cursor:pointer;animation:hToastIn .3s ease';
    var kalanMetin = h.kalan_dk === 0 ? 'Şimdi!' : h.kalan_dk + ' dakika kaldı';
    toast.innerHTML =
      '<div style="display:flex;align-items:flex-start;gap:.6rem">' +
        '<span style="font-size:1.1rem;margin-top:.1rem">⏰</span>' +
        '<div style="flex:1;min-width:0">' +
          '<div style="font-weight:700;font-size:.88rem;color:var(--text);margin-bottom:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + h.baslik + '</div>' +
          (h.aciklama ? '<div style="font-size:.78rem;color:var(--text-muted);margin-bottom:.3rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + h.aciklama + '</div>' : '') +
          '<div style="font-size:.75rem;font-weight:600;color:#2563EB">' + kalanMetin + ' · ' + h.saat + '</div>' +
        '</div>' +
        '<button data-kapat="1" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1.1rem;line-height:1;padding:0;margin-left:.25rem">×</button>' +
      '</div>';
    toast.querySelector('[data-kapat]').addEventListener('click', function(e) {
      e.stopPropagation();
      toast.style.animation = 'hToastOut .3s ease forwards';
      setTimeout(function() { toast.remove(); }, 300);
    });
    toast.addEventListener('click', function() { window.location.href = '/hatirlaticilar'; });
    toastContainer.appendChild(toast);
    setTimeout(function() {
      toast.style.animation = 'hToastOut .3s ease forwards';
      setTimeout(function() { toast.remove(); }, 300);
    }, 8000);
  }

  function toastKontrol() {
    fetch('/hatirlatici-toast')
      .then(function(r) { return r.json(); })
      .then(function(json) {
        if (!json.ok || !Array.isArray(json.data)) return;
        json.data.forEach(toastGoster);
      })
      .catch(function() {});
  }

  // --- Pop-up modal (saati geldiğinde) ---
  var modal   = document.getElementById('hatirlaticiModal');
  var icerik  = document.getElementById('hatirlaticiModalIcerik');
  var kapatBtn = document.getElementById('hatirlaticiModalKapatBtn');
  var bekleyenler = [];
  var gosteriliyor = false;

  function modalKapat() { if (modal) modal.style.display = 'none'; }
  if (modal) modal.addEventListener('click', function(e) { if (e.target === modal) modalKapat(); });
  if (kapatBtn) kapatBtn.addEventListener('click', function() { modalKapat(); gosteriliyor = false; sonrakiniGoster(); });
  var gitBtn = modal ? modal.querySelector('a') : null;
  if (gitBtn) gitBtn.addEventListener('click', function() { modalKapat(); gosteriliyor = false; });

  function sonrakiniGoster() {
    if (!bekleyenler.length || gosteriliyor || !modal) return;
    gosteriliyor = true;
    var h = bekleyenler.shift();
    var html = '<strong style="color:var(--text);font-size:.95rem;display:block;margin-bottom:.4rem">' + h.baslik + '</strong>';
    if (h.aciklama) html += '<div style="color:var(--text);margin-bottom:.5rem;line-height:1.5">' + h.aciklama + '</div>';
    html += '<div style="display:flex;gap:1rem;margin-top:.3rem"><small style="color:var(--text-muted)">🕐 ' + h.saat + '</small><small style="color:var(--text-muted)">📅 ' + h.tarih + '</small></div>';
    icerik.innerHTML = html;
    modal.style.display = 'flex';
  }

  var sonKontrolDakika = '';
  function popupKontrol() {
    var simdi = new Date();
    var dakika = simdi.getHours() + ':' + simdi.getMinutes();
    if (dakika === sonKontrolDakika) return;
    sonKontrolDakika = dakika;
    fetch('/hatirlatici-popup')
      .then(function(r) { return r.json(); })
      .then(function(json) {
        if (!json.ok || !Array.isArray(json.data) || !json.data.length) return;
        bekleyenler = bekleyenler.concat(json.data);
        if (!gosteriliyor) sonrakiniGoster();
      })
      .catch(function() {});
  }

  // İlk kontrol 5sn sonra, sonra her dakika
  setTimeout(function() {
    toastKontrol();
    popupKontrol();
    setInterval(function() { toastKontrol(); popupKontrol(); }, 60000);
  }, 5000);
})();
</script>
