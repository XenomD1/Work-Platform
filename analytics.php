<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "time_entry_helpers.php";
require_once "analytics_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("
    SELECT role, department, first_name, last_name
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

$is_org_wide = analyticsIsOrgWide($role);
$is_department_scoped = analyticsIsDepartmentScoped($role);
$is_self_scoped = !$is_org_wide && !$is_department_scoped;

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/*
    Filters. A department filter is only ever honored for an
    Administrator - Manager/IT Administrator are locked to their own
    department regardless of what's in the query string, and
    Employee/Team Lead never see department-level data at all.
*/
$date_from = trim($_GET["date_from"] ?? "");
$date_to = trim($_GET["date_to"] ?? "");

if ($date_from !== "" && DateTime::createFromFormat("Y-m-d", $date_from) === false) {
    $date_from = "";
}
if ($date_to !== "" && DateTime::createFromFormat("Y-m-d", $date_to) === false) {
    $date_to = "";
}

$known_departments = $is_org_wide ? getKnownDepartments($conn) : [];
$department_filter = "";

if ($is_org_wide) {
    $requested = trim($_GET["department"] ?? "");
    if ($requested !== "" && in_array($requested, $known_departments, true)) {
        $department_filter = $requested;
    }
} elseif ($is_department_scoped) {
    $department_filter = $department;
}

$project_filter = (int)($_GET["project"] ?? 0);
$projects_for_filter = getLoggableProjects($conn, $user_id, $role, $department);

/*
    Effective scope passed into the helpers:
      - self-scoped: scope_user_id = viewer, scope_department = null
      - department-scoped: scope_user_id = null, scope_department = own dept
      - org-wide, no department chosen: both null (everything)
      - org-wide, a department chosen: scope_user_id = null, scope_department = chosen
*/
$scope_user_id = $is_self_scoped ? $user_id : null;
$scope_department = ($is_department_scoped || ($is_org_wide && $department_filter !== "")) ? $department_filter : null;

$task_stats = getTaskAnalytics($conn, $scope_user_id, $scope_department, $project_filter, $date_from, $date_to);
$time_off_stats = getTimeOffAnalytics($conn, $scope_user_id, $scope_department, $date_from, $date_to);
$training_stats = getTrainingAnalytics($conn, $scope_department);
$project_rows = getProjectAnalytics($conn, $role, $department, $user_id, $project_filter);

$tracked_hours_total = $is_self_scoped
    ? getTrackedHoursForUser($conn, $user_id, $project_filter, $date_from, $date_to)
    : 0.0;

/*
    Workload/Department comparison rows are only built for management
    roles - an Employee/Team Lead never sees other employees' numbers.
*/
$workload_rows = [];
$department_rows = [];

if (!$is_self_scoped) {

    if ($is_org_wide && $department_filter === "") {
        $emp_stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name, first_name");
        $emp_stmt->execute();
    } else {
        $emp_stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE active = 1 AND department = ? ORDER BY last_name, first_name");
        $emp_stmt->bind_param("s", $scope_department);
        $emp_stmt->execute();
    }
    $emp_result = $emp_stmt->get_result();
    $scope_users = [];
    while ($row = $emp_result->fetch_assoc()) {
        $scope_users[] = $row;
    }
    $emp_stmt->close();

    $workload_rows = getWorkloadByEmployee($conn, $scope_users, $project_filter);

    if ($is_org_wide && $department_filter === "") {
        foreach ($known_departments as $dept) {
            $dept_task_stats = getTaskAnalytics($conn, null, $dept, 0, $date_from, $date_to);
            $dept_time_off = getTimeOffAnalytics($conn, null, $dept, $date_from, $date_to);
            $department_rows[] = [
                "department" => $dept,
                "active_tasks" => $dept_task_stats["not_started"] + $dept_task_stats["in_progress"],
                "overdue_tasks" => $dept_task_stats["overdue"],
                "complete_tasks" => $dept_task_stats["complete"],
                "pending_time_off" => $dept_time_off["Pending"],
            ];
        }
    }
}

