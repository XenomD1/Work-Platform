<?php
/*
    new_role.php

    #28 Configurable Roles & Permissions - creates a new custom role
    (name + description only). It starts with zero permissions
    granted - an Administrator configures its actual capabilities
    afterward on roles.php, the same page used to edit any other
    role's permissions. Administrator-only, same as roles.php itself.
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "csrf_helpers.php";
require_once "activity_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND active = 1");
$me_stmt->bind_param("i", $user_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();
$me_stmt->close();

if (!$me) {
    header("Location: login.php");
    exit;
}

$role = $me["role"];

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

if ($role !== "Administrator") {
    http_response_code(403);
    echo "Only an Administrator can create roles.";
    exit;
}

$error = "";
$name = "";
$description = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requireValidCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if ($name === "") {
        $error = "Role name is required.";
    } elseif (mb_strlen($name) > 100) {
        $error = "Role name is too long.";
    } else {
        $check_stmt = $conn->prepare("SELECT id FROM custom_roles WHERE name = ?");
        $check_stmt->bind_param("s", $name);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if ($exists) {
            $error = "A role with that name already exists.";
        } else {
            $insert_stmt = $conn->prepare("INSERT INTO custom_roles (name, description, is_system) VALUES (?, ?, 0)");
            $insert_stmt->bind_param("ss", $name, $description);
            $insert_stmt->execute();
            $insert_stmt->close();

            logActivity(
                $conn,
                $user_id,
                "Create",
                "Role",
                0,
                "Created role \"" . $name . "\".",
                "roles.php?role=" . urlencode($name)
            );

            $_SESSION["roles_message"] = "Role \"" . $name . "\" created. Configure its permissions below.";
            header("Location: roles.php?role=" . urlencode($name));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Role</title>
<link rel="stylesheet" href="app.css?v=22">
</head>
<body>

<div class="page">

<div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item"><a href="dashboard.php">Dashboard</a></div>
        <div class="sidebar-item"><a href="tasks.php">Tasks</a></div>
        <div class="sidebar-item"><a href="projects.php">Projects</a></div>
        <div class="sidebar-item"><a href="calendar.php">Calendar</a></div>
        <div class="sidebar-item"><a href="events.php">Meetings / Events</a></div>
        <div class="sidebar-item"><a href="teams.php">Teams</a></div>
        <div class="sidebar-item"><a href="org_chart.php">Organization Chart</a></div>
        <div class="sidebar-item"><a href="files.php">Files</a></div>
        <div class="sidebar-item"><a href="reports.php">Reports</a></div>
        <div class="sidebar-item"><a href="analytics.php">Analytics</a></div>
        <div class="sidebar-item"><a href="training.php">Training</a></div>
        <div class="sidebar-item"><a href="time_off.php">Time Off</a></div>
        <div class="sidebar-item"><a href="timesheets.php">Timesheets</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item"><a href="announcements.php">Announcements</a></div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
        <div class="sidebar-item"><a href="users.php">User Management</a></div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
        <div class="sidebar-item active"><a href="roles.php">Roles &amp; Permissions</a></div>
        <?php endif; ?>

<div class="sidebar-bottom">
        <div class="sidebar-item"><a href="account.php">Account</a></div>
        <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
</div>

<div class="main">

    <div class="header">
        <div>
            <h1>NEW ROLE</h1>
        </div>
        <a class="button" href="roles.php">BACK TO ROLES</a>
    </div>

    <div class="content">

        <div class="card">

            <?php if ($error): ?>
                <div class="error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <div class="form-row">
                    <label for="name">Role Name</label>
                    <input type="text" id="name" name="name" value="<?php echo e($name); ?>" required>
                    <div class="help">Examples: Department Coordinator, Project Coordinator, Training Coordinator, HR Manager, Report Reviewer.</div>
                </div>

                <div class="form-row">
                    <label for="description">Description (optional)</label>
                    <textarea id="description" name="description" rows="3"><?php echo e($description); ?></textarea>
                </div>

                <div class="actions">
                    <button class="button primary" type="submit">CREATE ROLE</button>
                </div>

            </form>

        </div>

    </div>

</div>

</div>

</body>
</html>
