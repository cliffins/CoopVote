(function () {
  'use strict';
  function render(id) {
    const host = document.getElementById('election-qr');
    if (!host) return;
    const link = new URL('../voter/election.html', location.href);
    link.searchParams.set('election', String(id));
    const canvas = host.querySelector('canvas');
    const copy = host.querySelector('[data-qr-copy]');
    const download = host.querySelector('[data-qr-download]');
    try {
      const qr = qrcode(0, 'M');
      qr.addData(link.href); qr.make();
      const cells = qr.getModuleCount(), scale = 6, margin = 4;
      canvas.width = canvas.height = (cells + margin * 2) * scale;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = '#000000';
      for (let row = 0; row < cells; row++) for (let col = 0; col < cells; col++) {
        if (qr.isDark(row, col)) ctx.fillRect((col + margin) * scale, (row + margin) * scale, scale, scale);
      }
      canvas.dataset.url = link.href;
      download.href = canvas.toDataURL('image/png');
      download.download = 'coopvote-election-' + id + '-qr.png';
    } catch (_) {
      canvas.hidden = true; download.hidden = true;
      host.querySelector('[data-qr-help]').textContent = 'QR code unavailable. Copy the election link instead.';
    }
    copy.onclick = async function () {
      copy.disabled = true;
      try {
        if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(link.href);
        else {
          const input = document.createElement('textarea'); input.value = link.href;
          input.style.cssText = 'position:fixed;left:-9999px'; document.body.appendChild(input); input.select();
          try { if (!document.execCommand('copy')) throw Error('Copy unavailable'); }
          finally { input.remove(); copy.focus({ preventScroll: true }); }
        }
        CoopVote.toast('Election link copied.', 'success');
      } catch (_) { CoopVote.toast('Could not copy the link. Open the election link below and copy its address.', 'error'); }
      finally { setTimeout(() => { copy.disabled = false; }, 1000); }
    };
    const open = host.querySelector('[data-qr-link]'); open.href = link.href;
    if (['localhost', '127.0.0.1', '[::1]'].includes(location.hostname)) {
      host.querySelector('[data-qr-local]').hidden = false;
    }
  }
  window.CoopVoteElectionQR = { render };
})();
