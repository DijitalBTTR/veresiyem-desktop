'use strict';

process.on('uncaughtException', (err) => {
  if (err.code === 'EPIPE') return; // PHP pipe kapandığında sessizce geç
});

const { app, BrowserWindow, ipcMain, shell, dialog, Notification } = require('electron');
const path = require('path');
const { spawn } = require('child_process');
const fs = require('fs');
const net = require('net');
const https = require('https');
const crypto = require('crypto');
let autoUpdater;

let mainWindow;
let phpProcess;
let phpPort = 8765;
let userDataPath;
let dbPath;

const PING_URL    = 'https://veresiyem.com.tr/app-ping';
const API_BASE    = 'https://api.veresiyem.com.tr/api/v1';
const LISANS_URL  = API_BASE + '/lisans-kontrol';

// İnternet + API erişim kontrolü
function internetVarMi() {
  return new Promise((resolve) => {
    const req = https.request({
      hostname: 'api.veresiyem.com.tr',
      path: '/api/v1/lisans-kontrol',
      method: 'GET',
      timeout: 5000,
    }, res => { res.resume(); resolve(true); });
    req.on('error', () => resolve(false));
    req.on('timeout', () => { req.destroy(); resolve(false); });
    req.end();
  });
}

// Token kaydedilmiş mi kontrol et, lisans durumunu API'den doğrula
function lisansKontrolEt(token) {
  return new Promise((resolve) => {
    if (!token) return resolve({ ok: false, lisans_aktif: false });
    const req = https.request({
      hostname: 'api.veresiyem.com.tr',
      path: '/api/v1/lisans-kontrol',
      method: 'GET',
      timeout: 8000,
      headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
    }, res => {
      let body = '';
      res.on('data', d => body += d);
      res.on('end', () => {
        try { resolve(JSON.parse(body)); } catch(_) { resolve({ ok: false, lisans_aktif: false }); }
      });
    });
    req.on('error', () => resolve(null)); // null = bağlantı hatası (offline)
    req.on('timeout', () => { req.destroy(); resolve(null); });
    req.end();
  });
}

// Token'ı userData'ya kaydet/oku
function tokenOku(dataDir) {
  try {
    const f = require('path').join(dataDir, 'api_token.txt');
    return require('fs').existsSync(f) ? require('fs').readFileSync(f, 'utf8').trim() : null;
  } catch(_) { return null; }
}
function tokenKaydet(dataDir, token) {
  try {
    require('fs').writeFileSync(require('path').join(dataDir, 'api_token.txt'), token || '', 'utf8');
  } catch(_) {}
}

// UUID'yi userData klasöründe saklıyoruz (uygulama yeniden yüklenirse aynı kalır)
function getOrCreateUUID(dataDir) {
  const uuidFile = path.join(dataDir, 'device_id.txt');
  if (fs.existsSync(uuidFile)) {
    const existing = fs.readFileSync(uuidFile, 'utf8').trim();
    if (existing) return existing;
  }
  const uuid = crypto.randomUUID();
  fs.writeFileSync(uuidFile, uuid, 'utf8');
  return uuid;
}

function sendPing(event, uuid) {
  try {
    const payload = JSON.stringify({
      event,
      uuid,
      version:  app.getVersion(),
      platform: process.platform,
      arch:     process.arch,
    });

    const url = new URL(PING_URL);
    const options = {
      hostname: url.hostname,
      path:     url.pathname,
      method:   'POST',
      headers: {
        'Content-Type':   'application/json',
        'Content-Length': Buffer.byteLength(payload),
        'User-Agent':     `Veresiyem/${app.getVersion()} Electron`,
      },
      timeout: 8000,
    };

    const req = https.request(options, res => {
      res.resume(); // body'yi tüket, bellek sızıntısı olmasın
    });
    req.on('error',   () => {}); // sessizce geç — çevrimdışı olabilir
    req.on('timeout', () => { req.destroy(); });
    req.write(payload);
    req.end();
  } catch (_) {
    // Ping hatası uygulamayı etkilemesin
  }
}

function getPhpBin() {
  const phpBinary = process.platform === 'win32' ? 'php.exe' : 'php';
  if (app.isPackaged) return path.join(process.resourcesPath, 'php-bin', phpBinary);
  return path.join(__dirname, '..', 'php-bin', phpBinary);
}

function getPhpIni() {
  if (app.isPackaged) return path.join(process.resourcesPath, 'php-bin', 'php.ini');
  return path.join(__dirname, '..', 'php-bin', 'php.ini');
}

