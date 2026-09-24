<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];
$message = "";

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department,
           two_factor_enabled, two_factor_enrolled_at
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

$notification_categories = [
    "notify_task_assignment" => "Task Assignment",
    "notify_project_changes" => "Project Changes",
    "notify_event_reminders" => "Event Reminders",
    "notify_file_activity" => "File Activity",
    "notify_report_activity" => "Report Activity",
];

$notification_defaults = [
    "notify_task_assignment" => 1,
    "notify_project_changes" => 1,
    "notify_event_reminders" => 1,
    "notify_file_activity" => 0,
    "notify_report_activity" => 1,
];

/*
    Email preferences (#23). Separate from the in-app categories above -
    a user can turn email on for a category without touching whether
    it still shows up in-app, and vice versa.
*/
$email_categories = [
    "email_task_assignment" => "Task Assignment (sent immediately)",
    "email_mentions" => "Mentions (included in your digest)",
];

$email_defaults = [
    "email_task_assignment" => 0,
    "email_mentions" => 1,
];

$allowed_digest_frequencies = [
    "disabled" => "Off",
    "daily" => "Daily",
    "weekly" => "Weekly",
];

$stmt = $conn->prepare("
    SELECT
        default_landing_page,
        default_calendar_view,
        notify_task_assignment,
        notify_project_changes,
        notify_event_reminders,
        notify_file_activity,
        notify_report_activity,
        email_task_assignment,
        email_mentions,
        digest_frequency
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
        "default_calendar_view" => "month",
        "digest_frequency" => "disabled",
    ];
    $preferences += $notification_defaults;
    $preferences += $email_defaults;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requireValidCsrfToken();

    $default_landing_page = trim($_POST["default_landing_page"] ?? "");
    $default_calendar_view = trim($_POST["default_calendar_view"] ?? "");
    $digest_frequency = trim($_POST["digest_frequency"] ?? "disabled");

    if (!array_key_exists($default_landing_page, $allowed_landing_pages)) {
        $message = "Select a valid default landing page.";
    } elseif (!array_key_exists($default_calendar_view, $allowed_calendar_views)) {
        $message = "Select a valid default calendar view.";
    } elseif (!array_key_exists($digest_frequency, $allowed_digest_frequencies)) {
        $message = "Select a valid digest frequency.";
    } else {
        $notify_task_assignment = isset($_POST["notify_task_assignment"]) ? 1 : 0;
        $notify_project_changes = isset($_POST["notify_project_changes"]) ? 1 : 0;
        $notify_event_reminders = isset($_POST["notify_event_reminders"]) ? 1 : 0;
        $notify_file_activity = isset($_POST["notify_file_activity"]) ? 1 : 0;
        $notify_report_activity = isset($_POST["notify_report_activity"]) ? 1 : 0;
        $email_task_assignment = isset($_POST["email_task_assignment"]) ? 1 : 0;
        $email_mentions = isset($_POST["email_mentions"]) ? 1 : 0;

        $stmt = $conn->prepare("
            INSERT INTO user_preferences (
                user_id,
                default_landing_page,
                default_calendar_view,
                notify_task_assignment,
                notify_project_changes,
                notify_event_reminders,
                notify_file_activity,
                notify_report_activity,
                email_task_assignment,
                email_mentions,
                digest_frequency
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                default_landing_page = VALUES(default_landing_page),
                default_calendar_view = VALUES(default_calendar_view),
                notify_task_assignment = VALUES(notify_task_assignment),
                notify_project_changes = VALUES(notify_project_changes),
                notify_event_reminders = VALUES(notify_event_reminders),
                notify_file_activity = VALUES(notify_file_activity),
                notify_report_activity = VALUES(notify_report_activity),
                email_task_assignment = VALUES(email_task_assignment),
                email_mentions = VALUES(email_mentions),
                digest_frequency = VALUES(digest_frequency)
        ");
        $stmt->bind_param(
            "issiiiiiiis",
            $user_id,
            $default_landing_page,
            $default_calendar_view,
            $notify_task_assignment,
            $notify_project_changes,
            $notify_event_reminders,
            $notify_file_activity,
            $notify_report_activity,
            $email_task_assignment,
            $email_mentions,
            $digest_frequency
        );
        $stmt->execute();
        $stmt->close();

        $preferences["default_landing_page"] = $default_landing_page;
        $preferences["default_calendar_view"] = $default_calendar_view;
        $preferences["notify_task_assignment"] = $notify_task_assignment;
        $preferences["notify_project_changes"] = $notify_project_changes;
        $preferences["notify_event_reminders"] = $notify_event_reminders;
        $preferences["notify_file_activity"] = $notify_file_activity;
        $preferences["notify_report_activity"] = $notify_report_activity;
        $preferences["email_task_assignment"] = $email_task_assignment;
        $preferences["email_mentions"] = $email_mentions;
        $preferences["digest_frequency"] = $digest_frequency;
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
<link rel="stylesheet" href="app.css?v=22">
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
        <div class="sidebar-item"><a href="org_chart.php">Organization Chart</a></div>
        <div class="sidebar-item"><a href="files.php">Files</a></div>
        <div class="sidebar-item"><a href="reports.php">Reports</a></div>
        <div class="sidebar-item"><a href="analytics.php">Analytics</a></div>
        <div class="sidebar-item"><a href="training.php">Training</a></div>
        <div class="sidebar-item"><a href="time_off.php">Time Off</a></div>
        <div class="sidebar-item"><a href="timesheets.php">Timesheets</a></div>
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
                        <?php echo csrfField(); ?>

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

                        <div class="card" style="margin-top:24px;margin-bottom:0;box-shadow:none;border:1px solid rgba(0,0,0,.12);">
                            <div class="card-header">
                                <h2 style="margin:0;">Notification Preferences</h2>
                            </div>

                            <div class="card-body">
                                <div class="help" style="margin-bottom:14px;">
                                    Choose which in-app notifications you want to receive. Turning a category off
                                    doesn't remove notifications you've already gotten - it just stops new ones
                                    from that category.
                                </div>

                                <?php foreach ($notification_categories as $field => $label): ?>
                                    <div class="form-row" style="flex-direction:row;align-items:center;gap:10px;">
                                        <input
                                            type="checkbox"
                                            id="<?php echo e($field); ?>"
                                            name="<?php echo e($field); ?>"
                                            value="1"
                                            <?php echo (int)($preferences[$field] ?? $notification_defaults[$field]) === 1 ? "checked" : ""; ?>
                                            style="width:auto;"
                                        >
                                        <label for="<?php echo e($field); ?>" style="margin:0;">
                                            <?php echo e($label); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="card" style="margin-top:24px;margin-bottom:0;box-shadow:none;border:1px solid rgba(0,0,0,.12);">
                            <div class="card-header">
                                <h2 style="margin:0;">Email Preferences</h2>
                            </div>

                            <div class="card-body">
                                <div class="help" style="margin-bottom:14px;">
                                    Email works alongside your in-app notifications, not instead of them.
                                    Task assignment emails go out right away; mentions are included in your digest below.
                                </div>

                                <?php foreach ($email_categories as $field => $label): ?>
                                    <div class="form-row" style="flex-direction:row;align-items:center;gap:10px;">
                                        <input
                                            type="checkbox"
                                            id="<?php echo e($field); ?>"
                                            name="<?php echo e($field); ?>"
                                            value="1"
                                            <?php echo (int)($preferences[$field] ?? $email_defaults[$field]) === 1 ? "checked" : ""; ?>
                                            style="width:auto;"
                                        >
                                        <label for="<?php echo e($field); ?>" style="margin:0;">
                                            <?php echo e($label); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="card" style="margin-top:24px;margin-bottom:0;box-shadow:none;border:1px solid rgba(0,0,0,.12);">
                            <div class="card-header">
                                <h2 style="margin:0;">Digest Emails</h2>
                            </div>

                            <div class="card-body">
                                <div class="help" style="margin-bottom:14px;">
                                    A digest summarizes tasks due soon, overdue work, mentions, and other recent
                                    activity into a single email instead of sending one for every minor update.
                                </div>

                                <div class="form-row">
                                    <label for="digest_frequency">Digest Frequency</label>
                                    <select id="digest_frequency" name="digest_frequency">
                                        <?php foreach ($allowed_digest_frequencies as $value => $label): ?>
                                            <option
                                                value="<?php echo e($value); ?>"
                                                <?php echo ($preferences["digest_frequency"] ?? "disabled") === $value ? "selected" : ""; ?>
                                            >
                                                <?php echo e($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <button class="button" type="submit" style="margin-top:18px;">SAVE SETTINGS</button>
                    </form>
                </div>
            </div>

            <?php if (in_array($role, ["Administrator", "IT Administrator"], true)): ?>
            <div class="card">
                <div class="card-header">
                    <h2>Two-Factor Authentication</h2>
                </div>
                <div class="card-body">
                    <?php if ($current_user["two_factor_enabled"]): ?>
                        <p>Status: <strong>Enabled</strong><?php echo $current_user["two_factor_enrolled_at"] ? " since " . e(date("F j, Y", strtotime($current_user["two_factor_enrolled_at"]))) : ""; ?>.</p>
                    <?php else: ?>
                        <p>Status: <strong>Not Enrolled</strong>. As an <?php echo e($role); ?> account, we recommend turning this on.</p>
                    <?php endif; ?>
                    <a class="button" href="two_factor_enroll.php">MANAGE TWO-FACTOR AUTHENTICATION</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
