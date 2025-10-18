/* global OC */

document.addEventListener('DOMContentLoaded', async () => {
  const container = document.getElementById('app-content');
  const baseUrl = OC.generateUrl('/apps/figdeckbridge/api');
  const requestToken = OC.requestToken;

  const state = {
    boards: [],
    figmaProjects: [],
    mappings: [],
    stacksCache: new Map(),
  };

  const statusBar = document.createElement('div');
  statusBar.className = 'mapping-status';

  const rowsWrapper = document.createElement('div');
  rowsWrapper.className = 'mapping-rows';

  const actionsBar = document.createElement('div');
  actionsBar.className = 'mapping-actions-bar';

  const addButton = document.createElement('button');
  addButton.className = 'secondary';
  addButton.type = 'button';
  addButton.textContent = '➕ Zuordnung hinzufügen';

  const saveButton = document.createElement('button');
  saveButton.className = 'primary';
  saveButton.type = 'button';
  saveButton.textContent = '💾 Zuordnungen speichern';

  const helperText = document.createElement('p');
  helperText.className = 'mapping-helper';
  helperText.innerHTML = 'Wähle links ein Deck-Board (und eine Liste) und ordne es rechts einer Figma-Datei aus einem Projekt zu.';

  container.innerHTML = '';
  container.appendChild(helperText);
  container.appendChild(statusBar);
  container.appendChild(rowsWrapper);
  actionsBar.appendChild(addButton);
  actionsBar.appendChild(saveButton);
  container.appendChild(actionsBar);

  function setStatus(message, type = 'info') {
    statusBar.textContent = message;
    statusBar.dataset.state = type;
  }

  async function ncFetch(url, options = {}) {
    const opts = { ...options };
    opts.headers = {
      'requesttoken': requestToken,
      ...(options.headers || {}),
    };
    return fetch(url, opts);
  }

  function renderFigmaOptions(select, selectedKey) {
    select.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Figma-Datei auswählen …';
    select.appendChild(placeholder);

    let hasOptions = false;
    state.figmaProjects.forEach(project => {
      if (!Array.isArray(project.files) || project.files.length === 0) {
        return;
      }

      const group = document.createElement('optgroup');
      const teamName = project.teamName ? project.teamName : 'Figma';
      group.label = `${teamName} › ${project.name}`;

      project.files.forEach(file => {
        if (!file.key) {
          return;
        }
        const option = document.createElement('option');
        option.value = file.key;
        option.textContent = file.name || file.key;
        if (file.key === selectedKey) {
          option.selected = true;
        }
        group.appendChild(option);
        hasOptions = true;
      });

      if (group.children.length) {
        select.appendChild(group);
      }
    });

    select.disabled = !hasOptions;
  }

  async function ensureStacks(boardId) {
    if (!boardId) {
      return [];
    }

    if (state.stacksCache.has(boardId)) {
      return state.stacksCache.get(boardId);
    }

    const res = await fetch(`${baseUrl}/deck/stacks/${boardId}`);
    if (!res.ok) {
      throw new Error(`Stacks konnten nicht geladen werden (HTTP ${res.status})`);
    }
    const data = await res.json();
    const stacks = Array.isArray(data.stacks) ? data.stacks : [];
    state.stacksCache.set(boardId, stacks);
    return stacks;
  }

  async function updateStackSelect(select, mapping) {
    const boardId = mapping.board_id ? Number(mapping.board_id) : 0;
    select.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = boardId ? '⏳ Lade Listen …' : 'Board wählen …';
    select.appendChild(placeholder);

    if (!boardId) {
      select.disabled = true;
      mapping.stack_id = '';
      return;
    }

    select.disabled = true;

    try {
      const stacks = await ensureStacks(boardId);
      select.innerHTML = '';

      const prompt = document.createElement('option');
      prompt.value = '';
      prompt.textContent = 'Deck-Liste auswählen …';
      select.appendChild(prompt);

      stacks.forEach(stack => {
        const opt = document.createElement('option');
        opt.value = stack.id;
        opt.textContent = stack.title || `Liste ${stack.id}`;
        if (Number(mapping.stack_id) === Number(stack.id)) {
          opt.selected = true;
        }
        select.appendChild(opt);
      });

      if (!select.value && stacks.length > 0) {
        select.value = stacks[0].id;
        mapping.stack_id = stacks[0].id;
      }

      select.disabled = stacks.length === 0;
    } catch (error) {
      select.innerHTML = '';
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = '⚠️ Stacks konnten nicht geladen werden';
      select.appendChild(opt);
      select.disabled = true;
      setStatus(error.message, 'error');
    }
  }

  function renderRows() {
    rowsWrapper.innerHTML = '';

    if (state.mappings.length === 0) {
      state.mappings.push({ board_id: '', stack_id: '', file_key: '' });
    }

    state.mappings.forEach((mapping, index) => {
      const row = document.createElement('div');
      row.className = 'mapping-row';

      const boardField = document.createElement('div');
      boardField.className = 'mapping-field';
      const boardLabel = document.createElement('label');
      boardLabel.textContent = 'Deck-Board';
      const boardSelect = document.createElement('select');
      const boardPlaceholder = document.createElement('option');
      boardPlaceholder.value = '';
      boardPlaceholder.textContent = 'Board auswählen …';
      boardSelect.appendChild(boardPlaceholder);

      state.boards.forEach(board => {
        const option = document.createElement('option');
        option.value = board.id;
        option.textContent = board.title || `Board ${board.id}`;
        if (Number(mapping.board_id) === Number(board.id)) {
          option.selected = true;
        }
        boardSelect.appendChild(option);
      });

      boardSelect.addEventListener('change', async () => {
        mapping.board_id = boardSelect.value;
        mapping.stack_id = '';
        await updateStackSelect(stackSelect, mapping);
      });

      boardField.appendChild(boardLabel);
      boardField.appendChild(boardSelect);

      const stackField = document.createElement('div');
      stackField.className = 'mapping-field';
      const stackLabel = document.createElement('label');
      stackLabel.textContent = 'Deck-Liste';
      const stackSelect = document.createElement('select');
      stackSelect.disabled = true;
      stackSelect.addEventListener('change', () => {
        mapping.stack_id = stackSelect.value;
      });
      stackField.appendChild(stackLabel);
      stackField.appendChild(stackSelect);

      const figmaField = document.createElement('div');
      figmaField.className = 'mapping-field';
      const figmaLabel = document.createElement('label');
      figmaLabel.textContent = 'Figma-Projekt & Datei';
      const figmaSelect = document.createElement('select');
      renderFigmaOptions(figmaSelect, mapping.file_key);
      figmaSelect.addEventListener('change', () => {
        mapping.file_key = figmaSelect.value;
      });
      figmaField.appendChild(figmaLabel);
      figmaField.appendChild(figmaSelect);

      const removeWrapper = document.createElement('div');
      removeWrapper.className = 'mapping-remove';
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'danger';
      removeBtn.textContent = '🗑️';
      removeBtn.title = 'Zuordnung entfernen';
      removeBtn.addEventListener('click', () => {
        state.mappings.splice(index, 1);
        renderRows();
      });
      removeWrapper.appendChild(removeBtn);

      row.appendChild(boardField);
      row.appendChild(stackField);
      row.appendChild(figmaField);
      row.appendChild(removeWrapper);

      rowsWrapper.appendChild(row);

      updateStackSelect(stackSelect, mapping);
    });
  }

  addButton.addEventListener('click', () => {
    state.mappings.push({ board_id: '', stack_id: '', file_key: '' });
    renderRows();
  });

  saveButton.addEventListener('click', async () => {
    const valid = state.mappings.filter(item => item.board_id && item.stack_id && item.file_key);

    if (valid.length === 0) {
      setStatus('Bitte mindestens eine vollständige Zuordnung anlegen.', 'error');
      return;
    }

    saveButton.disabled = true;
    setStatus('💾 Speichere Zuordnungen …', 'info');

    try {
      const res = await ncFetch(`${baseUrl}/mapping`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ mappings: valid }),
      });

      const data = await res.json();
      if (!res.ok || data.ok === false) {
        throw new Error(data.error || `Speichern fehlgeschlagen (HTTP ${res.status})`);
      }

      state.mappings = Array.isArray(data.mappings) ? data.mappings : [];
      renderRows();
      setStatus('✅ Zuordnungen gespeichert.', 'success');
    } catch (error) {
      setStatus(`❌ ${error.message}`, 'error');
    } finally {
      saveButton.disabled = false;
    }
  });

  setStatus('🔄 Lade Daten …', 'info');

  try {
    const [deckRes, figmaRes, mappingRes] = await Promise.all([
      fetch(`${baseUrl}/deck/boards`),
      fetch(`${baseUrl}/figma/files`),
      fetch(`${baseUrl}/mapping`),
    ]);

    const deckData = deckRes.ok ? await deckRes.json() : { ok: false, error: `Deck-API: HTTP ${deckRes.status}` };
    const figmaData = figmaRes.ok ? await figmaRes.json() : { ok: false, error: `Figma-API: HTTP ${figmaRes.status}` };
    const mappingData = mappingRes.ok ? await mappingRes.json() : { ok: false, error: `Mapping: HTTP ${mappingRes.status}` };
    let hadError = false;

    if (deckData.ok === false) {
      setStatus(`⚠️ ${deckData.error || 'Deck-Boards konnten nicht geladen werden.'}`, 'error');
      hadError = true;
    } else {
      state.boards = Array.isArray(deckData.boards) ? deckData.boards : [];
    }

    if (figmaData.ok === false) {
      setStatus(`⚠️ ${figmaData.error || 'Figma-Daten konnten nicht geladen werden.'}`, 'error');
      hadError = true;
    } else {
      state.figmaProjects = Array.isArray(figmaData.projects) ? figmaData.projects : [];
    }

    if (mappingData.ok === false) {
      setStatus(`⚠️ ${mappingData.error || 'Mappings konnten nicht geladen werden.'}`, 'error');
      hadError = true;
    } else {
      state.mappings = Array.isArray(mappingData.mappings) ? mappingData.mappings : [];
    }

    renderRows();

    if (hadError) {
      return;
    }

    if (state.figmaProjects.length === 0) {
      setStatus('ℹ️ Bitte verbinde die App mit Figma, um Projekte und Dateien auszuwählen.', 'info');
    } else {
      setStatus('✅ Daten geladen. Du kannst nun Zuordnungen anpassen.', 'success');
    }
  } catch (error) {
    setStatus(`❌ ${error.message}`, 'error');
  }
});
