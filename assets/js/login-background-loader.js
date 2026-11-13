(function () {
  'use strict';
  const base = new URL('.', document.currentScript.src);
  let started = false;
  // Decorations must never delay the form, session notice, or first paint.
  function load() {
    if (started || document.hidden || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    started = true;
    const library = document.createElement('script');
    library.src = new URL('vendor/three.min.js', base).href;
    library.async = true;
    library.onload = () => {
      const scene = document.createElement('script');
      scene.src = new URL('login-background.js?v=20260924', base).href;
      scene.async = true;
      document.body.appendChild(scene);
    };
    document.body.appendChild(library);
  }
  // Opening the login in a background tab should not permanently skip the scene.
  document.addEventListener('visibilitychange', load);
  requestAnimationFrame(() => requestAnimationFrame(() => {
    if (window.requestIdleCallback) window.requestIdleCallback(load, { timeout: 1500 });
    else setTimeout(load, 0);
  }));
})();
