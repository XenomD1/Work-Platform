<?php
/*
    team_chart.php

    #25 Organization & Team Chart - single-team view: project, team
    lead, and members. Reachable from department_chart.php, or
    directly by anyone who is themselves the team's lead or a member
    (so a person can always see their own team's chart even in the
    edge case where the team's project department doesn't match their
    own department field).
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "org_chart_helpers.php";
require_once "announcement_helpers.php";

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
$department = $me["department"] ?? "";

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$team_id = (int)($_GET["team_id"] ?? 0);

if ($team_id <= 0) {
    http_response_code(404);
    echo "Team not found.";
    exit;
}

$team = getTeamDetail($conn, $team_id);

if (!$team) {
    http_response_code(404);
    echo "Team not found.";
    exit;
}

$allowed = canViewDepartmentChart($role, $department, $team["project_department"])
    || userBelongsToTeam($conn, $user_id, $team_id);

if (!$allowed) {
    http_response_code(403);
    echo "You do not have permission to view this team's chart.";
    exit;
}

$is_admin = isAnyAdministrator($role);

function userLink(string $label, int $id, bool $is_admin): string {
    if ($is_admin) {
        return '<a href="edit_user.php?id=' . (int)$id . '">' . htmlspecialchars($label, ENT_QUOTES, "UTF-8") . '</a>';
    }
    return htmlspecialchars($label, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Team Chart</title>

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
        <div class="sidebar-item"><a href="roles.php">Roles &amp; Permissions</a></div>
        <?php endif; ?>

        <div class="sidebar-bottom">
        <div class="sidebar-item"><a href="account.php">Account</a></div>
        <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
</div>

<div class="main">

    <div class="header">
        <div>
            <h1><?php echo e($team["name"]); ?></h1>
            <div class="sub">Team structure and project relationship</div>
        </div>
        <a class="button" href="department_chart.php?department=<?php echo urlencode($team["project_department"]); ?>">BACK TO DEPARTMENT</a>
    </div>

    <div class="content">

        <div class="card">
            <h2>Project</h2>
            <p><a href="projects.php?project=<?php echo (int)$team["project_id"]; ?>"><?php echo e($team["project_name"]); ?></a></p>
            <?php if ($team["project_manager_name"]): ?>
                <p class="meta-line">Project Manager: <?php echo e($team["project_manager_name"]); ?></p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Team Lead</h2>
            <?php if ($team["lead_id"]): ?>
                <p><?php echo userLink($team["lead_first_name"] . " " . $team["lead_last_name"], (int)$team["lead_id"], $is_admin); ?></p>
            <?php else: ?>
                <div class="empty">No team lead assigned.</div>
            <?php endif; ?>
        </div>

        <?php if ($team["description"]): ?>
        <div class="card">
            <h2>Description</h2>
            <p><?php echo nl2br(e($team["description"])); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($team["responsibilities"]): ?>
        <div class="card">
            <h2>Responsibilities</h2>
            <p><?php echo nl2br(e($team["responsibilities"])); ?></p>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Members</h2>
            <?php if (!$team["members"]): ?>
                <div class="empty">No members on this team.</div>
            <?php else: ?>
                <ul>
                    <?php foreach ($team["members"] as $mem): ?>
                        <li>
                            <?php echo userLink($mem["first_name"] . " " . $mem["last_name"], (int)$mem["id"], $is_admin); ?>
                            — <?php echo e($mem["role"]); ?><?php echo $mem["department"] ? " (" . e($mem["department"]) . ")" : ""; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

    </div>

</div>

</div>

</body>
</html>
