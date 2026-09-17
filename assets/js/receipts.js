'use strict';

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}

(() => {
  let saved = null, pending = null, previous = '';
  let cooldownUntil = 0, cooldownTimer = null;
  const list = document.getElementById('receipt-list');
  const status = document.getElementById('receipt-status');
  const refresh = document.getElementById('refresh-receipts');
  const printIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6z" /></svg>';

  function receiptMarkup(receipt, member, printing = false) {
    const note = receipt.published
      ? 'The admin has published the election results.'
      : 'Please wait for the admin to publish the election results.';
    return `<article class="receipt-card">
      <div class="receipt-heading"><h2>${esc(receipt.electionTitle)}</h2><span class="receipt-state">✓ Vote recorded</span></div>
      <dl class="receipt-details">
        <div><dt>Receipt code</dt><dd class="receipt-code">${esc(receipt.receiptCode || 'Not available')}</dd></div>
        <div><dt>Submitted at</dt><dd>${esc(receipt.castAt)}</dd></div>
        <div><dt>Voter</dt><dd>${esc(member.name)}</dd></div>
        <div><dt>Member ID</dt><dd>${esc(member.memberId)}</dd></div>
      </dl>
      <div class="receipt-footer"><p class="receipt-note">${note}</p>
        ${printing ? '' : `<div class="receipt-actions"><button type="button" class="receipt-button" data-print="${Number(receipt.id)}" aria-label="${esc('Print / Save PDF: ' + receipt.electionTitle)}">${printIcon}Print / Save PDF</button></div>`}
      </div>
    </article>`;
  }

  function loadReceipts(manual = false) {
    if (pending) return pending;
    refresh.disabled = true;
    refresh.textContent = 'Refreshing…';
    refresh.setAttribute('aria-busy', 'true');
    if (!saved) status.textContent = 'Loading your receipts…';
    list.setAttribute('aria-busy', 'true');
    const controller = new AbortController();
    let timeout;
    const deadline = new Promise((resolve, reject) => {
      timeout = setTimeout(() => {
        reject(new Error('Receipt refresh timed out.'));
        controller.abort();
      }, 15000);
    });
    pending = Promise.race([
      CoopVote.api('voter/receipts', { cache: 'no-store', signal: controller.signal }), deadline
    ]).then(data => {
      if (!Array.isArray(data.receipts) || !data.member) throw new Error('Could not read your receipts. Please try again.');
      saved = data;
      const serialized = JSON.stringify(data);
      if (serialized !== previous) {
        const content = document.querySelector('.content'), top = content.scrollTop;
        list.innerHTML = data.receipts.length
          ? data.receipts.map(receipt => receiptMarkup(receipt, data.member)).join('')
          : '<section class="receipt-empty"><h2>No vote receipts yet</h2><p>Your receipt will appear here after you submit a vote.</p><a class="receipt-button" href="ballot.html">Go to Cast My Vote</a></section>';
        content.scrollTop = top;
        previous = serialized;
      }
      const count = data.receipts.length;
      status.textContent = count + (count === 1 ? ' receipt' : ' receipts') + ' · Latest votes first';
      if (manual) CoopVote.toast('Receipts are up to date.', 'success');
    }).catch(error => {
      status.textContent = saved ? 'Showing your last loaded receipts.' : 'No receipts loaded. Select Refresh to try again.';
      if (manual) CoopVote.toast('Could not refresh your receipts. Please try again.', 'error');
      throw error;
    }).finally(() => {
      clearTimeout(timeout);
      pending = null;
      if (manual) {
        cooldownUntil = Date.now() + 5000;
        clearTimeout(cooldownTimer);
        cooldownTimer = setTimeout(() => {
          if (!pending) refresh.disabled = false;
        }, 5000);
      }
      refresh.disabled = Date.now() < cooldownUntil;
      refresh.textContent = 'Refresh';
      refresh.setAttribute('aria-busy', 'false');
      list.setAttribute('aria-busy', 'false');
    });
    return pending;
  }

  // Print the selected receipt in place; closing the dialog leaves this page open.
  list.addEventListener('click', event => {
    const button = event.target.closest('[data-print]');
    if (!button || !list.contains(button)) return;
    const receipt = saved?.receipts.find(item => Number(item.id) === Number(button.dataset.print));
    if (!receipt) return;
    document.getElementById('print-area').innerHTML = '<h1>CoopVote · Vote Receipt</h1>' + receiptMarkup(receipt, saved.member, true);
    window.print();
  });
  window.addEventListener('afterprint', () => { document.getElementById('print-area').innerHTML = ''; });
  refresh.addEventListener('click', () => {
    if (pending || Date.now() < cooldownUntil) return;
    loadReceipts(true).catch(() => {});
  });
  CoopVote.guard('member').then(async () => {
    try { await loadReceipts(); } catch (error) { /* The retry message stays on the page. */ }
    CoopVote.watch(loadReceipts, 10000);
  });
})();
