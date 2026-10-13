<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
girisGerekli();

$uid      = userId();
$activePage = 'alt-hesaplar';
$apiBase  = getenv('VERESIYEM_API_BASE') ?: 'https://api.veresiyem.com.tr';
$token    = $_SESSION['api_token'] ?? '';

// Sadece patron erişebilir
if (($_SESSION['user_rol'] ?? 'patron') !== 'patron') {
    header('Location: /'); exit;
}

$hata   = '';
$basari = '';

// ----- Yardımcı: API isteği -----
function altHesapApiIste(string $method, string $yol, array $veri, string $apiBase, string $token): array {
    $m = strtoupper($method);
    $opts = [
        'method'        => $m,
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer $token",
        'timeout'       => 10,
        'ignore_errors' => true,
    ];
    if ($m !== 'GET') $opts['content'] = json_encode($veri);
    $ctx  = stream_context_create(['http' => $opts]);
    $body = @file_get_contents($apiBase . $yol, false, $ctx);
    return $body ? (json_decode($body, true) ?: []) : [];
}

// ----- POST işlemleri -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'ekle') {
        $ad    = trim($_POST['ad'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $sifre = trim($_POST['sifre'] ?? '');
        if (!$ad || !$email || strlen($sifre) < 6) {
            $hata = 'Ad, e-posta ve en az 6 karakterli şifre zorunludur.';
        } else {
            $yetkiler = [
                'cariler'     => isset($_POST['yetki_cariler'])    ? 1 : 0,
                'stok'        => isset($_POST['yetki_stok'])       ? 1 : 0,
                'kasa'        => isset($_POST['yetki_kasa'])       ? 1 : 0,
                'raporlar'    => isset($_POST['yetki_raporlar'])   ? 1 : 0,
                'ayarlar'     => isset($_POST['yetki_ayarlar'])    ? 1 : 0,
                'hizli_satis' => isset($_POST['yetki_hizli_satis'])? 1 : 0,
                'tahsilat'    => isset($_POST['yetki_tahsilat'])   ? 1 : 0,
                'hareket_sil' => isset($_POST['yetki_hareket_sil'])? 1 : 0,
            ];
            $sonuc = altHesapApiIste('POST', '/alt-hesaplar', [
                'ad' => $ad, 'email' => $email, 'sifre' => $sifre, 'yetkiler' => $yetkiler
            ], $apiBase, $token);
            if (!empty($sonuc['ok'])) {
                $basari = '"' . htmlspecialchars($ad) . '" adlı kullanıcı eklendi.';
            } else {
                $hata = $sonuc['mesaj'] ?? 'Kullanıcı eklenemedi.';
            }
        }

    } elseif ($aksiyon === 'guncelle') {
        $id    = (int)($_POST['kullanici_id'] ?? 0);
        $ad    = trim($_POST['ad'] ?? '');
        $sifre = trim($_POST['sifre'] ?? '');
        $yetkiler = [
            'cariler'     => isset($_POST['yetki_cariler'])    ? 1 : 0,
            'stok'        => isset($_POST['yetki_stok'])       ? 1 : 0,
            'kasa'        => isset($_POST['yetki_kasa'])       ? 1 : 0,
            'raporlar'    => isset($_POST['yetki_raporlar'])   ? 1 : 0,
            'ayarlar'     => isset($_POST['yetki_ayarlar'])    ? 1 : 0,
            'hizli_satis' => isset($_POST['yetki_hizli_satis'])? 1 : 0,
            'tahsilat'    => isset($_POST['yetki_tahsilat'])   ? 1 : 0,
            'hareket_sil' => isset($_POST['yetki_hareket_sil'])? 1 : 0,
        ];
        $payload = ['yetkiler' => $yetkiler];
        if ($ad)    $payload['ad']    = $ad;
        if ($sifre) $payload['sifre'] = $sifre;
        $sonuc = altHesapApiIste('PUT', '/alt-hesaplar/' . $id, $payload, $apiBase, $token);
        if (!empty($sonuc['ok'])) {
            $basari = 'Kullanıcı güncellendi.';
        } else {
            $hata = $sonuc['mesaj'] ?? 'Güncelleme başarısız.';
        }

    } elseif ($aksiyon === 'sil') {
        $id    = (int)($_POST['kullanici_id'] ?? 0);
        $sonuc = altHesapApiIste('DELETE', '/alt-hesaplar/' . $id, [], $apiBase, $token);
        if (!empty($sonuc['ok'])) {
            $basari = 'Kullanıcı silindi.';
        } else {
            $hata = $sonuc['mesaj'] ?? 'Silme başarısız.';
        }
    }
}

