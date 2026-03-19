// admin/assets/js/classes.js
$(function () {
  const API = "class_action.php";

  // small toast helper (hoisted so available to handlers below)
  function showToast(message, type = 'success') {
    const id = 'sysToast-' + Date.now();
    const el = document.createElement('div');
    el.id = id;
    el.className = `toast align-items-center text-bg-${type} border-0 position-fixed bottom-0 end-0 m-3`;
    el.role = 'alert';
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    document.body.appendChild(el);
    const bsToast = new bootstrap.Toast(el, { delay: 3500 });
    bsToast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  // init selectpicker
  if ($.fn.selectpicker) $('.selectpicker').selectpicker();

  // Initialize DataTable centrally (used by the module)
  const table = $('#classesTable').DataTable({
    ajax: { url: API, type: 'GET', data: { action: 'fetch' } },
    columns: [
      { data: null, render: (d, t, row, meta) => meta.row + 1 },
      { data: 'class_name' },
      { data: 'stream' },
      { data: 'year_label' },
      { data: 'subjects', render: d => (d && d.length ? d.join(', ') : '—') },
      { data: 'teachers', render: d => (d && d.length ? d.join(', ') : '—') },
      { data: 'students_count' },
      {
        data: 'id',
        render: function (id, type, row) {
          return `
            <div class="btn-group">
              <button class="btn btn-sm btn-primary btn-edit" data-id="${id}"><i class="fa fa-edit"></i></button>
              <button class="btn btn-sm btn-warning btn-assign-subject" data-id="${id}"><i class="fa fa-book"></i></button>
              <button class="btn btn-sm btn-info btn-assign-students" data-id="${id}"><i class="fa fa-users"></i></button>
              <button class="btn btn-sm btn-success btn-promote" data-id="${id}" data-name="${row.class_name}"><i class="fa fa-arrow-up"></i></button>
              <button class="btn btn-sm btn-danger btn-delete" data-id="${id}"><i class="fa fa-trash"></i></button>
            </div>`;
        }
      }
    ],
    dom: 'Bfrtip',
    buttons: [
      { extend: 'excelHtml5', className: 'btn btn-success btn-sm', text: '<i class="fa fa-file-excel"></i> Excel' },
      { extend: 'pdfHtml5', className: 'btn btn-danger btn-sm', text: '<i class="fa fa-file-pdf"></i> PDF' },
      { extend: 'print', className: 'btn btn-secondary btn-sm', text: '<i class="fa fa-print"></i> Print' }
    ],
    responsive: true,
    pageLength: 10,
    ordering: false
  });

  // expose table globally if other scripts need it
  window.classesTable = table;

  // Allow external code to request a reload via custom event
  $(document).on('dataReloaded', function () {
    table.ajax.reload(null, false);
  });

  // Wire search box to DataTable
  $('#classSearch').on('keyup', function () {
    table.search(this.value).draw();
  });

  // btnAddClass -> open modal for adding
  $(document).on('click', '#btnAddClass', function () {
    $('#classForm')[0].reset();
    $('#class_id').val('');
    $('#classModalTitle').text('Add Class');
    $('#classModal').modal('show');
  });

  // DataTable initialization handled in classes.php inline script.
  // We'll wire UI actions here: add/edit/delete, assign subject/students, promote/transfer.

  // ADD / EDIT CLASS (uses existing modals)
  $(document).on('submit', '#classForm', function (e) {
    e.preventDefault();
    const form = $(this);
    const data = form.serialize() + '&action=save';
    $.post(API, data, function (res) {
      if (!res.success) return showToast(res.message || 'Save failed', 'danger');
      $('#classModal').modal('hide');
      $(document).trigger('dataReloaded');
      showToast(res.message || 'Class saved');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // EDIT class
  $(document).on('click', '.btn-edit', function () {
    const id = $(this).data('id');
    $.get(API, { action: 'get', id }, function (res) {
      if (!res.success) return showToast(res.message || 'Could not fetch', 'danger');
      const c = res.data;
      $('#class_id').val(c.id);
      $('#class_name').val(c.class_name);
      $('#class_stream').val(c.stream);
      $('#class_year').val(c.year_id || '');
      $('#classModalTitle').text('Edit Class');
      $('#classModal').modal('show');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // DELETE
  $(document).on('click', '.btn-delete', function () {
    const id = $(this).data('id');
    if (!confirm('Delete class? This will remove mappings and unassign students.')) return;
    $.post(API, { action: 'delete', id }, function (res) {
      if (!res.success) return showToast(res.message || 'Delete failed', 'danger');
      $(document).trigger('dataReloaded');
      showToast(res.message || 'Class deleted');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // ASSIGN SUBJECT (same pattern as before)
  $(document).on('click', '.btn-assign-subject', function () {
    const id = $(this).data('id');
    $('#assignSubjectClassId').val(id);
    $('#assignSubjectTeachers').empty();
    $('#assignSubjectSelect').empty().append('<option>Loading...</option>');
    $.get(API, { action: 'fetch_subjects' }, function (res) {
      $('#assignSubjectSelect').empty();
      if (res.success) {
        $('#assignSubjectSelect').append('<option value="">-- Select Subject --</option>');
        res.data.forEach(s => $('#assignSubjectSelect').append(`<option value="${s.id}">${s.name}</option>`));
      } else {
        $('#assignSubjectSelect').append('<option value="">No subjects</option>');
      }
    }, 'json').fail(() => { $('#assignSubjectSelect').empty().append('<option value="">Error</option>'); });

    if ($.fn.selectpicker) $('#assignSubjectTeachers').selectpicker('deselectAll').selectpicker('refresh');
    $('#assignSubjectModal').modal('show');
  });

  $('#assignSubjectForm').on('submit', function (e) {
    e.preventDefault();
    const data = $(this).serialize() + '&action=assign_subject';
    $.post(API, data, function (res) {
      if (!res.success) return showToast(res.message || 'Assign failed', 'danger');
      $('#assignSubjectModal').modal('hide');
      $(document).trigger('dataReloaded');
      showToast(res.message || 'Assigned');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // When subject selected in assign subject modal, fetch teachers with assigned flag
  $(document).on('change', '#assignSubjectSelect', function () {
    const sid = $(this).val();
    const $sel = $('#assignSubjectTeachers');
    $sel.empty();
    if (!sid) { if ($.fn.selectpicker) $sel.selectpicker('refresh'); return; }
    $.get(API, { action: 'fetch_teachers', subject_id: sid }, function (res) {
      if (!res.success) { showToast(res.message || 'No teachers', 'warning'); return; }
      res.data.forEach(t => {
        $sel.append(`<option value="${t.id}" ${t.assigned ? 'selected' : ''}>${t.first_name} ${t.last_name}</option>`);
      });
      if ($.fn.selectpicker) $sel.selectpicker('refresh');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // ASSIGN STUDENTS (lazy load select options)
  $(document).on('click', '.btn-assign-students', function () {
    const id = $(this).data('id');
    $('#assignStudentsClassId').val(id);
    const $sel = $('#assignStudentsSelect');
    $sel.empty().append('<option>Loading...</option>');
    $.get(API, { action: 'fetch_unassigned_students' }, function (res) {
      $sel.empty();
      if (res.success) {
        res.data.forEach(s => $sel.append(`<option value="${s.id}">${(s.admission_no ? s.admission_no + ' — ' : '')}${s.first_name} ${s.last_name}</option>`));
        if ($.fn.selectpicker) $sel.selectpicker('refresh');
      } else {
        $sel.append('<option value="">No students</option>');
      }
    }, 'json').fail(() => { $sel.empty().append('<option value="">Error</option>'); if ($.fn.selectpicker) $sel.selectpicker('refresh'); });
    $('#assignStudentsModal').modal('show');
  });

  $('#assignStudentsForm').on('submit', function (e) {
    e.preventDefault();
    $.post(API, $(this).serialize() + '&action=assign_students', function (res) {
      if (!res.success) return showToast(res.message || 'Assign failed', 'danger');
      $('#assignStudentsModal').modal('hide');
      $(document).trigger('dataReloaded');
      showToast(res.message || 'Students assigned');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // PROMOTE / TRANSFER
  $(document).on('click', '.btn-promote', function () {
    const fromId = $(this).data('id');
    const fromName = $(this).data('name') || '';
    $('#promoteFromClassId').val(fromId);
    $('#promoteFromClassName').text(fromName);

    // clear modal
    $('#promoteStudentsSelect').empty();
    $('#promoteAll').prop('checked', true);
    $('#promotionType').val('promotion');

    // fetch students for this class (used for selective promotions)
    $.get(API, { action: 'fetch_students_for_class', class_id: fromId }, function (res) {
      if (!res.success) {
        showToast(res.message || 'No students', 'warning');
      } else {
        res.data.forEach(s => {
          $('#promoteStudentsSelect').append(`<option value="${s.id}">${(s.admission_no ? s.admission_no + ' — ' : '')}${s.first_name} ${s.last_name}</option>`);
        });
        if ($.fn.selectpicker) $('#promoteStudentsSelect').selectpicker('refresh');
      }
    }, 'json').fail(() => showToast('Server error', 'danger'));

    $('#promoteModal').modal('show');
  });

  // Toggle promote_all when students are selected/deselected
  $(document).on('changed.bs.select', '#promoteStudentsSelect', function () {
    const any = $(this).val() && $(this).val().length;
    if (any) $('#promoteAll').prop('checked', false);
  });

  $('#promoteForm').on('submit', function (e) {
    e.preventDefault();
    const data = $(this).serialize() + '&action=promote_transfer';
    $.post(API, data, function (res) {
      if (!res.success) return showToast(res.message || 'Operation failed', 'danger');
      $('#promoteModal').modal('hide');
      $(document).trigger('dataReloaded');
      showToast(res.message || 'Operation completed');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });
});
