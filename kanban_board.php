<?php
/*
    kanban_board.php

    #24 Task Kanban / Board View. Same underlying Tasks query and
    visibility rule as bulk_tasks.php (Administrator = everything,
    Manager/IT Administrator = own department, everyone else = tasks
    they're assigned to), grouped into three status columns instead of
    a flat table. No drag-and-drop - moving a task between columns
    posts to update_task_status.php through a normal form, same as
    every other status change in the platform.

    Filters (project / priority / assignee) and Saved Views apply the
    same way they do everywhere else: a filtered view just produces a
    filtered board instead of a separate set of Kanban-only filters.

    Note: this reads Saved Views under module = 'Tasks'. If your
    saved_views rows for the task list use a different module string,
    adjust the query near the top of the "Saved view" section below.
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
require_once "task_dependency_helpers.php";
require_once "task_board_helpers.php";

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
$can_reassign = ($role === "Administrator" || $role === "Manager" || $role === "IT Administrator");

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/* ---- Saved view: load its query_string as defaults, GET params on
   the URL itself always win over it. ---- */

$view_id = (int)($_GET["view"] ?? 0);
$view_defaults = [];

if ($view_id > 0) {
    $view_stmt = $conn->prepare("
        SELECT query_string, name
        FROM saved_views
        WHERE id = ? AND user_id = ? AND module = 'Tasks'
    ");
    $view_stmt->bind_param("ii", $view_id, $user_id);
    $view_stmt->execute();
    $view_row = $view_stmt->get_result()->fetch_assoc();
    $view_stmt->close();

    if ($view_row) {
        parse_str((string)$view_row["query_string"], $view_defaults);
    } else {
        $view_id = 0;
    }
}

function boardParam(string $key, $default, array $view_defaults) {
    if (isset($_GET[$key]) && $_GET[$key] !== "") {
        return $_GET[$key];
    }
    if (isset($view_defaults[$key]) && $view_defaults[$key] !== "") {
        return $view_defaults[$key];
    }
    return $default;
}

$project_filter = (int)boardParam("project", 0, $view_defaults);
$priority_filter = trim((string)boardParam("priority", "", $view_defaults));
$assignee_filter = (int)boardParam("assignee", 0, $view_defaults);

/* ---- Scoped task query, same visibility rule as bulk_tasks.php ---- */

$sql = "
    SELECT DISTINCT
        t.id, t.name, t.status, t.priority, t.due_date, t.completion,
        t.project_id, t.recurrence_type,
        p.name AS project_name, p.department AS project_department,
        p.manager_id AS project_manager_id
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
";
$where = [];
$params = [];
$types = "";

if ($assignee_filter > 0) {
    $sql .= " INNER JOIN task_assignments ta_filter ON ta_filter.task_id = t.id ";
    $where[] = "ta_filter.user_id = ?";
    $params[] = $assignee_filter;
    $types .= "i";
}

if ($role === "Administrator") {
    // no extra scope
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $where[] = "p.department = ?";
    $params[] = $department;
    $types .= "s";
} else {
    $sql .= " INNER JOIN task_assignments ta_scope ON ta_scope.task_id = t.id ";
    $where[] = "ta_scope.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

if ($project_filter > 0) {
    $where[] = "t.project_id = ?";
    $params[] = $project_filter;
    $types .= "i";
}

if ($priority_filter !== "" && in_array($priority_filter, ["Low", "Medium", "High", "Critical"], true)) {
    $where[] = "t.priority = ?";
    $params[] = $priority_filter;
    $types .= "s";
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY t.due_date ASC, t.name ASC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$tasks_by_id = [];
$task_ids = [];
while ($row = $result->fetch_assoc()) {
    $tasks_by_id[(int)$row["id"]] = $row;
    $task_ids[] = (int)$row["id"];
}
$stmt->close();

/* ---- Assignees, batched for every task on the board ---- */

$assignees_by_task = [];
if ($task_ids) {
    $placeholders = implode(",", array_fill(0, count($task_ids), "?"));
    $assign_stmt = $conn->prepare("
        SELECT ta.task_id, u.id AS user_id, u.first_name, u.last_name
        FROM task_assignments ta
        INNER JOIN users u ON u.id = ta.user_id
        WHERE ta.task_id IN ($placeholders)
    ");
    $assign_stmt->bind_param(str_repeat("i", count($task_ids)), ...$task_ids);
    $assign_stmt->execute();
    $assign_result = $assign_stmt->get_result();
    while ($row = $assign_result->fetch_assoc()) {
        $tid = (int)$row["task_id"];
        if (!isset($assignees_by_task[$tid])) {
            $assignees_by_task[$tid] = [];
        }
        $assignees_by_task[$tid][] = [
            "id" => (int)$row["user_id"],
            "name" => $row["first_name"] . " " . $row["last_name"],
        ];
    }
    $assign_stmt->close();
}

/* ---- Columns ---- */

$columns = boardStatusColumns();
$board = ["Not Started" => [], "In Progress" => [], "Complete" => []];

foreach ($tasks_by_id as $tid => $task) {
    $status = in_array($task["status"], $columns, true) ? $task["status"] : "Not Started";
    $board[$status][] = $task;
}

/* ---- Filter dropdown data, scoped the same way as the task query ---- */

if ($role === "Administrator") {
    $proj_result = $conn->query("SELECT id, name FROM projects ORDER BY name ASC");
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $proj_stmt = $conn->prepare("SELECT id, name FROM projects WHERE department = ? ORDER BY name ASC");
    $proj_stmt->bind_param("s", $department);
    $proj_stmt->execute();
    $proj_result = $proj_stmt->get_result();
} else {
    $proj_stmt = $conn->prepare("
        SELECT DISTINCT p.id, p.name
        FROM projects p
        INNER JOIN tasks t ON t.project_id = p.id
        INNER JOIN task_assignments ta ON ta.task_id = t.id
        WHERE ta.user_id = ?
        ORDER BY p.name ASC
    ");
    $proj_stmt->bind_param("i", $user_id);
    $proj_stmt->execute();
    $proj_result = $proj_stmt->get_result();
}
$projects = [];
while ($row = $proj_result->fetch_assoc()) {
    $projects[] = $row;
}

$employees = [];
if ($can_reassign) {
    if ($role === "Administrator") {
        $emp_result = $conn->query("SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name, first_name");
    } else {
        $emp_stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE active = 1 AND department = ? ORDER BY last_name, first_name");
        $emp_stmt->bind_param("s", $department);
        $emp_stmt->execute();
        $emp_result = $emp_stmt->get_result();
    }
    while ($row = $emp_result->fetch_assoc()) {
        $employees[] = $row;
    }
}

/* ---- Saved views available to offer in the dropdown ---- */

$saved_views_stmt = $conn->prepare("SELECT id, name FROM saved_views WHERE user_id = ? AND module = 'Tasks' ORDER BY name ASC");
$saved_views_stmt->bind_param("i", $user_id);
$saved_views_stmt->execute();
$saved_views_result = $saved_views_stmt->get_result();
$saved_views = [];
while ($row = $saved_views_result->fetch_assoc()) {
    $saved_views[] = $row;
}
$saved_views_stmt->close();

/* ---- Build the current filter query string, reused for links back
   into this page (column status buttons redirect here preserving
   filters). ---- */

$current_query = [];
if ($project_filter > 0) $current_query["project"] = $project_filter;
if ($priority_filter !== "") $current_query["priority"] = $priority_filter;
if ($assignee_filter > 0) $current_query["assignee"] = $assignee_filter;
if ($view_id > 0) $current_query["view"] = $view_id;
$current_query_string = http_build_query($current_query);

$status_labels = [
    "Not Started" => "NOT STARTED",
    "In Progress" => "IN PROGRESS",
    "Complete" => "COMPLETE",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Task Board</title>

<link rel="stylesheet" href="app.css?v=22">

<style>
.board { display: flex; gap: 16px; align-items: flex-start; }
.board-column { flex: 1; min-width: 0; background: rgba(0,0,0,0.03); border-radius: 8px; padding: 10px; }
.board-column-header { font-weight: 600; padding: 6px 4px 10px 4px; display: flex; justify-content: space-between; }
.board-card { background: #fff; border-radius: 6px; padding: 10px; margin-bottom: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.12); }
.board-card .task-name { font-weight: 600; display: block; margin-bottom: 4px; }
.board-card .meta-line { font-size: 0.85em; opacity: 0.8; margin-bottom: 2px; }
.board-card .badge-blocked { display: inline-block; font-size: 0.75em; padding: 1px 6px; border-radius: 10px; background: #f4d0d0; margin-top: 4px; }
.board-card .completion-bar { background: rgba(0,0,0,0.08); border-radius: 4px; height: 6px; margin-top: 6px; overflow: hidden; }
.board-card .completion-fill { background: #4c8bf5; height: 100%; }
.board-card .move-actions { margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap; }
.board-card .move-actions form { display: inline; }
.board-card .move-actions button { font-size: 0.78em; padding: 3px 8px; }
.board-empty { opacity: 0.6; font-size: 0.9em; padding: 8px 4px; }
</style>

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
            <h1>TASK BOARD</h1>
            <div class="sub">Same tasks and filters as the list view, organized by status</div>
        </div>
        <div class="actions">
            <a class="button" href="tasks.php">LIST VIEW</a>
            <a class="button primary" href="kanban_board.php">BOARD VIEW</a>
        </div>
    </div>

    <div class="content">

        <?php if (isset($_GET["board_error"])): ?>
            <div class="error">
                <?php echo $_GET["board_error"] === "blocked"
                    ? "That task is blocked by an incomplete dependency and can't move to In Progress or Complete yet."
                    : "You don't have permission to change that task's status."; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="GET" class="filters">
                <div class="filter-grid">

                    <div class="field">
                        <label for="project">Project</label>
                        <select id="project" name="project" onchange="this.form.submit()">
                            <option value="0">All Projects</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo (int)$p["id"]; ?>" <?php echo $project_filter === (int)$p["id"] ? "selected" : ""; ?>><?php echo e($p["name"]); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority" onchange="this.form.submit()">
                            <option value="">All Priorities</option>
                            <?php foreach (["Low", "Medium", "High", "Critical"] as $pr): ?>
                                <option value="<?php echo e($pr); ?>" <?php echo $priority_filter === $pr ? "selected" : ""; ?>><?php echo e($pr); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($can_reassign): ?>
                    <div class="field">
                        <label for="assignee">Assignee</label>
                        <select id="assignee" name="assignee" onchange="this.form.submit()">
                            <option value="0">Everyone In Scope</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int)$emp["id"]; ?>" <?php echo $assignee_filter === (int)$emp["id"] ? "selected" : ""; ?>><?php echo e($emp["first_name"] . " " . $emp["last_name"]); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($saved_views): ?>
                    <div class="field">
                        <label for="view">Saved View</label>
                        <select id="view" name="view" onchange="this.form.submit()">
                            <option value="0">— Choose —</option>
                            <?php foreach ($saved_views as $sv): ?>
                                <option value="<?php echo (int)$sv["id"]; ?>" <?php echo $view_id === (int)$sv["id"] ? "selected" : ""; ?>><?php echo e($sv["name"]); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                </div>
            </form>
        </div>

        <div class="board">
        <?php foreach ($columns as $status): ?>
            <div class="board-column">
                <div class="board-column-header">
                    <span><?php echo e($status_labels[$status]); ?></span>
                    <span><?php echo count($board[$status]); ?></span>
                </div>

                <?php if (!$board[$status]): ?>
                    <div class="board-empty">No tasks.</div>
                <?php endif; ?>

                <?php foreach ($board[$status] as $task):
                    $tid = (int)$task["id"];
                    $assignees = $assignees_by_task[$tid] ?? [];
                    $assignee_ids = array_map(fn($a) => $a["id"], $assignees);
                    $project = [
                        "department" => $task["project_department"],
                        "manager_id" => $task["project_manager_id"],
                    ];
                    $can_manage = canBoardManageTask($role, $department, $user_id, $project, $assignee_ids);
                    $blocked = ($status !== "Complete") && taskHasOpenBlockers($conn, $tid);
                    $completion = (int)($task["completion"] ?? 0);
                ?>
                    <div class="board-card">
                        <a class="task-name" href="view_task.php?id=<?php echo $tid; ?>"><?php echo e($task["name"]); ?></a>
                        <div class="meta-line"><?php echo e($task["project_name"]); ?></div>
                        <div class="meta-line">Priority: <?php echo e($task["priority"]); ?></div>
                        <div class="meta-line">
                            Assignee<?php echo count($assignees) === 1 ? "" : "s"; ?>:
                            <?php echo $assignees ? e(implode(", ", array_map(fn($a) => $a["name"], $assignees))) : "Unassigned"; ?>
                        </div>
                        <div class="meta-line">Due: <?php echo $task["due_date"] ? e(date("M j, Y", strtotime($task["due_date"]))) : "—"; ?></div>

                        <div class="completion-bar"><div class="completion-fill" style="width: <?php echo max(0, min(100, $completion)); ?>%;"></div></div>
                        <div class="meta-line"><?php echo $completion; ?>% complete</div>

                        <?php if ($blocked): ?>
                            <span class="badge-blocked">Blocked by dependency</span>
                        <?php endif; ?>

                        <?php if ($can_manage): ?>
                        <div class="move-actions">
                            <?php foreach ($columns as $target_status):
                                if ($target_status === $status) continue;
                                $would_block = in_array($target_status, ["In Progress", "Complete"], true) && $blocked;
                            ?>
                                <form method="POST" action="update_task_status.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="task_id" value="<?php echo $tid; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo e($target_status); ?>">
                                    <input type="hidden" name="return_query" value="<?php echo e($current_query_string); ?>">
                                    <button type="submit" <?php echo $would_block ? "disabled title=\"Blocked by an incomplete dependency\"" : ""; ?>>
                                        Move to <?php echo e($status_labels[$target_status]); ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>

            </div>
        <?php endforeach; ?>
        </div>

    </div>

</div>

</div>

</body>
</html>
