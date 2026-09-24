<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "task_dependency_helpers.php";
require_once "task_recurrence_helpers.php";
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


/*
    Get task ID
*/

$task_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($task_id <= 0) {
    header("Location: tasks.php");
    exit;
}


/*
    Load task information
*/

$sql = "
    SELECT
        tasks.id,
        tasks.project_id,
        tasks.name,
        tasks.description,
        tasks.priority,
        tasks.status,
        tasks.start_date,
        tasks.due_date,
        tasks.completion,
        tasks.recurrence_type,
        tasks.recurrence_end_date,
        tasks.recurrence_max_occurrences,
        tasks.recurrence_occurrence_number,
        tasks.recurrence_parent_task_id,
        projects.name AS project_name,
        projects.department AS project_department,
        projects.manager_id AS project_manager_id
    FROM tasks
    INNER JOIN projects
        ON tasks.project_id = projects.id
    WHERE tasks.id = ?
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "i",
    $task_id
);

$stmt->execute();

$result = $stmt->get_result();

$task = $result->fetch_assoc();

$stmt->close();


/*
    If task does not exist
*/

if (!$task) {
    header("Location: tasks.php");
    exit;
}

$is_assigned_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM task_assignments
    WHERE task_id = ? AND user_id = ?
");
$is_assigned_stmt->bind_param("ii", $task_id, $user_id);
$is_assigned_stmt->execute();
$is_assigned = (int)$is_assigned_stmt->get_result()->fetch_assoc()["total"] > 0;
$is_assigned_stmt->close();

$can_view_task =
    $role === "Administrator"
    || (
        ($role === "Manager" || $role === "IT Administrator")
        && $task["project_department"] === $user_department
    )
    || (
        $task["project_manager_id"] !== null
        && (int)$task["project_manager_id"] === $user_id
    )
    || $is_assigned;

if (!$can_view_task) {
    header("Location: tasks.php");
    exit;
}


/*
    Load employees assigned to the task
*/

$assigned_users = [];

$sql = "
    SELECT
        users.first_name,
        users.last_name
    FROM task_assignments
    INNER JOIN users
        ON task_assignments.user_id = users.id
    WHERE task_assignments.task_id = ?
    ORDER BY users.last_name ASC, users.first_name ASC
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "i",
    $task_id
);

$stmt->execute();

$assigned_result = $stmt->get_result();

while ($user = $assigned_result->fetch_assoc()) {

    $assigned_users[] =
        $user["first_name"] . " " .
        $user["last_name"];
}

$stmt->close();

/*
    Edit permission (this was missing entirely - without it,
    $can_edit_task was undefined and the EDIT TASK button, and
    checklist deletion, silently never appeared for anyone).
*/

$can_edit_task =
    $role === "Administrator"
    || (
        ($role === "Manager" || $role === "IT Administrator")
        && $task["project_department"] === $user_department
    )
    || (
        $task["project_manager_id"] !== null
        && (int)$task["project_manager_id"] === $user_id
    );

/*
    Task checklist.
*/

