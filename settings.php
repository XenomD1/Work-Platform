<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

$user_id = (int)$_SESSION["user_id"];
$message = "";

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ?
      AND active = 1
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$role = $current_user["role"];

$allowed_landing_pages = [
    "dashboard.php" => "Dashboard",
    "projects.php" => "Projects",
    "tasks.php" => "Tasks",
    "events.php" => "Meetings / Events",
    "teams.php" => "Teams",
    "files.php" => "Files",
    "reports.php" => "Reports",
    "training.php" => "Training",
    "time_off.php" => "Time Off",
    "calendar.php" => "Calendar",
    "notifications.php" => "Notifications"
];

$allowed_calendar_views = [
    "month" => "Month",
    "week" => "Week",
    "day" => "Day"
];

$stmt = $conn->prepare("
    SELECT default_landing_page, default_calendar_view
    FROM user_preferences
    WHERE user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$preferences = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$preferences) {
    $preferences = [
        "default_landing_page" => "dashboard.php",
        "default_calendar_view" => "month"
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $default_landing_page = trim($_POST["default_landing_page"] ?? "");
    $default_calendar_view = trim($_POST["default_calendar_view"] ?? "");

    if (!array_key_exists($default_landing_page, $allowed_landing_pages)) {
        $message = "Select a valid default landing page.";
    } elseif (!array_key_exists($default_calendar_view, $allowed_calendar_views)) {
        $message = "Select a valid default calendar view.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO user_preferences
                (user_id, default_landing_page, default_calendar_view)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                default_landing_page = VALUES(default_landing_page),
                default_calendar_view = VALUES(default_calendar_view)
        ");
        $stmt->bind_param(
            "iss",
            $user_id,
            $default_landing_page,
            $default_calendar_view
        );
        $stmt->execute();
        $stmt->close();

        $preferences["default_landing_page"] = $default_landing_page;
        $preferences["default_calendar_view"] = $default_calendar_view;
        $message = "Settings saved.";
    }
}

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings</title>
<link rel="stylesheet" href="app.css?v=20">
</head>
<body>
<div class="page">
    <aside class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item"><a href="dashboard.php">Dashboard</a></div>
        <div class="sidebar-item"><a href="tasks.php">Tasks</a></div>
        <div class="sidebar-item"><a href="projects.php">Projects</a></div>
        <div class="sidebar-item"><a href="calendar.php">Calendar</a></div>
        <div class="sidebar-item"><a href="events.php">Meetings / Events</a></div>
        <div class="sidebar-item"><a href="teams.php">Teams</a></div>
        <div class="sidebar-item"><a href="files.php">Files</a></div>
        <div class="sidebar-item"><a href="reports.php">Reports</a></div>
        <div class="sidebar-item"><a href="training.php">Training</a></div>
        <div class="sidebar-item"><a href="time_off.php">Time Off</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>

        <?php if (isAnyAdministrator($role)): ?>
            <div class="sidebar-item"><a href="users.php">User Management</a></div>
        <?php endif; ?>

        <div class="sidebar-bottom">
            <div class="sidebar-item"><a href="account.php">Account</a></div>
            <div class="sidebar-item active"><a href="settings.php">Settings</a></div>
        </div>
    </aside>

    <main class="main">
        <header class="header">
            <div>
                <h1>Settings</h1>
                <div>Personal platform preferences</div>
            </div>
            <strong><?php echo e($role); ?></strong>
        </header>

        <div class="content">
            <?php if ($message !== ""): ?>
                <div class="message"><?php echo e($message); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2>General Preferences</h2>
                </div>

                <div class="card-body">
                    <form method="POST">
                        <div class="form-row">
                            <label for="default_landing_page">Default Page After Login</label>
                            <select id="default_landing_page" name="default_landing_page">
                                <?php foreach ($allowed_landing_pages as $value => $label): ?>
                                    <option
                                        value="<?php echo e($value); ?>"
                                        <?php echo $preferences["default_landing_page"] === $value ? "selected" : ""; ?>
                                    >
                                        <?php echo e($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help">
                                This is the page you will be taken to immediately after signing in.
                            </div>
                        </div>

                        <div class="form-row">
                            <label for="default_calendar_view">Default Calendar View</label>
                            <select id="default_calendar_view" name="default_calendar_view">
                                <?php foreach ($allowed_calendar_views as $value => $label): ?>
                                    <option
                                        value="<?php echo e($value); ?>"
                                        <?php echo $preferences["default_calendar_view"] === $value ? "selected" : ""; ?>
                                    >
                                        <?php echo e($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help">
                                When you open Calendar without choosing a view, it will start in this view.
                            </div>
                        </div>

                        <button class="button" type="submit">SAVE SETTINGS</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
