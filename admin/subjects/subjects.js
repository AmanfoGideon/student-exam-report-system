$(function () {
  const toastContainer = $('#toastContainer');
  const showToast = (message, type = 'success') => {
    const toastId = 'toast' + Date.now();
    const toastHTML = `
      <div class="toast align-items-center text-bg-${type} border-0" id="${toastId}" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body">${message}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>`;
    toastContainer.append(toastHTML);
    const bsToast = new bootstrap.Toast(document.getElementById(toastId), { delay: 3000 });
    bsToast.show();
    bsToast._element.addEventListener('hidden.bs.toast', () => $('#' + toastId).remove());
  };

  if ($.fn.selectpicker) $('.selectpicker').selectpicker();

  // === FETCH SUBJECTS TABLE ===
  const table = $('#subjectsTable').DataTable({
    ajax: {
      url: 'subject_action.php',
      type: 'GET',
      data: { action: 'fetch' },
      dataSrc: json => (json?.data || []).map((row, idx) => ({
        idx: idx + 1,
        id: row.id,
        subject_code: row.subject_code || '',
        name: row.name,
        description: row.description || '',
        classes: (row.classes || []).join(', '),
        teachers: (row.teachers || []).join(', ')
      }))
    },
    columns: [
      { data: 'idx', width: '5%' },
      { data: 'subject_code', width: '10%' },
      { data: 'name', width: '25%' },
      { data: 'description', width: '20%' },
      { data: 'classes', width: '15%' },
      { data: 'teachers', width: '15%' },
      {
        data: 'id',
        orderable: false,
        searchable: false,
        width: '10%',
        render: data => `
          <div class="btn-group">
            <button class="btn btn-sm btn-primary btn-edit" data-id="${data}"><i class="fa fa-edit"></i></button>
            <button class="btn btn-sm btn-warning btn-assign-teacher" data-id="${data}"><i class="fa fa-user-plus"></i></button>
            <button class="btn btn-sm btn-info btn-assign-class" data-id="${data}"><i class="fa fa-chalkboard"></i></button>
            <button class="btn btn-sm btn-danger btn-delete" data-id="${data}"><i class="fa fa-trash"></i></button>
          </div>`
      }
    ],
    pageLength: 10,
    responsive: true,
    dom: 'Bfrtip',
    buttons: [
      { extend: 'csvHtml5', text: '<i class="fa fa-file-csv"></i> CSV' },
      { extend: 'excelHtml5', text: '<i class="fa fa-file-excel"></i> Excel' },
      { extend: 'print', text: '<i class="fa fa-print"></i> Print' }
    ],
    language: { emptyTable: 'No subjects found' }
  });

  $('#subjectSearch').on('input', function () {
    table.search(this.value).draw();
  });

  // === ADD NEW SUBJECT ===
  $('#btnAddSubject').click(() => {
    $('#subjectForm')[0].reset();
    $('#subject_id').val('');
    $('#subjectModalTitle').text('Add Subject');

    // load classes and teachers dynamically
    $.get('subject_action.php', { action: 'load_lists' }, res => {
      if (res.success) {
        const { classes, teachers } = res.data;
        const $classSel = $('#classesSelect').empty();
        const $teacherSel = $('#teachersSelectMain').empty();
        classes.forEach(c => $classSel.append(`<option value="${c.id}">${c.name}</option>`));
        teachers.forEach(t => $teacherSel.append(`<option value="${t.id}">${t.full_name}</option>`));
        if ($.fn.selectpicker) $('.selectpicker').selectpicker('refresh');
      }
    }, 'json');

    new bootstrap.Modal(document.getElementById('subjectModal')).show();
  });

  // === EDIT SUBJECT ===
  $('#subjectsTable tbody').on('click', '.btn-edit', function () {
    const id = $(this).data('id');
    $.get('subject_action.php', { action: 'get', id }, res => {
      if (!res.success) return showToast(res.message || 'Could not fetch subject', 'danger');
      const s = res.data;
      $('#subject_id').val(s.id);
      $('#subject_code').val(s.subject_code || '');
      $('#subject_name').val(s.name || '');
      $('#subject_description').val(s.description || '');
      $.get('subject_action.php', { action: 'load_lists' }, resp => {
        if (resp.success) {
          const { classes, teachers } = resp.data;
          const $classSel = $('#classesSelect').empty();
          const $teacherSel = $('#teachersSelectMain').empty();
          classes.forEach(c => $classSel.append(`<option value="${c.id}">${c.name}</option>`));
          teachers.forEach(t => $teacherSel.append(`<option value="${t.id}">${t.full_name}</option>`));
          if ($.fn.selectpicker) {
            $('#classesSelect').selectpicker('val', s.class_ids || []).selectpicker('refresh');
            $('#teachersSelectMain').selectpicker('val', s.teacher_ids || []).selectpicker('refresh');
          }
        }
      }, 'json');
      $('#subjectModalTitle').text('Edit Subject');
      new bootstrap.Modal(document.getElementById('subjectModal')).show();
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // === SAVE SUBJECT ===
  $('#subjectForm').submit(function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'save');
    $.ajax({
      url: 'subject_action.php',
      method: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      dataType: 'json',
      success: res => {
        if (!res.success) return showToast(res.message, 'danger');
        showToast(res.message || 'Subject saved');
        bootstrap.Modal.getInstance(document.getElementById('subjectModal')).hide();
        table.ajax.reload(null, false);
      },
      error: () => showToast('Server error', 'danger')
    });
  });

  // === DELETE SUBJECT ===
  $('#subjectsTable tbody').on('click', '.btn-delete', function () {
    const id = $(this).data('id');
    if (!confirm('Delete this subject?')) return;
    $.post('subject_action.php', { action: 'delete', id }, res => {
      if (res.success) {
        table.ajax.reload(null, false);
        showToast('Subject deleted');
      } else showToast(res.message || 'Delete failed', 'danger');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // === ASSIGN TEACHER ===
  $('#subjectsTable tbody').on('click', '.btn-assign-teacher', function () {
    const id = $(this).data('id');
    $('#assignTeacherSubjectId').val(id);
    const $sel = $('#teachersSelectAssign').empty().append('<option disabled selected>Loading...</option>');
    if ($.fn.selectpicker) $sel.selectpicker('refresh');

    $.get('subject_action.php', { action: 'list_teachers', subject_id: id }, res => {
      $sel.empty();
      if (res.success && Array.isArray(res.data)) {
        res.data.forEach(t => $sel.append(`<option value="${t.id}">${t.full_name}</option>`));
        if ($.fn.selectpicker) $sel.selectpicker('val', res.selected_ids || []).selectpicker('refresh');
      } else $sel.append('<option disabled>No teachers found</option>');
    }, 'json');

    new bootstrap.Modal(document.getElementById('assignTeacherModal')).show();
  });

  $('#assignTeacherForm').submit(function (e) {
    e.preventDefault();
    const data = $(this).serialize() + '&action=assign_teacher';
    $.post('subject_action.php', data, res => {
      if (!res.success) return showToast(res.message || 'Assign failed', 'danger');
      bootstrap.Modal.getInstance(document.getElementById('assignTeacherModal')).hide();
      table.ajax.reload(null, false);
      showToast(res.message || 'Teacher(s) assigned');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });

  // === ASSIGN CLASS ===
  $('#subjectsTable tbody').on('click', '.btn-assign-class', function () {
    const id = $(this).data('id');
    $('#assignClassSubjectId').val(id);
    const $sel = $('#assignClassSelect').empty().append('<option disabled selected>Loading...</option>');
    if ($.fn.selectpicker) $sel.selectpicker('refresh');

    $.get('subject_action.php', { action: 'list_classes', subject_id: id }, res => {
      $sel.empty();
      if (res.success && Array.isArray(res.data)) {
        res.data.forEach(c => $sel.append(`<option value="${c.id}">${c.name}</option>`));
        if ($.fn.selectpicker) $sel.selectpicker('val', res.selected_ids || []).selectpicker('refresh');
      } else $sel.append('<option disabled>No classes found</option>');
    }, 'json');

    new bootstrap.Modal(document.getElementById('assignClassModal')).show();
  });

  $('#assignClassForm').submit(function (e) {
    e.preventDefault();
    $.post('subject_action.php', $(this).serialize() + '&action=assign_class', res => {
      if (!res.success) return showToast(res.message || 'Assign failed', 'danger');
      bootstrap.Modal.getInstance(document.getElementById('assignClassModal')).hide();
      table.ajax.reload(null, false);
      showToast(res.message || 'Class(es) assigned');
    }, 'json').fail(() => showToast('Server error', 'danger'));
  });
});
