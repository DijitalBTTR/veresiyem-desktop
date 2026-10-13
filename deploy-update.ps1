# Veresiyem — Güncelleme Yayınlama Scripti
# Kullanım: PowerShell'de çalıştır -> .\deploy-update.ps1
# Yapılan işlemler:
#   1. npm run build ile yeni installer üretir
#   2. build/ klasöründeki .exe ve latest.yml'i sunucuya yükler

$SSH_KEY   = "C:\Users\pc01\Desktop\SSH-Backup\gelirgider_vps"
$REMOTE    = "root@94.73.180.206"
$REMOTE_DIR = "/var/www/veresiyem/downloads"
$BUILD_DIR  = ".\build"

Write-Host "=== Veresiyem Güncelleme Yayınlama ===" -ForegroundColor Cyan

# 1. electron-updater yüklü mü kontrol
if (-not (Test-Path ".\node_modules\electron-updater")) {
    Write-Host "electron-updater kuruluyor..." -ForegroundColor Yellow
    npm install
}

# 2. Build al
Write-Host "`n[1/3] Build aliniyor..." -ForegroundColor Yellow
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Host "BUILD HATASI! Durduruluyor." -ForegroundColor Red
    exit 1
}

# 3. Üretilen dosyaları bul
$exe = Get-ChildItem "$BUILD_DIR\*.exe" | Select-Object -First 1
$yml = Get-ChildItem "$BUILD_DIR\latest.yml" | Select-Object -First 1

if (-not $exe) {
    Write-Host "HATA: .exe dosyasi bulunamadi!" -ForegroundColor Red
    exit 1
}
if (-not $yml) {
    Write-Host "HATA: latest.yml bulunamadi!" -ForegroundColor Red
    exit 1
}

Write-Host "`n[2/3] Sunucuya yukleniyor..."  -ForegroundColor Yellow
Write-Host "  -> $($exe.Name)"
Write-Host "  -> latest.yml"

# Eski dosyaları yedekle, yenilerini yükle
$scp = "scp -i `"$SSH_KEY`" -o StrictHostKeyChecking=no"
Invoke-Expression "$scp `"$($exe.FullName)`" ${REMOTE}:${REMOTE_DIR}/$($exe.Name)"
Invoke-Expression "$scp `"$($yml.FullName)`" ${REMOTE}:${REMOTE_DIR}/latest.yml"

if ($LASTEXITCODE -ne 0) {
    Write-Host "YUKLEME HATASI!" -ForegroundColor Red
    exit 1
}

# 4. Sunucu izinlerini düzelt
$ssh = "ssh -i `"$SSH_KEY`" -o StrictHostKeyChecking=no $REMOTE"
Invoke-Expression "$ssh `"chmod 644 $REMOTE_DIR/*.exe $REMOTE_DIR/latest.yml 2>/dev/null; echo OK`""

Write-Host "`n[3/3] Tamamlandi!" -ForegroundColor Green
Write-Host "  Yayinlanan: $($exe.Name)" -ForegroundColor Green
Write-Host "  URL: https://veresiyem.com.tr/downloads/$($exe.Name)" -ForegroundColor Green
Write-Host "  Kullanicilar bir sonraki acilista otomatik bildirim alacak.`n" -ForegroundColor Cyan
