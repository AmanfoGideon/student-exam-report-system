/* reports.js (optimized)
 - Optimized for performance, security, and UX.
 - Features:
   ✅ Debounced filter reloads
   ✅ Deferred rendering for DataTables
   ✅ Global AJAX error handling
   ✅ CSRF header protection
   ✅ Double-click prevention
*/

$(function () {
  const $container = $('div.container[data-report-action]');
  const ACTION_URL = $container.data('report-action');
  const RENDER_URL = $container.data('render-student');
  const CSRF = $container.data('csrf') || $('#csrf_token').val();

  const $loading = $('#loadingOverlay');
  const $toastCont = $('#toastContainer');

  // Preloader adapter: prefer global site preloader (admin-dashboard) when available,
  // otherwise fall back to the local showLoading() helper used in this file.
  function showPreloader(show = true) {
    try {
      if (show && typeof window.showLoader === 'function') {
        window.showLoader();
        return;
      }
      if (!show && typeof window.hideLoader === 'function') {
        window.hideLoader();
        return;
      }
    } catch (e) { /* ignore and fallback */ }
    // Local fallback
    showLoading(show);
  }

  /** ------------------------
   * Helpers
   * ------------------------ **/
  function showLoading(show = true) {
    $loading.toggleClass('d-none', !show);
  }
  
  function toast(msg, type = 'success', delay = 4000) {
    const id = `t${Date.now()}`;
    const $t = $(`
      <div id="${id}" class="toast align-items-center text-bg-${type} border-0" role="alert">
        <div class="d-flex">
          <div class="toast-body">${msg}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>
    `);
    $toastCont.append($t);
    const toastObj = new bootstrap.Toast($t[0], { delay });
    toastObj.show();
    $t.on('hidden.bs.toast', () => $t.remove());
  }

  // Global AJAX setup
  $.ajaxSetup({
    headers: { 'X-CSRF-Token': CSRF },
    error: function (xhr) {
      showPreloader(false);
      toast(`Server Error: ${xhr.status} ${xhr.statusText}`, 'danger');
    },
  });

  /** ------------------------
   * DataTables: Students
   * ------------------------ **/
  // small helper to render safe thumbnail HTML (handles empty src)
  function thumbnailHtml(src) {
    const safe = src && typeof src === 'string' ? src : '/assets/images/default_user.png';
    return `<img src="${safe}" class="student-photo-thumb img-fluid" alt="student photo" loading="lazy">`;
  }

  const studentsTable = $('#studentsTable').DataTable({
    processing: true,
    serverSide: true,            // ✅ enable server-side for scalability
    deferRender: true,
    pageLength: 10,
    ajax: {
      url: ACTION_URL,
      type: 'POST',
      data: d => ({
        ...d,
        action: 'list_students',
        class_id: $('#filter_class').val(),
        year_id: $('#filter_year').val(),
        term_id: $('#filter_term').val(),
        subject_id: $('#filter_subject').val(),
      }),
      dataSrc: json => json.data || [],
      beforeSend: () => showPreloader(true),
      complete: () => showPreloader(false),
    },
    columns: [
      { data: 'idx', className: 'text-center' },
      { data: 'admission_no' },
      // thumbnail column uses helper
      { data: 'photo', render: d => thumbnailHtml(d), orderable: false },
      { data: 'name' },
      { data: 'gender' },
      { data: 'dob' },
      { data: 'class_name' },
      { data: null, orderable: false, render: rowActionCell },
    ],
  });

  function rowActionCell(row) {
    const sid = row.id;
    const c = $('#filter_class').val() || row.class_id || '';
    const y = $('#filter_year').val();
    const t = $('#filter_term').val();
    // small screen: render dropdown menu to save horizontal space
    if (window.matchMedia && window.matchMedia('(max-width:640px)').matches) {
      return `
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Actions</button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item previewReportBtn" href="#" data-id="${sid}" data-class="${c}" data-term="${t}" data-year="${y}">Preview</a></li>
            <li><a class="dropdown-item genPdfBtn" href="#" data-id="${sid}" data-class="${c}" data-term="${t}" data-year="${y}">PDF</a></li>
            <li><a class="dropdown-item metaBtn" href="#" data-id="${sid}" data-class="${c}" data-term="${t}" data-year="${y}">Meta</a></li>
          </ul>
        </div>`;
    }
    // default: inline buttons
    return `
      <div class="btn-group actions" role="group">
        <button class="btn btn-sm btn-outline-primary previewReportBtn" data-id="${sid}" data-class="${c}" data-term="${t}" data-year="${y}" title="Preview Report">Preview</button>
        <button class="btn btn-sm btn-outline-success genPdfBtn" data-id="${sid}" data-class="${c}" data-term="${t}" data-year="${y}" title="Generate PDF">PDF</button>
        <button class="btn btn-sm btn-outline-secondary metaBtn" data-id="${sid}" data-class="${c}" data-term="${t}" data-year="${y}" title="Edit Meta">Meta</button>
      </div>`;
  }

  /** ------------------------
   * DataTables: Scores
   * ------------------------ **/
  const scoresTable = $('#scoresTable').DataTable({
    processing: true,
    deferRender: true,
    ajax: {
      url: ACTION_URL,
      type: 'POST',
      data: d => ({
        ...d,
        action: 'list_scores_preview',
        class_id: $('#filter_class').val(),
        year_id: $('#filter_year').val(),
        term_id: $('#filter_term').val(),
      }),
      dataSrc: json => json.data || [],
      beforeSend: () => showPreloader(true),
      complete: () => showPreloader(false),
    },
    columns: [
      { data: 'idx', className: 'text-center' },
      { data: 'student' },
      { data: 'subject' },
      { data: 'total', className: 'text-center' },
      { data: 'position', className: 'text-center' },
      { data: 'class_name' },
      { data: 'term' },
      { data: 'year' },
    ],
  });

  /** ------------------------
   * Filter Handling (debounced)
   * ------------------------ **/
  let filterTimeout;
  $('#btnApply').on('click', function (e) {
    e.preventDefault();
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
      studentsTable.ajax.reload(null, true);
      scoresTable.ajax.reload(null, true);
      $('#btnGenClass').prop('disabled', false);
    }, 300);
  });

  /** ------------------------
   * Attendance & Remarks Modal
   * ------------------------ **/
  $(document).on('click', '.metaBtn', function () {
    const sid = $(this).data('id');
    const classId = $(this).data('class');
    const termId = $(this).data('term');
    const yearId = $(this).data('year');

    // Reset fields before fetching
    $('#metaForm')[0].reset();
    $('#meta_student_id').val(sid);
    $('#meta_class_id').val(classId);
    $('#meta_term_id').val(termId);
    $('#meta_year_id').val(yearId);

    showPreloader(true);
    $.post(ACTION_URL, {
      action: 'get_meta',
      student_id: sid,
      class_id: classId,
      term_id: termId,
      year_id: yearId,
    }, function (resp) {
      showPreloader(false);
      if (resp?.success && resp.data) {
        const m = resp.data;
        for (const [key, val] of Object.entries(m)) {
          $(`#${key}`).val(val);
        }
      }
      new bootstrap.Modal('#metaModal').show();
    }, 'json').fail(() => {
      showPreloader(false);
      new bootstrap.Modal('#metaModal').show();
    });
  });

  $('#present_days, #total_days').on('input', function () {
    const present = parseInt($('#present_days').val()) || 0;
    const total = parseInt($('#total_days').val()) || 0;
    $('#attendance_percent').val(total ? ((present / total) * 100).toFixed(2) : '0.00');
  });

  $('#saveMetaBtn').on('click', function (e) {
    e.preventDefault();

    const $btn = $(this);
    if ($btn.prop('disabled')) return; // prevent double submit
    $btn.prop('disabled', true);
    showPreloader(true);

    const data = $('#metaForm').serializeArray();
    data.push({ name: 'action', value: 'save_meta' });

    $.post(ACTION_URL, data, function (resp) {
      showPreloader(false);
      $btn.prop('disabled', false);
      if (resp?.success) {
        toast('Saved successfully', 'success');
        bootstrap.Modal.getInstance('#metaModal')?.hide();
        scoresTable.ajax.reload(null, false);
      } else {
        toast(resp.msg || 'Save failed', 'danger');
      }
    }, 'json').fail(() => {
      showPreloader(false);
      $btn.prop('disabled', false);
      toast('Server error while saving', 'danger');
    });
  });

  /** ------------------------
   * Report Preview & Generation
   * ------------------------ **/
  $(document).on('click', '.previewReportBtn, .genPdfBtn', function () {
    const sid = $(this).data('id');
    const c = $(this).data('class');
    const t = $(this).data('term');
    const y = $(this).data('year');
    const isPdf = $(this).hasClass('genPdfBtn');

    if (!sid || !c || !t || !y) {
      toast('Missing student/class/term/year info', 'danger');
      return;
    }

    const params = $.param({
      student_id: sid,
      class_id: c,
      term_id: t,
      year_id: y,
      ...(isPdf && { pdf: 1 }),
    });
    // show the site preloader briefly while the new tab/window is being opened
    showPreloader(true);
    window.open(`${RENDER_URL}?${params}`, '_blank');
    // hide after a short delay (new tab will render independently)
    setTimeout(() => showPreloader(false), 900);
  });

  /** ------------------------
   * Class Report Preview / Generate
   * ------------------------ **/
  // Preview class (open render_student_report.php which supports ?preview=class)
  $('#previewClassBtn').off('click').on('click', function (e) {
    e.preventDefault();
    const classId = $('#filter_class').val();
    const termId = $('#filter_term').val();
    const yearId = $('#filter_year').val();
    if (!classId || !termId || !yearId) { toast('Please select class, term, and year first', 'danger'); return; }
    const params = $.param({ preview: 'class', class_id: classId, term_id: termId, year_id: yearId });
    window.open(`${RENDER_URL}?${params}`, '_blank');
  });

  // Generate class PDF (POST to report_action.php so server runs generate_class_pdf)
  $('#btnGenClass').off('click').on('click', function (e) {
    e.preventDefault();
    const classId = $('#filter_class').val();
    const termId = $('#filter_term').val();
    const yearId = $('#filter_year').val();
    if (!classId || !termId || !yearId) { toast('Please select class, term, and year first', 'danger'); return; }

    // create and submit a hidden form to ACTION_URL (POST) with target _blank
    const $form = $('<form/>', { method: 'POST', action: ACTION_URL, target: '_blank', style: 'display:none' });
    $form.append($('<input>').attr({ type: 'hidden', name: 'csrf_token', value: CSRF }));
    $form.append($('<input>').attr({ type: 'hidden', name: 'action', value: 'generate_class_pdf' }));
    $form.append($('<input>').attr({ type: 'hidden', name: 'class_id', value: classId }));
    $form.append($('<input>').attr({ type: 'hidden', name: 'term_id', value: termId }));
    $form.append($('<input>').attr({ type: 'hidden', name: 'year_id', value: yearId }));
    $('body').append($form);
    // show the preloader while the generation request is sent
    showPreloader(true);
    $form[0].submit();
    // remove and hide preloader after a short grace period (new window will handle PDF)
    setTimeout(() => { $form.remove(); showPreloader(false); }, 2000);
  });
});
