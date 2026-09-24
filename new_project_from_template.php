<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "notification_helpers.php";
require_once "project_template_helpers.php";
require_once "csrf_helpers.php";

$current_user_id = (int)$_SESSION["user_id"];

$user_stmt = $conn->prepare("
    SELECT role, department
    FROM users
    WHERE id = ? AND active = 1
");
$user_stmt->bind_param("i", $current_user_id);
$user_stmt->execute();
$me = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$me) {
    header("Location: login.php");
    exit;
}

if (!isAnyAdministrator($me["role"])) {
    header("Location: projects.php");
    exit;
}

$role = $me["role"];
$department = $me["department"] ?? "";

$template_id = (int)($_GET["template"] ?? $_POST["template"] ?? 0);

if ($template_id <= 0) {
    header("Location: templates.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT id, name, department
    FROM project_templates
    WHERE id = ?
");
$stmt->bind_param("i", $template_id);
$stmt->execute();
$template = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$template) {
    header("Location: templates.php");
    exit;
}

if ($role !== "Administrator" && $template["department"] !== $department) {
    header("Location: templates.php");
    exit;
}

$user_stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$user_stmt->bind_param("i", $current_user_id);
$user_stmt->execute();
$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$manager_name = trim(($current_user["first_name"] ?? "") . " " . ($current_user["last_name"] ?? ""));

$error = "";
$project_name = "";
$start_date = "";
$end_date = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $project_name = trim($_POST["project_name"] ?? "");
    $start_date = trim($_POST["start_date"] ?? "");
    $end_date = trim($_POST["end_date"] ?? "");

    if ($role === "IT Administrator") {
        $project_department = $department;
    } else {
        $project_department = trim($_POST["department"] ?? "");
    }

    if ($project_name === "") {
        $error = "Project name is required.";
    } elseif ($start_date === "") {
        $error = "Start date is required, since template dates are calculated relative to it.";
    } elseif ($project_department === "") {
        $error = "Department is required.";
    } else {

        $new_project_id = createProjectFromTemplate(
            $conn,
            $template_id,
            $project_name,
            $project_department,
            $start_date,
            $end_date !== "" ? $end_date : null,
            $current_user_id,
            $manager_name
        );

        if ($new_project_id !== null) {
            header("Location: projects.php?project=" . $new_project_id);
            exit;
        }

        $error = "Unable to create a project from this template.";
    }
}

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Create Project From Template</title>

<link rel="stylesheet" href="app.css?v=22">

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
        <div class="sidebar-item active">
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
            <a href="org_chart.php">Organization Chart</a>
        </div>
        <div class="sidebar-item">
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item">
            <a href="analytics.php">Analytics</a>
        </div>
        <div class="sidebar-item">
            <a href="training.php">Training</a>
        </div>
        <div class="sidebar-item">
            <a href="time_off.php">Time Off</a>
        </div>
        <div class="sidebar-item">
            <a href="timesheets.php">Timesheets</a>
        </div>
        <div class="sidebar-item">
            <a href="notifications.php">Notifications</a>
        </div>

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
        <div class="sidebar-item">
            <a href="roles.php">Roles &amp; Permissions</a>
        </div>
        <?php endif; ?>

<div class="sidebar-bottom">
        <div class="sidebar-item">
            <a href="account.php">Account</a>
        </div>
        <div class="sidebar-item">
            <a href="settings.php">Settings</a>
        </div>
        </div>
    </div>

<div class="main">

    <div class="header">
        <div>
            <h1>CREATE PROJECT FROM TEMPLATE</h1>
            <div class="sub"><?= e($template["name"]) ?></div>
        </div>

        <a class="button" href="templates.php">
            BACK TO TEMPLATES
        </a>
    </div>

    <div class="content">

    <?php if ($error !== ""): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="card">

        <div class="notice">
            You will automatically become this project's manager. Task and
            milestone dates in the template will be scheduled relative to the
            start date you choose below.
        </div>

        <form method="POST">

            <?= csrfField() ?>

            <input type="hidden" name="template" value="<?= $template_id ?>">

            <div class="form-row">
                <label for="project_name">Project Name</label>
                <input
                    type="text"
                    id="project_name"
                    name="project_name"
                    value="<?= e($project_name) ?>"
                    required
                >
            </div>

            <div class="form-row">
                <label for="department">Department</label>

                <?php if ($role === "IT Administrator"): ?>
                    <input
                        type="text"
                        id="department"
                        name="department"
                        value="<?= e($department) ?>"
                        readonly
                    >
                <?php else: ?>
                    <input
                        type="text"
                        id="department"
                        name="department"
                        value="<?= e($_POST["department"] ?? $template["department"]) ?>"
                    >
                <?php endif; ?>
            </div>

            <div class="form-row">
                <label for="start_date">Start Date</label>
                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= e($start_date) ?>"
                    required
                >
            </div>

            <div class="form-row">
                <label for="end_date">End Date</label>
                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= e($end_date) ?>"
                >
            </div>

            <div class="actions">
                <a class="button" href="templates.php">CANCEL</a>
                <button class="button primary" type="submit">CREATE PROJECT</button>
            </div>

        </form>

    </div>

    </div>

</div>

</div>

</body>
</html>
