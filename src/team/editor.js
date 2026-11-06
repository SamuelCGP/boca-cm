////////////////////////////////////////////////////////////////////////////////
// BOCA-CM: in-browser code editor for the team's Runs page.
//
//    This program is free software: you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation, either version 3 of the License, or
//    (at your option) any later version.
//
// Expects window.BOCACM_CONFIG = { storageKey, problems: [{number, name,
// basefilename}], languages: [{number, name, extension}] } and the markup
// produced by team/run.php. Uses CodeMirror 5 (../codemirror).
////////////////////////////////////////////////////////////////////////////////
(function () {
  'use strict';

  var cfg = window.BOCACM_CONFIG;
  if (!cfg || typeof CodeMirror === 'undefined') return;

  var STORAGE_KEY = 'bocacm:' + cfg.storageKey;
  var LOCK_KEY = STORAGE_KEY + ':lock';
  var BUSY_MS = 20000;      // typing within this window postpones the auto-reload
  var LOCK_BEAT_MS = 4000;  // heartbeat of the "editor open in this tab" lock
  var LOCK_STALE_MS = 12000;

  var state = { tabs: [], active: null, seq: 0 };
  var editor = null;
  var lastEdit = 0;
  var loading = false;      // true while we swap documents programmatically
  var storageOk = true;
  var el = {};

  // ---------------------------------------------------------------- helpers
  function $(id) { return document.getElementById(id); }

  function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch (e) { storageOk = false; return null; }
  }
  function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); return true; } catch (e) { storageOk = false; return false; }
  }
  function storageRemove(key) {
    try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
  }

  function findBy(list, field, value) {
    for (var i = 0; i < list.length; i++) if (String(list[i][field]) === String(value)) return list[i];
    return null;
  }
  function problemByNumber(n) { return findBy(cfg.problems, 'number', n); }
  function languageByNumber(n) { return findBy(cfg.languages, 'number', n); }

  // Map a BOCA language (by extension, then by name) to a CodeMirror mode.
  function modeFor(lang) {
    if (!lang) return null;
    var ext = String(lang.extension || '').toLowerCase().replace(/^\./, '');
    var name = String(lang.name || '').toLowerCase();
    var byExt = {
      'c': 'text/x-csrc', 'h': 'text/x-csrc',
      'cpp': 'text/x-c++src', 'cc': 'text/x-c++src', 'cxx': 'text/x-c++src', 'c++': 'text/x-c++src',
      'java': 'text/x-java', 'kt': 'text/x-kotlin', 'kts': 'text/x-kotlin',
      'cs': 'text/x-csharp', 'scala': 'text/x-scala',
      'py': { name: 'python', version: 3 }, 'py3': { name: 'python', version: 3 },
      'py2': { name: 'python', version: 2 }
    };
    if (byExt[ext]) return byExt[ext];
    if (/c\+\+|cpp/.test(name)) return 'text/x-c++src';
    if (/kotlin/.test(name)) return 'text/x-kotlin';
    if (/java/.test(name)) return 'text/x-java';
    if (/python\s*2|py2/.test(name)) return { name: 'python', version: 2 };
    if (/python|py/.test(name)) return { name: 'python', version: 3 };
    if (/^c$|^c\s|\bc\b/.test(name)) return 'text/x-csrc';
    return null;
  }

  function extensionOf(lang) {
    return lang ? String(lang.extension || '').replace(/^\./, '') : '';
  }

  // Suggested file name: last name used for the same problem/language, the
  // public class name for Java, or the problem's base file name + extension.
  function suggestFilename(tab) {
    if (tab.filename && tab.filenameFor === tab.problem + '/' + tab.language) return tab.filename;
    var lang = languageByNumber(tab.language);
    var ext = extensionOf(lang);
    var base = '';
    if (lang && /java/i.test(lang.name + ' ' + ext) && !/kotlin|kt/i.test(lang.name + ' ' + ext)) {
      var m = /public\s+(?:final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/.exec(tab.code);
      if (m) base = m[1];
    }
    if (!base) {
      var prob = problemByNumber(tab.problem);
      base = prob ? (prob.basefilename || prob.name) : 'code';
    }
    return ext ? base + '.' + ext : base;
  }

  // Same restrictions the server applies to run file names (kept conservative).
  function filenameError(name) {
    if (name.length < 1) return 'The file name cannot be empty.';
    if (name.length > 100) return 'The file name must have at most 100 characters.';
    if (!/^[A-Za-z0-9_.+\-]+$/.test(name))
      return 'Use only letters, digits, "_", "-", "+" and "." (no spaces).';
    return null;
  }

  function tabTitle(tab) {
    if (tab.filename) return tab.filename;
    var prob = problemByNumber(tab.problem);
    var lang = languageByNumber(tab.language);
    if (prob || lang) return (prob ? prob.name : '?') + (lang ? ' (' + lang.name + ')' : '');
    return 'Untitled ' + tab.id;
  }

  // -------------------------------------------------------------- state I/O
  function newTab(fields) {
    state.seq += 1;
    var tab = { id: state.seq, code: '', problem: '-1', language: '-1', filename: '', filenameFor: '' };
    for (var k in fields) if (fields.hasOwnProperty(k)) tab[k] = fields[k];
    state.tabs.push(tab);
    return tab;
  }

  function activeTab() { return findBy(state.tabs, 'id', state.active); }

  function save() {
    var tab = activeTab();
    if (tab && editor) tab.code = editor.getValue();
    storageSet(STORAGE_KEY, JSON.stringify(state));
  }

  function load() {
    var raw = storageGet(STORAGE_KEY);
    if (raw) {
      try {
        var s = JSON.parse(raw);
        if (s && s.tabs && s.tabs.length) state = s;
      } catch (e) { /* corrupted: start fresh */ }
    }
    if (!state.tabs.length) state.active = newTab({}).id;
    if (!activeTab()) state.active = state.tabs[0].id;
  }

  // -------------------------------------------------------------- rendering
  function fillSelect(select, list, labelField, current) {
    select.innerHTML = '';
    var none = document.createElement('option');
    none.value = '-1';
    none.textContent = ' -- ';
    select.appendChild(none);
    for (var i = 0; i < list.length; i++) {
      var o = document.createElement('option');
      o.value = String(list[i].number);
      o.textContent = list[i][labelField];
      select.appendChild(o);
    }
    select.value = findBy(list, 'number', current) ? String(current) : '-1';
  }

  function renderTabs() {
    el.tabs.innerHTML = '';
    for (var i = 0; i < state.tabs.length; i++) {
      (function (tab) {
        var b = document.createElement('span');
        b.className = 'bocacm-tab' + (tab.id === state.active ? ' bocacm-tab-active' : '');
        b.title = tabTitle(tab);
        var label = document.createElement('span');
        label.textContent = tabTitle(tab);
        b.appendChild(label);
        b.onclick = function () { switchTo(tab.id); };
        var x = document.createElement('span');
        x.className = 'bocacm-tab-close';
        x.textContent = '\u00d7';
        x.title = 'Close tab';
        x.onclick = function (ev) { ev.stopPropagation(); closeTab(tab.id); };
        b.appendChild(x);
        el.tabs.appendChild(b);
      })(state.tabs[i]);
    }
    var add = document.createElement('span');
    add.className = 'bocacm-tab bocacm-tab-new';
    add.textContent = '+';
    add.title = 'New tab';
    add.onclick = function () { save(); switchTo(newTab({}).id); };
    el.tabs.appendChild(add);
  }

  function showTab() {
    var tab = activeTab();
    fillSelect(el.problem, cfg.problems, 'name', tab.problem);
    fillSelect(el.language, cfg.languages, 'name', tab.language);
    loading = true;
    editor.setOption('mode', modeFor(languageByNumber(tab.language)));
    editor.setValue(tab.code || '');
    editor.clearHistory();
    loading = false;
    renderTabs();
    editor.focus();
  }

  function switchTo(id) {
    save();
    state.active = id;
    showTab();
    save();
  }

  function closeTab(id) {
    var tab = findBy(state.tabs, 'id', id);
    if (!tab) return;
    if (id === state.active) tab.code = editor.getValue();
    if (tab.code.replace(/\s/g, '') !== '' && !confirm('Close tab "' + tabTitle(tab) + '"? Its code will be lost.'))
      return;
    var idx = state.tabs.indexOf(tab);
    state.tabs.splice(idx, 1);
    if (!state.tabs.length) newTab({});
    if (id === state.active) state.active = state.tabs[Math.min(idx, state.tabs.length - 1)].id;
    showTab();
    save();
  }

  // Opens code in a new tab, or switches to an existing tab with the same code.
  function openInTab(fields) {
    save();
    for (var i = 0; i < state.tabs.length; i++) {
      var t = state.tabs[i];
      if (t.code === fields.code && t.problem === fields.problem && t.language === fields.language) {
        switchTo(t.id);
        return;
      }
    }
    var cur = activeTab();
    var tab;
    if (cur && cur.code.replace(/\s/g, '') === '' && !cur.filename) {
      for (var k in fields) if (fields.hasOwnProperty(k)) cur[k] = fields[k];   // reuse an empty tab
      tab = cur;
    } else {
      tab = newTab(fields);
    }
    state.active = tab.id;
    showTab();
    save();
  }

  // --------------------------------------------------------------- actions
  function submitRun() {
    var tab = activeTab();
    tab.code = editor.getValue();
    tab.problem = el.problem.value;
    tab.language = el.language.value;
    if (tab.problem === '-1' || tab.language === '-1') {
      alert('Invalid problem and/or language');
      return;
    }
    if (tab.code.replace(/\s/g, '') === '') {
      alert('The editor is empty.');
      return;
    }
    var prob = problemByNumber(tab.problem), lang = languageByNumber(tab.language);
    var name = suggestFilename(tab), msg = '', err;
    for (;;) {
      name = prompt(msg + 'Confirm submission of problem ' + prob.name + ' in ' + lang.name +
                    '.\nFile name:', name);
      if (name === null) return;             // cancelled
      name = name.replace(/^\s+|\s+$/g, '');
      if (!(err = filenameError(name))) break;
      msg = err + '\n\n';
    }

    var file;
    try {
      file = new File([tab.code], name, { type: 'text/plain' });
      var dt = new DataTransfer();
      dt.items.add(file);
      el.fileInput.files = dt.files;
    } catch (e) {
      alert('Your browser cannot submit from the editor. Please save the code to a file and use the ' +
            'regular file submission (BOCA-CM needs a recent Firefox or Chromium).');
      return;
    }
    tab.filename = name;
    tab.filenameFor = tab.problem + '/' + tab.language;
    save();
    el.formProblem.value = tab.problem;
    el.formLanguage.value = tab.language;
    el.form.confirmation.value = 'confirm';
    el.form.submit();
  }

  function readTextFile(file, done) {
    var reader = new FileReader();
    reader.onload = function () { done(String(reader.result)); };
    reader.onerror = function () { alert('Could not read file ' + file.name); };
    reader.readAsText(file);
  }

  function warnEncoding(text) {
    if (text.indexOf('\ufffd') >= 0)
      alert('Warning: this file is not valid UTF-8; some characters may have been replaced. ' +
            'Check the code before submitting.');
  }

  function openLocalFile() {
    var f = el.openInput.files && el.openInput.files[0];
    if (!f) return;
    readTextFile(f, function (text) {
      warnEncoding(text);
      var tab = activeTab();
      var lang = null, dot = f.name.lastIndexOf('.');
      if (dot >= 0) lang = findBy(cfg.languages, 'extension', f.name.substring(dot + 1));
      openInTab({
        code: text,
        problem: tab ? el.problem.value : '-1',
        language: lang ? String(lang.number) : (tab ? el.language.value : '-1'),
        filename: f.name,
        filenameFor: ''
      });
      el.openInput.value = '';
    });
  }

  // Called by the "Edit" link of a previous run in the runs table.
  function openRun(link) {
    var url = link.getAttribute('data-url');
    var prob = findBy(cfg.problems, 'name', link.getAttribute('data-problem'));
    var lang = findBy(cfg.languages, 'name', link.getAttribute('data-language'));
    var fname = link.getAttribute('data-filename') || '';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.responseType = 'arraybuffer';
    xhr.onload = function () {
      var ctype = xhr.getResponseHeader('Content-Type') || '';
      if (xhr.status !== 200 || /text\/html/i.test(ctype)) {
        alert('Could not load the code of this run.');
        return;
      }
      var text;
      try { text = new TextDecoder('utf-8').decode(new Uint8Array(xhr.response)); }
      catch (e) { alert('Your browser cannot decode this file.'); return; }
      warnEncoding(text);
      var p = prob ? String(prob.number) : '-1', l = lang ? String(lang.number) : '-1';
      openInTab({ code: text, problem: p, language: l, filename: fname, filenameFor: p + '/' + l });
      el.root.scrollIntoView();
    };
    xhr.onerror = function () { alert('Could not load the code of this run.'); };
    xhr.send();
    return false;
  }

  // ------------------------------------------- one-browser-tab lock/warning
  function instanceId() {
    var id = null;
    try { id = window.sessionStorage.getItem(LOCK_KEY); } catch (e) { /* ignore */ }
    if (!id) {
      id = String(Date.now()) + Math.random().toString(36).slice(2);
      try { window.sessionStorage.setItem(LOCK_KEY, id); } catch (e) { /* ignore */ }
    }
    return id;
  }

  function setupLock() {
    var me = instanceId();
    function other() {
      var raw = storageGet(LOCK_KEY);
      if (!raw) return false;
      try {
        var l = JSON.parse(raw);
        return l.id !== me && Date.now() - l.time < LOCK_STALE_MS;
      } catch (e) { return false; }
    }
    function beat() {
      if (other()) {
        el.warning.style.display = '';
      } else {
        el.warning.style.display = 'none';
        storageSet(LOCK_KEY, JSON.stringify({ id: me, time: Date.now() }));
      }
    }
    beat();
    setInterval(beat, LOCK_BEAT_MS);
    window.addEventListener('beforeunload', function () {
      save();
      var raw = storageGet(LOCK_KEY);
      try { if (raw && JSON.parse(raw).id === me) storageRemove(LOCK_KEY); } catch (e) { /* ignore */ }
    });
  }

  // ------------------------------------------------------------------ init
  function init() {
    el.root = $('bocacm');
    if (!el.root) return;
    el.tabs = $('bocacm-tabs');
    el.problem = $('bocacm-problem');
    el.language = $('bocacm-language');
    el.openInput = $('bocacm-open');
    el.warning = $('bocacm-warning');
    el.form = document.form1;
    el.fileInput = el.form.sourcefile;
    el.formProblem = el.form.problem;
    el.formLanguage = el.form.language;

    load();
    editor = CodeMirror($('bocacm-editor'), {
      lineNumbers: true,
      indentUnit: 4,
      tabSize: 4,
      indentWithTabs: false,
      matchBrackets: true,
      autoCloseBrackets: true,
      styleActiveLine: true,
      viewportMargin: 50,
      extraKeys: {
        'Tab': function (cm) {
          if (cm.somethingSelected()) cm.indentSelection('add');
          else cm.replaceSelection(cm.getOption('indentWithTabs') ? '\t' :
                 new Array(cm.getOption('indentUnit') + 1).join(' '), 'end');
        },
        'Shift-Tab': function (cm) { cm.indentSelection('subtract'); },
        'Ctrl-S': function () { save(); },
        'Cmd-S': function () { save(); }
      }
    });
    var saveTimer = null;
    editor.on('change', function () {
      if (loading) return;
      lastEdit = Date.now();
      if (saveTimer) clearTimeout(saveTimer);
      saveTimer = setTimeout(save, 500);
    });

    el.problem.onchange = function () { activeTab().problem = el.problem.value; renderTabs(); save(); };
    el.language.onchange = function () {
      var tab = activeTab();
      tab.language = el.language.value;
      editor.setOption('mode', modeFor(languageByNumber(tab.language)));
      renderTabs();
      save();
    };
    el.openInput.onchange = openLocalFile;
    $('bocacm-send').onclick = submitRun;

    showTab();
    save();
    if (!storageOk || storageGet(STORAGE_KEY) === null) $('bocacm-nostorage').style.display = '';
    setupLock();
  }

  // Used by reload.js: postpone the periodic page reload while the team types.
  window.bocacmBusy = function () { return Date.now() - lastEdit < BUSY_MS; };
  window.bocacmSave = function () { if (editor) save(); };
  window.bocacmOpenRun = openRun;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
