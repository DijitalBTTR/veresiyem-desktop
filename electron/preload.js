const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  drag: (() => {
    let _t = 0, _ax = 0, _ay = 0;
    return (dx, dy) => {
      _ax += dx; _ay += dy;
      const now = Date.now();
      if (now - _t < 16) return; // ~60fps
      _t = now;
      ipcRenderer.send('win-drag', _ax, _ay);
      _ax = 0; _ay = 0;
    };
  })(),
  minimize:    () => ipcRenderer.send('win-minimize'),
  maximize:    () => ipcRenderer.send('win-maximize'),
  close:       () => ipcRenderer.send('win-close'),
  isMaximized: () => ipcRenderer.invoke('win-is-maximized'),
  getPort:     () => ipcRenderer.invoke('get-php-port'),
  getVersion:  () => ipcRenderer.invoke('get-app-version'),

  // Auto-update
  onUpdateAvailable:  (cb) => ipcRenderer.on('update-available',  (_e, info) => cb(info)),
  onUpdateDownloaded: (cb) => ipcRenderer.on('update-downloaded', (_e, info) => cb(info)),
  installNow:  () => ipcRenderer.send('update-install-now'),
  installLater:() => ipcRenderer.send('update-install-later'),

  // Yedek
  backupDb:  () => ipcRenderer.invoke('backup-db'),
  restoreDb: () => ipcRenderer.invoke('restore-db'),
  // PDF kaydet
  savePdf: (html) => ipcRenderer.invoke('save-pdf', html),
  // Fiş yazdırma
  printFis: (html) => ipcRenderer.invoke('print-fis', html),
  // Mod & API
  getAppMode:    () => ipcRenderer.invoke('get-app-mode'),
  getApiToken:   () => ipcRenderer.invoke('get-api-token'),
  saveApiToken:  (t) => ipcRenderer.invoke('save-api-token', t),
  lisansKontrol: () => ipcRenderer.invoke('lisans-iptal-kontrol'),
});