$checklist_message = "";
$checklist_error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["checklist_action"])) {

    requireValidCsrfToken();

    $checklist_action = trim($_POST["checklist_action"]);

    if ($checklist_action === "add") {

        $item_text = trim($_POST["item_text"] ?? "");

        if ($item_text === "") {
            $checklist_error = "Checklist item text is required.";
        } elseif (mb_strlen($item_text) > 255) {
            $checklist_error = "Checklist item text is too long.";
        } else {

            $sort_stmt = $conn->prepare("
                SELECT COALESCE(MAX(sort_order), -1) AS max_sort
                FROM task_checklist_items
                WHERE task_id = ?
            ");
            $sort_stmt->bind_param("i", $task_id);
            $sort_stmt->execute();
            $next_sort = (int)$sort_stmt->get_result()->fetch_assoc()["max_sort"] + 1;
            $sort_stmt->close();

            $insert_stmt = $conn->prepare("
                INSERT INTO task_checklist_items
                    (task_id, item_text, created_by, sort_order)
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->bind_param("isii", $task_id, $item_text, $user_id, $next_sort);

            if ($insert_stmt->execute()) {
                $checklist_message = "Checklist item added.";
            } else {
                $checklist_error = "Unable to add checklist item.";
            }

            $insert_stmt->close();
        }

    } elseif ($checklist_action === "toggle") {

        $item_id = (int)($_POST["item_id"] ?? 0);

        $item_stmt = $conn->prepare("
            SELECT id, is_completed
            FROM task_checklist_items
            WHERE id = ? AND task_id = ?
        ");
        $item_stmt->bind_param("ii", $item_id, $task_id);
        $item_stmt->execute();
        $checklist_item = $item_stmt->get_result()->fetch_assoc();
        $item_stmt->close();

        if (!$checklist_item) {
            $checklist_error = "Checklist item not found.";
        } else {

            $now_completed = (int)$checklist_item["is_completed"] === 1 ? 0 : 1;

            if ($now_completed === 1) {
                $toggle_stmt = $conn->prepare("
                    UPDATE task_checklist_items
                    SET is_completed = 1,
                        completed_by = ?,
                        completed_at = NOW()
                    WHERE id = ?
                ");
                $toggle_stmt->bind_param("ii", $user_id, $item_id);
            } else {
                $toggle_stmt = $conn->prepare("
                    UPDATE task_checklist_items
                    SET is_completed = 0,
                        completed_by = NULL,
                        completed_at = NULL
                    WHERE id = ?
                ");
                $toggle_stmt->bind_param("i", $item_id);
            }

            if ($toggle_stmt->execute()) {
                $checklist_message = $now_completed === 1
                    ? "Checklist item marked complete."
                    : "Checklist item reopened.";
            } else {
                $checklist_error = "Unable to update checklist item.";
            }

            $toggle_stmt->close();
        }

    } elseif ($checklist_action === "delete") {

        if (!$can_edit_task) {
            $checklist_error = "You do not have permission to delete checklist items.";
        } else {

            $item_id = (int)($_POST["item_id"] ?? 0);

            $delete_stmt = $conn->prepare("
                DELETE FROM task_checklist_items
                WHERE id = ? AND task_id = ?
            ");
            $delete_stmt->bind_param("ii", $item_id, $task_id);

            if ($delete_stmt->execute()) {
                $checklist_message = "Checklist item deleted.";
            } else {
                $checklist_error = "Unable to delete checklist item.";
            }

            $delete_stmt->close();
        }
    }
}

$checklist_items = [];

$checklist_stmt = $conn->prepare("
    SELECT id, item_text, is_completed, sort_order
    FROM task_checklist_items
    WHERE task_id = ?
    ORDER BY sort_order ASC, id ASC
");
$checklist_stmt->bind_param("i", $task_id);
$checklist_stmt->execute();
$checklist_result = $checklist_stmt->get_result();

while ($row = $checklist_result->fetch_assoc()) {
    $checklist_items[] = $row;
}

$checklist_stmt->close();

$task_blockers = getTaskBlockers($conn, $task_id);
$recurrence_parent_task = null;

if (!empty($task["recurrence_parent_task_id"])) {
    $parent_stmt = $conn->prepare("SELECT id, name FROM tasks WHERE id = ?");
    $parent_stmt->bind_param("i", $task["recurrence_parent_task_id"]);
    $parent_stmt->execute();
    $recurrence_parent_task = $parent_stmt->get_result()->fetch_assoc();
    $parent_stmt->close();
}

$recurrence_labels = [
    "daily" => "Daily",
    "weekly" => "Weekly",
    "monthly" => "Monthly",
    "quarterly" => "Quarterly",
    "yearly" => "Yearly"
];
$tasks_blocked_by_this = getTasksBlockedBy($conn, $task_id);

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        View Task
    </title>


    <link rel="stylesheet" href="app.css?v=22">


</head>


<body>


<div class="page">


    <!-- =========================
         SIDEBAR
    ========================= -->

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


    <!-- =========================
         MAIN
    ========================= -->

    <main class="main">


        <!-- HEADER -->

        <div class="header">

            <div class="header-title">
                Task Details
            </div>

            <div class="header-actions">

                <a href="comments.php?module=Task&id=<?= (int)$task["id"] ?>" class="button">
                    COMMENTS
                </a>

                <?php if ($can_edit_task): ?>

                    <a
                        href="edit_task.php?id=<?= $task["id"] ?>"
                        class="edit-button"
                    >
                        EDIT TASK
                    </a>

                <?php endif; ?>

            </div>

        </div>


        <!-- CONTENT -->

        <div class="content">


            <!-- TASK NAME -->

            <div class="task-name">

                <?= htmlspecialchars($task["name"]) ?>

            </div>


            <!-- DESCRIPTION -->

            <div class="description">

                <div class="section-title">
                    Description
                </div>


                <div class="description-text"><?php if (!empty($task["description"])): ?><?= htmlspecialchars($task["description"]) ?><?php else: ?>No description provided.<?php endif; ?></div>

            </div>


            <!-- TASK INFORMATION -->

            <div class="information">


                <!-- PROJECT -->

                <div class="information-item">

                    <div class="label">
                        Project
                    </div>


                    <div class="value">

                        <a
                            href="projects.php?project=<?= $task["project_id"] ?>"
                            style="color: black;"
                        >

                            <?= htmlspecialchars($task["project_name"]) ?>

                        </a>

                    </div>

                </div>


                <!-- ASSIGNED TO -->

                <div class="information-item">

                    <div class="label">
                        Assigned To
                    </div>


                    <div class="value">

                        <?php if (count($assigned_users) > 0): ?>

                            <ul class="assigned-list">

                                <?php foreach ($assigned_users as $user): ?>

                                    <li>
                                        <?= htmlspecialchars($user) ?>
                                    </li>

                                <?php endforeach; ?>

                            </ul>

                        <?php else: ?>

                            No employees assigned.

                        <?php endif; ?>

                    </div>

                </div>


                <!-- PRIORITY -->

                <div class="information-item">

                    <div class="label">
                        Priority
                    </div>


                    <div class="value">

                        <?= htmlspecialchars(
                            $task["priority"] ?? ""
                        ) ?>

                    </div>

                </div>


                <!-- STATUS -->

                <div class="information-item">

                    <div class="label">
                        Status
                    </div>


                    <div class="value">

                        <?= htmlspecialchars(
                            $task["status"] ?? ""
                        ) ?>

                    </div>

                </div>


                <!-- COMPLETION -->

                <div class="information-item">

                    <div class="label">
                        Completion
                    </div>


                    <div class="value">

                        <?= htmlspecialchars(
                            $task["completion"] ?? 0
                        ) ?>%

                    </div>

                </div>


                <!-- START DATE -->

                <div class="information-item">

                    <div class="label">
                        Start Date
                    </div>


                    <div class="value">

                        <?php if (!empty($task["start_date"])): ?>

                            <?= htmlspecialchars(
                                $task["start_date"]
                            ) ?>

                        <?php else: ?>

                            Not Set

                        <?php endif; ?>

                    </div>

                </div>


                <!-- DUE DATE -->

                <div class="information-item">

                    <div class="label">
                        Due Date
                    </div>


                    <div class="value">

                        <?php if (!empty($task["due_date"])): ?>

                            <?= htmlspecialchars(
                                $task["due_date"]
                            ) ?>

                        <?php else: ?>

                            Not Set

                        <?php endif; ?>

                    </div>

                </div>


                <!-- RECURRENCE -->

                <?php if ($task["recurrence_type"] !== "none"): ?>
                <div class="information-item">

                    <div class="label">
                        Repeats
                    </div>

                    <div class="value">

                        <?= htmlspecialchars($recurrence_labels[$task["recurrence_type"]] ?? $task["recurrence_type"]) ?>

                        <?php if (!empty($task["recurrence_end_date"])): ?>
                            until <?= htmlspecialchars(date("M j, Y", strtotime($task["recurrence_end_date"]))) ?>
                        <?php endif; ?>

                        <?php if (!empty($task["recurrence_max_occurrences"])): ?>
                            (occurrence <?= (int)$task["recurrence_occurrence_number"] ?> of <?= (int)$task["recurrence_max_occurrences"] ?>)
                        <?php endif; ?>

                    </div>

                </div>
                <?php endif; ?>

                <?php if ($recurrence_parent_task): ?>
                <div class="information-item">

                    <div class="label">
                        Spawned From
                    </div>

                    <div class="value">
                        <a
                            href="view_task.php?id=<?= (int)$recurrence_parent_task["id"] ?>"
                            style="color: black;"
                        >
                            <?= htmlspecialchars($recurrence_parent_task["name"]) ?>
                        </a>
                    </div>

                </div>
                <?php endif; ?>


                <!-- TASK ID -->

                <?php if (isAnyAdministrator($role)): ?>
                <div class="information-item">

                    <div class="label">
                        Task ID
                    </div>


                    <div class="value">

                        <?= htmlspecialchars(
                            $task["id"]
                        ) ?>

                    </div>

                </div>
                <?php endif; ?>


            </div>



        <div class="card">

            <h2>Dependencies</h2>

            <div class="dependency-section">
                <div class="dependency-label">Blocked By</div>

                <?php if (!$task_blockers): ?>

                    <div class="empty">This task has no blockers.</div>

                <?php else: ?>

                    <div class="dependency-list">

                        <?php foreach ($task_blockers as $blocker): ?>

                            <a
                                class="dependency-item<?= $blocker["status"] !== "Complete" ? " open" : "" ?>"
                                href="view_task.php?id=<?= (int)$blocker["id"] ?>"
                            >
                                <?= htmlspecialchars($blocker["name"]) ?>
                                <span class="dependency-status"><?= htmlspecialchars($blocker["status"]) ?></span>
                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>
            </div>

            <div class="dependency-section">
                <div class="dependency-label">Blocking</div>

                <?php if (!$tasks_blocked_by_this): ?>

                    <div class="empty">This task is not blocking anything.</div>

                <?php else: ?>

                    <div class="dependency-list">

                        <?php foreach ($tasks_blocked_by_this as $blocked_task): ?>

                            <a
                                class="dependency-item"
                                href="view_task.php?id=<?= (int)$blocked_task["id"] ?>"
                            >
                                <?= htmlspecialchars($blocked_task["name"]) ?>
                                <span class="dependency-status"><?= htmlspecialchars($blocked_task["status"]) ?></span>
                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>
            </div>

        </div>


        <div class="card checklist-card">
            <h2>Task Checklist</h2>
            <?php if ($checklist_message !== ""): ?><div class="message"><?= htmlspecialchars($checklist_message) ?></div><?php endif; ?>
            <?php if ($checklist_error !== ""): ?><div class="error"><?= htmlspecialchars($checklist_error) ?></div><?php endif; ?>

            <form method="post" class="checklist-form">
                <?= csrfField() ?>
                <input type="hidden" name="checklist_action" value="add">
                <div class="checklist-form-field">
                    <label for="item_text">New Checklist Item</label>
                    <input id="item_text" name="item_text" maxlength="255" required>
                </div>
                <button class="button primary" type="submit">ADD ITEM</button>
            </form>

            <?php if (!$checklist_items): ?>
                <div class="empty">No checklist items yet.</div>
            <?php else: ?>
                <?php foreach ($checklist_items as $item): ?>
                    <div class="checklist-row">
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="checklist_action" value="toggle">
                            <input type="hidden" name="item_id" value="<?= (int)$item["id"] ?>">
                            <button class="button" type="submit"><?= (int)$item["is_completed"] === 1 ? "REOPEN" : "COMPLETE" ?></button>
                        </form>
                        <div class="checklist-text<?= (int)$item["is_completed"] === 1 ? " completed" : "" ?>">
                            <?= htmlspecialchars($item["item_text"]) ?>
                        </div>
                        <?php if ($can_edit_task): ?>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="checklist_action" value="delete">
                                <input type="hidden" name="item_id" value="<?= (int)$item["id"] ?>">
                                <button class="button danger" type="submit">DELETE</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- FOOTER -->

        <div class="footer">

            <a
                href="tasks.php"
                style="color: black;"
            >
                Back to Tasks
            </a>

        </div>

        </div>


    </main>

</div>


</body>

</html>
