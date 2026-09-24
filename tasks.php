<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "saved_view_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$current_user_stmt = $conn->prepare("
    SELECT role, department
    FROM users
    WHERE id = ? AND active = 1
");
$current_user_stmt->bind_param("i", $user_id);
$current_user_stmt->execute();
$current_user = $current_user_stmt->get_result()->fetch_assoc();
$current_user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$role = $current_user["role"];
$user_department = $current_user["department"];

$can_create_task = isAnyAdministrator($role) || $role === "Manager";

if (!$can_create_task) {
    $manager_check_stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM projects
        WHERE manager_id = ?
    ");
    $manager_check_stmt->bind_param("i", $user_id);
    $manager_check_stmt->execute();
    $can_create_task =
        (int)$manager_check_stmt->get_result()->fetch_assoc()["total"] > 0;
    $manager_check_stmt->close();
}

/*
    Load tasks and their projects.

    Visibility:
    - Administrator: every task.
    - Manager / IT Administrator: tasks whose project is in their own
      department, tasks for projects they manage, or tasks they are
      personally assigned to.
    - Everyone else: only tasks for projects they manage, or tasks
      they are personally assigned to.
*/
$params = [];
$types = "";

if ($role === "Administrator") {
    $where_sql = "1=1";
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $where_sql = "
        (
            projects.department = ?
            OR projects.manager_id = ?
            OR EXISTS (
                SELECT 1 FROM task_assignments ta
                WHERE ta.task_id = tasks.id AND ta.user_id = ?
            )
        )
    ";
    $params = [$user_department, $user_id, $user_id];
    $types = "sii";
} else {
    $where_sql = "
        (
            projects.manager_id = ?
            OR EXISTS (
                SELECT 1 FROM task_assignments ta
                WHERE ta.task_id = tasks.id AND ta.user_id = ?
            )
        )
    ";
    $params = [$user_id, $user_id];
    $types = "ii";
}

/*
    Filters (also usable as Saved Views): status, priority, overdue
    only, and a name search.
*/
$status_filter = trim($_GET["status"] ?? "");
$priority_filter = trim($_GET["priority"] ?? "");
$overdue_only = isset($_GET["overdue"]) && $_GET["overdue"] === "1";
$search = trim($_GET["search"] ?? "");

$allowed_statuses = ["Not Started", "In Progress", "Complete"];
$allowed_priorities = ["Low", "Medium", "High"];

if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = "";
}

if (!in_array($priority_filter, $allowed_priorities, true)) {
    $priority_filter = "";
}

if ($status_filter !== "") {
    $where_sql .= " AND tasks.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($priority_filter !== "") {
    $where_sql .= " AND tasks.priority = ?";
    $params[] = $priority_filter;
    $types .= "s";
}

if ($overdue_only) {
    $where_sql .= " AND tasks.due_date < CURDATE() AND tasks.status <> 'Complete'";
}

if ($search !== "") {
    $where_sql .= " AND tasks.name LIKE ?";
    $params[] = "%" . $search . "%";
    $types .= "s";
}

$sql = "
    SELECT
        tasks.id,
        tasks.project_id,
        tasks.name,
        tasks.priority,
        tasks.status,
        tasks.due_date,
        tasks.completion,
        projects.name AS project_name,
        projects.department AS project_department,
        projects.manager_id AS project_manager_id
    FROM tasks
    INNER JOIN projects
        ON tasks.project_id = projects.id
    WHERE $where_sql
    ORDER BY tasks.due_date ASC, tasks.name ASC
";

$stmt = $conn->prepare($sql);

if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    die("Error loading tasks: " . $conn->error);
}


/*
    Load employees assigned to tasks
*/
$assigned_users = [];

$assignment_sql = "
    SELECT
        task_assignments.task_id,
        users.first_name,
        users.last_name
    FROM task_assignments
    INNER JOIN users
        ON task_assignments.user_id = users.id
    ORDER BY users.last_name ASC, users.first_name ASC
";

$assignment_result = $conn->query($assignment_sql);

if ($assignment_result) {

    while ($assignment = $assignment_result->fetch_assoc()) {

        $task_id = $assignment["task_id"];

        $full_name =
            $assignment["first_name"] . " " .
            $assignment["last_name"];

        if (!isset($assigned_users[$task_id])) {
            $assigned_users[$task_id] = [];
        }

        $assigned_users[$task_id][] = $full_name;
    }
}


/*
    Store tasks so we know how many there are
*/
$tasks = [];

while ($task = $result->fetch_assoc()) {
    $tasks[] = $task;
}

$total_tasks = count($tasks);

if ($role === "Administrator") {
    $department = "All Departments";
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $department = $user_department;
} else {
    $department = "My Tasks";
}

$saved_views = getSavedViews($conn, $user_id, "Tasks");

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Tasks</title>


    <link rel="stylesheet" href="app.css?v=22">

</head>


<body>


