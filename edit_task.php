<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "notification_helpers.php";

$current_user_id = (int)$_SESSION["user_id"];

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
    $_SESSION["role"] === "Administrator"
    || (
        ($_SESSION["role"] === "Manager" || $_SESSION["role"] === "IT Administrator")
        && $task["project_department"] === ($_SESSION["department"] ?? "")
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
    Save changes
*/

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $priority = trim($_POST["priority"] ?? "");
    $status = trim($_POST["status"] ?? "");
    $start_date = $_POST["start_date"] ?? "";
    $due_date = $_POST["due_date"] ?? "";

    $selected_users = $_POST["assigned_users"] ?? [];

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
                completion = ?
            WHERE id = ?
        ";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            "ssssssdi",
            $name,
            $description,
            $priority,
            $status,
            $start_date_value,
            $due_date_value,
            $completion,
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