try {
  // Electron context'inde bu çalışmalı
  const e = process.electronBinding ? process.electronBinding('app') : require('electron');
  console.log('type:', typeof e);
  if (typeof e === 'object') console.log('keys:', Object.keys(e).slice(0,5).join(','));
} catch(err) {
  console.log('ERROR:', err.message);
}
