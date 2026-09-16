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

if (!isAnyAdministrator($_SESSION["role"])) {
    header("Location: projects.php");
    exit;
}

$message = "";

/*
 * Active users, for the Project Manager selector.
 * Selecting a real account instead of typing a name avoids the
 * whole class of bugs where permission checks elsewhere in the app
 * try to match a free-text manager name against a user's name.
 */
$users = [];

$user_result = $conn->query("
    SELECT id, first_name, last_name
    FROM users
    WHERE active = 1
    ORDER BY last_name ASC, first_name ASC
");

if ($user_result) {
    while ($row = $user_result->fetch_assoc()) {
        $users[] = $row;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $type = trim($_POST["type"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if ($_SESSION["role"] === "IT Administrator") {
        $department = $_SESSION["department"] ?? "";
    }

    $manager_id = (int)($_POST["manager_id"] ?? 0);
    $manager = "";

    if ($manager_id > 0) {
        foreach ($users as $person) {
            if ((int)$person["id"] === $manager_id) {
                $manager = trim($person["first_name"] . " " . $person["last_name"]);
                break;
            }
        }

        if ($manager === "") {
            // Submitted an id that isn't a real active user.
            $manager_id = 0;
        }
    }

    $status = trim($_POST["status"] ?? "");
    $phase = trim($_POST["phase"] ?? "");
    $complexity = trim($_POST["complexity"] ?? "");
    $cost = $_POST["cost"] ?? null;
    $roi = trim($_POST["roi"] ?? "");
    $completion = $_POST["completion"] ?? 0;
    $completion = (float)$completion;

    if ($completion < 0) {
        $completion = 0;
    }

    if ($completion > 100) {
        $completion = 100;
    }

    $start_date = $_POST["start_date"] ?? "";
    $start_date = ($start_date !== "") ? $start_date : null;

    $end_date = $_POST["end_date"] ?? "";
    $end_date = ($end_date !== "") ? $end_date : null;
    $overview = trim($_POST["overview"] ?? "");
    $additional = trim($_POST["additional"] ?? "");

    if ($name === "") {
        $message = "Project Name is required.";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO projects
            (
                name,
                type,
                department,
                description,
                start_date,
                end_date,
                manager,
                manager_id,
                status,
                phase,
                complexity,
                cost,
                roi,
                completion,
                overview,
                additional
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $manager_id_value = $manager_id > 0 ? $manager_id : null;

        $stmt->bind_param(
            "sssssssisssdsdss",
            $name,
            $type,
            $department,
            $description,
            $start_date,
            $end_date,
            $manager,
            $manager_id_value,
            $status,
            $phase,
            $complexity,
            $cost,
            $roi,
            $completion,
            $overview,
            $additional
        );

        if ($stmt->execute()) {

            $project_id = $conn->insert_id;

            $child_tables = [
                "objectives" => ["field" => "objective", "input" => "objectives"],
                "deliverables" => ["field" => "deliverable", "input" => "deliverables"],
                "priorities" => ["field" => "priority", "input" => "priorities"],
                "risks" => ["field" => "risk", "input" => "risks"]
            ];

            foreach ($child_tables as $table => $info) {

                $items = preg_split(
                    "/\r\n|\r|\n/",
                    $_POST[$info["input"]] ?? ""
                );

                $insert = $conn->prepare(
                    "INSERT INTO $table (project_id, {$info["field"]}) VALUES (?, ?)"
                );

                foreach ($items as $item) {

                    $item = trim($item);

                    if ($item === "") {
                        continue;
                    }

                    $insert->bind_param("is", $project_id, $item);
                    $insert->execute();
                }

                $insert->close();
            }


            /*
                Milestones need four fields each (name, due date,
                status, completion), so they're handled separately
                from the single-column child tables above.
            */

            $milestone_names = $_POST["milestone_name"] ?? [];
            $milestone_due = $_POST["milestone_due"] ?? [];
            $milestone_status = $_POST["milestone_status"] ?? [];
            $milestone_completion = $_POST["milestone_completion"] ?? [];

            $milestone_insert = $conn->prepare("
                INSERT INTO milestones
                (
                    project_id,
                    milestone,
                    due_date,
                    status,
                    completion
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($milestone_names as $i => $milestone_name) {

                $milestone_name = trim($milestone_name);

                if ($milestone_name === "") {
                    continue;
                }

                $due_date = $milestone_due[$i] ?? "";
                $due_date = ($due_date !== "") ? $due_date : null;

                $milestone_status_value = trim($milestone_status[$i] ?? "");

                $milestone_completion_value =
                    (float)($milestone_completion[$i] ?? 0);

                if ($milestone_status_value === "Complete") {
                    $milestone_completion_value = 100;
                } elseif ($milestone_status_value === "Not Started") {
                    $milestone_completion_value = 0;
                }

                $milestone_insert->bind_param(
                    "isssd",
                    $project_id,
                    $milestone_name,
                    $due_date,
                    $milestone_status_value,
                    $milestone_completion_value
                );

                $milestone_insert->execute();
            }

            $milestone_insert->close();

            if ($manager_id > 0 && $manager_id !== $current_user_id) {
                createNotification(
                    $conn,
                    $manager_id,
                    "Project",
                    "Project Manager Assignment",
                    "You were assigned as the Project Manager for \"" . $name . "\".",
                    "Projects",
                    $project_id,
                    "projects.php?project=" . $project_id
                );
            }

            header("Location: projects.php?project=" . $project_id);
            exit;

        } else {
            $message = "Error creating project: " . $conn->error;
        }

        $stmt->close();
    }
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

?>

<!DOCTYPE html>
<html>
<head>

    <title>New Project</title>

<link rel="stylesheet" href="app.css?v=9">

</head>

<body>

<div class="page">

    <div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item"><a href="dashboard.php">Dashboard</a></div>
        <div class="sidebar-item"><a href="tasks.php">Tasks</a></div>
        <div class="sidebar-item active"><a href="projects.php">Projects</a></div>
        <div class="sidebar-item"><a href="calendar.php">Calendar</a></div>
        <div class="sidebar-item"><a href="events.php">Meetings / Events</a></div>
        <div class="sidebar-item"><a href="teams.php">Teams</a></div>
        <div class="sidebar-item"><a href="files.php">Files</a></div>
        <div class="sidebar-item"><a href="reports.php">Reports</a></div>
        <div class="sidebar-item"><a href="training.php">Training</a></div>
        <div class="sidebar-item"><a href="time_off.php">Time Off</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>

        <?php if (isAnyAdministrator($_SESSION["role"] ?? "")): ?>
        <div class="sidebar-item"><a href="users.php">User Management</a></div>
        <?php endif; ?>

        <div class="sidebar-bottom">
            <div class="sidebar-item"><a href="account.php">Account</a></div>
            <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
    </div>

    <main class="main">

        <div class="header">
            <div class="header-title">New Project</div>
        </div>

        <div class="content">

    <?php if ($message !== ""): ?>
        <div class="message">
            <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <div class="card">

        <div class="edit-grid">

            <div class="form-row">
                <label>Project Name</label>
                <input
                    type="text"
                    name="name"
                    value="<?php echo e($_POST["name"] ?? ""); ?>"
                    required
                >
            </div>

            <div class="form-row">
                <label>Project Type</label>
                <input
                    type="text"
                    name="type"
                    value="<?php echo e($_POST["type"] ?? ""); ?>"
                >
            </div>

            <div class="form-row">
                <label>Department</label>
                <?php if ($_SESSION["role"] === "IT Administrator"): ?>
                    <input
                        type="text"
                        name="department"
                        value="<?php echo e($_SESSION["department"] ?? ""); ?>"
                        readonly
                    >
                <?php else: ?>
                    <input
                        type="text"
                        name="department"
                        value="<?php echo e($_POST["department"] ?? ""); ?>"
                    >
                <?php endif; ?>
            </div>

            <div class="form-row">
                <label>Project Manager</label>
                <select name="manager_id">
                    <option value="">-- No Manager --</option>

                    <?php
                    $selected_manager_id = (int)($_POST["manager_id"] ?? 0);
                    ?>

                    <?php foreach ($users as $person): ?>
                        <option
                            value="<?php echo (int)$person["id"]; ?>"
                            <?php echo $selected_manager_id === (int)$person["id"] ? "selected" : ""; ?>
                        >
                            <?php echo e($person["first_name"] . " " . $person["last_name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label>Status</label>
                <input
                    type="text"
                    name="status"
                    value="<?php echo e($_POST["status"] ?? ""); ?>"
                >
            </div>

            <div class="form-row">
                <label>Phase</label>
                <input
                    type="text"
                    name="phase"
                    value="<?php echo e($_POST["phase"] ?? ""); ?>"
                >
            </div>

            <div class="form-row">
                <label>Complexity</label>
                <input
                    type="text"
                    name="complexity"
                    value="<?php echo e($_POST["complexity"] ?? ""); ?>"
                >
            </div>

            <div class="form-row">
                <label>Completion %</label>
                <input
                    type="number"
                    name="completion"
                    min="0"
                    max="100"
                    step="0.01"
                    value="<?php echo e($_POST["completion"] ?? "0"); ?>"
                >
            </div>

            <div class="form-row">
                <label>Project Cost</label>
                <input
                    type="number"
                    name="cost"
                    step="0.01"
                    value="<?php echo e($_POST["cost"] ?? ""); ?>"
                >
            </div>

            <div class="form-row">
                <label>Project Benefit / ROI</label>
                <input
                    type="text"
                    name="roi"
                    value="<?php echo e($_POST["roi"] ?? ""); ?>"
                >
            </div>

            <div class="form-row">
                <label>Start Date</label>
                <input
                    type="date"
                    name="start_date"
                    value="<?php echo e($_POST["start_date"] ?? ""); ?>"
                >
            </div>

            <div class="form-row">
                <label>End Date</label>
                <input
                    type="date"
                    name="end_date"
                    value="<?php echo e($_POST["end_date"] ?? ""); ?>"
                >
            </div>

            <div class="form-row full">
                <label>Project Description</label>
                <textarea name="description"><?php echo e($_POST["description"] ?? ""); ?></textarea>
            </div>

            <div class="form-row full">
                <label>Overview</label>
                <textarea name="overview"><?php echo e($_POST["overview"] ?? ""); ?></textarea>
            </div>

            <div class="form-row full">
                <label>Objectives</label>
                <textarea name="objectives"><?php echo e($_POST["objectives"] ?? ""); ?></textarea>
            </div>

            <div class="form-row full">
                <label>Key Deliverables</label>
                <textarea name="deliverables"><?php echo e($_POST["deliverables"] ?? ""); ?></textarea>
            </div>

            <div class="form-row full">
                <label>Milestones</label>

                <div class="milestone-edit-list">

                    <?php for ($i = 0; $i < 5; $i++): ?>

                        <div class="milestone-edit-row">

                            <input
                                type="text"
                                name="milestone_name[]"
                                placeholder="Milestone"
                                value="<?php echo e($_POST["milestone_name"][$i] ?? ""); ?>"
                            >

                            <input
                                type="date"
                                name="milestone_due[]"
                                value="<?php echo e($_POST["milestone_due"][$i] ?? ""); ?>"
                            >

                            <input
                                type="text"
                                name="milestone_status[]"
                                placeholder="Status"
                                value="<?php echo e($_POST["milestone_status"][$i] ?? ""); ?>"
                            >

                            <input
                                type="number"
                                name="milestone_completion[]"
                                placeholder="Completion %"
                                min="0"
                                max="100"
                                step="0.01"
                                value="<?php echo e($_POST["milestone_completion"][$i] ?? ""); ?>"
                            >

                        </div>

                    <?php endfor; ?>

                </div>
            </div>

            <div class="form-row full">
                <label>Priorities</label>
                <textarea name="priorities"><?php echo e($_POST["priorities"] ?? ""); ?></textarea>
            </div>

            <div class="form-row full">
                <label>Risks / Issues</label>
                <textarea name="risks"><?php echo e($_POST["risks"] ?? ""); ?></textarea>
            </div>

            <div class="form-row full">
                <label>Additional Information</label>
                <textarea name="additional"><?php echo e($_POST["additional"] ?? ""); ?></textarea>
            </div>

        </div>

        <div class="toolbar" style="margin-top:20px;">

            <button class="btn-primary" type="submit">
                Create Project
            </button>

            <a href="projects.php" class="button">
                Cancel
            </a>

        </div>

        </div>

    </form>

        </div>

    </main>

</div>

</body>
</html>