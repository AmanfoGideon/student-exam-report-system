// teacher/reports.js
// Trimmed & teacher-scoped DataTables + meta modal logic
(function () {
  'use strict';

  // helpers
  function $id(id){ return document.getElementById(id); }
  function qs(sel){ return document.querySelector(sel); }
  function qsa(sel){ return Array.from(document.querySelectorAll(sel)); }
  function toast(msg, type = 'info') {
    // simple bootstrap toast builder
    const cont = document.getElementById('toastContainer');
    if (!cont) { console.log(type, msg); return; }
    const wrapper = document.createElement('div');
    wrapper.className = `toast align-items-center text-bg-${type === 'error' ? 'danger' : (type === 'success' ? 'success' : 'info')} border-0`;
    wrapper.role = 'alert';
    wrapper.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    cont.appendChild(wrapper);
    const bsToast = new bootstrap.Toast(wrapper, { delay: 4000 });
    bsToast.show();
    wrapper.addEventListener('hidden.bs.toast', () => wrapper.remove());
  }

  // Container metadata
  const container = document.querySelector('.container[data-report-action]');
  if (!container) { console.warn('report container missing'); return; }

  const ACTION_URL = container.dataset.reportAction || 'report_action.php';
  const RENDER_URL = container.dataset.renderStudent || 'render_student_report.php';
  const CSRF = container.dataset.csrf || (document.getElementById('csrf_token') && document.getElementById('csrf_token').value) || '';

  // selectors
  const $filterClass = $id('filter_class');
  const $filterSubject = $id('filter_subject');
  const $filterYear = $id('filter_year');
  const $filterTerm = $id('filter_term');
  const $btnApply = $id('btnApply');
  const $previewClassBtn = $id('previewClassBtn');

  // DataTables instances
  let studentsTable = null;
  let scoresTable = null;

  // Build DataTables for students
  function initStudentsTable() {
    if (studentsTable) {
      try { studentsTable.destroy(); } catch (e) {}
      $('#studentsTable').empty();
    }

    studentsTable = $('#studentsTable').DataTable({
      processing: true,
      serverSide: true,
      deferRender: true,
      pageLength: 10,
      ajax: {
        url: ACTION_URL,
        type: 'POST',
        data: function (d) {
          return Object.assign({}, d, {
            action: 'list_students',
            class_id: $filterClass ? $filterClass.value : '',
            subject_id: $filterSubject ? $filterSubject.value : '',
            term_id: $filterTerm ? $filterTerm.value : '',
            year_id: $filterYear ? $filterYear.value : '',
            csrf_token: CSRF
          });
        },
        dataSrc: function (json) {
          if (!json || !json.data) return [];
          return json.data.map(row => {
            // render actions column
            const actions = [
              `<button class="btn btn-sm btn-outline-primary previewBtn" data-id="${row.id}" data-class="${row.class_id}" title="Preview"><i class="fa fa-eye"></i></button>`,
              `<button class="btn btn-sm btn-outline-secondary metaBtn ms-1" data-id="${row.id}" data-class="${row.class_id}" title="Meta"><i class="fa fa-pen"></i></button>`
            ].join(' ');
            return {
              idx: row.idx,
              admission_no: row.admission_no || '',
              photo: `<img src="${row.photo||'/assets/images/placeholder.png'}" class="student-photo-thumb" alt="photo">`,
              name: row.name || '',
              gender: row.gender || '',
              dob: row.dob || '',
              class_name: row.class_name || '',
              actions: actions,
              // keep raw for event handlers
              raw: row
            };
          });
        },
        error: function (xhr, status, err) {
          console.error('students ajax error', status, err, xhr && xhr.responseText);
          toast('Failed to load students', 'error');
        }
      },
      columns: [
        { data: 'idx', width: '4%' },
        { data: 'admission_no' },
        { data: 'photo', orderable: false, searchable: false },
        { data: 'name' },
        { data: 'gender' },
        { data: 'dob' },
        { data: 'class_name' },
        { data: 'actions', orderable: false, searchable: false }
      ],
      order: [[3, 'asc']],
      responsive: true,
      dom: 'Bfrtip',
      buttons: [
        { extend: 'excelHtml5', text: '<i class="fa fa-file-excel"></i> Excel' },
        { extend: 'pdfHtml5', text: '<i class="fa fa-file-pdf"></i> PDF' },
        { extend: 'print', text: '<i class="fa fa-print"></i> Print' }
      ]
    });
  }

  // Build Scores Preview table
  function initScoresTable() {
    if (scoresTable) {
      try { scoresTable.destroy(); } catch (e) {}
      $('#scoresTable').empty();
    }
    scoresTable = $('#scoresTable').DataTable({
      processing: true,
      serverSide: false, // server returns full list for preview (not huge)
      deferRender: true,
      pageLength: 10,
      ajax: {
        url: ACTION_URL,
        type: 'POST',
        data: function () {
          return {
            action: 'list_scores_preview',
            class_id: $filterClass ? $filterClass.value : '',
            term_id: $filterTerm ? $filterTerm.value : '',
            year_id: $filterYear ? $filterYear.value : '',
            csrf_token: CSRF
          };
        },
        dataSrc: function (json) {
          if (!json || !json.data) return [];
          return json.data;
        },
        error: function () { toast('Failed to load scores preview', 'error'); }
      },
      columns: [
        { data: 'idx', width: '4%' },
        { data: 'student' },
        { data: 'subject' },
        { data: 'total', className: 'text-center' },
        { data: 'position', className: 'text-center' },
        { data: 'class_name' },
        { data: 'term' },
        { data: 'year' }
      ],
      order: [[4, 'asc']],
      responsive: true,
      dom: 'Bfrtip',
      buttons: [
        { extend: 'excelHtml5', text: '<i class="fa fa-file-excel"></i> Excel' },
        { extend: 'pdfHtml5', text: '<i class="fa fa-file-pdf"></i> PDF' },
        { extend: 'print', text: '<i class="fa fa-print"></i> Print' }
      ]
    });
  }

  // Apply filters handler (debounced small)
  let filterTimer = null;
  function applyFilters() {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(() => {
      if (!studentsTable) initStudentsTable(); else studentsTable.ajax.reload(null, true);
      if (!scoresTable) initScoresTable(); else scoresTable.ajax.reload(null, true);
    }, 150);
  }

  // Meta modal helpers
  const metaModalEl = document.getElementById('metaModal');
  const metaModal = metaModalEl ? new bootstrap.Modal(metaModalEl) : null;

  function openMetaModal(studentId, classId, termId, yearId) {
    // clear form
    qsa('#metaForm input, #metaForm textarea, #metaForm select').forEach(el => el.value = '');
    $id('meta_student_id').value = studentId;
    $id('meta_class_id').value = classId;
    $id('meta_term_id').value = termId || ($filterTerm ? $filterTerm.value : '');
    $id('meta_year_id').value = yearId || ($filterYear ? $filterYear.value : '');

    // fetch existing meta
    const body = new URLSearchParams({
      action: 'get_meta',
      student_id: studentId,
      class_id: classId,
      term_id: $id('meta_term_id').value,
      year_id: $id('meta_year_id').value,
      csrf_token: CSRF
    });

    fetch(ACTION_URL, { method: 'POST', body: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
      .then(r => r.json())
      .then(json => {
        if (json?.data) {
          const d = json.data;
          // Map fields if present
          ['present_days','total_days','attendance_percent','class_teacher_remark','head_teacher_remark','attitude','interest','promotion_status','vacation_date','next_term_begins'].forEach(k=>{
            if ($id(k) && (k in d)) $id(k).value = d[k] ?? '';
          });
        }
        metaModal?.show();
      })
      .catch(err => {
        console.error('get_meta error', err);
        metaModal?.show();
      });
  }

  // Save meta
  function saveMeta() {
    const payload = {
      action: 'save_meta',
      student_id: $id('meta_student_id').value,
      class_id: $id('meta_class_id').value,
      term_id: $id('meta_term_id').value,
      year_id: $id('meta_year_id').value,
      present_days: $id('present_days').value || 0,
      total_days: $id('total_days').value || 0,
      class_teacher_remark: $id('class_teacher_remark').value || '',
      head_teacher_remark: $id('head_teacher_remark').value || '',
      attitude: $id('attitude').value || '',
      interest: $id('interest').value || '',
      promotion_status: $id('promotion_status').value || '',
      vacation_date: $id('vacation_date').value || '',
      next_term_begins: $id('next_term_begins').value || '',
      csrf_token: CSRF
    };

    fetch(ACTION_URL, {
      method: 'POST',
      body: new URLSearchParams(payload).toString(),
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    })
      .then(r => r.json())
      .then(json => {
        if (json && json.success) {
          toast('Saved successfully', 'success');
          metaModal?.hide();
          if (scoresTable) scoresTable.ajax.reload(null, false);
        } else {
          toast(json?.msg || 'Save failed', 'error');
        }
      })
      .catch(err => {
        console.error('save_meta error', err);
        toast('Network/server error', 'error');
      });
  }

  // event delegates
  document.addEventListener('click', function (ev) {
    const el = ev.target.closest('.previewBtn, .metaBtn');
    if (!el) return;
    ev.preventDefault();
    if (el.classList.contains('previewBtn')) {
      const sid = el.getAttribute('data-id');
      const cid = el.getAttribute('data-class') || ($filterClass ? $filterClass.value : '');
      const term = $filterTerm ? $filterTerm.value : '';
      const year = $filterYear ? $filterYear.value : '';
      if (!sid || !cid || !term || !year) { toast('Select class, term and year first', 'info'); return; }
      const params = new URLSearchParams({ student_id: sid, class_id: cid, term_id: term, year_id: year });
      window.open(`${RENDER_URL}?${params.toString()}`, '_blank');
      return;
    }
    if (el.classList.contains('metaBtn')) {
      const sid = el.getAttribute('data-id');
      const cid = el.getAttribute('data-class') || ($filterClass ? $filterClass.value : '');
      openMetaModal(sid, cid);
    }
  });

  // attendance percent live calc
  ['present_days', 'total_days'].forEach(id => {
    const el = $id(id);
    if (!el) return;
    el.addEventListener('input', () => {
      const present = parseFloat($id('present_days').value) || 0;
      const total = parseFloat($id('total_days').value) || 0;
      $id('attendance_percent').value = total ? ((present / total) * 100).toFixed(2) : '0.00';
    });
  });

  // save meta button
  $id('saveMetaBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    saveMeta();
  });

  // preview class (open render with preview=class)
  $previewClassBtn?.addEventListener('click', function (e) {
    const classId = $filterClass ? $filterClass.value : '';
    const termId = $filterTerm ? $filterTerm.value : '';
    const yearId = $filterYear ? $filterYear.value : '';
    if (!classId || !termId || !yearId) return toast('Select class, term and year first', 'info');
    const params = new URLSearchParams({ preview: 'class', class_id: classId, term_id: termId, year_id: yearId });
    window.open(`${RENDER_URL}?${params.toString()}`, '_blank');
  });

  // filter apply
  $btnApply?.addEventListener('click', function (e) {
    e.preventDefault();
    applyFilters();
  });

  // initialize tables on load if filters already set
  if ($filterClass && $filterClass.value && $filterYear && $filterTerm && $filterSubject) {
    initStudentsTable();
    initScoresTable();
  } else {
    // init minimal, but we avoid loading until user applies filters
    initStudentsTable(); // serverSide will return empty until filters selected
    initScoresTable();
  }

  // expose for debug (optional)
  window._teacherReports = {
    reload: applyFilters,
    studentsTable,
    scoresTable
  };

})();