function getPhpRoot() {
  if (app.isPackaged) return path.join(process.resourcesPath, 'php');
  return path.join(__dirname, '..', 'php');
}

function findFreePort(start) {
  return new Promise((resolve) => {
    const server = net.createServer();
    server.listen(start, '127.0.0.1', () => {
      const port = server.address().port;
      server.close(() => resolve(port));
    });
    server.on('error', () => resolve(findFreePort(start + 1)));
  });
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 900,
    minHeight: 600,
    frame: false,
    backgroundColor: '#F4F6F8',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      nodeIntegration: false,
      contextIsolation: true,
      webSecurity: false,
    },
    show: true,
  });

  // DevTools sadece geliştirme ortamında açılır
  if (!app.isPackaged) mainWindow.webContents.openDevTools({ mode: 'detach' });

  const url = `http://127.0.0.1:${phpPort}/`;
  console.log('Loading URL:', url);
  mainWindow.loadURL(url);

  mainWindow.webContents.on('did-fail-load', (e, code, desc, failUrl) => {
    console.error('Page load failed:', code, desc, failUrl);
  });

  mainWindow.webContents.setWindowOpenHandler(({ url: openUrl }) => {
    if (!openUrl.startsWith(`http://127.0.0.1:${phpPort}`)) {
      shell.openExternal(openUrl);
      return { action: 'deny' };
    }
    return { action: 'allow' };
  });
}

// --- Auto Updater ---
function setupAutoUpdater() {
  autoUpdater = require('electron-updater').autoUpdater;
  autoUpdater.autoDownload    = true;   // arka planda sessizce indir
  autoUpdater.autoInstallOnAppQuit = false; // kullanıcı onayı olmadan yükleme

  autoUpdater.on('update-available', (info) => {
    // Güncelleme bulundu — indirme zaten başladı (autoDownload:true)
    mainWindow && mainWindow.webContents.send('update-available', {
      version: info.version,
      releaseNotes: info.releaseNotes || '',
    });
  });

  autoUpdater.on('update-downloaded', (info) => {
    // İndirme bitti — kullanıcıya sor
    mainWindow && mainWindow.webContents.send('update-downloaded', {
      version: info.version,
    });
  });

  autoUpdater.on('error', (err) => {
    console.error('AutoUpdater error:', err.message);
  });

  // Uygulama açıldıktan 5 sn sonra kontrol et (startup yavaşlamasın)
  setTimeout(() => {
    autoUpdater.checkForUpdates().catch(() => {});
  }, 5000);

  ipcMain.on('update-install-now', () => {
    autoUpdater.quitAndInstall(false, true);
  });

  ipcMain.on('update-install-later', () => {
    autoUpdater.autoInstallOnAppQuit = true;
  });
}

