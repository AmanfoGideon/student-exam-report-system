
// admin/scores/scores.js
// Polished client script: adaptive Bootstrap 5 toasts (top-right), robust fetch/error logging,
// client+server validation for MAX_CA / MAX_EXAM, and PHP-based backend expectations.

document.addEventListener('DOMContentLoaded', () => {
  const MAX_CA = 50, MAX_EXAM = 50;

  let scoresTable = null;
  let selectedClass = '', selectedSubject = '', selectedYear = '', selectedTerm = '';

  const toastEl = document.getElementById('liveToast');
  const toastBody = document.getElementById('toast-body');
  const bsToast = (toastEl && window.bootstrap && bootstrap.Toast) ? new bootstrap.Toast(toastEl, { delay: 5000 }) : null;

  function applyToastTheme(type = 'info') {
    if (!toastEl) return;
    toastEl.classList.remove('bg-success','bg-danger','bg-info','bg-dark','text-white','text-dark');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (type === 'success') toastEl.classList.add('bg-success','text-white');
    else if (type === 'error') toastEl.classList.add('bg-danger','text-white');
    else toastEl.classList.add(prefersDark ? 'bg-dark' : 'bg-info', prefersDark ? 'text-white' : 'text-dark');
  }

  function showToast(message, type = 'info') {
    if (!toastBody) { console.log(`[${type}] ${message}`); return; }
    toastBody.textContent = message;
    applyToastTheme(type);
    try { if (bsToast) bsToast.show(); } catch (e) { console.warn('Toast show failed', e); }
  }

  function getCsrf() {
    const el = document.getElementById('csrf_token');
    return el ? el.value : '';
  }

  // send client log (best-effort)
  function sendClientLog(context, status, message) {
    try {
      const params = new URLSearchParams();
      params.append('action', 'log_error');
      params.append('context', context);
      params.append('status', String(status));
      params.append('message', message);
      params.append('csrf_token', getCsrf());
      if (navigator.sendBeacon) {
        navigator.sendBeacon('score_action.php', params);
      } else {
        fetch('score_action.php', { method: 'POST', body: params.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }).catch(()=>{});
      }
    } catch (e) { console.warn('sendClientLog failed', e); }
  }

  // Load saved scores table
  function loadScoresTable() {
    if (!selectedClass || !selectedSubject || !selectedYear || !selectedTerm) return;

    if (scoresTable) { try { scoresTable.destroy(); } catch (e) {} document.querySelector('#scoresTable tbody')?.remove(); }

    scoresTable = $('#scoresTable').DataTable({
      ajax: {
        url: 'score_action.php',
        type: 'POST',
        data: function() {
          return {
            action: 'load_scores',
            class_id: selectedClass,
            subject_id: selectedSubject,
            year_id: selectedYear,
            term_id: selectedTerm,
            csrf_token: getCsrf()
          };
        },
        dataSrc: function(json) {
          if (!json) { showToast('Empty response from server', 'error'); return []; }
          if (json.status === 'error') { showToast(json.message || 'Server error', 'error'); return []; }
          return json.data || [];
        },
        error: function(xhr, status, err) {
          const txt = xhr && xhr.responseText ? xhr.responseText.slice(0,1000) : status;
          console.error('load_scores error', status, err, txt);
          sendClientLog('load_scores', xhr ? xhr.status : 'network', txt);
          showToast('Failed to load saved scores — check console', 'error');
        }
      },
      columns: [
        { data: 'student' }, { data: 'class' }, { data: 'subject' }, { data: 'term' }, { data: 'year' },
        { data: 'class_score' }, { data: 'exam_score' }, { data: 'total' }, { data: 'grade' }, { data: 'remark' },
        { data: 'action' }
      ],
      order: [[7, 'desc']],
      dom: 'Bfrtip',
      buttons: ['csv','excel','pdf','print'],
      responsive: true
    });
  }

  function setSaving(on) {
    const saveBtn = document.getElementById('saveBulkBtn');
    const spinner = document.getElementById('bulkSpinner');
    if (saveBtn) saveBtn.disabled = !!on;
    if (spinner) spinner.classList.toggle('d-none', !on);
  }

  // fetch students for entry
  function loadEntryTable() {
    if (!selectedClass || !selectedSubject || !selectedYear || !selectedTerm) {
      document.getElementById('scoresEntrySection').style.display = 'none';
      return;
    }
    setSaving(true);

    const body = new URLSearchParams({
      action: 'fetch_students',
      class_id: selectedClass,
      subject_id: selectedSubject,
      year_id: selectedYear,
      term_id: selectedTerm,
      csrf_token: getCsrf()
    }).toString();

    fetch('score_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
      .then(async res => {
        const text = await res.text();
        try { return { status: res.status, json: JSON.parse(text) }; }
        catch (e) { sendClientLog('fetch_students_invalid_json', res.status, text); throw new Error('Invalid server response while fetching students.'); }
      })
      .then(({ json }) => {
        setSaving(false);
        if (json.status === 'error') { showToast(json.message || 'Error loading students', 'error'); return; }
        const tbody = document.querySelector('#scoresEntryTable tbody');
        if (!tbody) { showToast('Entry table missing', 'error'); return; }
        tbody.innerHTML = '';
        json.students.forEach(s => {
          const ca = Number.isFinite(Number(s.class_score)) ? parseInt(String(s.class_score),10) : 0;
          const ex = Number.isFinite(Number(s.exam_score)) ? parseInt(String(s.exam_score),10) : 0;
          const total = Math.min(ca,MAX_CA) + Math.min(ex,MAX_EXAM);
          const tr = document.createElement('tr');
          tr.dataset.id = s.id;
          tr.innerHTML = `<td>${escapeHtml(String(s.name || ''))}</td>
                          <td><input type="number" class="form-control class_score" min="0" max="${MAX_CA}" value="${ca}"></td>
                          <td><input type="number" class="form-control exam_score" min="0" max="${MAX_EXAM}" value="${ex}"></td>
                          <td class="total-cell">${total}</td>`;
          tbody.appendChild(tr);
        });
        document.getElementById('scoresEntrySection').style.display = 'block';
      })
      .catch(err => { setSaving(false); console.error(err); showToast(err.message || 'Failed to load students', 'error'); });
  }

  function escapeHtml(s='') {
    return String(s).replace(/[&<>"'`=\/]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'})[c]);
  }

  // Delegated input handler
  document.addEventListener('input', (e) => {
    try {
      const t = e.target;
      if (!t) return;
      const r = t.closest('tr'); if (!r) return;
      const caEl = r.querySelector('.class_score'), exEl = r.querySelector('.exam_score');
      if (!caEl || !exEl) return;
      let ca = parseInt(String(caEl.value),10); ca = isNaN(ca)?0:ca;
      let ex = parseInt(String(exEl.value),10); ex = isNaN(ex)?0:ex;
      if (ca > MAX_CA) { ca = MAX_CA; caEl.value = MAX_CA; showToast(`Class score cannot exceed ${MAX_CA}.`, 'error'); }
      if (ex > MAX_EXAM) { ex = MAX_EXAM; exEl.value = MAX_EXAM; showToast(`Exam score cannot exceed ${MAX_EXAM}.`, 'error'); }
      if (ca < 0) { ca = 0; caEl.value = 0; }
      if (ex < 0) { ex = 0; exEl.value = 0; }
      r.querySelector('.total-cell').textContent = (ca + ex);
    } catch (err) { console.error('Input handler error', err); }
  });

  // Bulk save
  const bulkForm = document.getElementById('bulkScoresForm');
  if (bulkForm) {
    bulkForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const rows = Array.from(document.querySelectorAll('#scoresEntryTable tbody tr'));
      const scores = [];
      for (const tr of rows) {
        const sid = tr.dataset.id;
        const caEl = tr.querySelector('.class_score'), exEl = tr.querySelector('.exam_score');
        if (!sid || !caEl || !exEl) continue;
        const ca = parseInt(String(caEl.value),10) || 0;
        const ex = parseInt(String(exEl.value),10) || 0;
        if (ca > MAX_CA || ex > MAX_EXAM) { showToast(`Scores exceed allowed limits (CA ≤ ${MAX_CA}, Exam ≤ ${MAX_EXAM}).`, 'error'); return; }
        scores.push({ student_id: sid, class_score: ca, exam_score: ex });
      }
      if (!scores.length) { showToast('No scores to save.', 'info'); return; }

      setSaving(true);
      const body = new URLSearchParams({
        action: 'save_bulk_scores',
        class_id: selectedClass,
        subject_id: selectedSubject,
        year_id: selectedYear,
        term_id: selectedTerm,
        scores: JSON.stringify(scores),
        csrf_token: getCsrf()
      }).toString();

      fetch('score_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(async res => {
          const text = await res.text();
          try { return { status: res.status, json: JSON.parse(text) }; }
          catch (e) { sendClientLog('save_bulk_scores_invalid_json', res.status, text); throw new Error('Invalid server response.'); }
        })
        .then(({ json }) => {
          setSaving(false);
          if (json.status === 'success') {
            showToast('Scores saved successfully.', 'success');
            loadEntryTable(); loadScoresTable();
          } else {
            sendClientLog('save_bulk_scores_failed', 200, JSON.stringify(json));
            showToast(json.message || 'Failed to save scores', 'error');
          }
        })
        .catch(err => { setSaving(false); console.error(err); showToast(err.message || 'Failed to save scores', 'error'); });
    });
  }

  // Edit actions
  document.addEventListener('click', (e) => {
    const el = e.target;
    if (!el) return;
    if (el.classList.contains('editScoreBtn')) {
      let row = el.getAttribute('data-row');
      try { row = JSON.parse(row); } catch (err) { row = null; }
      if (!row) { showToast('Unable to parse row for edit.', 'error'); return; }
      document.getElementById('edit_score_id').value = row.id;
      document.getElementById('edit_class_score').value = row.class_score;
      document.getElementById('edit_exam_score').value = row.exam_score;
      const modalEl = document.getElementById('editScoreModal');
      if (modalEl && window.bootstrap && bootstrap.Modal) new bootstrap.Modal(modalEl).show();
    }
  });

  // Edit submit
  const editForm = document.getElementById('editScoreForm');
  if (editForm) {
    editForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const id = document.getElementById('edit_score_id').value;
      const ca = parseInt(String(document.getElementById('edit_class_score').value),10) || 0;
      const ex = parseInt(String(document.getElementById('edit_exam_score').value),10) || 0;
      if (ca > MAX_CA || ex > MAX_EXAM) { showToast(`CA or Exam cannot exceed ${MAX_CA}.`, 'error'); return; }

      setSaving(true);
      const body = new URLSearchParams({
        action: 'edit_score',
        id: id,
        class_score: ca,
        exam_score: ex,
        csrf_token: getCsrf()
      }).toString();

      fetch('score_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(async res => {
          const text = await res.text();
          try { return { status: res.status, json: JSON.parse(text) }; }
          catch (e) { sendClientLog('edit_score_invalid_json', res.status, text); throw new Error('Invalid server response.'); }
        })
        .then(({ json }) => {
          setSaving(false);
          if (json.status === 'success') {
            showToast('Score updated successfully.', 'success');
            loadEntryTable(); loadScoresTable();
            const modalEl = document.getElementById('editScoreModal'); const inst = bootstrap.Modal.getInstance(modalEl); if (inst) inst.hide();
          } else {
            sendClientLog('edit_score_failed', 200, JSON.stringify(json));
            showToast(json.message || 'Failed to update score', 'error');
          }
        })
        .catch(err => { setSaving(false); console.error(err); showToast('Failed to update score', 'error'); });
    });
  }

  // Export
  document.getElementById('exportScoresBtn')?.addEventListener('click', () => {
    if (!selectedClass || !selectedSubject || !selectedYear || !selectedTerm) { showToast('Please select filters before exporting.', 'info'); return; }
    const url = `score_action.php?action=export_scores&class_id=${encodeURIComponent(selectedClass)}&subject_id=${encodeURIComponent(selectedSubject)}&year_id=${encodeURIComponent(selectedYear)}&term_id=${encodeURIComponent(selectedTerm)}`;
    window.open(url, '_blank');
  });

  // Import
  const importForm = document.getElementById('importScoresForm');
  if (importForm) {
    importForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(importForm);
      fd.append('action','import_scores');
      fd.append('csrf_token', getCsrf());
      setSaving(true);
      fetch('score_action.php', { method: 'POST', body: fd })
        .then(async res => {
          const text = await res.text();
          try { return { status: res.status, json: JSON.parse(text) }; }
          catch (e) { sendClientLog('import_scores_invalid_json', res.status, text); throw new Error('Invalid server response.'); }
        })
        .then(({ json }) => {
          setSaving(false);
          if (json.status === 'success') {
            showToast(json.message || 'Imported successfully', 'success');
            loadEntryTable(); loadScoresTable();
            const modalEl = document.getElementById('importScoresModal'); const inst = bootstrap.Modal.getInstance(modalEl); if (inst) inst.hide();
          } else {
            sendClientLog('import_scores_failed', 200, JSON.stringify(json));
            showToast(json.message || 'Import failed', 'error');
          }
        })
        .catch(err => { setSaving(false); console.error(err); showToast('Failed to import scores', 'error'); });
    });
  }

  // select listeners
  ['class_id','subject_id','year_id','term_id'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', () => {
      selectedClass = document.getElementById('class_id')?.value || '';
      selectedSubject = document.getElementById('subject_id')?.value || '';
      selectedYear = document.getElementById('year_id')?.value || '';
      selectedTerm = document.getElementById('term_id')?.value || '';
      loadEntryTable(); loadScoresTable();
    });
  });

  // initial
  selectedClass = document.getElementById('class_id')?.value || '';
  selectedSubject = document.getElementById('subject_id')?.value || '';
  selectedYear = document.getElementById('year_id')?.value || '';
  selectedTerm = document.getElementById('term_id')?.value || '';

  if (selectedClass && selectedSubject && selectedYear && selectedTerm) { loadEntryTable(); loadScoresTable(); }
});

