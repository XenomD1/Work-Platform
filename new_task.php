<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "notification_helpers.php";
require_once "task_recurrence_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

$current_user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("
    SELECT role, department
    FROM users
    WHERE id = ? AND active = 1
");
$me_stmt->bind_param("i", $current_user_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();
$me_stmt->close();

if (!$me) {
    header("Location: login.php");
    exit;
}

$current_role = $me["role"];
$current_department = $me["department"] ?? "";

if (!isAnyAdministrator($current_role)) {
    header("Location: tasks.php");
    exit;
}


/* -------------------------------------------------
   GET PROJECT
------------------------------------------------- */

$project_id = isset($_GET["project"])
    ? (int)$_GET["project"]
    : 0;


/* -------------------------------------------------
   LOAD PROJECTS
   IT Administrators only see/choose from projects in
   their own department.
------------------------------------------------- */

$projects = [];

$project_result = $conn->query("
    SELECT id, name, department
    FROM projects
    ORDER BY name ASC
");

if ($project_result) {
    while ($row = $project_result->fetch_assoc()) {
        if (
            canAdministerDepartment(
                $current_role,
                $current_department,
                $row["department"]
            )
        ) {
            $projects[] = $row;
        }
    }
}


/* -------------------------------------------------
   LOAD SELECTED PROJECT
------------------------------------------------- */

$project = null;

if ($project_id > 0) {

    $stmt = $conn->prepare("
        SELECT id, name, department
        FROM projects
        WHERE id = ?
    ");

    $stmt->bind_param("i", $project_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $project = $result->fetch_assoc();

    $stmt->close();

    if (
        !$project
        || !canAdministerDepartment(
            $current_role,
            $current_department,
            $project["department"]
        )
    ) {
        $project = null;
        $project_id = 0;
    }
}


/* -------------------------------------------------
   LOAD ACTIVE USERS
------------------------------------------------- */

$users = [];

$result = $conn->query("
    SELECT id, first_name, last_name, department
    FROM users
    WHERE active = 1
    ORDER BY last_name ASC, first_name ASC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}


/* -------------------------------------------------
   CREATE TASK
------------------------------------------------- */

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    if ($project_id <= 0) {
        $project_id = (int)($_POST["project_id"] ?? 0);
    }

    if ($project_id > 0 && $project === null) {

        $stmt = $conn->prepare("
            SELECT id, name, department
            FROM projects
            WHERE id = ?
        ");

        $stmt->bind_param("i", $project_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $project = $result->fetch_assoc();

        $stmt->close();

        if (
            $project
            && !canAdministerDepartment(
                $current_role,
                $current_department,
                $project["department"]
            )
        ) {
            $project = null;
            $project_id = 0;
        }
    }

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $priority = trim($_POST["priority"] ?? "Medium");
    $status = trim($_POST["status"] ?? "Not Started");

    $completion = $_POST["completion"] ?? 0;

    $start_date = $_POST["start_date"] ?? "";
    $due_date = $_POST["due_date"] ?? "";

    $assigned_users = $_POST["assigned_users"] ?? [];

    $recurrence_type = trim($_POST["recurrence_type"] ?? "none");
    $recurrence_end_date = trim($_POST["recurrence_end_date"] ?? "");
    $recurrence_max_occurrences = trim($_POST["recurrence_max_occurrences"] ?? "");


    /* ---------------------------------------------
       VALIDATION
    --------------------------------------------- */

    if ($project_id <= 0 || $project === null) {

        $error = "Please select a project.";

    } elseif ($name === "") {

        $error = "Task name is required.";

    } elseif (!in_array($recurrence_type, TASK_RECURRENCE_TYPES, true)) {

        $error = "Invalid recurrence type.";

    } elseif (
        $recurrence_type !== "none"
        && $recurrence_end_date === ""
        && $recurrence_max_occurrences === ""
    ) {

        $error = "A recurring task needs either an end date or a maximum number of occurrences.";

    } elseif ($recurrence_type !== "none" && $due_date === "") {

        $error = "A recurring task needs a due date to schedule the next occurrence from.";

    } else {

        $completion = (float)$completion;

        if ($completion < 0) {
            $completion = 0;
        }

        if ($completion > 100) {
            $completion = 100;
        }


        /* -----------------------------------------
           AUTO-PROMOTE: entering a completion
           percentage above 0 while status is still
           "Not Started" means the task has begun, so
           bump the status instead of silently resetting
           the completion back to 0.
        ----------------------------------------- */

        if ($status === "Not Started" && $completion > 0) {
            $status = "In Progress";
        }


        /* -----------------------------------------
           STATUS CONTROLS COMPLETION
        ----------------------------------------- */

        if ($status === "Not Started") {
            $completion = 0;
        } elseif ($status === "Complete") {
            $completion = 100;
        }


        /* -----------------------------------------
           EMPTY DATES BECOME NULL
        ----------------------------------------- */

        if ($start_date === "") {
            $start_date = null;
        }

        if ($due_date === "") {
            $due_date = null;
        }

        if ($recurrence_type === "none") {
            $recurrence_end_date = null;
            $recurrence_max_occurrences = null;
        } else {
            $recurrence_end_date = $recurrence_end_date !== "" ? $recurrence_end_date : null;
            $recurrence_max_occurrences = $recurrence_max_occurrences !== "" ? (int)$recurrence_max_occurrences : null;
        }


        /* -----------------------------------------
           INSERT TASK
        ----------------------------------------- */

        $stmt = $conn->prepare("
            INSERT INTO tasks
            (
                project_id,
                name,
                description,
                priority,
                status,
                start_date,
                due_date,
                completion,
                recurrence_type,
                recurrence_end_date,
                recurrence_max_occurrences
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "issssssdssi",
            $project_id,
            $name,
            $description,
            $priority,
            $status,
            $start_date,
            $due_date,
            $completion,
            $recurrence_type,
            $recurrence_end_date,
            $recurrence_max_occurrences
        );

        if ($stmt->execute()) {

            $task_id = $stmt->insert_id;

            $stmt->close();


            /* -------------------------------------
               INSERT ASSIGNMENTS
               Only for IDs that correspond to a real,
               active user - a tampered or stale form
               submission shouldn't be able to create an
               assignment pointing at a nonexistent user.
            ------------------------------------- */

            if (!empty($assigned_users)) {

                $valid_user_ids = [];

                foreach ($users as $person) {
                    $valid_user_ids[(int)$person["id"]] = true;
                }

                $assignment_stmt = $conn->prepare("
                    INSERT INTO task_assignments
                    (
                        task_id,
                        user_id
                    )
                    VALUES (?, ?)
                ");

                foreach ($assigned_users as $user_id) {

                    $user_id = (int)$user_id;

                    if (!isset($valid_user_ids[$user_id])) {
                        continue;
                    }

                    $assignment_stmt->bind_param(
                        "ii",
                        $task_id,
                        $user_id
                    );

                    if ($assignment_stmt->execute()) {
                        if ($user_id !== $current_user_id) {
                            createNotification(
                                $conn,
                                $user_id,
                                "Task",
                                "New Task Assignment",
                                "You were assigned to the task \"" . $name . "\".",
                                "Tasks",
                                $task_id,
                                "view_task.php?id=" . $task_id
                            );
                        }
                    }
                }

                $assignment_stmt->close();
            }


            /* -------------------------------------
               RETURN TO TASK
            ------------------------------------- */

            header("Location: view_task.php?id=" . $task_id);
            exit;

        } else {

            $error = "Unable to create the task.";
            $stmt->close();
        }
    }
}


/* -------------------------------------------------
   HTML ESCAPE
------------------------------------------------- */

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>New Task</title>

<link rel="stylesheet" href="app.css?v=22">

</head>

<body>

<div class="page">


    <!-- SIDEBAR -->

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

        <?php if (canPostAnnouncements($current_role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($current_role)): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($current_role === "Administrator"): ?>
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


    <!-- MAIN -->

    <div class="main">


        <!-- HEADER -->

        <div class="header">

            <div class="header-title">
                New Task
            </div>

            <div class="header-project">
                <?php if ($project !== null): ?>
                    Project: <?= e($project["name"]) ?>
                <?php else: ?>
                    Select Project
                <?php endif; ?>
            </div>

        </div>


        <!-- CONTENT -->

        <div class="content">


            <?php if ($error !== ""): ?>

                <div class="error">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>


            <form method="POST">

                <?= csrfField() ?>


                <!-- TASK INFORMATION -->

                <div class="form-box">

                    <div class="form-title">
                        Task Information
                    </div>


                    <div class="form-row">


                        <div class="form-group full">

                            <label for="name">
                                Task Name
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?= e($_POST["name"] ?? "") ?>"
                                required
                            >

                        </div>


                        <div class="form-group full">

                            <label for="project_id">
                                Project
                            </label>

                            <?php if ($project !== null): ?>

                                <div class="project-locked">
                                    <?= e($project["name"]) ?>
                                </div>

                                <input
                                    type="hidden"
                                    name="project_id"
                                    value="<?= (int)$project["id"] ?>"
                                >

                            <?php else: ?>

                                <select
                                    id="project_id"
                                    name="project_id"
                                    required
                                >
                                    <option value="">Select a project</option>

                                    <?php foreach ($projects as $available_project): ?>

                                        <option
                                            value="<?= (int)$available_project["id"] ?>"
                                            <?= (
                                                (int)($_POST["project_id"] ?? 0)
                                                === (int)$available_project["id"]
                                            ) ? "selected" : "" ?>
                                        >
                                            <?= e($available_project["name"]) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            <?php endif; ?>

                        </div>


                        <div class="form-group full">

                            <label for="description">
                                Description
                            </label>

                            <textarea
                                id="description"
                                name="description"
                            ><?= e($_POST["description"] ?? "") ?></textarea>

                        </div>


                        <div class="form-group">

                            <label for="priority">
                                Priority
                            </label>

                            <select
                                id="priority"
                                name="priority"
                            >

                                <option value="Low"
                                    <?= (($_POST["priority"] ?? "") === "Low") ? "selected" : "" ?>>
                                    Low
                                </option>

                                <option value="Medium"
                                    <?= (($_POST["priority"] ?? "Medium") === "Medium") ? "selected" : "" ?>>
                                    Medium
                                </option>

                                <option value="High"
                                    <?= (($_POST["priority"] ?? "") === "High") ? "selected" : "" ?>>
                                    High
                                </option>

                            </select>

                        </div>


                        <div class="form-group">

                            <label for="status">
                                Status
                            </label>

                            <select
                                id="status"
                                name="status"
                            >

                                <option value="Not Started"
                                    <?= (($_POST["status"] ?? "Not Started") === "Not Started") ? "selected" : "" ?>>
                                    Not Started
                                </option>

                                <option value="In Progress"
                                    <?= (($_POST["status"] ?? "") === "In Progress") ? "selected" : "" ?>>
                                    In Progress
                                </option>

                                <option value="Complete"
                                    <?= (($_POST["status"] ?? "") === "Complete") ? "selected" : "" ?>>
                                    Complete
                                </option>

                            </select>

                        </div>


                        <div class="form-group">

                            <label for="completion">
                                Completion %
                            </label>

                            <input
                                type="number"
                                id="completion"
                                name="completion"
                                min="0"
                                max="100"
                                step="1"
                                value="<?= e($_POST["completion"] ?? "0") ?>"
                            >

                        </div>


                        <div class="form-group">

                            <label for="start_date">
                                Start Date
                            </label>

                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                value="<?= e($_POST["start_date"] ?? "") ?>"
                            >

                        </div>


                        <div class="form-group">

                            <label for="due_date">
                                Due Date
                            </label>

                            <input
                                type="date"
                                id="due_date"
                                name="due_date"
                                value="<?= e($_POST["due_date"] ?? "") ?>"
                            >

                        </div>


                    </div>


                    <div class="form-row">

                        <div class="form-group">

                            <label for="recurrence_type">
                                Repeats
                            </label>

                            <select id="recurrence_type" name="recurrence_type">
                                <?php
                                $recurrence_labels = [
                                    "none" => "Does Not Repeat",
                                    "daily" => "Daily",
                                    "weekly" => "Weekly",
                                    "monthly" => "Monthly",
                                    "quarterly" => "Quarterly",
                                    "yearly" => "Yearly"
                                ];
                                $selected_recurrence = $_POST["recurrence_type"] ?? "none";
                                ?>
                                <?php foreach ($recurrence_labels as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $selected_recurrence === $value ? "selected" : "" ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        </div>

                        <div class="form-group">

                            <label for="recurrence_end_date">
                                Repeat Until
                            </label>

                            <input
                                type="date"
                                id="recurrence_end_date"
                                name="recurrence_end_date"
                                value="<?= e($_POST["recurrence_end_date"] ?? "") ?>"
                            >

                        </div>

                        <div class="form-group">

                            <label for="recurrence_max_occurrences">
                                Or Max Occurrences
                            </label>

                            <input
                                type="number"
                                id="recurrence_max_occurrences"
                                name="recurrence_max_occurrences"
                                min="1"
                                value="<?= e($_POST["recurrence_max_occurrences"] ?? "") ?>"
                            >

                            <div class="help-text">
                                A repeating task needs a due date, and either an end date or a max number of occurrences.
                            </div>

                        </div>

                    </div>


                </div>


                <!-- ASSIGNED USERS -->

                <div class="form-box">

                    <div class="form-title">
                        Assign Employees
                    </div>

                    <div class="user-list">

                        <?php if (empty($users)): ?>

                            No active employees are available.

                        <?php else: ?>

                            <?php foreach ($users as $user): ?>

                                <div class="user-option">

                                    <label>

                                        <input
                                            type="checkbox"
                                            name="assigned_users[]"
                                            value="<?= $user["id"] ?>"
                                            <?php
                                            if (
                                                isset($_POST["assigned_users"]) &&
                                                in_array(
                                                    $user["id"],
                                                    $_POST["assigned_users"]
                                                )
                                            ) {
                                                echo "checked";
                                            }
                                            ?>
                                        >

                                        <?= e(
                                            $user["first_name"] .
                                            " " .
                                            $user["last_name"]
                                        ) ?>

                                        <?php if (!empty($user["department"])): ?>

                                            — <?= e($user["department"]) ?>

                                        <?php endif; ?>

                                    </label>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- ACTIONS -->

                <div class="actions">

                    <button
                        type="submit"
                        class="button"
                    >
                        Create Task
                    </button>

                    <a
                        href="<?= $project_id > 0
                            ? "projects.php?project=" . $project_id
                            : "tasks.php"
                        ?>"
                        class="button"
                    >
                        Cancel
                    </a>

                </div>


            </form>

        </div>

    </div>

</div>

</body>

</html>