// Tüm IPC handler'ları app ready sonrası kurulur
function setupIpc() {
  // Drag throttle — setPosition çok sık çağrılmasın
  let _dragPending = false;
  let _dragDx = 0, _dragDy = 0;
  ipcMain.on('win-drag', (event, dx, dy) => {
    if (!mainWindow) return;
    _dragDx += dx; _dragDy += dy;
    if (_dragPending) return;
    _dragPending = true;
    setImmediate(() => {
      const [x, y] = mainWindow.getPosition();
      mainWindow.setPosition(Math.round(x + _dragDx), Math.round(y + _dragDy));
      _dragDx = 0; _dragDy = 0;
      _dragPending = false;
    });
  });

  ipcMain.on('win-minimize', () => mainWindow && mainWindow.minimize());
  ipcMain.on('win-maximize', () => {
    if (!mainWindow) return;
    if (mainWindow.isMaximized()) mainWindow.unmaximize();
    else mainWindow.maximize();
  });
  ipcMain.on('win-close', () => mainWindow && mainWindow.close());
  ipcMain.handle('win-is-maximized', () => mainWindow ? mainWindow.isMaximized() : false);
  ipcMain.handle('get-php-port', () => phpPort);
  ipcMain.handle('get-db-path', () => dbPath);
  ipcMain.handle('get-app-version', () => app.getVersion());
  ipcMain.handle('get-app-mode', () => process.env.VERESIYEM_APP_MODE || 'offline');
  ipcMain.handle('get-api-token', () => tokenOku(userDataPath));
  ipcMain.handle('save-api-token', (e, token) => { tokenKaydet(userDataPath, token); return true; });
  ipcMain.handle('lisans-iptal-kontrol', async () => {
    const token = tokenOku(userDataPath);
    if (!token) return { lisans_aktif: true };
    const sonuc = await lisansKontrolEt(token);
    if (sonuc && sonuc.ok === true && sonuc.lisans_aktif === false) {
      return { lisans_aktif: false };
    }
    return { lisans_aktif: true };
  });

  ipcMain.handle('backup-db', async () => {
    const tarih = new Date().toISOString().slice(0,10);
    const { canceled, filePath } = await dialog.showSaveDialog(mainWindow, {
      title: 'Yedek Kaydet',
      defaultPath: `veresiyem_yedek_${tarih}.db`,
      filters: [{ name: 'Veritabanı Yedeği', extensions: ['db'] }]
    });
    if (canceled || !filePath) return { ok: false };
    fs.copyFileSync(dbPath, filePath);
    return { ok: true, filePath };
  });

  ipcMain.handle('restore-db', async () => {
    const { canceled, filePaths } = await dialog.showOpenDialog(mainWindow, {
      title: 'Yedekten Geri Yükle',
      filters: [{ name: 'Veritabanı Yedeği', extensions: ['db'] }],
      properties: ['openFile']
    });
    if (canceled || !filePaths[0]) return { ok: false };
    const tmpBackup = dbPath + '.bak';
    fs.copyFileSync(dbPath, tmpBackup);
    try {
      fs.copyFileSync(filePaths[0], dbPath);
      return { ok: true };
    } catch(e) {
      fs.copyFileSync(tmpBackup, dbPath);
      return { ok: false, error: e.message };
    }
  });

  ipcMain.handle('save-pdf', async (event, htmlContent) => {
    const { canceled, filePath } = await dialog.showSaveDialog(mainWindow, {
      title: 'PDF Kaydet',
      defaultPath: 'ekstre.pdf',
      filters: [{ name: 'PDF Belgesi', extensions: ['pdf'] }]
    });
    if (canceled || !filePath) return { ok: false };
    const win = new BrowserWindow({ show: false, webPreferences: { nodeIntegration: false, contextIsolation: true } });
    const tmpFile = path.join(require('os').tmpdir(), `ekstre_${Date.now()}.html`);
    fs.writeFileSync(tmpFile, htmlContent, 'utf8');
    await win.loadFile(tmpFile);
    const pdfData = await win.webContents.printToPDF({ printBackground: true, pageSize: 'A4', marginsType: 0 });
    win.destroy();
    fs.writeFileSync(filePath, pdfData);
    try { fs.unlinkSync(tmpFile); } catch(_) {}
    return { ok: true, filePath };
  });

  ipcMain.handle('print-fis', async (event, htmlContent) => {
    const os = require('os');
    const tmpFile = path.join(os.tmpdir(), `fis_${Date.now()}.html`);
    fs.writeFileSync(tmpFile, htmlContent, 'utf8');
    const win = new BrowserWindow({
      width: 340, height: 700,
      show: true,
      title: 'Fiş Önizleme',
      webPreferences: { nodeIntegration: false, contextIsolation: true }
    });
    win.loadFile(tmpFile);
    win.webContents.once('did-finish-load', () => {
      win.webContents.print(
        {
          silent: false,
          printBackground: true,
          pageSize: { width: 80000, height: 297000 },
          margins: { marginType: 'none' },
        },
        (success) => {
          try { fs.unlinkSync(tmpFile); } catch(_) {}
          if (!win.isDestroyed()) win.destroy();
        }
      );
    });
  });
}

