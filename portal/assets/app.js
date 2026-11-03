// Submit filter forms when a dropdown changes.
document.addEventListener('change', function (e) {
  if (e.target.matches('select[data-autosubmit]')) e.target.form.submit();
  if (e.target.matches('select[data-reload-country]')) window.location = e.target.getAttribute('data-reload-country') + encodeURIComponent(e.target.value);
});

// Confirmation prompt before deleting a file or meeting.
document.addEventListener('submit', function (e) {
  var msg = e.target && e.target.getAttribute ? e.target.getAttribute('data-confirm') : null;
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// Student form: sections, meeting formats and the unsaved-changes warning.
(function () {
  var form = document.getElementById('student_main_form');
  if (!form) return;

  // ---------- sections ----------
  function showStep(key) {
    var panes = form.querySelectorAll('.workflow-step-pane');
    var found = false;
    panes.forEach(function (p) { var on = p.getAttribute('data-step') === key; p.hidden = !on; if (on) found = true; });
    if (!found) return;
    document.querySelectorAll('.stepper-nav .step-nav-btn').forEach(function (b) {
      var on = b.getAttribute('data-step') === key;
      b.classList.toggle('primary', on); b.classList.toggle('ghost', !on);
      if (on) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
    });
    var inp = document.getElementById('active_step_input');
    if (inp) inp.value = key;
    var nav = document.querySelector('.stepper-nav');
    if (nav) nav.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function stepOf(el) { var p = el && el.closest ? el.closest('.workflow-step-pane') : null; return p ? p.getAttribute('data-step') : null; }
  function focusField(key) {
    var box = form.querySelector('[data-field="' + key + '"]');
    if (!box) return;
    showStep(stepOf(box));
    var inp = box.querySelector('input:not([type=hidden]), select, textarea');
    setTimeout(function () { box.scrollIntoView({ behavior: 'smooth', block: 'center' }); if (inp) inp.focus({ preventScroll: true }); }, 60);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.step-nav-btn') : null;
    if (b && b.getAttribute('data-step')) { e.preventDefault(); showStep(b.getAttribute('data-step')); return; }
    var g = e.target.closest ? e.target.closest('[data-goto-field]') : null;
    if (g) { e.preventDefault(); focusField(g.getAttribute('data-goto-field')); }
  });

  // ---------- meeting format: a face-to-face meeting needs no recording ----------
  function syncMeeting(kind) {
    var modeName = kind === 'initial' ? 'initial_meeting_mode' : (kind === 'tax' ? 'tax_meeting_mode' : 'second_meeting_mode');
    var checked = form.querySelector('input[name="' + modeName + '"]:checked');
    var f2f = checked && checked.value === 'Face-to-Face';
    form.querySelectorAll('[data-f2f-hide="' + kind + '"]').forEach(function (w) { w.hidden = f2f; });
    var note = form.querySelector('[data-f2f-note="' + kind + '"]');
    if (note) note.hidden = !f2f;
    if (kind === 'initial' && f2f) {
      var held = form.querySelector('[name="initial_meeting"]');
      var rec = form.querySelector('[name="recorded_uploaded"]');
      if (held && held.value === 'Yes' && rec && (rec.value === '' || rec.value === 'No')) rec.value = 'Yes, face to face';
    }
  }
  ['initial', 'tax', 'second'].forEach(syncMeeting);
  form.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('[data-mode-for]')) syncMeeting(t.name === 'initial_meeting_mode' ? 'initial' : (t.name === 'tax_meeting_mode' ? 'tax' : 'second'));
    if (t.name === 'initial_meeting') syncMeeting('initial');
  });

  // ---------- required fields: check before sending (the server checks again) ----------
  form.addEventListener('submit', function (e) {
    var draft = e.submitter && e.submitter.value === 'save_draft';
    var first = null;
    form.querySelectorAll('[data-req="1"]').forEach(function (el) {
      if (el.disabled) return;
      var box = el.closest('[data-field]');
      var empty = String(el.value || '').trim() === '';
      var need = !draft || el.name === 'name';
      if (box) box.classList.toggle('has-err', empty && need);
      if (empty && need && !first) first = el;
    });
    if (first) {
      e.preventDefault();
      focusField(first.name);
      alertBox((first.getAttribute('data-label') || 'This field') + ' is required.');
      return;
    }
    dirty = false;
  });
  function alertBox(msg) {
    var s = document.getElementById('form_alert');
    if (!s) { s = document.createElement('div'); s.id = 'form_alert'; s.className = 'flash bad'; s.setAttribute('role', 'alert'); form.parentNode.insertBefore(s, form); }
    s.textContent = msg;
  }

  // ---------- unsaved changes ----------
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; var n = document.getElementById('dirty_note'); if (n) n.hidden = false; });
  form.addEventListener('change', function () { dirty = true; var n = document.getElementById('dirty_note'); if (n) n.hidden = false; });
  form.addEventListener('docs:changed', function () { dirty = true; var n = document.getElementById('dirty_note'); if (n) n.hidden = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();

// In-page document & recording preview modal.
(function () {
  var modal = null;
  function closeModal() {
    if (!modal) return;
    modal.remove();
    modal = null;
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('a[data-view]') : null;
    if (!btn || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    closeModal();
    var src = btn.getAttribute('data-view');
    var openUrl = btn.getAttribute('data-open') || src;
    var dlUrl = btn.getAttribute('data-dl') || '';
    var title = btn.getAttribute('data-title') || 'Preview';

    modal = document.createElement('div');
    modal.className = 'vmodal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');

    var box = document.createElement('div');
    box.className = 'vmodal-box';

    var head = document.createElement('div');
    head.className = 'vmodal-h';

    var b = document.createElement('b');
    b.textContent = title;
    head.appendChild(b);

    var openLink = document.createElement('a');
    openLink.className = 'btn xs';
    openLink.href = openUrl;
    openLink.target = '_blank';
    openLink.rel = 'noopener';
    openLink.textContent = 'Open in new tab ↗';
    head.appendChild(openLink);

    if (dlUrl) {
      var dlLink = document.createElement('a');
      dlLink.className = 'btn xs primary';
      dlLink.href = dlUrl;
      dlLink.textContent = 'Download';
      head.appendChild(dlLink);
    }

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn xs';
    closeBtn.textContent = 'Close ✕';
    closeBtn.addEventListener('click', closeModal);
    head.appendChild(closeBtn);

    var frame = document.createElement('iframe');
    frame.className = 'vmodal-frame';
    frame.src = src;
    frame.setAttribute('allow', 'autoplay; fullscreen');

    box.appendChild(head);
    box.appendChild(frame);
    modal.appendChild(box);
    modal.addEventListener('click', function (ev) {
      if (ev.target === modal) closeModal();
    });
    document.body.appendChild(modal);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal) closeModal();
  });
})();

