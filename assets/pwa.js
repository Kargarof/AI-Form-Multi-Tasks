/* AI Form Multi Tasks · instalación como app y modo sin conexión */
(() => {
  'use strict';

  const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const ua = navigator.userAgent;
  const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  // ---------- Service worker ----------
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => { /* sin SW la app sigue funcionando */ });
    });
  }

  // Al cerrar sesión, borra la copia de las tareas guardada en este dispositivo.
  document.addEventListener('submit', (e) => {
    if (!e.target.matches('form.logout')) return;
    navigator.serviceWorker?.controller?.postMessage('clear-data');
    if ('caches' in window) caches.delete('aiform-mt-data').catch(() => {});
  }, true);

  // ---------- Botón «Instalar» ----------
  let deferredPrompt = null;

  function setupInstallUI() {
    const btn = document.getElementById('installBtn');
    const help = document.getElementById('installHelp');
    if (!btn) return;
    if (isStandalone()) { btn.hidden = true; return; }

    // iPhone/iPad: Safari no tiene aviso automático, se explica cómo hacerlo.
    if (isIOS && help) btn.hidden = false;

    btn.addEventListener('click', async () => {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        deferredPrompt = null;
        if (outcome === 'accepted') btn.hidden = true;
      } else if (help) {
        help.showModal();
      }
    });
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    const btn = document.getElementById('installBtn');
    if (btn && !isStandalone()) btn.hidden = false;
  });

  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    const btn = document.getElementById('installBtn');
    if (btn) btn.hidden = true;
  });

  // ---------- Aviso de sin conexión ----------
  function setupOfflineBar() {
    const bar = document.getElementById('offlineBar');
    if (!bar) return;
    const update = () => { bar.hidden = navigator.onLine && !document.body.classList.contains('offline-copy'); };
    window.addEventListener('online', () => { document.body.classList.remove('offline-copy'); update(); });
    window.addEventListener('offline', update);
    window.addEventListener('aiform:offline-copy', update);
    update();
  }

  if (isStandalone()) document.documentElement.classList.add('standalone');

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { setupInstallUI(); setupOfflineBar(); });
  } else {
    setupInstallUI(); setupOfflineBar();
  }
})();
