
$(document).ready(function () {
    // Initialize DataTable with server-side processing
    var table = $('#studentsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: "student_action.php",
            type: "POST",
            data: { action: "fetch" }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'pdf', 'print'],
        columns: [
            { data: 0 }, // ID
            { data: 1 }, // First Name
            { data: 2 }, // Last Name
            { data: 3 }, // DOB
            { data: 4 }, // Address
            { data: 5 }, // Class
            { data: 6 }, // Guardian Name
            { data: 7 }, // Guardian Phone
            { data: 8 }, // Guardian Address
            { data: 9, orderable: false, searchable: false }, // Photo
            { data: 10, orderable: false, searchable: false } // Actions
        ]
    });

    // Reset modal when opening
    $('#addStudentBtn').on('click', function () {
        $('#studentForm')[0].reset();
        $('#studentId').val('');
        $('#studentPhotoPreview').attr('src', '').hide();
        $('#studentModal .modal-title').text('Add Student');
        $('#studentModal').modal('show');
    });

    // Photo preview
    $('#photo').on('change', function () {
        const file = this.files[0];
        if (file && file.type.match('image.*')) {
            const reader = new FileReader();
            reader.onload = function (e) {
                $('#studentPhotoPreview').attr('src', e.target.result).show();
            };
            reader.readAsDataURL(file);
        }
    });

    // Add/Edit submit
    $('#studentForm').on('submit', function (e) {
        e.preventDefault();

        // DOB validation
        let dob = $('#dob').val();
        if (dob) {
            let dobDate = new Date(dob);
            let today = new Date();
            if (dobDate > today) {
                alert("Date of Birth cannot be in the future.");
                return false;
            }
        }

        var formData = new FormData(this);
        var action = $('#studentId').val() ? 'edit' : 'add';
        formData.append('action', action);

        $.ajax({
            url: "student_action.php",
            method: "POST",
            data: formData,
            contentType: false,
            processData: false,
            success: function (data) {
                $('#studentModal').modal('hide');
                table.ajax.reload();
            },
            error: function () {
                alert("Error saving student.");
            }
        });
    });

    // Edit student
    $('#studentsTable').on('click', '.editStudent', function () {
        var id = $(this).data('id');
        $.ajax({
            url: "student_action.php",
            method: "POST",
            data: { action: 'getStudent', id: id },
            dataType: "json",
            success: function (student) {
                $('#studentId').val(student.id);
                $('#first_name').val(student.first_name);
                $('#last_name').val(student.last_name);
                $('#dob').val(student.dob);
                $('#address').val(student.address);
                $('#class_id').val(student.class_id);
                $('#guardian_name').val(student.guardian_name);
                $('#guardian_phone').val(student.guardian_phone);
                $('#guardian_address').val(student.guardian_address);
                
                if (student.photo) {
                    $('#studentPhotoPreview')
                        .attr('src', '/uploads/students/' + student.photo)
                        .show();
                } else {
                    $('#studentPhotoPreview').hide();
                }

                $('#studentModal .modal-title').text('Edit Student');
                $('#studentModal').modal('show');
            }
        });
    });

    // Delete student
    $('#studentsTable').on('click', '.deleteStudent', function () {
        if (!confirm("Are you sure you want to delete this student?")) return;

        var id = $(this).data('id');
        $.ajax({
            url: "student_action.php",
            method: "POST",
            data: { action: 'delete', id: id },
            success: function () {
                table.ajax.reload();
            }
        });
    });
});