// Meetings panel: show only the fields for the chosen format (face to face / online / phone).
(function () {
  function sync(form) {
    var sel = form.querySelector('.meet-type-select');
    var v = sel ? sel.value : '';
    form.querySelectorAll('.mgroup').forEach(function (g) {
      var on = g.getAttribute('data-for') === v;
      g.hidden = !on;
      g.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !on; });
    });
  }
  document.querySelectorAll('form[data-meet-form]').forEach(sync);
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('.meet-type-select')) sync(e.target.form);
  });
})();

// Documents checklist: a chosen file is uploaded at once and shown under its document as "added — not saved yet"
// (it can be removed until the record is saved). The status and the pending list at the top follow live.
(function () {
  document.querySelectorAll('.docs-box').forEach(function (box) {
    var endpoint = box.getAttribute('data-endpoint');
    var csrf = box.getAttribute('data-csrf');
    var sid = box.getAttribute('data-student') || '0';
    var country = box.getAttribute('data-country') || '';
    var formId = box.getAttribute('data-form');
    var form = formId ? document.getElementById(formId) : null;
    var doneWord = box.getAttribute('data-done') || 'Done';
    var pendingUploads = 0;

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function changed() { if (form) form.dispatchEvent(new Event('docs:changed')); recount(); }

    function stateOf(item) {
      var saved = parseInt(item.getAttribute('data-saved') || '0', 10);
      var staged = item.querySelectorAll('.fline.staged:not(.failed)').length;
      var mark = item.querySelector('select.dk-mark');
      var m = mark ? mark.value : (item.getAttribute('data-mark') || '');
      if (m === 'Received' || m === 'Verified') return 'have';
      if (m === 'Requested' && staged === 0 && saved === 0) return 'pending';
      if (staged > 0 || saved > 0) return 'have';
      return item.getAttribute('data-need') === '1' || m === 'Requested' ? 'pending' : 'optional';
    }
    function recount() {
      var need = 0, got = 0, pend = [];
      box.querySelectorAll('.dk[data-key]').forEach(function (item) {
        var mark = item.querySelector('select.dk-mark');
        var asked = mark ? mark.value === 'Requested' : item.getAttribute('data-mark') === 'Requested';
        var counts = item.getAttribute('data-need') === '1' || asked;
        if (asked) item.setAttribute('data-saved', item.getAttribute('data-saved-new') || '0');
        else item.setAttribute('data-saved', item.getAttribute('data-saved-all') || '0');
        var st = stateOf(item);
        item.classList.toggle('is-have', st === 'have');
        item.classList.toggle('is-pending', st === 'pending');
        var badge = item.querySelector('.dk-badge');
        var stagedN = item.querySelectorAll('.fline.staged:not(.failed)').length;
        if (badge) {
          badge.className = 'pill dk-badge ' + (st === 'have' ? 'ok' : (st === 'pending' ? 'warn' : ''));
          badge.textContent = st === 'have' ? (stagedN && item.getAttribute('data-saved') === '0' && !(mark && mark.value === 'Received') ? 'Received · not saved yet' : 'Received') : (st === 'pending' ? 'Pending' : 'Optional');
        }
        if (counts && item.getAttribute('data-in-status') === '1') { need++; if (st === 'have') got++; else pend.push(item.getAttribute('data-short')); }
      });
      var pill = box.querySelector('[data-docs-status]');
      if (pill) { pill.textContent = pend.length ? 'Pending' : doneWord; pill.className = 'pill ' + (pend.length ? 'warn' : 'ok'); }
      var c = box.querySelector('[data-docs-count]'); if (c) c.textContent = got + ' of ' + need;
      var bar = box.querySelector('[data-docs-bar]'); if (bar) bar.style.width = (need ? Math.round(got / need * 100) : 100) + '%';
      var p = box.querySelector('[data-docs-pending]');
      if (p) { p.textContent = pend.length ? pend.join(', ') : 'Nothing — every needed document is received'; p.classList.toggle('ok-t', !pend.length); }
    }

    function addLine(item, file) {
      var list = item.querySelector('.dk-files');
      var line = document.createElement('div');
      line.className = 'fline staged';
      line.innerHTML = '<span class="ficon">…</span><div class="fmeta"><b title="' + esc(file.name) + '">' + esc(file.name) + '</b><span class="m">Uploading… <i class="up-bar"><i></i></i></span></div>'
        + '<span class="fbtns"><button type="button" class="btn xs dk-remove" title="Remove this file (it is not saved yet)">✕ Remove</button></span>';
      list.appendChild(line);
      list.hidden = false;
      var key = item.getAttribute('data-key');
      var fd = new FormData();
      fd.append('_csrf', csrf); fd.append('student_id', sid); fd.append('country', country); fd.append('doc_key', key); fd.append('file', file);
      var xhr = new XMLHttpRequest();
      pendingUploads++;
      xhr.upload.addEventListener('progress', function (ev) { if (ev.lengthComputable) { var b = line.querySelector('.up-bar i'); if (b) b.style.width = Math.round(ev.loaded / ev.total * 100) + '%'; } });
      xhr.addEventListener('loadend', function () {
        pendingUploads--;
        var j = null; try { j = JSON.parse(xhr.responseText); } catch (e) {}
        if (xhr.status === 200 && j && j.ok) {
          line.setAttribute('data-token', j.token);
          line.querySelector('.ficon').outerHTML = j.icon;
          line.querySelector('.m').innerHTML = esc(j.size) + ' · <span class="new-t">Added — saved when you press Save</span>';
          var hid = document.createElement('input');
          hid.type = 'hidden'; hid.name = 'staged[' + key + '][]'; hid.value = j.token;
          if (formId) hid.setAttribute('form', formId);
          line.appendChild(hid);
        } else {
          line.classList.add('failed');
          line.querySelector('.ficon').textContent = '!';
          line.querySelector('.m').innerHTML = '<span class="bad-t">' + esc((j && j.error) || 'The upload did not complete. Check the connection and try again.') + '</span>';
          line.querySelector('.dk-remove').textContent = '✕';
        }
        changed();
      });
      xhr.open('POST', endpoint);
      xhr.send(fd);
      changed();
    }

    box.addEventListener('change', function (e) {
      var t = e.target;
      if (t.matches('input[type=file].dk-input')) {
        var item = t.closest('.dk');
        Array.prototype.forEach.call(t.files, function (f) { addLine(item, f); });
        t.value = '';
        e.stopPropagation();
      } else if (t.matches('select.dk-mark')) {
        changed();
      }
    });
    box.addEventListener('click', function (e) {
      var rm = e.target.closest ? e.target.closest('.dk-remove') : null;
      if (!rm) return;
      var line = rm.closest('.fline');
      var tok = line.getAttribute('data-token');
      if (tok) {
        var fd = new FormData(); fd.append('_csrf', csrf); fd.append('action', 'discard'); fd.append('token', tok);
        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
      }
      var list = line.parentNode;
      line.remove();
      if (!list.children.length) list.hidden = true;
      changed();
    });
    // Do not send the form while a file is still uploading.
    var f = form || box.closest('form');
    if (f) f.addEventListener('submit', function (e) {
      if (pendingUploads > 0) { e.preventDefault(); window.alert('A file is still uploading. Wait until it shows "Added", then save.'); }
    });
    recount();
  });
})();
