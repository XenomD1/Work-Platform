<?php
/*
    org_chart.php

    #25 Organization & Team Chart - organization-level entry point.
    Administrators get the full department-by-department breakdown.
    Everyone else only ever has one department's worth of chart to
    look at, so they're sent straight to their own department view
    rather than shown an org-wide page that mostly says "you can't
    open this."
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

if (!isAnyAdministrator($role)) {
    header("Location: department_chart.php?department=" . urlencode($department));
    exit;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$departments = getOrgDepartments($conn);

/* One manager-level name per department, for a quick at-a-glance row. */
$dept_managers = [];
foreach ($departments as $d) {
    $roster = getDepartmentRoster($conn, $d["department"]);
    $names = array_map(fn($m) => $m["first_name"] . " " . $m["last_name"] . " (" . $m["role"] . ")", $roster["management"]);
    $dept_managers[$d["department"]] = $names;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Organization Chart</title>

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
        <div class="sidebar-item active"><a href="org_chart.php">Organization Chart</a></div>
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
            <h1>ORGANIZATION CHART</h1>
            <div class="sub">Departments, management, and how they connect to projects and teams</div>
        </div>
    </div>

    <div class="content">

        <?php if (!$departments): ?>

            <div class="empty">No departments found.</div>

        <?php else: ?>

            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Headcount</th>
                            <th>Management</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $d): ?>
                            <tr>
                                <td><?php echo e($d["department"]); ?></td>
                                <td><?php echo (int)$d["headcount"]; ?></td>
                                <td><?php echo $dept_managers[$d["department"]] ? e(implode(", ", $dept_managers[$d["department"]])) : "—"; ?></td>
                                <td><a class="button" href="department_chart.php?department=<?php echo urlencode($d["department"]); ?>">OPEN</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>

</div>

</div>

</body>
</html>