// ----- Listeyi çek -----
$calisanlar = [];
if ($token) {
    $sonuc = altHesapApiIste('GET', '/alt-hesaplar', [], $apiBase, $token);
    $calisanlar = $sonuc['calisanlar'] ?? [];
}

$pageTitle = 'Kullanıcılar';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kullanıcılar · Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.yetki-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .4rem .75rem;
}
.yetki-satir {
    display: flex;
    align-items: center;
    gap: .45rem;
    font-size: .82rem;
    color: var(--ink2);
    cursor: pointer;
    user-select: none;
}
.yetki-satir input[type=checkbox] { cursor: pointer; accent-color: var(--primary); }
.kullanici-kart {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: 1rem 1.1rem;
    margin-bottom: .75rem;
}
.kullanici-kart-head {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: .65rem;
}
.kullanici-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--primary);
    color: #fff; font-weight: 700; font-size: .9rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.kullanici-ad { font-weight: 600; font-size: .9rem; color: var(--ink); }
.kullanici-email { font-size: .75rem; color: var(--ink3); }
.badge-yetki {
    display: inline-block; padding: .1rem .45rem;
    border-radius: 99px; font-size: .65rem; font-weight: 600;
    background: rgba(22,163,74,.12); color: #16a34a;
    margin: .1rem .15rem .1rem 0;
}
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    z-index: 200; display: none; align-items: center; justify-content: center;
}
.modal-overlay.acik { display: flex; }
.modal-kutu {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: calc(var(--r) * 1.5); padding: 1.5rem;
    width: 100%; max-width: 420px; max-height: 90vh; overflow-y: auto;
}
.modal-baslik {
    font-size: 1rem; font-weight: 700; color: var(--ink); margin-bottom: 1.1rem;
}
</style>
</head>
<body>
<?php require 'includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require 'includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="page-header">
      <div>
        <h1 class="page-title">Kullanıcılar</h1>
        <p class="page-sub">Alt kullanıcı ekleyin, yetkilerini yönetin</p>
      </div>
      <?php if ($token): ?>
      <button class="btn btn-primary" onclick="modalAc('ekle-modal')">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Kullanıcı Ekle
      </button>
      <?php endif; ?>
    </div>

    <?php if ($hata): ?>
    <div class="alert alert-error" style="margin-bottom:1rem">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($hata) ?>
    </div>
    <?php endif; ?>
    <?php if ($basari): ?>
    <div class="alert alert-success" style="margin-bottom:1rem">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      <?= $basari ?>
    </div>
    <?php endif; ?>

    <?php if (!$token): ?>
    <div class="empty-state">
      <div class="empty-icon" style="font-size:2.5rem">🔗</div>
      <div class="empty-title">Bulut bağlantısı gerekli</div>
      <div class="empty-sub">
        Kullanıcı yönetimi için bulut hesabınızla giriş yapmanız gerekir.<br><br>
        Şu an yerel (offline) modda giriş yapılmış. Lütfen çıkış yapıp <strong>e-posta ve şifrenizle</strong> tekrar giriş yapın.
      </div>
      <a href="/cikis" class="btn btn-primary" style="margin-top:1.25rem">Çıkış Yap → Tekrar Giriş</a>
    </div>
    <?php elseif (empty($calisanlar)): ?>
    <div class="empty-state">
      <div class="empty-icon">👤</div>
      <div class="empty-title">Henüz kullanıcı yok</div>
      <div class="empty-sub">Programa birden fazla bilgisayardan erişmek için kullanıcı ekleyin. Her kullanıcı kendi lisans anahtarı ile giriş yapar.</div>
      <button class="btn btn-primary" style="margin-top:1rem" onclick="modalAc('ekle-modal')">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        İlk Kullanıcıyı Ekle
      </button>
    </div>
    <?php else: ?>

    <div style="margin-bottom:1rem;font-size:.8rem;color:var(--ink3);background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:.65rem .9rem;display:flex;gap:.5rem;align-items:flex-start">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:.1rem"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span>Her kullanıcı farklı bir bilgisayarda programı kullanabilir. Giriş yapabilmek için o bilgisayarda ayrı bir lisans anahtarı girilmesi zorunludur.</span>
    </div>

    <?php foreach ($calisanlar as $c):
      $yetkileri = [];
      $yetkileri[] = $c['cariler']     ? 'Cariler'      : null;
      $yetkileri[] = $c['stok']        ? 'Stok'         : null;
      $yetkileri[] = $c['kasa']        ? 'Kasa'         : null;
      $yetkileri[] = $c['raporlar']    ? 'Raporlar'     : null;
      $yetkileri[] = $c['hizli_satis'] ? 'Hızlı Satış'  : null;
      $yetkileri[] = $c['tahsilat']    ? 'Tahsilat'     : null;
      $yetkileri[] = $c['ayarlar']     ? 'Ayarlar'      : null;
      $yetkileri[] = $c['hareket_sil'] ? 'Hareket Sil'  : null;
      $yetkileri   = array_filter($yetkileri);
      $harf        = mb_strtoupper(mb_substr($c['ad'], 0, 1, 'UTF-8'), 'UTF-8');
    ?>
    <div class="kullanici-kart">
      <div class="kullanici-kart-head">
        <div class="kullanici-avatar"><?= $harf ?></div>
        <div style="flex:1;min-width:0">
          <div class="kullanici-ad"><?= htmlspecialchars($c['ad']) ?></div>
          <div class="kullanici-email"><?= htmlspecialchars($c['email']) ?></div>
        </div>
        <div style="display:flex;gap:.4rem">
          <button class="btn btn-sm btn-outline"
            onclick="duzenleAc(<?= htmlspecialchars(json_encode($c)) ?>)">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Düzenle
          </button>
          <button class="btn btn-sm btn-danger"
            onclick="silOnayla(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['ad'])) ?>')">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
            Sil
          </button>
        </div>
      </div>
      <div>
        <?php foreach ($yetkileri as $y): ?>
        <span class="badge-yetki"><?= $y ?></span>
        <?php endforeach; ?>
        <?php if (empty($yetkileri)): ?>
        <span style="font-size:.75rem;color:var(--ink3)">Hiç yetki atanmamış</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
  </main>
