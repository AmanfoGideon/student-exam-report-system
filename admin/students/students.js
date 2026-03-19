// admin/students/students.js
$(function () {
  const API = 'student_action.php';
  const $table = $('#studentsTable');
  const $toastContainer = $('#toastContainer'); // keep if you have a toast area in your footer/header
  let dt = null;

  /* ----------------------
     Utilities & UI helpers
     ---------------------- */
  function makeToast(msg, type = 'success') {
    // graceful fallback in case no toast container
    const container = $toastContainer.length ? $toastContainer : $('body');
    const bgCls = (type === 'success') ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-info');
    const $t = $(`
      <div class="toast ${bgCls} text-white" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body">${msg}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>
    `);
    container.append($t);
    const bs = new bootstrap.Toast($t[0], { delay: 3500 });
    bs.show();
    $t.on('hidden.bs.toast', () => $t.remove());
  }

  function setBtnLoading($btn, isLoading = true, text = null) {
    if (!$btn || !$btn.length) return;
    if (isLoading) {
      $btn.data('orig-html', $btn.html());
      $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>' + (text ? ' ' + text : ''));
    } else {
      $btn.prop('disabled', false).html($btn.data('orig-html') || $btn.html());
    }
  }

  // small debounce util for search
  function debounce(fn, delay = 250) {
    let t;
    return function () {
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  /* ----------------------
     DataTable initialization
     ---------------------- */
  function destroyTableIfExists() {
    if ($.fn.DataTable.isDataTable('#studentsTable')) {
      // Remove buttons container to avoid duplicates
      try {
        const existing = $table.DataTable();
        if (existing && existing.buttons && typeof existing.buttons === 'function') {
          try { existing.buttons().container().remove(); } catch (err) { /* ignore */ }
        }
      } catch (err) {
        // ignore
      }
      $table.DataTable().destroy();
      // clear table html (optional - keeps header)
      $table.find('tbody').empty();
    }
  }

  function initTable() {
    // ensure we don't accidentally double init
    destroyTableIfExists();

    dt = $table.DataTable({
      serverSide: true,
      processing: true,
      ajax: {
        url: API,
        type: 'GET',
        data: function (d) { d.action = 'fetch'; }
      },
      columns: [
        {
          data: 'id',
          orderable: false,
          searchable: false,
          render: function (id) { return `<input type="checkbox" class="row-checkbox" value="${id}">`; },
          width: '36px'
        },
        {
          data: 'photo',
          orderable: false,
          searchable: false,
          render: function (src) {
            const s = src ? src : '../../assets/images/avatar.png';
            return `<img src="${s}" class="img-small" onerror="this.src='../../assets/images/avatar.png'">`;
          },
          width: '56px'
        },
        { data: 'admission_no' },
        { data: 'full_name' },
        { data: 'gender' },
        { data: 'dob' },
        { data: 'address' },
        { data: 'class_label' },
        { data: 'guardian_name' },
        { data: 'guardian_phone' },
        {
          data: 'id',
          orderable: false,
          searchable: false,
          render: function (id) {
            // compact icon buttons (Bootstrap btn-sm classes expected to be styled by the page)
            return `
              <div class="btn-group" role="group" aria-label="actions">
                <button class="btn btn-sm btn-primary edit-btn" data-id="${id}" title="Edit"><i class="fa fa-edit"></i></button>
                <button class="btn btn-sm btn-danger delete-btn" data-id="${id}" title="Delete"><i class="fa fa-trash"></i></button>
              </div>
            `;
          },
          width: '110px'
        }
      ],
      dom: 'Bfrtip',
      buttons: [
        { extend: 'excelHtml5', text: '<i class="fa fa-file-excel"></i> Excel' },
        { extend: 'pdfHtml5', text: '<i class="fa fa-file-pdf"></i> PDF' },
        { extend: 'print', text: '<i class="fa fa-print"></i> Print' }
      ],
      responsive: true,
      pageLength: 15,
      order: [[3, 'asc']],
      autoWidth: false,
      language: {
        processing: '<div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div> Loading...'
      },
      drawCallback: function (settings) {
        // re-apply any small UI tweaks after draw (tooltips, etc.)
        $('[data-bs-toggle="tooltip"]').tooltip({ boundary: 'viewport' });
      }
    });

    // style the DT Buttons to match Bootstrap (move them into a wrapper)
    try {
      dt.buttons().container().addClass('dt-buttons btn-group mb-2');
      // convert datatables buttons to bootstrap btn classes
      dt.buttons().container().find('button').addClass('btn btn-sm btn-outline-secondary');
    } catch (err) { /* ignore */ }

    // expose globally if needed
    window.studentsDataTable = dt;
  }

  // initialize once
  initTable();

  // convenient reload helper
  function reloadTable(drawReset = false) {
    if (dt && dt.ajax) {
      dt.ajax.reload(null, !drawReset); // keep paging by default
      return;
    }
    // fallback: re-init table if dt missing
    initTable();
  }

  /* ----------------------
     Event handlers (delegated)
     ---------------------- */

  // debounced external search input
  $('#studentSearch').on('input', debounce(function () {
    if (dt) dt.search(this.value).draw();
  }, 250));

  // Select-all behavior
  $(document).on('change', '#selectAllRows', function () {
    const checked = $(this).is(':checked');
    // use DataTable's tbody to find checkboxes
    $table.find('tbody .row-checkbox').prop('checked', checked).trigger('change');
  });

  // single row checkbox toggles row highlight
  $(document).on('change', '#studentsTable tbody .row-checkbox', function () {
    $(this).closest('tr').toggleClass('table-active', $(this).is(':checked'));
  });

  /* ---- Add student (show modal) ---- */
  $('#addStudentBtn').on('click', function () {
    // reset form safely
    const $form = $('#studentForm');
    $form[0].reset();
    $('#student_id').val('');
    $('#existing_photo').val('');
    $('#preview').hide().attr('src', '#');
    $('#formAlert').empty();
    // show modal (bootstrap)
    const modalEl = document.getElementById('studentModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  });

  // image preview for form
  $('#photo').on('change', function () {
    const f = this.files && this.files[0];
    if (!f) { $('#preview').hide(); return; }
    const reader = new FileReader();
    reader.onload = function (e) { $('#preview').attr('src', e.target.result).show(); };
    reader.readAsDataURL(f);
  });

  /* ---- Save student (create/update) ---- */
  $('#studentForm').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#saveStudentBtn');
    const fd = new FormData(this);
    setBtnLoading($btn, true, 'Saving...');
    $.ajax({
      url: API + '?action=save',
      method: 'POST',
      data: fd,
      contentType: false,
      processData: false,
      dataType: 'json'
    }).done(function (res) {
      if (res && res.success) {
        makeToast(res.message || 'Saved', 'success');
        const modalEl = document.getElementById('studentModal');
        bootstrap.Modal.getInstance(modalEl)?.hide();
        reloadTable(false);
      } else {
        const msg = (res && res.message) ? res.message : 'Save failed';
        $('#formAlert').html(`<div class="alert alert-danger">${msg}</div>`);
        makeToast(msg, 'danger');
      }
    }).fail(function () {
      makeToast('Server error', 'danger');
    }).always(function () {
      setBtnLoading($btn, false);
    });
  });

  /* ---- Edit (load single record into modal) ---- */
  $(document).on('click', '.edit-btn', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    setBtnLoading($(this), true);
    $.getJSON(API, { action: 'get', id: id }, function (res) {
      setBtnLoading($('.edit-btn[data-id="' + id + '"]'), false);
      if (!res || !res.success) {
        makeToast(res && res.message ? res.message : 'Record not found', 'danger');
        return;
      }
      const d = res.data || {};
      $('#student_id').val(d.id || '');
      $('#admission_no').val(d.admission_no || '');
      $('#first_name').val(d.first_name || '');
      $('#last_name').val(d.last_name || '');
      $('#gender').val(d.gender || '');
      $('#dob').val(d.dob || '');
      $('#class_id').val(d.class_id || '');
      $('#address').val(d.address || '');
      $('#guardian_name').val(d.guardian_name || '');
      $('#guardian_phone').val(d.guardian_phone || '');
      $('#existing_photo').val(d.photo_path || '');
      if (d.photo_url) { $('#preview').attr('src', d.photo_url).show(); } else { $('#preview').hide(); }
      $('#formAlert').empty();
      const modalEl = document.getElementById('studentModal');
      const modal = new bootstrap.Modal(modalEl);
      modal.show();
    }).fail(function () {
      setBtnLoading($('.edit-btn[data-id="' + id + '"]'), false);
      makeToast('Server error', 'danger');
    });
  });

  /* ---- Delete (with confirm modal) ---- */
  async function showConfirm(title, message, okText = 'Yes', okClass = 'btn-danger') {
    return new Promise((resolve) => {
      $('#confirmTitle').text(title);
      $('#confirmBody').text(message);
      const $ok = $('#confirmOk');
      $ok.text(okText).removeClass('btn-danger btn-primary btn-success btn-warning').addClass(okClass);
      const modalEl = document.getElementById('confirmModal');
      const modal = new bootstrap.Modal(modalEl);
      $('#confirmOk').off('click').on('click', function () { modal.hide(); resolve(true); });
      $('#confirmCancel').off('click').on('click', function () { modal.hide(); resolve(false); });
      modal.show();
    });
  }

  $(document).on('click', '.delete-btn', async function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const ok = await showConfirm('Delete student', 'Delete this student permanently?', 'Delete', 'btn-danger');
    if (!ok) return;
    const $btn = $(this);
    setBtnLoading($btn, true);
    $.post(API, { action: 'delete', id: id }, function (res) {
      if (res && res.success) {
        makeToast(res.message || 'Deleted', 'success');
        reloadTable(false);
      } else {
        makeToast(res && res.message ? res.message : 'Delete failed', 'danger');
      }
    }, 'json').fail(function () { makeToast('Server error', 'danger'); })
      .always(function () { setBtnLoading($btn, false); });
  });

  /* ---- Bulk actions: an approach using selected checkboxes ---- */
  function selectedIds() {
    const ids = [];
    $table.find('tbody .row-checkbox:checked').each(function () { ids.push($(this).val()); });
    return ids;
  }

  $('#promoteBtn').on('click', function () {
    const ids = selectedIds();
    if (!ids.length) return makeToast('Select one or more students', 'info');
    $('#promoteModal').modal('show');
  });

  $('#transferBtn').on('click', function () {
    const ids = selectedIds();
    if (!ids.length) return makeToast('Select one or more students', 'info');
    $('#transferModal').modal('show');
  });

  $('#promoteForm').on('submit', function (e) {
    e.preventDefault();
    const ids = selectedIds();
    const target = $('#promoteTargetClass').val();
    if (!target) return makeToast('Select target class', 'info');
    showConfirm('Confirm promotion', `Promote ${ids.length} student(s) to selected class?`, 'Promote', 'btn-success').then(ok => {
      if (!ok) return;
      const $btn = $(this).find('button[type="submit"]');
      setBtnLoading($btn, true, 'Promoting...');
      $.post(API, { action: 'promote', students: ids, target_class_id: target }, function (res) {
        if (res && res.success) {
          $('#promoteModal').modal('hide');
          makeToast(res.message || 'Promoted', 'success');
          reloadTable(false);
        } else {
          makeToast(res && res.message ? res.message : 'Promotion failed', 'danger');
        }
      }, 'json').fail(function () { makeToast('Server error', 'danger'); })
        .always(function () { setBtnLoading($btn, false); });
    });
  });

  $('#transferForm').on('submit', function (e) {
    e.preventDefault();
    const ids = selectedIds();
    const target = $('#transferTargetClass').val();
    if (!target) return makeToast('Select destination class', 'info');
    showConfirm('Confirm transfer', `Transfer ${ids.length} student(s) to selected class?`, 'Transfer', 'btn-warning').then(ok => {
      if (!ok) return;
      const $btn = $(this).find('button[type="submit"]');
      setBtnLoading($btn, true, 'Transferring...');
      $.post(API, { action: 'transfer', students: ids, target_class_id: target }, function (res) {
        if (res && res.success) {
          $('#transferModal').modal('hide');
          makeToast(res.message || 'Transferred', 'success');
          reloadTable(false);
        } else {
          makeToast(res && res.message ? res.message : 'Transfer failed', 'danger');
        }
      }, 'json').fail(function () { makeToast('Server error', 'danger'); })
        .always(function () { setBtnLoading($btn, false); });
    });
  });

  /* ---- Import flow ---- */
  $('#downloadTemplate').on('click', function () {
    const header = ['admission_no','first_name','last_name','gender','dob','class_id','guardian_name','guardian_phone','address','photo_filename'];
    const csv = header.join(',') + '\n' + 'ADM001,John,Doe,Male,2011-04-01,1,Jane Doe,0240000000,Home,photo.jpg\n';
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'students_template.csv'; document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  });

  $('#importForm').on('submit', function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    const $btn = $('#doImportBtn');
    setBtnLoading($btn, true, 'Importing...');
    $('#importProgress').show();
    $('#importProgressBar').css('width', '0%').text('0%');
    $('#importStatus').text('Uploading and processing...');

    $.ajax({
      url: API + '?action=bulk_import',
      method: 'POST',
      data: fd,
      contentType: false,
      processData: false,
      dataType: 'json',
      xhr: function () {
        const xhr = new window.XMLHttpRequest();
        xhr.upload.addEventListener("progress", function (evt) {
          if (evt.lengthComputable) {
            const percent = Math.round((evt.loaded / evt.total) * 100);
            $('#importProgressBar').css('width', percent + '%').text(percent + '%');
          }
        }, false);
        return xhr;
      }
    }).done(function (res) {
      if (res && res.success) {
        let msg = res.message || 'Import completed';
        if (res.errors && res.errors.length) {
          msg += ' — completed with some errors. Check console for details.';
          console.warn('Import errors', res.errors);
        }
        makeToast(msg, 'success');
        $('#importModal').modal('hide');
        reloadTable(false);
      } else {
        const msg = res && res.message ? res.message : 'Import failed';
        $('#importAlert').html(`<div class="alert alert-danger">${msg}</div>`);
        makeToast(msg, 'danger');
      }
    }).fail(function () {
      makeToast('Server error during import', 'danger');
    }).always(function () {
      setBtnLoading($btn, false);
      setTimeout(function () {
        $('#importProgress').hide();
        $('#importProgressBar').css('width', '0%').text('0%');
      }, 1200);
    });
  });

  /* ---- Load classes for selects dynamically (keeps in sync) ---- */
  function loadClasses() {
    $.getJSON(API, { action: 'fetch_classes' }, function (res) {
      if (!res || !res.success) return;
      let opts = '<option value="">-- Select --</option>';
      res.data.forEach(c => { opts += `<option value="${c.id}">${c.label}</option>`; });
      $('#promoteTargetClass, #transferTargetClass').html(opts);
      const cur = $('#class_id').val();
      $('#class_id').html(opts).val(cur || '');
    });
  }
  loadClasses();

  /* ---- Helpers for external code (optional) ---- */
  window.reloadStudentsTable = reloadTable;
  window.getSelectedStudentIds = selectedIds;

  /* ---- Ensure tooltips are initialized for current page elements ---- */
  $('[data-bs-toggle="tooltip"]').tooltip({ boundary: 'viewport' });

  // End of main
});
