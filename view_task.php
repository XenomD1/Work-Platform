<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

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


    <link rel="stylesheet" href="app.css?v=20">


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
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item">
            <a href="training.php">Training</a>
        </div>
        <div class="sidebar-item">
            <a href="time_off.php">Time Off</a>
        </div>
        <div class="sidebar-item">
            <a href="notifications.php">Notifications</a>
        </div>

        <?php if (isAnyAdministrator($_SESSION["role"] ?? "")): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
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

            <?php
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
            ?>

            <?php if ($can_edit_task): ?>

                <a
                    href="edit_task.php?id=<?= $task["id"] ?>"
                    class="edit-button"
                >
                    EDIT TASK
                </a>

            <?php endif; ?>

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