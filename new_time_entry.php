<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";
require_once "time_entry_helpers.php";
require_once "announcement_helpers.php";

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

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$projects = getLoggableProjects($conn, $user_id, $role, $department);

$selected_project = (int)($_GET["project"] ?? ($_POST["project_id"] ?? 0));
$tasks = $selected_project > 0 ? getProjectTasksForEntry($conn, $selected_project) : [];

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $project_id = (int)($_POST["project_id"] ?? 0);
    $task_id = (int)($_POST["task_id"] ?? 0);
    $work_date = trim($_POST["work_date"] ?? "");
    $hours_raw = trim($_POST["hours"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if ($project_id <= 0) {
        $error = "Select a project.";
    } elseif (!userCanLogTimeForProject($conn, $user_id, $role, $department, $project_id)) {
        http_response_code(403);
        exit("You do not have permission to log time against that project.");
    } elseif ($task_id > 0 && !taskBelongsToProject($conn, $task_id, $project_id)) {
        $error = "The selected task does not belong to the selected project.";
    } elseif (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $work_date)
        || DateTime::createFromFormat("Y-m-d", $work_date) === false
    ) {
        $error = "Enter a valid work date.";
    } elseif (
        !is_numeric($hours_raw)
        || (float)$hours_raw <= 0
        || (float)$hours_raw > 24
    ) {
        $error = "Hours must be a number greater than 0 and no more than 24.";
    } elseif (mb_strlen($description) > 1000) {
        $error = "Description is too long (1000 characters max).";
    } else {

        $hours = round((float)$hours_raw, 2);
        $task_param = $task_id > 0 ? $task_id : null;

        $stmt = $conn->prepare("
            INSERT INTO time_entries (user_id, project_id, task_id, work_date, hours, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiisds",
            $user_id,
            $project_id,
            $task_param,
            $work_date,
            $hours,
            $description
        );
        $stmt->execute();
        $stmt->close();

        header("Location: timesheets.php?saved=1");
        exit;
    }

    $selected_project = $project_id;
    $tasks = $selected_project > 0 ? getProjectTasksForEntry($conn, $selected_project) : [];
}

$default_date = date("Y-m-d");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Log Time</title>

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
            <h1>LOG TIME</h1>
            <div class="sub">Record hours worked on a project or task</div>
        </div>

        <a class="button" href="timesheets.php">BACK TO TIMESHEETS</a>
    </div>

    <div class="content">

    <div class="card">

        <?php if ($error !== ""): ?>
            <div class="error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if (!$projects): ?>

            <div class="empty">
                You don't have any projects available to log time against.
            </div>

        <?php else: ?>

        <form method="POST">
            <?php echo csrfField(); ?>

            <div class="form-row">
                <label for="project_id">Project</label>
                <select id="project_id" name="project_id" onchange="this.form.submit()" required>
                    <option value="">Select a project...</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo (int)$p["id"]; ?>" <?php echo $selected_project === (int)$p["id"] ? "selected" : ""; ?>>
                            <?php echo e($p["name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="task_id">Task (optional)</label>
                <select id="task_id" name="task_id" <?php echo !$tasks ? "disabled" : ""; ?>>
                    <option value="0">No specific task</option>
                    <?php foreach ($tasks as $t): ?>
                        <option value="<?php echo (int)$t["id"]; ?>" <?php echo (int)($_POST["task_id"] ?? 0) === (int)$t["id"] ? "selected" : ""; ?>>
                            <?php echo e($t["name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="help">Choose the project first to see its tasks.</div>
            </div>

            <div class="grid">
                <div class="form-row">
                    <label for="work_date">Work Date</label>
                    <input id="work_date" name="work_date" type="date" required
                        value="<?php echo e($_POST["work_date"] ?? $default_date); ?>">
                </div>

                <div class="form-row">
                    <label for="hours">Hours</label>
                    <input id="hours" name="hours" type="number" step="0.25" min="0.25" max="24" required
                        value="<?php echo e($_POST["hours"] ?? ""); ?>">
                </div>
            </div>

            <div class="form-row">
                <label for="description">Description / Notes</label>
                <textarea id="description" name="description" maxlength="1000" rows="4"><?php echo e($_POST["description"] ?? ""); ?></textarea>
            </div>

            <div class="actions">
                <button class="button primary" type="submit">SAVE TIME ENTRY</button>
                <a class="button" href="timesheets.php">CANCEL</a>
            </div>

        </form>

        <?php endif; ?>

    </div>

    </div>

</div>

</div>

</body>
</html>
