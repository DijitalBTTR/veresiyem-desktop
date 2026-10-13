// Merkezi fiş yazdırma — window._fisAyar ve window._firmaAdi kullanır
// Çağrı: veresiyemFisYazdir(data)  |  veresiyemFisHtml(data) → string

function veresiyemFisHtml(d) {
  var ayar   = window._fisAyar  || {};
  var firma  = window._firmaAdi || 'Veresiyem';
  var boyut  = ayar.boyut  || '80mm';
  var sablon = ayar.sablon || 'standart';

  var tip   = d.tip === 'alacak' ? 'NAKİT SATIŞ' : 'VADELİ SATIŞ';
  var tarih = (d.tarih || '').split('-').reverse().join('.');
  var simdi = new Date().toLocaleTimeString('tr-TR', {hour:'2-digit', minute:'2-digit'});

  var mm   = boyut === '58mm' ? '58mm' : '80mm';
  var fs   = boyut === '58mm' ? '8pt' : '9pt';
  var fsB  = boyut === '58mm' ? '10pt' : '11pt';
  var fsT  = boyut === '58mm' ? '10pt' : '11pt';
  var cols = boyut === '58mm' ? 24 : 38;

  function satir(sol, sag) {
    sol = String(sol || '');
    sag = String(sag || '');
    var maxSol = cols - sag.length - 1;
    if (sol.length > maxSol) sol = sol.substring(0, maxSol - 1) + '.';
    var bosluk = cols - sol.length - sag.length;
    if (bosluk < 1) bosluk = 1;
    return sol + ' '.repeat(bosluk) + sag;
  }
  function ortala(txt) {
    txt = String(txt || '');
    var bosluk = Math.max(0, Math.floor((cols - txt.length) / 2));
    return ' '.repeat(bosluk) + txt;
  }
  function sep(c) { return (c || '-').repeat(cols); }

  var toplam = 0;
  (d.kalemler || []).forEach(function(k) { toplam += parseFloat(k.tutar) || 0; });
  if (d.toplam) toplam = parseFloat(d.toplam);

  function fmt(v) { return '₺' + Math.abs(parseFloat(v)||0).toFixed(2).replace('.',','); }

  var lines = [];

  if (sablon === 'minimal') {
    lines.push(firma.toUpperCase());
    lines.push(sep('-'));
    lines.push(satir('Musteri :', d.cari_ad || ''));
    if (d.kalan_borc !== undefined && d.kalan_borc !== null) {
      lines.push(satir('Veresiye:', '₺' + parseFloat(d.kalan_borc).toFixed(2).replace('.',',')));
    }
    lines.push(satir('Tarih   :', tarih + ' ' + simdi));
    lines.push(satir('Tur     :', tip));
    lines.push(sep('-'));
    lines.push(satir('TOPLAM :', fmt(toplam)));
    lines.push(sep('-'));
    lines.push(ortala('Tesekkurler, Yine Bekleriz'));
    lines.push(ortala('(Mali Degeri Yoktur)'));

  } else if (sablon === 'detayli') {
    lines.push(firma.toUpperCase());
    lines.push(sep('-'));
    lines.push(satir('Musteri :', d.cari_ad || ''));
    if (d.kalan_borc !== undefined && d.kalan_borc !== null) {
      lines.push(satir('Veresiye:', '₺' + parseFloat(d.kalan_borc).toFixed(2).replace('.',',')));
    }
    lines.push(satir('Tarih   :', tarih + ' ' + simdi));
    if (d.tip === 'borc' && d.vade) lines.push(satir('Vade    :', d.vade.split('-').reverse().join('.')));
    lines.push(satir('Tur     :', tip));
    lines.push(sep('-'));
    lines.push(satir('Urun Adi', 'Tutar'));
    lines.push(sep('-'));
    (d.kalemler || []).forEach(function(k, i) {
      var t   = parseFloat(k.tutar) || 0;
      var mik = parseFloat(k.miktar) || 1;
      var ad  = k.aciklama || ('Kalem ' + (i+1));
      if (t < 0) {
        lines.push(satir('  indirim', '-' + fmt(t)));
      } else {
        var mikStr = mik > 1 ? ' x' + mik.toLocaleString('tr-TR') : '';
        lines.push(satir(ad + mikStr, fmt(t)));
      }
    });
    lines.push(sep('-'));
    lines.push(satir('TOPLAM :', fmt(toplam)));
    lines.push(sep('-'));
    lines.push(ortala('Tesekkurler, Yine Bekleriz'));
    lines.push(ortala('(Mali Degeri Yoktur)'));

  } else {
    // STANDART — Pixelpos sıralaması
    lines.push(firma.toUpperCase());
    lines.push(sep('-'));
    lines.push(satir('Musteri :', d.cari_ad || ''));
    if (d.kalan_borc !== undefined && d.kalan_borc !== null) {
      lines.push(satir('Veresiye:', '₺' + parseFloat(d.kalan_borc).toFixed(2).replace('.',',')));
    }
    lines.push(satir('Tarih   :', tarih + ' ' + simdi));
    if (d.tip === 'borc' && d.vade) lines.push(satir('Vade    :', d.vade.split('-').reverse().join('.')));
    lines.push(satir('Tur     :', tip));
    lines.push(sep('-'));
    lines.push(satir('Urun Adi', 'Tutar'));
    lines.push(sep('-'));
    (d.kalemler || []).forEach(function(k, i) {
      var t   = parseFloat(k.tutar) || 0;
      var mik = parseFloat(k.miktar);
      var ad  = k.aciklama || k.stok || 'Urun';
      if (t < 0) {
        lines.push(satir('  indirim', '-' + fmt(t)));
      } else {
        var mikStr = (mik && mik > 1) ? ' x' + mik.toLocaleString('tr-TR') : '';
        lines.push(satir(ad + mikStr, fmt(t)));
      }
    });
    lines.push(sep('-'));
    lines.push(satir('TOPLAM :', fmt(toplam)));
    lines.push(sep('-'));
    lines.push(ortala('Tesekkurler, Yine Bekleriz'));
    lines.push(ortala('(Mali Degeri Yoktur)'));
  }

  // ── HTML oluştur ──────────────────────────────────────────────
  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
  html += '* { margin:0; padding:0; box-sizing:border-box; }';
  html += 'html, body {';
  html += '  font-family: "Courier New", Courier, monospace;';
  html += '  font-size: ' + fs + ';';
  html += '  font-weight: bold;';
  html += '  line-height: 1.4;';
  html += '  width: ' + mm + ';';
  html += '  max-width: ' + mm + ';';
  html += '  padding: 2mm 2mm;';
  html += '  color: #000 !important;';
  html += '  background: #fff !important;';
  html += '  -webkit-print-color-adjust: exact;';
  html += '  print-color-adjust: exact;';
  html += '}';
  // Her satır: taşmayı kes, beyaz boşlukları koru
  html += 'div { white-space: pre; overflow: hidden; display: block; width:100%; }';
  html += '.firma { font-size:' + fsB + '; text-align:center; font-weight:900; letter-spacing:0.5px; white-space:normal; word-break:break-word; overflow:visible; display:block; margin-bottom:1mm; }';
  html += '.orta { text-align:center; white-space:normal; overflow:visible; }';
  html += '@media print {';
  html += '  @page { margin:0; padding:0; size:' + mm + ' auto; }';
  html += '  html, body { width:' + mm + '; padding:1mm 2mm; color:#000 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }';
  html += '}';
  html += '</style></head><body>';

  lines.forEach(function(line, idx) {
    var isFirma = idx === 0;
    var isOrta  = (line === tip || line === 'Satis Fisi')
               || line.indexOf('Tesekkurler') !== -1
               || line.indexOf('Mali Degeri') !== -1;
    if (isFirma) {
      html += '<div class="firma">' + line + '</div>';
    } else if (isOrta) {
      html += '<div class="orta">' + line + '</div>';
    } else {
      html += '<div>' + line + '</div>';
    }
  });

  html += '<div style="margin-top:15mm"></div>';
  html += '</body></html>';
  return html;
}

function veresiyemFisYazdir(d) {
  var html = veresiyemFisHtml(d);
  if (window.electronAPI && window.electronAPI.printFis) {
    window.electronAPI.printFis(html);
  } else {
    var w = window.open('', '_blank', 'width=370,height=600,toolbar=0,menubar=0');
    if (w) { w.document.write(html); w.document.close(); setTimeout(function(){ w.print(); }, 400); }
  }
}