$max_workload = 1;
foreach ($workload_rows as $row) {
    $max_workload = max($max_workload, $row["active"]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Analytics</title>

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
        <div class="sidebar-item active"><a href="analytics.php">Analytics</a></div>
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
            <h1>ANALYTICS</h1>
            <div class="sub">
                <?php if ($is_org_wide): ?>
                    Organization-wide view
                <?php elseif ($is_department_scoped): ?>
                    <?php echo e($department); ?> department view
                <?php else: ?>
                    Your work
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="content">

    <div class="card">
        <form method="GET" class="filters">
            <div class="filter-grid">

                <?php if ($is_org_wide): ?>
                <div class="field">
                    <label for="department">Department</label>
                    <select id="department" name="department" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($known_departments as $dept): ?>
                            <option value="<?php echo e($dept); ?>" <?php echo $department_filter === $dept ? "selected" : ""; ?>>
                                <?php echo e($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="field">
                    <label for="project">Project</label>
                    <select id="project" name="project" onchange="this.form.submit()">
                        <option value="0">All Projects</option>
                        <?php foreach ($projects_for_filter as $p): ?>
                            <option value="<?php echo (int)$p["id"]; ?>" <?php echo $project_filter === (int)$p["id"] ? "selected" : ""; ?>>
                                <?php echo e($p["name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="date_from">From</label>
                    <input id="date_from" type="date" name="date_from" value="<?php echo e($date_from); ?>">
                </div>

                <div class="field">
                    <label for="date_to">To</label>
                    <input id="date_to" type="date" name="date_to" value="<?php echo e($date_to); ?>">
                </div>

                <div class="filter-actions">
                    <button class="button primary" type="submit">APPLY</button>
                    <a class="button" href="analytics.php">CLEAR</a>
                </div>

            </div>
        </form>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="filter-grid" style="margin-bottom:18px;">
        <div class="card"><div class="label">Active Tasks</div><div style="font-size:26px;font-weight:600;"><?php echo $task_stats["not_started"] + $task_stats["in_progress"]; ?></div></div>
        <div class="card"><div class="label">Overdue Tasks</div><div style="font-size:26px;font-weight:600;color:#c0392b;"><?php echo $task_stats["overdue"]; ?></div></div>
        <div class="card"><div class="label">Active Projects</div><div style="font-size:26px;font-weight:600;"><?php echo count($project_rows); ?></div></div>
        <div class="card"><div class="label">Pending Time Off</div><div style="font-size:26px;font-weight:600;"><?php echo $time_off_stats["Pending"]; ?></div></div>
        <div class="card"><div class="label">Upcoming Training</div><div style="font-size:26px;font-weight:600;"><?php echo $training_stats["upcoming"]; ?></div></div>
        <?php if ($is_self_scoped): ?>
        <div class="card"><div class="label">Your Tracked Hours</div><div style="font-size:26px;font-weight:600;"><?php echo number_format($tracked_hours_total, 1); ?></div></div>
        <?php endif; ?>
    </div>

    <!-- TASK ANALYTICS -->
    <div class="card">
        <h2>Task Analytics</h2>
        <div class="grid">
            <div>
                <h3 style="font-size:13px;text-transform:uppercase;color:var(--slate);">By Status</h3>
                <?php
                echo renderBar("Not Started", $task_stats["not_started"], $task_stats["total"], "#95a5a6");
                echo renderBar("In Progress", $task_stats["in_progress"], $task_stats["total"], "#2f6fed");
                echo renderBar("Complete", $task_stats["complete"], $task_stats["total"], "#27ae60");
                ?>
            </div>
            <div>
                <h3 style="font-size:13px;text-transform:uppercase;color:var(--slate);">By Priority</h3>
                <?php
                echo renderBar("High", $task_stats["high"], $task_stats["total"], "#c0392b");
                echo renderBar("Medium", $task_stats["medium"], $task_stats["total"], "#e67e22");
                echo renderBar("Low", $task_stats["low"], $task_stats["total"], "#95a5a6");
                ?>
            </div>
        </div>
        <div class="detail" style="margin-top:10px;">
            <div class="label">Overdue</div>
            <div><?php echo $task_stats["overdue"]; ?> of <?php echo $task_stats["total"]; ?> tasks</div>
        </div>
    </div>

    <?php if (!$is_self_scoped): ?>
    <!-- WORKLOAD ANALYTICS -->
    <div class="card">
        <h2>Workload Analytics</h2>
        <?php if (!$workload_rows): ?>
            <div class="empty">No employees in scope.</div>
        <?php else: ?>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Active</th>
                        <th>Overdue</th>
                        <th>Complete</th>
                        <th>Tracked Hours</th>
                        <th>Workload</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workload_rows as $row): ?>
                        <tr>
                            <td><?php echo e($row["name"]); ?></td>
                            <td><?php echo (int)$row["active"]; ?></td>
                            <td><?php echo (int)$row["overdue"]; ?></td>
                            <td><?php echo (int)$row["complete"]; ?></td>
                            <td><?php echo number_format($row["tracked_hours"], 1); ?></td>
                            <td style="min-width:140px;"><?php echo renderBar("", $row["active"], $max_workload); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- PROJECT ANALYTICS -->
    <div class="card">
        <h2>Project Analytics</h2>
        <?php if (!$project_rows): ?>
            <div class="empty">No projects in scope.</div>
        <?php else: ?>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Completion</th>
                        <th>Overdue Tasks</th>
                        <th>Tracked Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($project_rows as $p): ?>
                        <tr>
                            <td><?php echo e($p["name"]); ?></td>
                            <td><?php echo e($p["department"]); ?></td>
                            <td><?php echo e($p["status"]); ?></td>
                            <td><?php echo e(round((float)($p["completion"] ?? 0), 1)); ?>%</td>
                            <td><?php echo (int)$p["overdue_tasks"]; ?></td>
                            <td><?php echo number_format($p["tracked_hours"], 1); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($is_org_wide && $department_filter === "" && $department_rows): ?>
    <!-- DEPARTMENT ANALYTICS -->
    <div class="card">
        <h2>Department Analytics</h2>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Active Tasks</th>
                        <th>Overdue Tasks</th>
                        <th>Complete Tasks</th>
                        <th>Pending Time Off</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($department_rows as $d): ?>
                        <tr>
                            <td><?php echo e($d["department"]); ?></td>
                            <td><?php echo (int)$d["active_tasks"]; ?></td>
                            <td><?php echo (int)$d["overdue_tasks"]; ?></td>
                            <td><?php echo (int)$d["complete_tasks"]; ?></td>
                            <td><?php echo (int)$d["pending_time_off"]; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TIME OFF ANALYTICS -->
    <div class="card">
        <h2>Time Off Analytics</h2>
        <?php
        echo renderBar("Pending", $time_off_stats["Pending"], max($time_off_stats["total"], 1), "#e67e22");
        echo renderBar("Approved", $time_off_stats["Approved"], max($time_off_stats["total"], 1), "#27ae60");
        echo renderBar("Rejected", $time_off_stats["Rejected"], max($time_off_stats["total"], 1), "#c0392b");
        ?>
    </div>

    <!-- TRAINING ANALYTICS -->
    <div class="card">
        <h2>Training Analytics</h2>
        <div class="detail">
            <div class="label">Upcoming</div>
            <div><?php echo $training_stats["upcoming"]; ?></div>
        </div>
        <div class="detail">
            <div class="label">Completed</div>
            <div><?php echo $training_stats["completed"]; ?></div>
        </div>
    </div>

    <div class="notice">
        Completion-over-time trend charts aren't included yet - the platform
        doesn't currently keep historical snapshots of task/project completion,
        only its current value. Adding a trend chart would need a small history
        table that records completion whenever it changes, which is a separate
        follow-up if you want that.
    </div>

    </div>

</div>

</div>

</body>
</html>
