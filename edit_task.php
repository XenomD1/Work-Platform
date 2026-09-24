<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "notification_helpers.php";
require_once "task_dependency_helpers.php";
require_once "task_recurrence_helpers.php";
require_once "csrf_helpers.php";

$current_user_id = (int)$_SESSION["user_id"];

/*
    Re-fetch the current user's role/department from the database rather
    than trusting the session, so a demotion/deactivation/department
    change takes effect immediately instead of only after the user logs
    out and back in.
*/

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

/*
    Administrators can edit any task.
    IT Administrators can only edit tasks belonging to a project
    in their own department.
    Managers can edit tasks in their own department.
    A Project Manager can edit tasks on their own project.
    Permission is checked below once the task is loaded.
*/


/*
    Get task ID
*/

$task_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($task_id <= 0) {
    header("Location: tasks.php");
    exit;
}


/*
    Load task
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
$stmt->bind_param("i", $task_id);
$stmt->execute();

$result = $stmt->get_result();
$task = $result->fetch_assoc();

$stmt->close();


if (!$task) {
    header("Location: tasks.php");
    exit;
}

$can_edit_this_task =
    $current_role === "Administrator"
    || (
        ($current_role === "Manager" || $current_role === "IT Administrator")
        && $task["project_department"] === $current_department
    )
    || (
        $task["project_manager_id"] !== null
        && (int)$task["project_manager_id"] === $current_user_id
    );

if (!$can_edit_this_task) {
    header("Location: tasks.php");
    exit;
}


/*
    Load all active employees
*/

$users = [];

$sql = "
    SELECT
        id,
        first_name,
        last_name,
        department
    FROM users
    WHERE active = 1
    ORDER BY last_name ASC, first_name ASC
";

$result = $conn->query($sql);

while ($user = $result->fetch_assoc()) {
    $users[] = $user;
}


/*
    Load current assignments
*/

$assigned_ids = [];

$sql = "
    SELECT user_id
    FROM task_assignments
    WHERE task_id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $task_id);
$stmt->execute();

$result = $stmt->get_result();

while ($assignment = $result->fetch_assoc()) {
    $assigned_ids[] = (int)$assignment["user_id"];
}

$stmt->close();


/*
    Load current blockers and the other tasks in this project that
    could be selected as a blocker (everything but this task itself).
*/

$current_blocker_ids = [];

