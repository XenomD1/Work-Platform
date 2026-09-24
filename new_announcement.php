<?php
/*
    new_announcement.php

    REPLACES the earlier version, which used an invented freeform
    "audience" concept instead of the real, fixed 5-value `type` enum
    (Organization Announcement / Department Announcement / Scheduled
    Maintenance / Office Closure / Policy Update) and a schema that
    didn't match the real announcements table.

    This is the page dashboard.php's "MANAGE ANNOUNCEMENTS" button
    already links to, so its URL is kept exactly as-is. It now also
    doubles as the entry point into full management (edit/deactivate/
    reactivate everything) via a link down to announcements.php, since
    dashboard.php only ever links here and I didn't want to require
    another edit to dashboard.php to reach the rest of #27.
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";
require_once "activity_helpers.php";
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
$is_admin = isAnyAdministrator($role);

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

if (!canPostAnnouncements($role)) {
    http_response_code(403);
    echo "You do not have permission to create announcements.";
    exit;
}

$error = "";
$title = "";
$body = "";
$type = "Department Announcement";
$target_department = $is_admin ? "" : $department;
$publish_mode = "now";
$publish_at_input = "";
$expires_at_input = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requireValidCsrfToken();

    $title = trim($_POST["title"] ?? "");
    $body = trim($_POST["body"] ?? "");
    $type = trim($_POST["type"] ?? "");
    $target_department = trim($_POST["department"] ?? "");
    $publish_mode = ($_POST["publish_mode"] ?? "now") === "later" ? "later" : "now";
    $publish_at_input = trim($_POST["publish_at"] ?? "");
    $expires_at_input = trim($_POST["expires_at"] ?? "");

    $effective_target = $target_department === "" ? null : $target_department;

    if (!in_array($type, announcementTypes(), true)) {
        $error = "Select a valid announcement type.";
    } elseif (!canManageAnnouncement($role, $department, $effective_target)) {
        $error = "You don't have permission to target that audience.";
    } elseif ($title === "" || $body === "") {
        $error = "Title and message are both required.";
    } else {
        if ($publish_mode === "later") {
            $publish_at_ts = $publish_at_input !== "" ? strtotime($publish_at_input) : false;
            if ($publish_at_ts === false) {
                $error = "Enter a valid publish date/time.";
            } else {
                $publish_at = date("Y-m-d H:i:s", $publish_at_ts);
            }
        } else {
            $publish_at = date("Y-m-d H:i:s");
        }

        $expires_at = null;
        if (!$error && $expires_at_input !== "") {
            $expires_at_ts = strtotime($expires_at_input);
            if ($expires_at_ts === false) {
                $error = "Enter a valid expiration date/time, or leave it blank.";
            } elseif (isset($publish_at) && $expires_at_ts <= strtotime($publish_at)) {
                $error = "Expiration must be after the publish time.";
            } else {
                $expires_at = date("Y-m-d H:i:s", $expires_at_ts);
            }
        }

        if (!$error) {
            $insert_stmt = $conn->prepare("
                INSERT INTO announcements (type, department, title, body, is_active, publish_at, expires_at, created_by)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?)
            ");
            $insert_stmt->bind_param(
                "ssssssi",
                $type,
                $effective_target,
                $title,
                $body,
                $publish_at,
                $expires_at,
                $user_id
            );
            $insert_stmt->execute();
            $new_id = $insert_stmt->insert_id;
            $insert_stmt->close();

            logActivity(
                $conn,
                $user_id,
                "Create",
                "Announcement",
                $new_id,
                "Created announcement \"" . $title . "\".",
                "announcements.php"
            );

            $_SESSION["announcement_message"] = "Announcement created.";
            header("Location: new_announcement.php");
            exit;
        }
    }
}

$available_departments = [];
if ($is_admin) {
    $dept_result = $conn->query("SELECT DISTINCT department FROM users WHERE active = 1 AND department IS NOT NULL AND department <> '' ORDER BY department ASC");
    while ($row = $dept_result->fetch_assoc()) {
        $available_departments[] = $row["department"];
    }
}

$flash = $_SESSION["announcement_message"] ?? null;
unset($_SESSION["announcement_message"]);

$recent = getManageableAnnouncements($conn, $role, $department);
$recent = array_slice($recent, 0, 8);
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
<title>New Announcement</title>
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
            <h1>NEW ANNOUNCEMENT</h1>
        </div>
        <a class="button" href="announcements.php">MANAGE ALL ANNOUNCEMENTS</a>
    </div>

    <div class="content">

        <?php if ($flash): ?>
            <div class="message"><?php echo e($flash); ?></div>
        <?php endif; ?>

        <div class="card">

            <?php if ($error): ?>
                <div class="error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <div class="form-row">
                    <label for="type">Type</label>
                    <select id="type" name="type">
                        <?php foreach (announcementTypes() as $t): ?>
                            <option value="<?php echo e($t); ?>" <?php echo $type === $t ? "selected" : ""; ?>><?php echo e($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" value="<?php echo e($title); ?>" maxlength="200" required>
                </div>

                <div class="form-row">
                    <label for="body">Message</label>
                    <textarea id="body" name="body" rows="5" required><?php echo e($body); ?></textarea>
                </div>

                <div class="form-row">
                    <label for="department">Audience</label>
                    <?php if ($is_admin): ?>
                        <select id="department" name="department">
                            <option value="" <?php echo $target_department === "" ? "selected" : ""; ?>>Organization-wide</option>
                            <?php foreach ($available_departments as $d): ?>
                                <option value="<?php echo e($d); ?>" <?php echo $target_department === $d ? "selected" : ""; ?>><?php echo e($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" value="<?php echo e($department); ?>" disabled>
                        <input type="hidden" name="department" value="<?php echo e($department); ?>">
                        <div class="help">You can only publish announcements to your own department.</div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <label>Publish</label>
                    <div class="actions">
                        <label><input type="radio" name="publish_mode" value="now" <?php echo $publish_mode === "now" ? "checked" : ""; ?> style="width:auto;" onchange="document.getElementById('publish_at_row').style.display='none';"> Publish Immediately</label>
                        <label><input type="radio" name="publish_mode" value="later" <?php echo $publish_mode === "later" ? "checked" : ""; ?> style="width:auto;" onchange="document.getElementById('publish_at_row').style.display='block';"> Publish Later</label>
                    </div>
                </div>

                <div class="form-row" id="publish_at_row" style="display: <?php echo $publish_mode === "later" ? "block" : "none"; ?>;">
                    <label for="publish_at">Publish Date/Time</label>
                    <input type="datetime-local" id="publish_at" name="publish_at" value="<?php echo e($publish_at_input); ?>">
                </div>

                <div class="form-row">
                    <label for="expires_at">Expiration Date/Time (optional)</label>
                    <input type="datetime-local" id="expires_at" name="expires_at" value="<?php echo e($expires_at_input); ?>">
                    <div class="help">Leave blank for an announcement that doesn't expire on its own.</div>
                </div>

                <div class="actions">
                    <button class="button primary" type="submit">CREATE ANNOUNCEMENT</button>
                </div>

            </form>

        </div>

        <div class="card">
            <h2>Recently Created</h2>
            <?php if (!$recent): ?>
                <div class="empty">No announcements yet.</div>
            <?php else: ?>
                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Publishes</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $a):
                                $state = announcementLifecycleState($a);
                            ?>
                                <tr>
                                    <td><?php echo e($a["title"]); ?></td>
                                    <td><span class="badge <?php echo $state_badge_class[$state]; ?>"><?php echo e($state); ?></span></td>
                                    <td><?php echo e(date("M j, Y g:i A", strtotime($a["publish_at"]))); ?></td>
                                    <td><a class="button" href="edit_announcement.php?id=<?php echo (int)$a["id"]; ?>">EDIT</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

</div>

</body>
</html>
