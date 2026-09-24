<?php
/*
    announcements.php

    REPLACES the earlier version to match the real schema. Full
    management list - all lifecycle states - reachable from
    new_announcement.php's "MANAGE ALL ANNOUNCEMENTS" link (since
    dashboard.php itself only ever links to new_announcement.php).
*/

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

$me_stmt = $conn->prepare("SELECT role, department FROM users WHERE id = ? AND active = 1");
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

if (!canPostAnnouncements($role)) {
    http_response_code(403);
    echo "You do not have permission to manage announcements.";
    exit;
}

$announcements = getManageableAnnouncements($conn, $role, $department);

$flash = $_SESSION["announcement_message"] ?? null;
unset($_SESSION["announcement_message"]);

$state_badge_class = [
    "Active" => "badge-active",
    "Scheduled" => "badge-scheduled",
    "Expired" => "badge-expired",
    "Inactive" => "badge-inactive",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements</title>
<link rel="stylesheet" href="app.css?v=22">
<style>
.badge { display:inline-block; font-size:.78em; padding:2px 8px; border-radius:10px; }
.badge-active { background:#d7f0d7; }
.badge-scheduled { background:#dbe7fb; }
.badge-expired { background:#eee; opacity:.8; }
.badge-inactive { background:#f4d0d0; }
</style>
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
        <div class="sidebar-item"><a href="timesheets.php">Timesheets</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item active"><a href="announcements.php">Announcements</a></div>
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
            <h1>ANNOUNCEMENTS</h1>
            <div class="sub">Every announcement you can manage, in every lifecycle state</div>
        </div>
        <a class="button primary" href="new_announcement.php">NEW ANNOUNCEMENT</a>
    </div>

    <div class="content">

        <?php if ($flash): ?>
            <div class="message"><?php echo e($flash); ?></div>
        <?php endif; ?>

        <?php if (!$announcements): ?>
            <div class="empty">No announcements yet.</div>
        <?php else: ?>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Audience</th>
                        <th>Status</th>
                        <th>Publishes</th>
                        <th>Expires</th>
                        <th>Author</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($announcements as $a):
                        $state = announcementLifecycleState($a);
                        $can_manage = canManageAnnouncement($role, $department, $a["department"]);
                    ?>
                        <tr>
                            <td><?php echo e($a["title"]); ?></td>
                            <td><?php echo e($a["type"]); ?></td>
                            <td><?php echo $a["department"] ? e($a["department"]) : "Organization-wide"; ?></td>
                            <td><span class="badge <?php echo $state_badge_class[$state]; ?>"><?php echo e($state); ?></span></td>
                            <td><?php echo e(date("M j, Y g:i A", strtotime($a["publish_at"]))); ?></td>
                            <td><?php echo $a["expires_at"] ? e(date("M j, Y g:i A", strtotime($a["expires_at"]))) : "Never"; ?></td>
                            <td><?php echo $a["created_by_name"] ? e($a["created_by_name"]) : "a former user"; ?></td>
                            <td>
                                <?php if ($can_manage): ?>
                                    <a class="button" href="edit_announcement.php?id=<?php echo (int)$a["id"]; ?>">EDIT</a>
                                    <form method="POST" action="announcement_action.php" style="display:inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="announcement_id" value="<?php echo (int)$a["id"]; ?>">
                                        <input type="hidden" name="action" value="<?php echo $a["is_active"] ? "deactivate" : "reactivate"; ?>">
                                        <button class="button" type="submit"><?php echo $a["is_active"] ? "DISABLE" : "REACTIVATE"; ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

    </div>

</div>

</div>

</body>
</html>
