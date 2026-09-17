/* Background refresh shared by administrator pages. */
(() => {
  'use strict';
  const cv = window.CoopVote;

  /** Keep existing data on errors and avoid resetting forms, scrolling or pagination. */
  cv.startLive = function (route, apply) {
    const status = document.querySelector('.header-title p') || document.createElement('small');
    status.className = 'cv-live-status';
    status.textContent = 'Loading updates…';
    if (!status.isConnected) document.querySelector('.header-title').append(status);
    let previous = '';
    async function refresh() {
      const requested = typeof route === 'function' ? route() : route;
      const data = await cv.api(requested);
      if (typeof route === 'function' && requested !== route()) return;
      if (document.activeElement?.matches('input,textarea,select,[contenteditable="true"]') || document.querySelector('.modal-backdrop.open,.modal-backdrop.show')) return;
      const content = document.querySelector('.content'), top = content.scrollTop;
      const serialized = JSON.stringify(data);
      if (serialized !== previous) {
        cv.keepPagination = true;
        try { apply(data); } finally { cv.keepPagination = false; }
        previous = serialized;
      }
      content.scrollTop = top;

    }
    refresh().then(() => { status.textContent = 'Connected · refreshes every 10 seconds'; })
      .catch(error => { status.textContent = error.message + ' · retrying'; })
      .finally(() => cv.watch(refresh, 10000, status));
  };
})();
