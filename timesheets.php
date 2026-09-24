<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "time_entry_helpers.php";
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
$is_management = ($role === "Administrator" || $role === "Manager" || $role === "IT Administrator");

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/*
    View mode: week (default), month, or a custom range. Week/month
    navigate via "anchor", a date somewhere inside the period shown.
*/
$view = $_GET["view"] ?? "week";
if (!in_array($view, ["week", "month", "range"], true)) {
    $view = "week";
}

$anchor = trim($_GET["anchor"] ?? date("Y-m-d"));
if (DateTime::createFromFormat("Y-m-d", $anchor) === false) {
    $anchor = date("Y-m-d");
}

if ($view === "week") {
    [$range_start, $range_end] = getWeekBounds($anchor);
} elseif ($view === "month") {
    [$range_start, $range_end] = getMonthBounds($anchor);
} else {
    $range_start = trim($_GET["date_from"] ?? date("Y-m-01"));
    $range_end = trim($_GET["date_to"] ?? date("Y-m-d"));
    if (DateTime::createFromFormat("Y-m-d", $range_start) === false) {
        $range_start = date("Y-m-01");
    }
    if (DateTime::createFromFormat("Y-m-d", $range_end) === false) {
        $range_end = date("Y-m-d");
    }
}

/*
    Management filters - only meaningful (and only honored) for
    Manager/IT Administrator/Administrator. An Employee/Team Lead always
    sees only their own entries, regardless of what's in the query
    string.
*/
$employee_filter = $is_management ? (int)($_GET["employee"] ?? 0) : 0;
$project_filter = (int)($_GET["project"] ?? 0);

/*
    Load candidate entries for the date range, then filter with
    canViewTimeEntry() - same "fetch broad, authorize per-row" pattern
    used by activity.php/search.php elsewhere in this app.
*/
$sql = "
    SELECT
        te.id, te.user_id, te.project_id, te.task_id, te.work_date,
        te.hours, te.description,
        p.name AS project_name, p.department AS project_department,
        p.manager_id AS project_manager_id,
        t.name AS task_name,
        CONCAT(u.first_name, ' ', u.last_name) AS user_name
    FROM time_entries te
    INNER JOIN projects p ON p.id = te.project_id
    LEFT JOIN tasks t ON t.id = te.task_id
    INNER JOIN users u ON u.id = te.user_id
    WHERE te.work_date BETWEEN ? AND ?
";
$params = [$range_start, $range_end];
$types = "ss";

if ($project_filter > 0) {
    $sql .= " AND te.project_id = ? ";
    $params[] = $project_filter;
    $types .= "i";
}

if ($employee_filter > 0) {
    $sql .= " AND te.user_id = ? ";
    $params[] = $employee_filter;
    $types .= "i";
}

$sql .= " ORDER BY te.work_date DESC, te.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$entries = [];
$total_hours = 0.0;
$daily_totals = [];

while ($row = $result->fetch_assoc()) {
    if (!canViewTimeEntry($row, $user_id, $role, $department)) {
        continue;
    }

    $entries[] = $row;
    $total_hours += (float)$row["hours"];

    $day = $row["work_date"];
    $daily_totals[$day] = ($daily_totals[$day] ?? 0) + (float)$row["hours"];
}

$stmt->close();

/*
    Employee dropdown for management filters - active users in scope
    (own department for Manager/IT Administrator, everyone for
    Administrator).
*/
$employee_options = [];