</div>

<!-- Kullanıcı Ekle Modal -->
<div class="modal-overlay" id="ekle-modal">
  <div class="modal-kutu">
    <div class="modal-baslik">Kullanıcı Ekle</div>
    <form method="post">
      <input type="hidden" name="aksiyon" value="ekle">
      <div class="form-group">
        <label class="form-label">Ad Soyad</label>
        <input type="text" name="ad" class="form-input" placeholder="Mehmet Yılmaz" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">E-posta <span style="color:var(--ink3);font-weight:400">(giriş için)</span></label>
        <input type="email" name="email" class="form-input" placeholder="mehmet@ornek.com" required>
      </div>
      <div class="form-group">
        <label class="form-label">Şifre <span style="color:var(--ink3);font-weight:400">(en az 6 karakter)</span></label>
        <input type="password" name="sifre" class="form-input" placeholder="Şifre belirleyin" required minlength="6">
      </div>
      <div class="form-group">
        <label class="form-label" style="margin-bottom:.5rem">Yetkiler</label>
        <div class="yetki-grid">
          <label class="yetki-satir"><input type="checkbox" name="yetki_cariler"    checked> Cariler</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_stok"       checked> Stok</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_hizli_satis" checked> Hızlı Satış</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_tahsilat"   checked> Tahsilat</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_kasa">             Kasa</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_raporlar">         Raporlar</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_ayarlar">          Ayarlar</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_hareket_sil">      Hareket Sil</label>
        </div>
      </div>
      <div style="display:flex;gap:.6rem;margin-top:1.25rem">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Ekle</button>
        <button type="button" class="btn btn-outline" onclick="modalKapat('ekle-modal')">İptal</button>
      </div>
    </form>
  </div>
