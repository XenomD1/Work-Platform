<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "csrf_helpers.php";
require_once "time_entry_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("
    SELECT role, department
    FROM users
    WHERE id = ? AND active = 1
");
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

$entry_id = (int)($_GET["id"] ?? $_POST["id"] ?? 0);

if ($entry_id <= 0) {
    header("Location: timesheets.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT
        te.id, te.user_id, te.work_date, te.hours, te.description,
        p.name AS project_name, p.department AS project_department,
        p.manager_id AS project_manager_id,
        t.name AS task_name
    FROM time_entries te
    INNER JOIN projects p ON p.id = te.project_id
    LEFT JOIN tasks t ON t.id = te.task_id
    WHERE te.id = ?
");
$stmt->bind_param("i", $entry_id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$entry) {
    header("Location: timesheets.php");
    exit;
}

if (!canManageTimeEntry($entry, $user_id, $role)) {
    http_response_code(403);
    exit("You do not have permission to delete this time entry.");
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $delete_stmt = $conn->prepare("DELETE FROM time_entries WHERE id = ?");
    $delete_stmt->bind_param("i", $entry_id);

    if ($delete_stmt->execute()) {
        $delete_stmt->close();
        header("Location: timesheets.php?deleted=1");
        exit;
    }

    $error = "The time entry could not be deleted.";
    $delete_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Delete Time Entry</title>

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
        <div class="sidebar-item active"><a href="timesheets.php">Timesheets</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item"><a href="announcements.php">Announcements</a></div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
        <div class="sidebar-item"><a href="users.php">User Management</a></div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
        <div class="sidebar-item"><a href="roles.php">Roles &amp; Permissions</a></div>
        <?php endif; ?>

        <div class="sidebar-bottom">
        <div class="sidebar-item"><a href="account.php">Account</a></div>
        <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
</div>

<div class="main">

    <div class="header">
        <h1>DELETE TIME ENTRY</h1>
    </div>

    <div class="content">

    <div class="card">

        <?php if ($error !== ""): ?>
            <div class="error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="warning">
            This permanently deletes the time entry. This cannot be undone.
        </div>

        <div class="detail">
            <div class="label">Project</div>
            <div><?php echo e($entry["project_name"]); ?></div>
        </div>

        <?php if (!empty($entry["task_name"])): ?>
        <div class="detail">
            <div class="label">Task</div>
            <div><?php echo e($entry["task_name"]); ?></div>
        </div>
        <?php endif; ?>

        <div class="detail">
            <div class="label">Date</div>
            <div><?php echo e(date("M j, Y", strtotime($entry["work_date"]))); ?></div>
        </div>

        <div class="detail">
            <div class="label">Hours</div>
            <div><?php echo number_format((float)$entry["hours"], 2); ?></div>
        </div>

        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="id" value="<?php echo (int)$entry_id; ?>">

            <div class="actions">
                <a class="button" href="timesheets.php">CANCEL</a>
                <button class="button danger" type="submit">DELETE PERMANENTLY</button>
            </div>
        </form>

    </div>

    </div>

</div>

</div>

</body>
</html>
