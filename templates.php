<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "project_template_helpers.php";
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

if (!isAnyAdministrator($me["role"])) {
    header("Location: projects.php");
    exit;
}

$role = $me["role"];
$department = $me["department"] ?? "";

$templates = getAvailableTemplates($conn, $role, $department);

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Project Templates</title>

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
            <h1>PROJECT TEMPLATES</h1>
            <div class="sub">Create a new project from a saved template</div>
        </div>

        <a class="button" href="projects.php">
            BACK TO PROJECTS
        </a>
    </div>

    <div class="content">

    <?php if (!$templates): ?>

        <div class="card">
            <div class="empty">
                No templates are available yet. Open a project and choose
                "Save As Template" to create one.
            </div>
        </div>

    <?php else: ?>

        <?php foreach ($templates as $template): ?>

            <div class="card">

                <div class="result-title"><?= e($template["name"]) ?></div>

                <div class="result-detail">
                    <?= e($template["department"]) ?>
                    <?php if (!empty($template["type"])): ?>
                        &middot; <?= e($template["type"]) ?>
                    <?php endif; ?>
                    &middot; Saved <?= e(date("M j, Y", strtotime($template["created_at"]))) ?>
                </div>

                <div class="actions">
                    <a
                        class="button primary"
                        href="new_project_from_template.php?template=<?= (int)$template["id"] ?>"
                    >
                        USE THIS TEMPLATE
                    </a>
                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

    </div>

</div>

</div>

</body>
</html>