app.whenReady().then(async () => {
  userDataPath = app.getPath('userData');
  dbPath = path.join(userDataPath, 'veresiyem.db');
  console.log('App ready, userData:', userDataPath);
  setupIpc();

  // --- Telemetri ping ---
  const deviceUUID   = getOrCreateUUID(userDataPath);
  const isFirstLaunch = !fs.existsSync(dbPath); // DB yoksa ilk kurulum
  if (isFirstLaunch) sendPing('install', deviceUUID);
  sendPing('open', deviceUUID);

  const phpBinPath = getPhpBin();
  if (!fs.existsSync(phpBinPath)) {
    dialog.showErrorBox('PHP Bulunamadı', `PHP bulunamadı:\n${phpBinPath}`);
    app.quit();
    return;
  }

  try {
    phpPort = await findFreePort(8765);
    const phpIni  = getPhpIni();
    const phpRoot = getPhpRoot();

    // Bu sürüm tamamen offline — internet/lisans kontrolü yok
    const appMod = 'offline';

    const env = Object.assign({}, process.env, {
      VERESIYEM_DB_PATH:  dbPath,
      VERESIYEM_DATA_DIR: userDataPath,
      VERESIYEM_APP_MODE: 'offline',
    });

    const spawnArgs = ['-S', `127.0.0.1:${phpPort}`, '-c', phpIni, 'router.php'];
    const spawnOpts = { cwd: phpRoot, env, windowsHide: true };

    // Mac'te binary çalıştırma izni ver
    if (process.platform !== 'win32') {
      try { require('fs').chmodSync(phpBinPath, 0o755); } catch(e) {}
    }

    phpProcess = spawn(phpBinPath, spawnArgs, spawnOpts);

    let phpStderr = '';
    let phpSpawnError = null;
    phpProcess.stdout.on('data', d => { try { console.log('PHP:', d.toString()); } catch(e) {} });
    phpProcess.stderr.on('data', d => { phpStderr += d.toString(); try { console.log('PHP ERR:', d.toString()); } catch(e) {} });
    phpProcess.on('error', err => { phpSpawnError = err; try { console.error('PHP spawn error:', err); } catch(e) {} });
    phpProcess.on('exit', code => { try { console.log('PHP exited:', code); } catch(e) {} });
    phpProcess.stdout.on('error', () => {});
    phpProcess.stderr.on('error', () => {});

    // PHP'nin gerçekten ayağa kalktığını doğrula
    const phpReady = await new Promise((resolve) => {
      let attempts = 0;
      const check = () => {
        const sock = net.createConnection({ port: phpPort, host: '127.0.0.1' });
        sock.on('connect', () => { sock.destroy(); resolve(true); });
        sock.on('error', () => {
          attempts++;
          if (attempts >= 20) resolve(false); // 4 saniye bekle
          else setTimeout(check, 200);
        });
      };
      setTimeout(check, 300);
    });

    if (!phpReady) {
      let msg = 'PHP sunucusu başlatılamadı.\n\n';
      if (phpSpawnError) {
        msg += `Spawn hatası: ${phpSpawnError.message}\n\n`;
      }
      if (phpStderr) {
        msg += `PHP çıktısı:\n${phpStderr.slice(0, 600)}\n\n`;
      }
      if (!phpSpawnError && !phpStderr) {
        msg += 'PHP yanıt vermedi (timeout).\n\n';
      }
      msg += `PHP yolu: ${phpBinPath}\nPHP var mı: ${fs.existsSync(phpBinPath)}\n\n`;
      msg += 'Eğer "DLL bulunamadı" hatası görüyorsanız:\nVisual C++ Redistributable (x64) kurun:\nhttps://aka.ms/vs/17/release/vc_redist.x64.exe';
      dialog.showErrorBox('Başlatma Hatası', msg);
      app.quit();
      return;
    }

    createWindow();
    hatirlaticiBaslat();
    if (app.isPackaged) setupAutoUpdater();
  } catch (err) {
    console.error('Startup error:', err);
    dialog.showErrorBox('Başlatma Hatası', err.message);
    app.quit();
  }
});

// --- Hatırlatıcı Bildirim Sistemi ---
let hatirlaticiTimer = null;

function hatirlaticiKontrol() {
  if (!phpPort) return;
  const http = require('http');
  const req = http.get(`http://127.0.0.1:${phpPort}/hatirlatici-kontrol`, (res) => {
    let body = '';
    res.on('data', d => body += d);
    res.on('end', () => {
      try {
        const json = JSON.parse(body);
        if (json.ok && Array.isArray(json.data)) {
          json.data.forEach(h => {
            if (Notification.isSupported()) {
              const n = new Notification({
                title: '⏰ ' + h.baslik,
                body: h.aciklama || `${h.tarih} ${h.saat}`,
                silent: false,
              });
              n.on('click', () => {
                if (mainWindow) {
                  mainWindow.show();
                  mainWindow.focus();
                  mainWindow.loadURL(`http://127.0.0.1:${phpPort}/hatirlaticilar`);
                }
              });
              n.show();
            }
          });
        }
      } catch (_) {}
    });
  });
  req.on('error', () => {});
}

function hatirlaticiBaslat() {
  // İlk kontrolü 10 saniye sonra yap (PHP ayağa kalksın)
  setTimeout(() => {
    hatirlaticiKontrol();
    // Sonra her dakika kontrol et
    hatirlaticiTimer = setInterval(hatirlaticiKontrol, 60 * 1000);
  }, 10000);
}

app.on('window-all-closed', () => {
  if (hatirlaticiTimer) clearInterval(hatirlaticiTimer);
  if (phpProcess) phpProcess.kill();
  app.quit();
});

app.on('before-quit', () => {
  if (phpProcess) phpProcess.kill();
});
