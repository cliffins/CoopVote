(() => {
  'use strict';
  let dialog, opener;
  function createPreview() {
    dialog = document.createElement('dialog');
    dialog.id = 'candidate-photo-preview';
    dialog.setAttribute('aria-labelledby', 'candidate-photo-preview-title');
    dialog.innerHTML = '<div class="photo-preview-head"><h2 id="candidate-photo-preview-title">Candidate photo</h2><button type="button" aria-label="Close photo preview">×</button></div><img alt=""><p class="photo-preview-error" role="status" hidden>Unable to load this photo. Close the preview and try again.</p>';
    document.body.append(dialog);
    dialog.querySelector('button').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    // Escape closes only the photo; keep the candidate profile underneath open.
    dialog.addEventListener('keydown', event => { if (event.key === 'Escape') event.stopPropagation(); });
    dialog.addEventListener('close', () => {
      dialog.querySelector('img').removeAttribute('src');
      if (opener?.isConnected) opener.focus({preventScroll:true});
    });
    dialog.querySelector('img').addEventListener('error', () => {
      if (!dialog.open) return;
      dialog.querySelector('img').hidden = true;
      dialog.querySelector('.photo-preview-error').hidden = false;
    });
  }
  document.addEventListener('click', event => {
    const button = event.target.closest('button[data-candidate-photo]');
    const image = button?.querySelector('img');
    if (!image?.getAttribute('src')) return;
    if (!dialog) createPreview();
    opener = button;
    const preview = dialog.querySelector('img');
    preview.hidden = false;
    dialog.querySelector('.photo-preview-error').hidden = true;
    document.getElementById('candidate-photo-preview-title').textContent = image.alt ? image.alt + ' — Profile photo' : 'Candidate profile photo';
    preview.alt = image.alt || 'Candidate profile photo';
    preview.src = image.currentSrc || image.src;
    if (!dialog.open) dialog.showModal();
    dialog.querySelector('button').focus();
  });
})();
