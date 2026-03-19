$(document).ready(function () {
  // Initialize DataTable
  let table = $("#usersTable").DataTable({
    ajax: {
      url: "user_action.php",
      type: "POST",
      data: function (d) {
        d.action = "list";
        d.role_id = $("#roleFilter").val(); // role filter
        d.search_value = $("#userSearch").val(); // search input
      },
      dataSrc: ""
    },
    columns: [
      { data: "id" },
      { data: "full_name" },
      { data: "username" },
      { data: "email" },
      { data: "phone" },
      { data: "role_name" },
      {
        data: null,
        render: function (data) {
          return `
            <button class="btn btn-sm btn-warning editUser" data-id="${data.id}">
              <i class="fa fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-danger deleteUser" data-id="${data.id}">
              <i class="fa fa-trash"></i>
            </button>
          `;
        }
      }
    ],
    dom: "Bfrtip",
    buttons: ["csv", "excel", "print"],
    responsive: true,
    destroy: true
  });

  // Reload table when filter/search changes
  $("#roleFilter").on("change", function () {
    table.ajax.reload();
  });

  $("#userSearch").on("keyup", function () {
    table.ajax.reload();
  });

  // Show Add Modal
  $("#btnAddUser").on("click", function () {
    $("#userForm")[0].reset();
    $("#user_id").val("");
    $("#userModalTitle").text("Add User");
    $("#userModal").modal("show");
  });

  // Submit Add/Edit User
  $("#userForm").on("submit", function (e) {
    e.preventDefault();
    $.ajax({
      url: "user_action.php",
      type: "POST",
      data: $(this).serialize() + "&action=save",
      success: function (res) {
        try {
          let json = JSON.parse(res);
          if (json.status === "success") {
            $("#userModal").modal("hide");
            table.ajax.reload();
          } else {
            $("#userFormAlert").html(
              `<div class="alert alert-danger">${json.message}</div>`
            );
          }
        } catch (e) {
          alert("Unexpected response: " + res);
        }
      }
    });
  });

  // Edit User
  $("#usersTable").on("click", ".editUser", function () {
    let id = $(this).data("id");
    console.log("Edit button clicked for ID:", id); // Debugging line
    $.post("user_action.php", { action: "get", id: id }, function (res) {
      console.log("AJAX response for get user:", res); // Debugging line
      let json = JSON.parse(res);
      if (json.status === "success") {
        let u = json.data;
        $("#user_id").val(u.id);
        $("#first_name").val(u.first_name);
        $("#last_name").val(u.last_name);
        $("#username").val(u.username);
        $("#email").val(u.email);
        $("#phone").val(u.phone);
        $("#role_id").val(u.role_id);
        $("#password").val("");
        $("#userModalTitle").text("Edit User");
        $("#userModal").modal("show");
        console.log("User data loaded, showing modal."); // Debugging line
      }
    });
  });

  // Delete User
  $("#usersTable").on("click", ".deleteUser", function () {
    if (!confirm("Delete this user?")) return;
    let id = $(this).data("id");
    $.post("user_action.php", { action: "delete", id: id }, function (res) {
      let json = JSON.parse(res);
      if (json.status === "success") {
        table.ajax.reload();
      } else {
        alert(json.message || "Failed to delete user.");
      }
    });
  });
});
