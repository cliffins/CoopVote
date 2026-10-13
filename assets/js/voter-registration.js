/* Keep voter creation controls aligned with the server's election window. */
(function () {
  'use strict';
  let allowed = false;
  let reason = 'Checking voter registration availability...';
  let pending = null;
  let timer = null;
  const get = id => document.getElementById(id);

  function sync() {
    const add = get('add-voter-btn');
    if (add) { add.disabled = !allowed; add.title = reason; }
    const bulk = get('import-voters-btn');
    if (bulk) { bulk.disabled = !allowed; bulk.title = reason; }
    const note = get('voter-registration-status');
    if (note) note.textContent = reason;
    const save = get('save-voter-btn');
    if (save) save.disabled = !get('edit-id').value && !allowed;
  }

  function refresh() {
    if (pending) return pending;
    pending = (async () => {
      try {
        const state = await CoopVote.api('voters/registration-status');
        allowed = state.allowed === true;
        reason = allowed ? '' : state.reason || 'Adding voters is currently unavailable.';
      } catch (_) {
        allowed = false;
        reason = 'Could not check election status. Reconnect or refresh to add a voter.';
      }
      sync();
      return allowed;
    })().finally(() => { pending = null; });
    return pending;
  }

  async function check() {
    if (await refresh()) return true;
    CoopVote.toast(reason, 'warn');
    return false;
  }

  function start() {
    sync();
    refresh();
    clearInterval(timer);
    timer = setInterval(() => { if (!document.hidden) refresh(); }, 10000);
  }

  window.addEventListener('focus', () => { if (timer) refresh(); });
  window.addEventListener('online', () => { if (timer) refresh(); });
  document.addEventListener('visibilitychange', () => { if (timer && !document.hidden) refresh(); });
  window.addEventListener('pagehide', () => { clearInterval(timer); timer = null; });
  window.addEventListener('pageshow', event => { if (event.persisted) start(); });
  window.CoopVoteVoterRegistration = { start, sync, refresh, check };
})();