</div>

<!-- Kullanıcı Düzenle Modal -->
<div class="modal-overlay" id="duzenle-modal">
  <div class="modal-kutu">
    <div class="modal-baslik">Kullanıcı Düzenle</div>
    <form method="post">
      <input type="hidden" name="aksiyon" value="guncelle">
      <input type="hidden" name="kullanici_id" id="duzenle-id">
      <div class="form-group">
        <label class="form-label">Ad Soyad</label>
        <input type="text" name="ad" id="duzenle-ad" class="form-input" placeholder="Değiştirmek için yazın">
      </div>
      <div class="form-group">
        <label class="form-label">Yeni Şifre <span style="color:var(--ink3);font-weight:400">(boş bırakılırsa değişmez)</span></label>
        <input type="password" name="sifre" class="form-input" placeholder="Değiştirmek için girin">
      </div>
      <div class="form-group">
        <label class="form-label" style="margin-bottom:.5rem">Yetkiler</label>
        <div class="yetki-grid">
          <label class="yetki-satir"><input type="checkbox" name="yetki_cariler"     id="d-cariler">    Cariler</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_stok"        id="d-stok">       Stok</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_hizli_satis" id="d-hizli_satis"> Hızlı Satış</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_tahsilat"    id="d-tahsilat">   Tahsilat</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_kasa"        id="d-kasa">       Kasa</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_raporlar"    id="d-raporlar">   Raporlar</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_ayarlar"     id="d-ayarlar">    Ayarlar</label>
          <label class="yetki-satir"><input type="checkbox" name="yetki_hareket_sil" id="d-hareket_sil"> Hareket Sil</label>
        </div>
      </div>
      <div style="display:flex;gap:.6rem;margin-top:1.25rem">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Kaydet</button>
        <button type="button" class="btn btn-outline" onclick="modalKapat('duzenle-modal')">İptal</button>
      </div>
    </form>
  </div>
</div>

<!-- Sil Onay Modal -->
<div class="modal-overlay" id="sil-modal">
  <div class="modal-kutu" style="max-width:360px">
    <div class="modal-baslik">Kullanıcıyı Sil</div>
    <p style="font-size:.85rem;color:var(--ink2);margin-bottom:1.25rem">
      <strong id="sil-ad-goster"></strong> adlı kullanıcı silinecek. Bu işlem geri alınamaz.
    </p>
    <form method="post">
      <input type="hidden" name="aksiyon" value="sil">
      <input type="hidden" name="kullanici_id" id="sil-id">
      <div style="display:flex;gap:.6rem">
        <button type="submit" class="btn btn-danger" style="flex:1;justify-content:center">Evet, Sil</button>
        <button type="button" class="btn btn-outline" onclick="modalKapat('sil-modal')">İptal</button>
      </div>
    </form>
  </div>
</div>

<script>
function modalAc(id)   { document.getElementById(id).classList.add('acik'); }
function modalKapat(id){ document.getElementById(id).classList.remove('acik'); }

document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('acik'); });
});

function duzenleAc(c) {
  document.getElementById('duzenle-id').value = c.id;
  document.getElementById('duzenle-ad').value = c.ad || '';
  ['cariler','stok','kasa','raporlar','ayarlar','hizli_satis','tahsilat','hareket_sil'].forEach(k => {
    const el = document.getElementById('d-' + k);
    if (el) el.checked = !!c[k];
  });
  modalAc('duzenle-modal');
}

function silOnayla(id, ad) {
  document.getElementById('sil-id').value = id;
  document.getElementById('sil-ad-goster').textContent = ad;
  modalAc('sil-modal');
}
</script>
</body>
</html>