$stmt = $conn->prepare("
    SELECT depends_on_task_id
    FROM task_dependencies
    WHERE task_id = ?
");
$stmt->bind_param("i", $task_id);
$stmt->execute();
$result = $stmt->get_result();

while ($dependency = $result->fetch_assoc()) {
    $current_blocker_ids[] = (int)$dependency["depends_on_task_id"];
}

$stmt->close();

$available_blocker_tasks = [];

$stmt = $conn->prepare("
    SELECT id, name, status
    FROM tasks
    WHERE project_id = ?
      AND id <> ?
    ORDER BY name ASC
");
$stmt->bind_param("ii", $task["project_id"], $task_id);
$stmt->execute();
$result = $stmt->get_result();

while ($other_task = $result->fetch_assoc()) {
    $available_blocker_tasks[] = $other_task;
}

$stmt->close();


/*
    Save changes
*/

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $priority = trim($_POST["priority"] ?? "");
    $status = trim($_POST["status"] ?? "");
    $start_date = $_POST["start_date"] ?? "";
    $due_date = $_POST["due_date"] ?? "";

    $selected_users = $_POST["assigned_users"] ?? [];

    $recurrence_type = trim($_POST["recurrence_type"] ?? "none");
    $recurrence_end_date = trim($_POST["recurrence_end_date"] ?? "");
    $recurrence_max_occurrences = trim($_POST["recurrence_max_occurrences"] ?? "");

    /*
        Make sure assigned user IDs are integers.
    */

    $selected_users = array_map("intval", $selected_users);

    /*
        Remove duplicates.
    */

    $selected_users = array_unique($selected_users);


    /*
        Validate task name.
    */

    if ($name === "") {

        $error = "Task name is required.";

    } elseif (
        $status !== "Not Started" &&
        $status !== "In Progress" &&
        $status !== "Complete"
    ) {

        $error = "Invalid task status.";

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

        /*
            Read and clamp the submitted completion value first so it can
            be used to decide whether the status should be auto-promoted.
        */

        $completion = isset($_POST["completion"])
            ? (float)$_POST["completion"]
            : 0;

        if ($completion < 0) {
            $completion = 0;
        }

        if ($completion > 100) {
            $completion = 100;
        }


        /*
            Auto-promote: if the task is still "Not Started" but the user
            entered a completion percentage above 0, that means they've
            started working on it, so bump the status to "In Progress"
            instead of silently discarding what they typed.
        */

        if ($status === "Not Started" && $completion > 0) {
            $status = "In Progress";
        }


        /*
            Completion rules:

            Not Started = 0
            In Progress = manually entered percentage
            Complete = 100
        */

        if ($status === "Not Started") {

            $completion = 0;

        } elseif ($status === "Complete") {

            $completion = 100;

        }

        // else In Progress: keep the clamped value entered above.


        /*
            Convert empty dates to NULL.
        */

        $start_date_value =
            ($start_date !== "")
                ? $start_date
                : null;

        $due_date_value =
            ($due_date !== "")
                ? $due_date
                : null;

        if ($recurrence_type === "none") {
            $recurrence_end_date_value = null;
            $recurrence_max_occurrences_value = null;
        } else {
            $recurrence_end_date_value = $recurrence_end_date !== "" ? $recurrence_end_date : null;
            $recurrence_max_occurrences_value = $recurrence_max_occurrences !== "" ? (int)$recurrence_max_occurrences : null;
        }


        /*
            Task dependencies ("Blocked by").

            A task cannot leave "Not Started" while it still has an
            open (not yet Complete) blocker. This only fires when the
            task is actually attempting to leave "Not Started" - once
            it has moved on, reopening a blocker later does not pull
            it back automatically.

            The selected blocker set is also checked for circular
            dependencies before anything is saved.
        */

        $dependency_ok = true;

        $selected_blockers = $_POST["blocked_by"] ?? [];
        $selected_blockers = array_map("intval", $selected_blockers);
        $selected_blockers = array_values(array_unique($selected_blockers));

        if ($task["status"] === "Not Started" && $status !== "Not Started") {
            if (taskHasOpenBlockers($conn, $task_id)) {
                $error = "This task is blocked by one or more incomplete tasks and cannot be started yet.";
                $dependency_ok = false;
            }
        }

        if (
            $dependency_ok
            && taskDependencyWouldCreateCycle($conn, (int)$task["project_id"], $task_id, $selected_blockers)
        ) {
            $error = "That combination of blockers would create a circular dependency.";
            $dependency_ok = false;
        }

        if (!$dependency_ok) {
            // Fall through without saving; $error is already set and
            // the form below will re-render with it.
        } else {

        /*
            Update task.
        */

        $sql = "
            UPDATE tasks
            SET
                name = ?,
                description = ?,
                priority = ?,
                status = ?,
                start_date = ?,
                due_date = ?,
                completion = ?,
                recurrence_type = ?,
                recurrence_end_date = ?,
                recurrence_max_occurrences = ?
            WHERE id = ?
        ";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            "ssssssdssii",
            $name,
            $description,
            $priority,
            $status,
            $start_date_value,
            $due_date_value,
            $completion,
            $recurrence_type,
            $recurrence_end_date_value,
            $recurrence_max_occurrences_value,
            $task_id
        );

        if ($stmt->execute()) {

            $stmt->close();

            $previous_assigned_ids = $assigned_ids;
            $newly_assigned_ids = array_values(
                array_diff($selected_users, $previous_assigned_ids)
            );
            $removed_assigned_ids = array_values(
                array_diff($previous_assigned_ids, $selected_users)
            );


            /*
                Remove old assignments.
            */

            $sql = "
                DELETE FROM task_assignments
                WHERE task_id = ?
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $task_id);
            $stmt->execute();
            $stmt->close();


            /*
                Add new assignments.
            */

            if (!empty($selected_users)) {

                $sql = "
                    INSERT INTO task_assignments
                    (task_id, user_id)
                    VALUES (?, ?)
                ";

                $stmt = $conn->prepare($sql);

                foreach ($selected_users as $user_id) {

                    $stmt->bind_param(
                        "ii",
                        $task_id,
                        $user_id
                    );

                    $stmt->execute();
                }

                $stmt->close();
            }


            /*
                Replace the blocker set wholesale, same pattern as
                assignments above.
            */

            $sql = "
                DELETE FROM task_dependencies
                WHERE task_id = ?
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $task_id);
            $stmt->execute();
            $stmt->close();

            if (!empty($selected_blockers)) {

                $sql = "
                    INSERT INTO task_dependencies
                    (task_id, depends_on_task_id)
                    VALUES (?, ?)
                ";

                $stmt = $conn->prepare($sql);

                foreach ($selected_blockers as $blocker_id) {

                    $stmt->bind_param(
                        "ii",
                        $task_id,
                        $blocker_id
                    );

                    $stmt->execute();
                }

                $stmt->close();
            }

            foreach ($newly_assigned_ids as $assigned_user_id) {
                if ((int)$assigned_user_id === $current_user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    (int)$assigned_user_id,
                    "Task",
                    "New Task Assignment",
                    "You were assigned to the task \"" . $name . "\".",
                    "Tasks",
                    $task_id,
                    "view_task.php?id=" . $task_id
                );
            }

            foreach ($removed_assigned_ids as $removed_user_id) {
                if ((int)$removed_user_id === $current_user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    (int)$removed_user_id,
                    "Task",
                    "Task Assignment Removed",
                    "You are no longer assigned to the task \"" . $name . "\".",
                    "Tasks",
                    $task_id,
                    "view_task.php?id=" . $task_id
                );
            }

            foreach ($selected_users as $assigned_user_id) {
                if (in_array((int)$assigned_user_id, $newly_assigned_ids, true)) {
                    continue;
                }

                if ((int)$assigned_user_id === $current_user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    (int)$assigned_user_id,
                    "Task",
                    "Task Updated",
                    "The task \"" . $name . "\" was updated.",
                    "Tasks",
                    $task_id,
                    "view_task.php?id=" . $task_id
                );
            }


            /*
                Recurring tasks: if this save just moved the task from
                something else into "Complete", spawn the next
                occurrence (if it's a recurring task that hasn't hit
                its end condition yet).
            */

            if ($task["status"] !== "Complete" && $status === "Complete") {

                $completed_task_for_recurrence = [
                    "id" => $task_id,
                    "project_id" => $task["project_id"],
                    "name" => $name,
                    "description" => $description,
                    "priority" => $priority,
                    "start_date" => $start_date_value,
                    "due_date" => $due_date_value,
                    "recurrence_type" => $recurrence_type,
                    "recurrence_end_date" => $recurrence_end_date_value,
                    "recurrence_max_occurrences" => $recurrence_max_occurrences_value,
                    "recurrence_occurrence_number" => $task["recurrence_occurrence_number"]
                ];

                $new_recurring_task_id = spawnNextTaskOccurrence($conn, $completed_task_for_recurrence);

                if ($new_recurring_task_id !== null) {

                    foreach ($selected_users as $assigned_user_id) {

                        if ((int)$assigned_user_id === $current_user_id) {
                            continue;
                        }

                        createNotification(
                            $conn,
                            (int)$assigned_user_id,
                            "Task",
                            "New Recurring Task",
                            "A new occurrence of \"" . $name . "\" was created.",
                            "Tasks",
                            $new_recurring_task_id,
                            "view_task.php?id=" . $new_recurring_task_id
                        );
                    }
                }
            }


            /*
                Return to the task's view page.
            */

            header(
                "Location: view_task.php?id=" . $task_id
            );

            exit;

        } else {

            $error = "Unable to save the task.";

            $stmt->close();
        }

        } // end dependency_ok gate
    }
}

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
        Edit Task
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


    <!-- =========================
         MAIN
    ========================= -->

    <main class="main">


        <!-- HEADER -->

        <div class="header">

            <div class="header-title">
                Edit Task
            </div>

        </div>


        <!-- CONTENT -->

        <div class="content">


            <?php if ($error !== ""): ?>

                <div class="error">

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>


            <form
                method="POST"
                class="form-box"
            >

                <?= csrfField() ?>


                <div class="form-row">


                    <!-- TASK NAME -->

                    <div class="form-group full">

                        <label for="name">
                            Task Name
                        </label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="<?= htmlspecialchars(
                                $task["name"]
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- PROJECT -->

                    <div class="form-group full">

                        <label>
                            Project
                        </label>

                        <div class="locked-field">

                            <?= htmlspecialchars(
                                $task["project_name"]
                            ) ?>

                        </div>

                        <div class="locked-note">

                            Project assignment cannot be changed
                            from the task editor.

                        </div>

                    </div>


                    <!-- DESCRIPTION -->

                    <div class="form-group full">

                        <label for="description">
                            Description
                        </label>

                        <textarea
                            id="description"
                            name="description"
                        ><?= htmlspecialchars(
                            $task["description"] ?? ""
                        ) ?></textarea>

                    </div>


                    <!-- ASSIGNED TO -->

                    <div class="form-group full">

                        <label>
                            Assigned To
                        </label>


                        <div class="user-list">

                            <?php foreach ($users as $user): ?>

                                <?php
                                $user_id =
                                    (int)$user["id"];

                                $checked =
                                    in_array(
                                        $user_id,
                                        $assigned_ids
                                    );
                                ?>


                                <div class="user-option">

                                    <label>

                                        <input
                                            type="checkbox"
                                            name="assigned_users[]"
                                            value="<?= $user_id ?>"
                                            <?= $checked
                                                ? "checked"
                                                : "" ?>
                                        >

                                        <?= htmlspecialchars(
                                            $user["first_name"]
                                            . " "
                                            . $user["last_name"]
                                        ) ?>

                                        <?php if (
                                            !empty(
                                                $user["department"]
                                            )
                                        ): ?>

                                            —
                                            <?= htmlspecialchars(
                                                $user["department"]
                                            ) ?>

                                        <?php endif; ?>

                                    </label>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>


                    <!-- BLOCKED BY -->

                    <div class="form-group full">

                        <label>
                            Blocked By
                        </label>

                        <?php if (empty($available_blocker_tasks)): ?>

                            <div class="help-text">
                                No other tasks exist in this project yet.
                            </div>

                        <?php else: ?>

                            <div class="user-list">

                                <?php foreach ($available_blocker_tasks as $other_task): ?>

                                    <?php
                                    $other_task_id = (int)$other_task["id"];

                                    $checked = in_array(
                                        $other_task_id,
                                        $current_blocker_ids
                                    );
                                    ?>

                                    <div class="user-option">

                                        <label>

                                            <input
                                                type="checkbox"
                                                name="blocked_by[]"
                                                value="<?= $other_task_id ?>"
                                                <?= $checked ? "checked" : "" ?>
                                            >

                                            <?= htmlspecialchars($other_task["name"]) ?>

                                            —

                                            <?= htmlspecialchars($other_task["status"]) ?>

                                        </label>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                        <div class="help-text">
                            This task cannot leave "Not Started" until every selected task above is Complete.
                        </div>

                    </div>


                    <!-- PRIORITY -->

                    <div class="form-group">

                        <label for="priority">
                            Priority
                        </label>

                        <select
                            id="priority"
                            name="priority"
                        >

                            <option
                                value=""
                                <?= $task["priority"] === ""
                                    ? "selected"
                                    : "" ?>
                            >
                                Select Priority
                            </option>

                            <option
                                value="Low"
                                <?= $task["priority"] === "Low"
                                    ? "selected"
                                    : "" ?>
                            >
                                Low
                            </option>

                            <option
                                value="Medium"
                                <?= $task["priority"] === "Medium"
                                    ? "selected"
                                    : "" ?>
                            >
                                Medium
                            </option>

                            <option
                                value="High"
                                <?= $task["priority"] === "High"
                                    ? "selected"
                                    : "" ?>
                            >
                                High
                            </option>

                        </select>

                    </div>


                    <!-- STATUS -->

                    <div class="form-group">

                        <label for="status">
                            Status
                        </label>

                        <select
                            id="status"
                            name="status"
                        >

                            <option
                                value="Not Started"
                                <?= $task["status"]
                                    === "Not Started"
                                    ? "selected"
                                    : "" ?>
                            >
                                Not Started
                            </option>

                            <option
                                value="In Progress"
                                <?= $task["status"]
                                    === "In Progress"
                                    ? "selected"
                                    : "" ?>
                            >
                                In Progress
                            </option>

                            <option
                                value="Complete"
                                <?= $task["status"]
                                    === "Complete"
                                    ? "selected"
                                    : "" ?>
                            >
                                Complete
                            </option>

                        </select>

                    </div>


                    <!-- COMPLETION -->

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
                            value="<?= htmlspecialchars(
                                $task["completion"] ?? 0
                            ) ?>"
                        >

                    </div>


                    <!-- START DATE -->

                    <div class="form-group">

                        <label for="start_date">
                            Start Date
                        </label>

                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            value="<?= htmlspecialchars(
                                $task["start_date"] ?? ""
                            ) ?>"
                        >

                    </div>


                    <!-- DUE DATE -->

                    <div class="form-group full">

                        <label for="due_date">
                            Due Date
                        </label>

                        <input
                            type="date"
                            id="due_date"
                            name="due_date"
                            value="<?= htmlspecialchars(
                                $task["due_date"] ?? ""
                            ) ?>"
                        >

                    </div>


                    <!-- RECURRENCE -->

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
                            ?>
                            <?php foreach ($recurrence_labels as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= $task["recurrence_type"] === $value ? "selected" : "" ?>>
                                    <?= htmlspecialchars($label) ?>
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
                            value="<?= htmlspecialchars($task["recurrence_end_date"] ?? "") ?>"
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
                            value="<?= htmlspecialchars((string)($task["recurrence_max_occurrences"] ?? "")) ?>"
                        >

                        <div class="help-text">
                            A repeating task needs a due date, and either an end date or a max number of occurrences. The next occurrence is created automatically when this task is marked Complete.
                        </div>

                    </div>


                </div>


                <!-- BUTTONS -->

                <div class="buttons">

                    <button
                        type="submit"
                        class="button"
                    >
                        SAVE CHANGES
                    </button>


                    <a
                        href="view_task.php?id=<?= $task["id"] ?>"
                        class="button"
                    >
                        CANCEL
                    </a>

                </div>


            </form>


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


    </main>

</div>


<script>

    (function () {

        var completionInput = document.getElementById("completion");
        var statusSelect = document.getElementById("status");

        if (!completionInput || !statusSelect) {
            return;
        }

        // Typing a completion % above 0 while "Not Started" is selected
        // means the task has begun, so bump the status automatically.

        completionInput.addEventListener("input", function () {

            var value = parseFloat(completionInput.value);

            if (!isNaN(value) && value > 0 && statusSelect.value === "Not Started") {
                statusSelect.value = "In Progress";
            }

        });

        // Keep completion in sync when the status is changed manually,
        // matching the server-side rules (Not Started = 0, Complete = 100).

        statusSelect.addEventListener("change", function () {

            if (statusSelect.value === "Not Started") {
                completionInput.value = "0";
            } else if (statusSelect.value === "Complete") {
                completionInput.value = "100";
            }

        });

    })();

</script>


</body>

</html>

<?php
$conn->close();
?>
