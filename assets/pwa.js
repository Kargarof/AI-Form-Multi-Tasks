/* AI Form Multi Tasks · instalación como app */
(() => {
  'use strict';

  const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const ua = navigator.userAgent;
  const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isAndroid = /Android/i.test(ua);
  const inApp = /Instagram|FBAN|FBAV|FB_IAB|WhatsApp|Line\/|Telegram|TikTok|Snapchat|LinkedInApp/i.test(ua);

  // ---------- Service worker ----------
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => { /* sin SW la app sigue funcionando */ });
    });
  }

  // ---------- Pasos para instalar según el dispositivo ----------
  function installGuide() {
    const b = (s) => `<b>${s}</b>`;
    if (inApp) {
      return {
        title: 'Abre la app en tu navegador',
        steps: isIOS
          ? [`Estás dentro de otra app (Instagram, WhatsApp…). Pulsa ${b('···')} o el icono de compartir y elige ${b('Abrir en Safari')}.`,
             `En Safari, pulsa ${b('Compartir')} y luego ${b('Añadir a pantalla de inicio')}.`]
          : [`Estás dentro de otra app (Instagram, WhatsApp…). Pulsa ${b('⋮')} y elige ${b('Abrir en Chrome')} (o en tu navegador).`,
             `Allí pulsa ${b('Instalar')}.`],
        copy: true,
      };
    }
    if (isIOS) {
      const safari = !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
      return {
        title: 'Instalar en iPhone o iPad',
        steps: [
          safari
            ? `Pulsa el botón ${b('Compartir')} (el cuadrado con la flecha hacia arriba), abajo en Safari.`
            : `Pulsa el botón ${b('Compartir')} de tu navegador (en Chrome está junto a la barra de direcciones). Si no aparece la opción del paso siguiente, abre esta página en ${b('Safari')}.`,
          `Desliza y elige ${b('Añadir a pantalla de inicio')}.`,
          `Pulsa ${b('Añadir')}. Después abre la app desde su icono y entra desde ahí.`,
        ],
        copy: !safari,
      };
    }
    if (isAndroid) {
      if (/SamsungBrowser/i.test(ua)) {
        return { title: 'Instalar en Android', steps: [`Pulsa el menú ${b('≡')} abajo a la derecha.`, `Elige ${b('Añadir página a')} → ${b('Pantalla de inicio')} (o ${b('Instalar')}).`] };
      }
      if (/Firefox/i.test(ua)) {
        return { title: 'Instalar en Android', steps: [`Pulsa el menú ${b('⋮')}.`, `Elige ${b('Instalar')} o ${b('Añadir a pantalla de inicio')}.`] };
      }
      return { title: 'Instalar en Android', steps: [`Pulsa el menú ${b('⋮')} arriba a la derecha.`, `Elige ${b('Instalar aplicación')} o ${b('Añadir a pantalla de inicio')}.`, `Confirma con ${b('Instalar')}.`] };
    }
    if (/Firefox/i.test(ua)) {
      return { title: 'Instalar en el ordenador', steps: [`Firefox no permite instalar apps web en el ordenador.`, `Abre esta página en ${b('Chrome')} o ${b('Edge')} y pulsa ${b('Instalar')}.`], copy: true };
    }
    if (/Safari/i.test(ua) && !/Chrome|Chromium|Edg/i.test(ua)) {
      return { title: 'Instalar en el Mac', steps: [`En la barra de menús, abre ${b('Archivo')}.`, `Elige ${b('Añadir al Dock')}.`] };
    }
    return { title: 'Instalar en el ordenador', steps: [`Pulsa el icono de instalar (una pantalla con una flecha) a la derecha de la barra de direcciones.`, `O abre el menú ${b('⋮')} → ${b('Enviar, guardar y compartir')} → ${b('Instalar')}.`] };
  }

  // ---------- Botones «Instalar» ----------
  let deferredPrompt = null;
  const buttons = () => [...document.querySelectorAll('.js-install')];
  const setVisible = (v) => buttons().forEach((btn) => { btn.hidden = !v; });

  function openGuide() {
    const dlg = document.getElementById('installHelp');
    if (!dlg) return;
    const g = installGuide();
    document.getElementById('installTitle').textContent = g.title;
    document.getElementById('installSteps').innerHTML = g.steps.map((s) => `<li>${s}</li>`).join('');
    const copy = document.getElementById('installCopyLink');
    copy.hidden = !g.copy;
    dlg.showModal();
  }

  function setupInstallUI() {
    if (isStandalone()) { setVisible(false); return; }
    setVisible(true); // siempre visible mientras no esté instalada
    buttons().forEach((btn) => btn.addEventListener('click', async () => {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        deferredPrompt = null;
        if (outcome === 'accepted') setVisible(false);
      } else {
        openGuide();
      }
    }));
    const dlg = document.getElementById('installHelp');
    if (dlg) {
      dlg.querySelector('[data-close-install]').addEventListener('click', () => dlg.close());
      dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
      document.getElementById('installCopyLink').addEventListener('click', async (e) => {
        const url = new URL('./', location.href).href;
        try { await navigator.clipboard.writeText(url); e.target.textContent = 'Enlace copiado'; } catch { prompt('Copia este enlace:', url); }
      });
    }
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (!isStandalone()) setVisible(true);
  });
  window.addEventListener('appinstalled', () => { deferredPrompt = null; setVisible(false); });

  if (isStandalone()) document.documentElement.classList.add('standalone');

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupInstallUI);
  else setupInstallUI();

  window.AIFormInstall = { openGuide, isIOS, isStandalone };
})();
