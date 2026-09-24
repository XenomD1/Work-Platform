<?php
/*
    department_chart.php

    #25 Organization & Team Chart - department-level view: management,
    employees, teams (with their leads), and the department's active
    projects. Administrators can open any department; everyone else
    only their own (canViewDepartmentChart() in org_chart_helpers.php).

    Team links go to team_chart.php?team_id=<id> - team_chart.php (also
    part of #25) is the single-team route and confirmed to read
    $_GET["team_id"], so this is not a guess.
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "org_chart_helpers.php";

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

$target_department = trim($_GET["department"] ?? "");

if ($target_department === "" || !canViewDepartmentChart($role, $department, $target_department)) {
    http_response_code(403);
    echo "You do not have permission to view this department's chart.";
    exit;
}

$roster = getDepartmentRoster($conn, $target_department);
$teams = getDepartmentTeams($conn, $target_department);
$projects = getDepartmentProjects($conn, $target_department);
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

<title>Department Chart</title>

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
            <h1><?php echo e(strtoupper($target_department)); ?></h1>
            <div class="sub">Management, employees, teams, and project relationships</div>
        </div>
        <?php if ($is_admin): ?>
        <a class="button" href="org_chart.php">BACK TO ORGANIZATION</a>
        <?php endif; ?>
    </div>

    <div class="content">

        <div class="card">
            <h2>Management</h2>
            <?php if (!$roster["management"]): ?>
                <div class="empty">No management on record for this department.</div>
            <?php else: ?>
                <ul>
                    <?php foreach ($roster["management"] as $m): ?>
                        <li><?php echo userLink($m["first_name"] . " " . $m["last_name"], (int)$m["id"], $is_admin); ?> — <?php echo e($m["role"]); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Employees</h2>
            <?php if (!$roster["employees"]): ?>
                <div class="empty">No employees on record for this department.</div>
            <?php else: ?>
                <ul>
                    <?php foreach ($roster["employees"] as $emp): ?>
                        <li><?php echo userLink($emp["first_name"] . " " . $emp["last_name"], (int)$emp["id"], $is_admin); ?> — <?php echo e($emp["role"]); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Teams</h2>
            <?php if (!$teams): ?>
                <div class="empty">No teams associated with this department's projects.</div>
            <?php else: ?>
                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Project</th>
                                <th>Team Lead</th>
                                <th>Members</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $t): ?>
                                <tr>
                                    <td><?php echo e($t["name"]); ?></td>
                                    <td><a href="projects.php?project=<?php echo (int)$t["project_id"]; ?>"><?php echo e($t["project_name"]); ?></a></td>
                                    <td><?php echo $t["lead_first_name"] ? e($t["lead_first_name"] . " " . $t["lead_last_name"]) : "—"; ?></td>
                                    <td><?php echo (int)$t["member_count"]; ?></td>
                                    <td><a class="button" href="team_chart.php?team_id=<?php echo (int)$t["id"]; ?>">OPEN</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Projects</h2>
            <?php if (!$projects): ?>
                <div class="empty">No active projects for this department.</div>
            <?php else: ?>
                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Manager</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $p): ?>
                                <tr>
                                    <td><a href="projects.php?project=<?php echo (int)$p["id"]; ?>"><?php echo e($p["name"]); ?></a></td>
                                    <td><?php echo e($p["status"]); ?></td>
                                    <td><?php echo e($p["manager_name"]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

</div>

</body>
</html>
