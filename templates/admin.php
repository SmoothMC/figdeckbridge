<div class="section">

  <!-- 🔐 Figma-Verbindung -->
  <h2>🔐 Figma-Verbindung</h2>
  <form id="figma-connect-form" autocomplete="off">
    <label for="client_id">Figma Client ID</label>
    <input type="text" id="client_id" name="client_id"
      placeholder="z. B. 1234abc..."
      autocomplete="off"
      autocapitalize="none"
      spellcheck="false">

    <label for="client_secret">Figma Client Secret</label>
    <input type="password" id="client_secret" name="client_secret"
      placeholder="••••••••"
      autocomplete="new-password"
      autocapitalize="none"
      spellcheck="false">

    <div class="btn-group">
      <button id="saveFigmaSettings" class="primary">💾 Speichern</button>
      <button id="connectFigma" class="primary">🔐 Mit Figma verbinden</button>
      <button id="disconnectFigma" class="danger">❌ Trennen</button>
    </div>

    <div id="figma-status"></div>
  </form>

  <hr>

  <!-- 🔁 Synchronisation -->
  <h2>🔁 Synchronisation</h2>
  <form id="sync-settings">
    <label for="mode">Modus auswählen</label>
    <select id="mode" name="mode">
      <option value="manual">Manuell (nur auf Knopfdruck)</option>
      <option value="cron">Automatisch per Cron</option>
    </select>

    <div class="btn-group">
      <button id="saveMode" class="primary">💾 Modus speichern</button>
      <button id="manualSync" class="primary">📦 Manuelle Synchronisation</button>
    </div>
  </form>

</div>

<?php
script('figdeckbridge', 'admin-settings');
?>
