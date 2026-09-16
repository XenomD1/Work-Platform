<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require_once "db.php";
require_once "role_helpers.php";

if (!isAnyAdministrator($_SESSION["role"])) {
    die("Access denied.");
}

$current_role = $_SESSION["role"];
$current_user_id = (int)$_SESSION["user_id"];

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

/*
 * An IT Administrator's power is scoped to their own department, so
 * they cannot create an Administrator account (unrestricted access)
 * or a user in a different department.
 */
$creatable_roles = ["Employee", "Manager", "Administrator", "IT Administrator"];

if ($current_role === "IT Administrator") {
    $creatable_roles = ["Employee", "Manager", "IT Administrator"];
}

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $role = $_POST["role"] ?? "Employee";
    $active = isset($_POST["active"]) ? 1 : 0;

    if ($current_role === "IT Administrator") {
        $department = $current_user_department;
    }

    if (
        $username === "" ||
        $password === "" ||
        $first_name === "" ||
        $last_name === "" ||
        $email === ""
    ) {
        $message = "Please fill in all required fields.";
        $message_type = "error";
    } elseif (!in_array($role, $creatable_roles, true)) {
        $message = "Invalid role.";
        $message_type = "error";
    } else {

        // Check whether username or email already exists
        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE username = ? OR email = ?
        ");

        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            $message = "Username or email already exists.";
            $message_type = "error";

        } else {

            // Securely hash the password
            $password_hash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare("
                INSERT INTO users
                (
                    username,
                    password,
                    first_name,
                    last_name,
                    email,
                    department,
                    role,
                    active
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "sssssssi",
                $username,
                $password_hash,
                $first_name,
                $last_name,
                $email,
                $department,
                $role,
                $active
            );

            if ($stmt->execute()) {
                $message = "User account created successfully.";
                $message_type = "success";

                // Clear the form after successful creation
                $username = "";
                $first_name = "";
                $last_name = "";
                $email = "";
                $department = "";
                $role = "Employee";
                $active = 1;

            } else {
                $message = "Error creating user account.";
                $message_type = "error";
            }
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Create User</title>

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
        <h1>Create User</h1>
        <a class="button" href="users.php">BACK TO USERS</a>
    </div>

    <div class="content">

    <?php if ($message !== ""): ?>
        <div class="message <?= htmlspecialchars($message_type) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">

    <form method="POST">

        <div class="form-row">
            <label>Username *</label>
            <input
                type="text"
                name="username"
                value="<?= htmlspecialchars($username ?? "") ?>"
                required
            >
        </div>

        <div class="form-row">
            <label>Password *</label>
            <input
                type="password"
                name="password"
                required
            >
        </div>

        <div class="form-row">
            <label>First Name *</label>
            <input
                type="text"
                name="first_name"
                value="<?= htmlspecialchars($first_name ?? "") ?>"
                required
            >
        </div>

        <div class="form-row">
            <label>Last Name *</label>
            <input
                type="text"
                name="last_name"
                value="<?= htmlspecialchars($last_name ?? "") ?>"
                required
            >
        </div>

        <div class="form-row">
            <label>Email *</label>
            <input
                type="email"
                name="email"
                value="<?= htmlspecialchars($email ?? "") ?>"
                required
            >
        </div>

        <div class="form-row">
            <label>Department</label>
            <?php if ($current_role === "IT Administrator"): ?>
                <input
                    type="text"
                    name="department"
                    value="<?= htmlspecialchars($current_user_department) ?>"
                    readonly
                >
            <?php else: ?>
                <input
                    type="text"
                    name="department"
                    value="<?= htmlspecialchars($department ?? "") ?>"
                >
            <?php endif; ?>
        </div>

        <div class="form-row">
            <label>Role</label>
            <select name="role">
                <?php foreach ($creatable_roles as $role_option): ?>
                    <option value="<?= htmlspecialchars($role_option) ?>"
                        <?= ($role ?? "Employee") === $role_option ? "selected" : "" ?>>
                        <?= htmlspecialchars($role_option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row checkbox-row">
            <input
                type="checkbox"
                name="active"
                id="active"
                <?= ($active ?? 1) ? "checked" : "" ?>
            >

            <label for="active">Account Active</label>
        </div>

        <button type="submit" class="btn-primary">
            Create User
        </button>

    </form>

    </div>

    </div>

</main>

</div>

</body>
</html>