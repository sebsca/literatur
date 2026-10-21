(() => {
  const recordBar = document.querySelector('.record-bar');
  if (recordBar) {
    const updateBarHeight = () => {
      document.documentElement.style.setProperty('--record-bar-height', `${Math.ceil(recordBar.getBoundingClientRect().height)}px`);
    };
    updateBarHeight();
    if ('ResizeObserver' in window) new ResizeObserver(updateBarHeight).observe(recordBar);
    else window.addEventListener('resize', updateBarHeight);
  }
  const form = document.querySelector('#article-form');
  if (!form) return;
  let dirty = false;
  let submitting = false;
  const state = document.querySelector('#save-state');
  const markDirty = () => {
    dirty = true;
    state.textContent = 'Ungespeicherte Änderungen';
    state.classList.add('dirty');
  };
  setupAttachmentUpload();
  form.addEventListener('input', (event) => {
    if (event.target.closest('.relation-row') && event.target.type !== 'hidden') {
      event.target.closest('.relation-row').querySelector('[type="hidden"]').value = '';
    }
    if (event.target.form === form) markDirty();
    updateCitation();
  });
  form.addEventListener('change', (event) => {
    if (event.target.form === form) markDirty();
    if (event.target.matches('.relation-row select')) {
      const option = event.target.selectedOptions[0];
      event.target.closest('.relation-row').querySelector('[type="hidden"]').value = option.dataset.id ?? '';
      if (option.dataset.new) {
        const input = document.createElement('input');
        input.name = event.target.name; input.maxLength = 50;
        input.setAttribute('aria-label', event.target.getAttribute('aria-label'));
        input.placeholder = 'Neuen Eintrag eingeben';
        event.target.replaceWith(input); input.focus();
      }
      updateCitation();
    }
  });
  form.addEventListener('submit', () => { submitting = true; });
  document.querySelector('#new-article-form')?.addEventListener('submit', (event) => {
    if (dirty && !window.confirm('Ungespeicherte Änderungen verwerfen und einen neuen Artikel öffnen?')) event.preventDefault();
    else submitting = true;
  });
  document.querySelectorAll('#delete-request-form, #project-delete-request-form').forEach(deleteForm => deleteForm.addEventListener('submit', (event) => {
    if (dirty && !window.confirm('Ungespeicherte Änderungen verwerfen und zur Löschbestätigung wechseln?')) event.preventDefault();
    else submitting = true;
  }));
  window.addEventListener('beforeunload', (event) => {
    if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; }
  });
  const filters = document.querySelector('#filter-form');
  const submitFilter = () => {
    if (dirty && !window.confirm('Ungespeicherte Änderungen verwerfen?')) return;
    submitting = true;
    filters.requestSubmit();
  };
  filters.addEventListener('submit', (event) => {
    if (!submitting && dirty && !window.confirm('Ungespeicherte Änderungen verwerfen?')) event.preventDefault();
    else submitting = true;
  });
  document.querySelector('#article-select').addEventListener('change', submitFilter);
  document.querySelectorAll('.filter-select').forEach(select => select.addEventListener('change', () => {
    document.querySelector('#article-select').value = '';
    submitFilter();
  }));
  document.querySelectorAll('.relation-editor').forEach((editor) => {
    editor.addEventListener('click', (event) => {
      const key = editor.dataset.relation;
      if (event.target.closest('.add-row')) {
        const row = document.createElement('div');
        row.className = 'relation-row';
        const input = editor.querySelector('template').content.firstElementChild.cloneNode(true);
        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'remove-row'; remove.textContent = '×';
        remove.setAttribute('aria-label', 'Eintrag entfernen');
        const originalId = document.createElement('input');
        originalId.type = 'hidden'; originalId.name = `${key}_ids[]`;
        row.append(originalId, input, remove);
        if (key === 'authors') {
          const move = document.createElement('button');
          move.type = 'button'; move.className = 'move-row'; move.textContent = '↑';
          move.setAttribute('aria-label', 'Autor nach oben');
          row.insertBefore(move, remove);
        }
        editor.querySelector('.relation-rows').append(row);
        input.focus(); markDirty();
      }
      if (event.target.closest('.remove-row')) {
        event.target.closest('.relation-row').remove(); markDirty(); updateCitation();
      }
      if (event.target.closest('.move-row')) {
        const row = event.target.closest('.relation-row');
        if (row.previousElementSibling) { row.previousElementSibling.before(row); markDirty(); updateCitation(); }
      }
    });
  });
  document.querySelector('#journal-select').addEventListener('change', (event) => {
    const data = event.target.selectedOptions[0].dataset;
    const isNew = event.target.value === 'new';
    document.querySelector('#new-journal-title').hidden = !isNew;
    document.querySelector('#new-journal-title input').required = isNew;
    document.querySelector('#journal-version').value = data.version || '';
    for (const key of ['issn', 'impact', 'publisher', 'url']) {
      document.querySelector(`#journal-${key}`).value = data[key] || '';
      document.querySelector(`#journal-${key}`).disabled = event.target.value === '';
    }
    updateJournalLink();
    updateCitation();
  });
  function updateCitation() {
    const title = form.elements.Titel.value.trim();
    const authors = Array.from(form.querySelectorAll('[name="authors[]"]')).map(input => input.value.trim()).filter(Boolean);
    const year = form.elements.Jahrgang.value;
    const band = form.elements.Band.value;
    const from = form.elements.SeiteVon.value;
    const to = form.elements.SeiteBis.value;
    const journal = document.querySelector('#journal-select').value === 'new' ? document.querySelector('#new-journal-title input').value : document.querySelector('#journal-select').selectedOptions[0].dataset.title || '';
    document.querySelector('#citation').value = `${authors.length ? authors.join(', ') + ' ' : ''}${year ? '(' + year + '): ' : ''}${title}. ${journal}${band ? ' ' + band : ''}${from ? ', ' + from : ''}${to ? '–' + to : ''}.`;
  }
  document.querySelector('#copy-citation').addEventListener('click', async (event) => {
    const citation = document.querySelector('#citation');
    try { await navigator.clipboard.writeText(citation.value); event.target.textContent = 'Kopiert'; }
    catch { citation.focus(); citation.select(); event.target.textContent = 'Text ausgewählt'; }
    window.setTimeout(() => { event.target.textContent = 'Kopieren'; }, 2000);
  });
  if (new URLSearchParams(window.location.search).has('new')) state.textContent = 'Neuer Artikel';
  if (form.dataset.unsaved === 'true') markDirty();
  function updateDoiLink() {
    const link = document.querySelector('#doi-open');
    let doi = document.querySelector('#doi-input').value.trim().replace(/^doi:\s*/i, '');
    const prefix = doi.match(/^https?:\/\/(?:dx\.)?doi\.org\//i);
    if (prefix) {
      try { doi = decodeURIComponent(doi.slice(prefix[0].length)); }
      catch { doi = ''; }
    }
    link.hidden = !/^10\.\d+(?:\.\d+)*\/[^\s\u0000-\u001f\u007f]+$/u.test(doi);
    if (link.hidden) { link.removeAttribute('href'); return; }
    const encoded = encodeURIComponent(doi)
      .replace(/[!'()*]/g, c => '%' + c.charCodeAt(0).toString(16).toUpperCase())
      .replace(/%2F/g, '/');
    link.href = 'https://doi.org/' + encoded;
  }
  document.querySelector('#doi-input').addEventListener('input', updateDoiLink);
  updateDoiLink();
  function updateJournalLink() {
    const link = document.querySelector('#journal-open');
    const value = document.querySelector('#journal-url').value;
    try {
      const url = new URL(value);
      link.hidden = !['http:', 'https:'].includes(url.protocol);
      if (!link.hidden) link.href = url.href;
    } catch { link.hidden = true; }
  }
  document.querySelector('#journal-url').addEventListener('input', updateJournalLink);

  function setupAttachmentUpload() {
    const input = document.querySelector('#attachment-input');
    const zone = document.querySelector('#attachment-dropzone');
    if (!input || input.matches(':disabled')) return;
    const maxFiles = Number(input.dataset.maxFiles);
    const maxBytes = Number(input.dataset.maxBytes);
    // Die normale Dateiauswahl bleibt auch ohne FileList-Unterstützung nutzbar.
    try { input.files = new DataTransfer().files; }
    catch {
      input.addEventListener('change', () => {
        const tooLarge = Array.from(input.files).some(file => file.size > maxBytes);
        input.setCustomValidity(input.files.length > maxFiles ? `Bitte höchstens ${maxFiles} Dateien auf einmal hinzufügen.` : tooLarge ? 'Ein Anhang darf höchstens 10 MB groß sein.' : '');
        input.reportValidity();
      });
      return;
    }
    zone.querySelector('.drop-hint').hidden = false;
    const feedback = document.querySelector('#attachment-feedback');
    const pending = document.querySelector('#pending-attachments');
    const list = pending.querySelector('ul');
    let files = [];
    let dragDepth = 0;
    const hasFiles = event => Array.from(event.dataTransfer?.types || []).includes('Files');
    const message = text => { feedback.textContent = text; feedback.hidden = !text; };
    const sync = () => {
      const transfer = new DataTransfer();
      files.forEach(file => transfer.items.add(file));
      input.files = transfer.files;
      list.replaceChildren();
      pending.hidden = files.length === 0;
      pending.querySelector('[role="status"]').textContent = `${files.length} neue ${files.length === 1 ? 'Datei' : 'Dateien'} · beim Speichern hinzufügen`;
      files.forEach((file, index) => {
        const row = document.createElement('li');
        const name = document.createElement('span');
        name.textContent = `${file.name} (${file.size < 1024 ? file.size + ' B' : Math.ceil(file.size / 1024).toLocaleString('de-DE') + ' KB'})`;
        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'remove-row'; remove.textContent = '×';
        remove.setAttribute('aria-label', `${file.name} aus der Auswahl entfernen`);
        remove.addEventListener('click', () => {
          files.splice(index, 1); message(''); sync(); markDirty();
          (list.children[Math.min(index, files.length - 1)]?.querySelector('button') || input).focus();
        });
        row.append(name, remove); list.append(row);
      });
    };
    const add = incoming => {
      const added = Array.from(incoming);
      if (!added.length) { sync(); return; }
      let error = '';
      if (files.length + added.length > maxFiles) error = `Bitte höchstens ${maxFiles} Dateien auf einmal hinzufügen.`;
      else if (added.some(file => file.size > maxBytes)) error = 'Ein Anhang darf höchstens 10 MB groß sein.';
      else if (added.some(file => file.size === 0)) error = 'Leere Dateien können nicht hinzugefügt werden.';
      else if (added.some(file => !/\.(pdf|jpe?g|png|gif|webp|tiff?)$/i.test(file.name))) error = 'Bitte PDF, JPG, PNG, GIF, WebP oder TIFF auswählen.';
      message(error);
      if (!error) { files.push(...added); markDirty(); }
      sync();
    };
    input.addEventListener('change', () => add(input.files));
    zone.addEventListener('dragenter', event => {
      if (!hasFiles(event)) return;
      event.preventDefault(); dragDepth++; zone.classList.add('drag-over');
    });
    zone.addEventListener('dragover', event => {
      if (!hasFiles(event)) return;
      event.preventDefault(); event.dataTransfer.dropEffect = 'copy';
    });
    zone.addEventListener('dragleave', event => {
      if (!hasFiles(event)) return;
      dragDepth = Math.max(0, dragDepth - 1);
      if (!dragDepth) zone.classList.remove('drag-over');
    });
    zone.addEventListener('drop', event => {
      if (!hasFiles(event)) return;
      event.preventDefault(); dragDepth = 0; zone.classList.remove('drag-over');
      add(event.dataTransfer.files);
    });
    // Außerhalb der Fläche abgelegte Dateien dürfen die Formularseite nicht ersetzen.
    for (const type of ['dragover', 'drop']) document.addEventListener(type, event => {
      if (hasFiles(event)) event.preventDefault();
    });
  }
})();
