/* Fit dialogs above mobile keyboards; CSS dvh is the fallback. Preserve pinch zoom. */
(function (window, document) {
  'use strict';
  const root = document.documentElement;
  const viewport = window.visualViewport;
  if (!viewport) return;
  let frame = null;
  function update() {
    frame = null;
    if (Math.abs(viewport.scale - 1) > 0.05) {
      root.style.removeProperty('--cv-visible-height');
      root.style.removeProperty('--cv-visible-top');
      root.removeAttribute('data-cv-compact-viewport');
      return;
    }
    const height = Math.round(viewport.height);
    if (!Number.isFinite(height) || height <= 0) return;
    root.style.setProperty('--cv-visible-height', height + 'px');
    root.style.setProperty('--cv-visible-top', Math.max(0, Math.round(viewport.offsetTop)) + 'px');
    root.toggleAttribute('data-cv-compact-viewport', height <= 400);
  }
  function schedule() {
    if (frame === null) frame = window.requestAnimationFrame(update);
  }
  viewport.addEventListener('resize', schedule, { passive: true });
  viewport.addEventListener('scroll', schedule, { passive: true });
  window.addEventListener('resize', schedule, { passive: true });
  window.addEventListener('pageshow', schedule);
  update();
})(window, document);
