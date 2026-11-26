<?php
declare(strict_types=1);
// Diálogo «Instalar la app». pwa.js rellena los pasos según el teléfono y el navegador.
?>
<dialog id="installHelp" class="dialog">
  <div class="dialog-body">
    <h3 id="installTitle">Instalar la app</h3>
    <ol class="install-steps" id="installSteps"></ol>
    <p class="install-note" id="installNote">Se abrirá a pantalla completa, con su propio icono, sin depender del navegador.</p>
    <div class="dialog-actions">
      <button class="btn" type="button" id="installCopyLink" hidden>Copiar enlace</button>
      <span class="spacer"></span>
      <button class="btn btn-primary" type="button" data-close-install>Entendido</button>
    </div>
  </div>
</dialog>
