<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "project_template_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

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

$current_role = $me["role"];
$current_department = $me["department"] ?? "";

$project_id = (int)($_GET["project"] ?? $_POST["project"] ?? 0);

if ($project_id <= 0) {
    header("Location: projects.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT id, name, department, manager_id
    FROM projects
    WHERE id = ?
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    header("Location: projects.php");
    exit;
}

$can_save_as_template =
    $current_role === "Administrator"
    || (
        ($current_role === "Manager" || $current_role === "IT Administrator")
        && $project["department"] === $current_department
    )
    || (int)($project["manager_id"] ?? 0) === $current_user_id;

if (!$can_save_as_template) {
    header("Location: projects.php?project=" . $project_id);
    exit;
}

$error = "";
$template_name = $project["name"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $template_name = trim($_POST["template_name"] ?? "");

    if ($template_name === "") {
        $error = "Template name is required.";
    } else {

        $new_template_id = saveProjectAsTemplate(
            $conn,
            $project_id,
            $template_name,
            $current_user_id
        );

        if ($new_template_id !== null) {
            header("Location: templates.php");
            exit;
        }

        $error = "Unable to save this project as a template.";
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

<title>Save As Template</title>

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

        <?php if (canPostAnnouncements($current_role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($current_role)): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($current_role === "Administrator"): ?>
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
            <h1>SAVE AS TEMPLATE</h1>
            <div class="sub"><?= e($project["name"]) ?></div>
        </div>

        <a class="button" href="projects.php?project=<?= $project_id ?>">
            BACK TO PROJECT
        </a>
    </div>

    <div class="content">

    <?php if ($error !== ""): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="card">

        <div class="notice">
            This copies the project's objectives, deliverables, priorities, risks,
            milestones, tasks, and team structure into a reusable template. Task and
            milestone dates are stored as day offsets from this project's start date,
            not as fixed dates. Assignees, task dependencies, recurrence settings, and
            checklists are not copied - those belong to this specific project, not the
            reusable structure.
        </div>

        <form method="POST">

            <?= csrfField() ?>

            <input type="hidden" name="project" value="<?= $project_id ?>">

            <div class="form-row">
                <label for="template_name">Template Name</label>
                <input
                    type="text"
                    id="template_name"
                    name="template_name"
                    value="<?= e($template_name) ?>"
                    required
                >
            </div>

            <div class="actions">
                <a class="button" href="projects.php?project=<?= $project_id ?>">CANCEL</a>
                <button class="button primary" type="submit">SAVE AS TEMPLATE</button>
            </div>

        </form>

    </div>

    </div>

</div>

</div>

</body>
</html>
