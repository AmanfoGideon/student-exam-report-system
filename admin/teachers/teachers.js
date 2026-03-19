document.addEventListener('DOMContentLoaded', function () {
    const teacherModal = new bootstrap.Modal(document.getElementById('teacherModal'));
    const assignModal = new bootstrap.Modal(document.getElementById('assignModal'));
    const teacherForm = document.getElementById('teacherForm');
    const assignForm = document.getElementById('assignForm');
    const btnAddTeacher = document.getElementById('btnAddTeacher');
    const teacherSearch = document.getElementById('teacherSearch');
    const modalTitle = document.getElementById('teacherModalTitle');
    const pageLoader = document.getElementById('pageLoader');
    const toastContainer = document.getElementById('toastContainer');

    let teachers = [];
    let table;

    // === Loader ===
    function showLoader() {
        if (!pageLoader) return;
        pageLoader.classList.add('show');
        pageLoader.classList.remove('hide');
    }
    function hideLoader() {
        if (!pageLoader) return;
        pageLoader.classList.add('hide');
        setTimeout(() => pageLoader.classList.remove('show'), 400);
    }

    // === Toast Notification ===
    function showToast(message, type = 'success') {
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center text-bg-${type} border-0 mb-2 shadow`;
        toastEl.role = 'alert';
        toastEl.innerHTML = `<div class="d-flex"><div class="toast-body fw-semibold">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        toastContainer.appendChild(toastEl);
        const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    // === Load teachers from backend ===
    function loadTeachers() {
        showLoader();
        fetch('teacher_action.php?action=list')
            .then(res => res.json())
            .then(data => {
                if (data.success && Array.isArray(data.data)) {
                    teachers = data.data;
                    renderTable(teachers);
                } else {
                    teachers = [];
                    renderTable([]);
                    showToast('Failed to load teachers', 'danger');
                }
                hideLoader();
            })
            .catch(() => {
                hideLoader();
                showToast('Failed to load teachers', 'danger');
            });
    }

   // === Render DataTable ===
function renderTable(list) {
    if (table) table.destroy();
    const tbody = document.querySelector('#teachersTable tbody');
    tbody.innerHTML = '';

    if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3 text-muted">No teachers found</td></tr>';
    } else {
        list.forEach((t, i) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${i + 1}</td>
                <td>${t.first_name} ${t.last_name || ''}</td>
                <td>${t.username}</td>
                <td>${t.email || '-'}</td>
                <td>${t.phone || '-'}</td>
                <td>${t.subjects || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-primary me-1 editTeacherBtn" data-id="${t.id}">
                        <i class="fa fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-secondary me-1 assignTeacherBtn" data-id="${t.id}">
                        <i class="fa fa-tasks"></i>
                    </button>
                    <button class="btn btn-sm btn-danger deleteTeacherBtn" data-id="${t.id}">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    // ✅ Responsive DataTable Integration
    table = $('#teachersTable').DataTable({
        responsive: true,
        scrollX: true,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50, 100],
        dom: 'Bfrtip',
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
        destroy: true
    });

    attachRowButtons();
}


    // === Row Buttons ===
    function attachRowButtons() {
        // Edit teacher
        document.querySelectorAll('.editTeacherBtn').forEach(btn => {
            btn.onclick = () => {
                const teacher = teachers.find(t => t.id == btn.dataset.id);
                if (!teacher) return;
                teacherForm.reset();
                modalTitle.textContent = 'Edit Teacher';
                teacherForm.teacher_id.value = teacher.id;
                teacherForm.username.value = teacher.username;
                teacherForm.email.value = teacher.email;
                teacherForm.first_name.value = teacher.first_name;
                teacherForm.last_name.value = teacher.last_name;
                teacherForm.phone.value = teacher.phone;
                teacherModal.show();
            };
        });

        // Assign subjects/classes
        document.querySelectorAll('.assignTeacherBtn').forEach(btn => {
            btn.onclick = () => {
                const teacher = teachers.find(t => t.id == btn.dataset.id);
                if (!teacher) return;
                document.getElementById('assign_teacher_id').value = teacher.id;
                $('#assign_subjects').val((teacher.subject_ids || '').split(',')).trigger('change');
                $('#assign_classes').val((teacher.class_ids || '').split(',')).trigger('change');
                assignModal.show();
            };
        });

        // Delete teacher
        document.querySelectorAll('.deleteTeacherBtn').forEach(btn => {
            btn.onclick = () => {
                if (!confirm('Are you sure you want to delete this teacher?')) return;
                showLoader();
                fetch(`teacher_action.php?action=delete&id=${btn.dataset.id}`)
                    .then(res => res.json())
                    .then(data => {
                        hideLoader();
                        if (data.success) {
                            showToast(data.message, 'success');
                            loadTeachers();
                        } else {
                            showToast(data.message || 'Delete failed', 'danger');
                        }
                    })
                    .catch(() => {
                        hideLoader();
                        showToast('Delete failed', 'danger');
                    });
            };
        });
    }

    // === Search Filter ===
    teacherSearch.addEventListener('input', () => {
        const q = teacherSearch.value.toLowerCase();
        const filtered = teachers.filter(t =>
            t.username.toLowerCase().includes(q) ||
            (t.first_name || '').toLowerCase().includes(q) ||
            (t.last_name || '').toLowerCase().includes(q) ||
            (`${t.first_name} ${t.last_name || ''}`.toLowerCase().includes(q))
        );
        renderTable(filtered);
    });

    // === Add Teacher ===
    btnAddTeacher.addEventListener('click', () => {
        teacherForm.reset();
        modalTitle.textContent = 'Add Teacher';
        document.getElementById('teacher_id').value = '';
        teacherModal.show();
    });

    // === Teacher Form Submit ===
    teacherForm.addEventListener('submit', e => {
        e.preventDefault();
        showLoader();
        const formData = new FormData(teacherForm);
        const action = formData.get('teacher_id') ? 'edit' : 'add';
        fetch(`teacher_action.php?action=${action}`, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                hideLoader();
                if (data.success) {
                    showToast(data.message, 'success');
                    teacherModal.hide();
                    loadTeachers();
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(() => {
                hideLoader();
                showToast('Save failed', 'danger');
            });
    });

    // === Assignment Form Submit ===
    assignForm.addEventListener('submit', e => {
        e.preventDefault();
        showLoader();
        const formData = new FormData(assignForm);
        fetch('teacher_action.php?action=assign', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                hideLoader();
                if (data.success) {
                    showToast(data.message, 'success');
                    assignModal.hide();
                    loadTeachers();
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(() => {
                hideLoader();
                showToast('Assignment failed', 'danger');
            });
    });

    // === Initial Load ===
    loadTeachers();
});
