<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

$user_id = (int)$_SESSION["user_id"];
$training_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($training_id <= 0) {
    header("Location: training.php");
    exit;
}

/* ---------------------------------------------------------
   CURRENT USER
--------------------------------------------------------- */
$user_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

/* ---------------------------------------------------------
   TRAINING
--------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        t.id,
        t.name,
        t.description,
        t.type,
        t.organizer,
        t.department,
        t.location,
        t.start_date,
        t.end_date,
        t.start_time,
        t.end_time,
        t.status,
        t.reminder,
        CONCAT(u.first_name, ' ', u.last_name) AS organizer_name
    FROM training t
    INNER JOIN users u ON t.organizer = u.id
    WHERE t.id = ?
      AND t.deleted = 0
");
$stmt->bind_param("i", $training_id);
$stmt->execute();
$training = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$training) {
    header("Location: training.php");
    exit;
}

/* ---------------------------------------------------------
   VISIBILITY
--------------------------------------------------------- */
$can_view = false;

if ($current_user["role"] === "Administrator") {
    $can_view = true;
} elseif ($current_user["department"] === $training["department"]) {
    $can_view = true;
} else {
    $attendee_stmt = $conn->prepare("
        SELECT id
        FROM training_attendees
        WHERE training_id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $attendee_stmt->bind_param("ii", $training_id, $user_id);
    $attendee_stmt->execute();
    $attendee_stmt->store_result();

    if ($attendee_stmt->num_rows > 0) {
        $can_view = true;
    }

    $attendee_stmt->close();
}

if (!$can_view) {
    header("Location: training.php");
    exit;
}

/* ---------------------------------------------------------
   EDIT PERMISSION
--------------------------------------------------------- */
$can_edit = false;

if ($current_user["role"] === "Administrator") {
    $can_edit = true;
} elseif (
    ($current_user["role"] === "Manager" || $current_user["role"] === "IT Administrator") &&
    $current_user["department"] === $training["department"]
) {
    $can_edit = true;
}

$reminder_labels = [
    "none" => "None",
    "15" => "15 minutes before",
    "30" => "30 minutes before",
    "60" => "1 hour before",
    "1440" => "1 day before"
];

$reminder_display = $reminder_labels[$training["reminder"]] ?? $training["reminder"];

function format_date_display($date) {
    if (!$date) {
        return "";
    }

    return date("F j, Y", strtotime($date));
}

function format_time_display($time) {
    if (!$time) {
        return "";
    }

    return date("g:i A", strtotime($time));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Training</title>

<link rel="stylesheet" href="app.css?v=15">

</head>

<body>

<div class="page">

<div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item">
            <a href="dashboard.php">Dashboard</a>
        </div>
        <div class="sidebar-item">
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
        <div class="sidebar-item active">
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

<div class="main">

    <div class="header">
        <div>
            <h1>TRAINING</h1>
            <div class="sub">Training details</div>
        </div>

        <div class="top-actions">
            <a class="button" href="training.php">BACK TO TRAINING</a>

            <?php if ($can_edit): ?>
                <a class="button primary"
                   href="edit_training.php?id=<?php echo (int)$training["id"]; ?>">
                    EDIT TRAINING
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">

    <div class="card">

        <div class="training-title">
            <?php echo htmlspecialchars($training["name"]); ?>
        </div>

        <div class="training-type">
            <?php echo htmlspecialchars($training["type"]); ?>
        </div>

        <div class="info-grid">

            <div class="info-item">
                <div class="info-label">Organizer</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($training["organizer_name"]); ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Department</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($training["department"]); ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Location</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($training["location"]); ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <span class="status">
                        <?php echo htmlspecialchars($training["status"]); ?>
                    </span>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Start Date</div>
                <div class="info-value">
                    <?php echo htmlspecialchars(format_date_display($training["start_date"])); ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">End Date</div>
                <div class="info-value">
                    <?php echo htmlspecialchars(format_date_display($training["end_date"])); ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Start Time</div>
                <div class="info-value">
                    <?php echo htmlspecialchars(format_time_display($training["start_time"])); ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">End Time</div>
                <div class="info-value">
                    <?php echo htmlspecialchars(format_time_display($training["end_time"])); ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Reminder</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($reminder_display); ?>
                </div>
            </div>

        </div>

    </div>

    <div class="card">
        <h2>Description</h2>

        <div class="description">
            <?php echo htmlspecialchars($training["description"]); ?>
        </div>
    </div>

    <div class="actions-bottom">
        <div class="training-id">
            Training ID: <?php echo (int)$training["id"]; ?>
        </div>

        <div>
            <a class="button" href="training.php">BACK TO TRAINING</a>

            <?php if ($can_edit): ?>
                <a class="button primary"
                   href="edit_training.php?id=<?php echo (int)$training["id"]; ?>">
                    EDIT TRAINING
                </a>
            <?php endif; ?>
        </div>
    </div>

</div>

</div>

</div>

</body>
</html>