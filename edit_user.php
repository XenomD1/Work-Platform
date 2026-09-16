<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

if (!isAnyAdministrator($_SESSION["role"])) {
    die("Access denied.");
}

$current_user_id = (int)$_SESSION["user_id"];
$current_role = $_SESSION["role"];

$current_user_stmt = $conn->prepare("
    SELECT department
    FROM users
    WHERE id = ?
");
$current_user_stmt->bind_param("i", $current_user_id);
$current_user_stmt->execute();
$current_user_row = $current_user_stmt->get_result()->fetch_assoc();
$current_user_stmt->close();

$current_user_department = $current_user_row["department"] ?? "";

$user_id = intval($_GET["id"] ?? $_POST["id"] ?? 0);

if ($user_id <= 0) {
    die("Invalid user.");
}

/*
 * Load the target user first, so we know its department before
 * deciding whether an IT Administrator is allowed to touch it -
 * both for viewing the form and for processing a save.
 */
$target_stmt = $conn->prepare("
    SELECT id, username, first_name, last_name, email,
           department, role, active
    FROM users
    WHERE id = ?
    LIMIT 1
");
$target_stmt->bind_param("i", $user_id);
$target_stmt->execute();
$target_result = $target_stmt->get_result();

if ($target_result->num_rows !== 1) {
    die("User not found.");
}

$user = $target_result->fetch_assoc();
$target_stmt->close();

if (!canAdministerDepartment($current_role, $current_user_department, $user["department"])) {
    die("Access denied.");
}

/*
 * An IT Administrator's power is scoped to their own department, so
 * they cannot use this form to move a user out of that department,
 * nor to grant unrestricted (non-department-scoped) Administrator
 * access to anyone.
 */
$editable_roles = ["Employee", "Manager", "Administrator", "IT Administrator"];

if ($current_role === "IT Administrator") {
    $editable_roles = ["Employee", "Manager", "IT Administrator"];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $role = $_POST["role"] ?? "Employee";
    $active = isset($_POST["active"]) ? 1 : 0;
    $new_password = $_POST["new_password"] ?? "";

    if ($username === "" || $first_name === "" || $last_name === "" || $email === "") {
        die("Username, first name, last name, and email are required.");
    }

    if (!in_array($role, $editable_roles, true)) {
        die("Invalid role.");
    }

    if ($current_role === "IT Administrator" && $department !== $current_user_department) {
        die("IT Administrators can only manage users within their own department.");
    }

    // Make sure the username/email isn't already used by a different account.

    $stmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE (username = ? OR email = ?)
          AND id != ?
    ");

    $stmt->bind_param("ssi", $username, $email, $user_id);
    $stmt->execute();

    $duplicate_result = $stmt->get_result();

    if ($duplicate_result->num_rows > 0) {
        die("Username or email is already in use by another account.");
    }

    $stmt->close();

    if ($new_password !== "") {

        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            UPDATE users
            SET username = ?,
                first_name = ?,
                last_name = ?,
                email = ?,
                department = ?,
                role = ?,
                active = ?,
                password = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ssssssisi",
            $username,
            $first_name,
            $last_name,
            $email,
            $department,
            $role,
            $active,
            $hashed_password,
            $user_id
        );

    } else {

        $stmt = $conn->prepare("
            UPDATE users
            SET username = ?,
                first_name = ?,
                last_name = ?,
                email = ?,
                department = ?,
                role = ?,
                active = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ssssssii",
            $username,
            $first_name,
            $last_name,
            $email,
            $department,
            $role,
            $active,
            $user_id
        );
    }

    $stmt->execute();
    $stmt->close();

    header("Location: users.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit User</title>

    <link rel="stylesheet" href="app.css?v=19">
</head>

<body>

<div class="page">

<div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item">
            <a href="dashboard.php">Dashboard</a>
        </div>
        <div class="sidebar-item">
            <a href="tasks.php">Tasks</a>
        </div>
        <div class="sidebar-item">
            <a href="projects.php">Projects</a>
        </div>
        <div class="sidebar-item">
            <a href="calendar.php">Calendar</a>
        </div>
        <div class="sidebar-item">
            <a href="events.php">Meetings / Events</a>
        </div>
        <div class="sidebar-item">
            <a href="teams.php">Teams</a>
        </div>
        <div class="sidebar-item">
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item">
            <a href="training.php">Training</a>
        </div>
        <div class="sidebar-item">
            <a href="time_off.php">Time Off</a>
        </div>
        <div class="sidebar-item">
            <a href="notifications.php">Notifications</a>
        </div>

        <div class="sidebar-item active">
            <a href="users.php">User Management</a>
        </div>

        <div class="sidebar-bottom">
            <div class="sidebar-item"><a href="account.php">Account</a></div>
            <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
</div>

<main class="main">

    <div class="header">
        <h1>Edit User</h1>
        <a class="button" href="users.php">BACK TO USERS</a>
    </div>

    <div class="content">

    <div class="card">

    <form method="post">

        <div class="form-row">
            <label>Username</label>
            <input type="text" name="username"
                   value="<?php echo htmlspecialchars($user["username"]); ?>">
        </div>

        <div class="form-row">
            <label>First Name</label>
            <input type="text" name="first_name"
                   value="<?php echo htmlspecialchars($user["first_name"]); ?>">
        </div>

        <div class="form-row">
            <label>Last Name</label>
            <input type="text" name="last_name"
                   value="<?php echo htmlspecialchars($user["last_name"]); ?>">
        </div>

        <div class="form-row">
            <label>Email</label>
            <input type="email" name="email"
                   value="<?php echo htmlspecialchars($user["email"]); ?>">
        </div>

        <div class="form-row">
	<label>New Password</label>
	<input type="password" name="new_password">

	<p class="help-text">
    	    Leave blank to keep the current password.
	</p>
        </div>

        <div class="form-row">
        <label>Department</label>
        <?php if ($current_role === "IT Administrator"): ?>
            <input type="text" name="department"
                   value="<?php echo htmlspecialchars($current_user_department); ?>"
                   readonly>
        <?php else: ?>
            <input type="text" name="department"
                   value="<?php echo htmlspecialchars($user["department"]); ?>">
        <?php endif; ?>
        </div>

        <div class="form-row">
        <label>Role</label>
        <select name="role">
            <?php foreach ($editable_roles as $role_option): ?>
                <option value="<?php echo htmlspecialchars($role_option); ?>"
                    <?php if ($user["role"] === $role_option) echo "selected"; ?>>
                    <?php echo htmlspecialchars($role_option); ?>
                </option>
            <?php endforeach; ?>
        </select>
        </div>

        <div class="form-row">
        <label style="display:flex; align-items:center; gap:7px; font-weight:400;">
            <input type="checkbox" name="active" style="width:auto;"
                   <?php if ($user["active"]) echo "checked"; ?>>
            Active
        </label>
        </div>

        <div class="buttons">
            <button type="submit" class="btn-primary">Save User</button>
            <a href="users.php" class="button">Cancel</a>
        </div>

    </form>

    </div>

    </div>

</main>

</div>

</body>
</html>