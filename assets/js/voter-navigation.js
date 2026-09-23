(function () {
  'use strict';
  let owner = null, elections = [], pending = null, stopWatching = null, expiryTimer = null;
  const recordedVotes = new Set();

  function liveElections() {
    return elections.filter(e => e.live === true && e.status === 'Active'
      && (!e.endDateISO || Date.parse(e.endDateISO) > Date.now()));
  }

  function render() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar || owner === null) return;
    const live = liveElections();
    const setBadge = (page, text, color, visible) => {
      const link = sidebar.querySelector('a[href$="/' + page + '"], a[href="' + page + '"]');
      if (!link) return;
      let badge = link.querySelector('.nav-badge');
      if (!visible) { badge?.remove(); return; }
      if (!badge) { badge = document.createElement('span'); link.appendChild(badge); }
      badge.className = 'nav-badge ' + color;
      badge.textContent = text;
    };
    setBadge('election.html', 'Live', 'green', live.length > 0);
    setBadge('ballot.html', 'Vote!', 'gold', live.some(e => !e.hasVoted && !recordedVotes.has(String(e.id))));
    clearTimeout(expiryTimer);
    const remaining = live.map(e => Date.parse(e.endDateISO) - Date.now()).filter(ms => ms > 0);
    if (remaining.length) expiryTimer = setTimeout(render, Math.min(2147483647, Math.min(...remaining) + 20));
  }

  function refresh() {
    if (owner === null) return Promise.resolve();
    if (pending) return pending;
    pending = window.CoopVote.api('voter/elections').then(data => {
      elections = Array.isArray(data.elections) ? data.elections : [];
      render();
    }).finally(() => { pending = null; });
    return pending;
  }

  function voted(electionId) {
    recordedVotes.add(String(electionId));
    render();
    refresh().catch(() => {});
  }

  function init(user) {
    if (!user || user.role === 'admin' || owner === user.id) return;
    owner = user.id;
    elections = [];
    recordedVotes.clear();
    if (stopWatching) stopWatching();
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    // A badge may refer to another open election when the current one is complete.
    sidebar.addEventListener('click', event => {
      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      const link = event.target.closest('a[href]');
      if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.getAttribute('aria-disabled') === 'true') return;
      const page = new URL(link.href).pathname.split('/').pop();
      if (page !== 'election.html' && page !== 'ballot.html') return;
      let available = liveElections();
      if (page === 'ballot.html') available = available.filter(e => !e.hasVoted && !recordedVotes.has(String(e.id)));
      const chosen = available.find(e => String(e.id) === window.CoopVote.selectedElection()) || available[0];
      if (chosen) window.CoopVote.setSelectedElection(chosen.id);
    });
    refresh().catch(() => {});
    stopWatching = window.CoopVote.watch(refresh, 30000);
  }

  window.CoopVoteVoterNavigation = { init, refresh, voted };
})();
