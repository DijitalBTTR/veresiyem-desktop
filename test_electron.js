const e = require('electron');
console.log('Keys:', Object.keys(e).join(', '));
console.log('ipcMain:', typeof e.ipcMain);
console.log('app:', typeof e.app);