if ($is_management) {
    if ($role === "Administrator") {
        $emp_stmt = $conn->prepare("
            SELECT id, first_name, last_name
            FROM users
            WHERE active = 1
            ORDER BY last_name, first_name
        ");
        $emp_stmt->execute();
    } else {
        $emp_stmt = $conn->prepare("
            SELECT id, first_name, last_name
            FROM users
            WHERE active = 1 AND department = ?
            ORDER BY last_name, first_name
        ");
        $emp_stmt->bind_param("s", $department);
        $emp_stmt->execute();
    }
    $emp_result = $emp_stmt->get_result();
    while ($row = $emp_result->fetch_assoc()) {
        $employee_options[] = $row;
    }
    $emp_stmt->close();
}

$projects = getLoggableProjects($conn, $user_id, $role, $department);

/*
    Week view: build a Mon-Sun grid regardless of whether every day has
    entries, so the breakdown is always complete.
*/
$week_days = [];
if ($view === "week") {
    $cursor = new DateTime($range_start);
    for ($i = 0; $i < 7; $i++) {
        $day_key = $cursor->format("Y-m-d");
        $week_days[] = [
            "date" => $day_key,
            "label" => $cursor->format("D, M j"),
            "total" => $daily_totals[$day_key] ?? 0,
        ];
        $cursor->modify("+1 day");
    }
}

function buildViewUrl($view, $anchor, $modify) {
    $dt = new DateTime($anchor);
    if ($modify !== null) {
        $dt->modify($modify);
    }
    return "timesheets.php?view=" . urlencode($view) . "&anchor=" . urlencode($dt->format("Y-m-d"));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Timesheets</title>

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
        <div>
            <h1>TIMESHEETS</h1>
            <div class="sub"><?php echo $is_management ? "Your time, and time you're permitted to review" : "Time you've recorded"; ?></div>
        </div>

        <a class="button primary" href="new_time_entry.php">+ LOG TIME</a>
    </div>

    <div class="content">

        <?php if (isset($_GET["saved"])): ?>
            <div class="message">Time entry saved.</div>
        <?php endif; ?>

        <div class="card">

            <div class="filters">
                <div class="filter-grid">

                    <div class="field">
                        <label>View</label>
                        <div class="actions">
                            <a class="button <?php echo $view === "week" ? "primary" : ""; ?>" href="timesheets.php?view=week&anchor=<?php echo e($anchor); ?>">Week</a>
                            <a class="button <?php echo $view === "month" ? "primary" : ""; ?>" href="timesheets.php?view=month&anchor=<?php echo e($anchor); ?>">Month</a>
                            <a class="button <?php echo $view === "range" ? "primary" : ""; ?>" href="timesheets.php?view=range">Custom Range</a>
                        </div>
                    </div>

                    <?php if ($view === "range"): ?>
                        <form method="GET">
                            <input type="hidden" name="view" value="range">
                            <div class="field">
                                <label for="date_from">From</label>
                                <input id="date_from" type="date" name="date_from" value="<?php echo e($range_start); ?>">
                            </div>
                            <div class="field">
                                <label for="date_to">To</label>
                                <input id="date_to" type="date" name="date_to" value="<?php echo e($range_end); ?>">
                            </div>
                            <button class="button primary" type="submit">APPLY</button>
                        </form>
                    <?php else: ?>
                        <div class="field">
                            <label>Period</label>
                            <div class="actions">
                                <a class="button" href="<?php echo e(buildViewUrl($view, $anchor, "-1 " . $view)); ?>">&laquo; Previous</a>
                                <span><?php echo e($range_start); ?> &ndash; <?php echo e($range_end); ?></span>
                                <a class="button" href="<?php echo e(buildViewUrl($view, $anchor, "+1 " . $view)); ?>">Next &raquo;</a>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <?php if ($is_management || $projects): ?>
            <form method="GET" class="filters" style="margin-top:10px;">
                <input type="hidden" name="view" value="<?php echo e($view); ?>">
                <input type="hidden" name="anchor" value="<?php echo e($anchor); ?>">
                <?php if ($view === "range"): ?>
                    <input type="hidden" name="date_from" value="<?php echo e($range_start); ?>">
                    <input type="hidden" name="date_to" value="<?php echo e($range_end); ?>">
                <?php endif; ?>

                <div class="filter-grid">

                    <?php if ($is_management): ?>
                    <div class="field">
                        <label for="employee">Employee</label>
                        <select id="employee" name="employee" onchange="this.form.submit()">
                            <option value="0">All Visible Employees</option>
                            <?php foreach ($employee_options as $emp): ?>
                                <option value="<?php echo (int)$emp["id"]; ?>" <?php echo $employee_filter === (int)$emp["id"] ? "selected" : ""; ?>>
                                    <?php echo e($emp["first_name"] . " " . $emp["last_name"]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="field">
                        <label for="project">Project</label>
                        <select id="project" name="project" onchange="this.form.submit()">
                            <option value="0">All Projects</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo (int)$p["id"]; ?>" <?php echo $project_filter === (int)$p["id"] ? "selected" : ""; ?>>
                                    <?php echo e($p["name"]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </form>
            <?php endif; ?>

        </div>

        <div class="card">
            <div class="detail">
                <div class="label">Total Hours (<?php echo e($range_start); ?> &ndash; <?php echo e($range_end); ?>)</div>
                <div class="value"><strong><?php echo number_format($total_hours, 2); ?></strong></div>
            </div>
        </div>

        <?php if ($view === "week"): ?>
        <div class="card">
            <h2>Weekly Breakdown</h2>
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($week_days as $day): ?>
                                <th><?php echo e($day["label"]); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php foreach ($week_days as $day): ?>
                                <td><?php echo number_format($day["total"], 2); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="section">
            <h2>Time Entries</h2>

            <div class="table-card">
                <?php if (!$entries): ?>
                    <div class="empty">No time entries for this period.</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <?php if ($is_management): ?><th>Employee</th><?php endif; ?>
                            <th>Project</th>
                            <th>Task</th>
                            <th>Hours</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?php echo e(date("M j, Y", strtotime($entry["work_date"]))); ?></td>
                                <?php if ($is_management): ?><td><?php echo e($entry["user_name"]); ?></td><?php endif; ?>
                                <td><?php echo e($entry["project_name"]); ?></td>
                                <td><?php echo e($entry["task_name"] ?? "—"); ?></td>
                                <td><?php echo number_format((float)$entry["hours"], 2); ?></td>
                                <td><?php echo e(mb_strimwidth((string)($entry["description"] ?? ""), 0, 80, "...")); ?></td>
                                <td>
                                    <?php if (canManageTimeEntry($entry, $user_id, $role)): ?>
                                        <a class="button" href="edit_time_entry.php?id=<?php echo (int)$entry["id"]; ?>">EDIT</a>
                                        <a class="button danger" href="delete_time_entry.php?id=<?php echo (int)$entry["id"]; ?>">DELETE</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

</div>

</body>
</html>