<div class="page">


    <!-- =================================
         SIDEBAR
    ================================= -->

    <div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item">
            <a href="dashboard.php">Dashboard</a>
        </div>
        <div class="sidebar-item active">
            <a href="tasks.php">Tasks</a>
        </div>
        <div class="sidebar-item">
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

    <!-- =================================
         TASK PAGE
    ================================= -->

    <main class="main">


        <!-- HEADER -->

        <div class="header">

            <div class="header-title">
                Tasks
            </div>


            <div class="header-department">
                Department: <?= htmlspecialchars($department) ?>
            </div>

        </div>

        <div class="content">

        <!-- NEW TASK BUTTON -->

        <div class="toolbar">

            <?php if ($can_create_task): ?>

                <a href="new_task.php" class="new-task">
                    NEW TASK
                </a>

            <?php endif; ?>

        </div>

        <!-- FILTERS -->

        <div class="card" style="margin-bottom:18px;">

            <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">

                <div class="form-row" style="margin:0;">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?= e($search) ?>" placeholder="Task name...">
                </div>

                <div class="form-row" style="margin:0;">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        <?php foreach ($allowed_statuses as $status_option): ?>
                            <option value="<?= e($status_option) ?>" <?= $status_filter === $status_option ? "selected" : "" ?>>
                                <?= e($status_option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row" style="margin:0;">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <option value="">All</option>
                        <?php foreach ($allowed_priorities as $priority_option): ?>
                            <option value="<?= e($priority_option) ?>" <?= $priority_filter === $priority_option ? "selected" : "" ?>>
                                <?= e($priority_option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row" style="margin:0;flex-direction:row;align-items:center;gap:6px;">
                    <input type="checkbox" id="overdue" name="overdue" value="1" style="width:auto;" <?= $overdue_only ? "checked" : "" ?>>
                    <label for="overdue" style="margin:0;">Overdue only</label>
                </div>

                <button class="button primary" type="submit">FILTER</button>
                <a class="button" href="tasks.php">CLEAR</a>

            </form>

        </div>

        <!-- SAVED VIEWS -->

        <div class="card" style="margin-bottom:18px;">

            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <strong>Saved Views:</strong>
                    <?php if (!$saved_views): ?>
                        <span style="color:var(--slate);">None yet</span>
                    <?php else: ?>
                        <?php foreach ($saved_views as $view): ?>
                            <a class="button" href="tasks.php?<?= e($view["query_string"]) ?>"><?= e($view["name"]) ?></a>
                            <form method="POST" action="saved_view_action.php" style="display:inline;" onsubmit="return confirm('Delete this saved view?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="module" value="Tasks">
                                <input type="hidden" name="view_id" value="<?= (int)$view["id"] ?>">
                                <button class="button" type="submit" title="Delete" style="padding:4px 8px;">&times;</button>
                            </form>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form method="POST" action="saved_view_action.php" id="save_view_form" style="display:flex;gap:8px;align-items:center;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="module" value="Tasks">
                    <input type="hidden" name="query_string" id="save_view_query_string" value="">
                    <input type="text" name="name" placeholder="Name this view..." maxlength="100" style="width:180px;">
                    <button class="button primary" type="submit">SAVE CURRENT VIEW</button>
                </form>

            </div>

        </div>

        <script>
        (function () {
            var form = document.getElementById("save_view_form");
            if (!form) return;
            form.addEventListener("submit", function () {
                document.getElementById("save_view_query_string").value =
                    window.location.search.replace(/^\?/, "");
            });
        })();
        </script>

        <!-- TASKS -->

        <div class="task-area">

            <?php if ($total_tasks > 0): ?>

                <div class="task-grid">


                    <?php foreach ($tasks as $task): ?>


                        <div class="task-card">


                            <!-- TASK NAME -->

                            <div class="task-name">

                                <?= htmlspecialchars($task["name"]) ?>

                            </div>


                            <!-- TASK INFORMATION -->

                            <div class="task-information">


                                <p>
                                    <strong>Project:</strong>
                                    <?= htmlspecialchars($task["project_name"]) ?>
                                </p>


                                <p>

                                    <strong>Assigned:</strong>

                                    <?php

                                    if (isset($assigned_users[$task["id"]])) {

                                        echo htmlspecialchars(
                                            implode(
                                                ", ",
                                                $assigned_users[$task["id"]]
                                            )
                                        );

                                    } else {

                                        echo "None";

                                    }

                                    ?>

                                </p>


                                <br>


                                <p>
                                    <strong>Priority:</strong>
                                    <?= htmlspecialchars($task["priority"] ?? "") ?>
                                </p>


                                <p>
                                    <strong>Status:</strong>
                                    <?= htmlspecialchars($task["status"] ?? "") ?>
                                </p>


                                <p>
                                    <strong>Completion:</strong>
                                    <?= htmlspecialchars($task["completion"] ?? 0) ?>%
                                </p>


                                <!-- DUE DATE -->

                                <p class="due-date">

                                    <strong>Due Date:</strong>

                                    <?php if (!empty($task["due_date"])): ?>

                                        <?= htmlspecialchars($task["due_date"]) ?>

                                    <?php else: ?>

                                        Not Set

                                    <?php endif; ?>

                                </p>


                            </div>


                            <!-- BUTTONS -->

                            <div class="task-actions">


                                <a href="view_task.php?id=<?= $task["id"] ?>">
                                    View Task
                                </a>

                                <?php
                                $can_edit_this_task =
                                    $role === "Administrator"
                                    || (
                                        ($role === "Manager" || $role === "IT Administrator")
                                        && $task["project_department"] === $user_department
                                    )
                                    || (
                                        $task["project_manager_id"] !== null
                                        && (int)$task["project_manager_id"] === $user_id
                                    );
                                ?>

                                <?php if ($can_edit_this_task): ?>

                                    <a href="edit_task.php?id=<?= $task["id"] ?>">
                                        Edit Task
                                    </a>

                                <?php endif; ?>


                            </div>


                        </div>


                    <?php endforeach; ?>


                </div>


            <?php else: ?>

                <div class="no-tasks">
                    No tasks have been created yet.
                </div>

            <?php endif; ?>

        </div>


        <!-- FOOTER -->

        <div class="footer">

            Showing <?= $total_tasks ?> of <?= $total_tasks ?> tasks

        </div>

        </div>

    </main>


</div>


</body>

</html>
