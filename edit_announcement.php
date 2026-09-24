<?php
/*
    edit_announcement.php

    REPLACES the earlier version to match the real schema/type enum.
    Authorized users can move the publish time earlier (publish a
    scheduled announcement now) or extend/shorten the expiration, or
    change its type/title/body/audience, subject to
    canManageAnnouncement() - the same rule new_announcement.php uses.
    Changing the department target is only offered to Administrators.
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

$announcement_id = (int)($_GET["id"] ?? $_POST["id"] ?? 0);

$stmt = $conn->prepare("SELECT * FROM announcements WHERE id = ?");
$stmt->bind_param("i", $announcement_id);
$stmt->execute();
$announcement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$announcement) {
    http_response_code(404);
    echo "Announcement not found.";
    exit;
}

if (!canManageAnnouncement($role, $department, $announcement["department"])) {
    http_response_code(403);
    echo "You do not have permission to edit this announcement.";
    exit;
}

$error = "";

function toDatetimeLocal(?string $mysql_datetime): string {
    if (!$mysql_datetime) {
        return "";
    }
    return date("Y-m-d\TH:i", strtotime($mysql_datetime));
}

$type = $announcement["type"];
$title = $announcement["title"];
$body = $announcement["body"];
$target_department = $announcement["department"] ?? "";
$publish_at_input = toDatetimeLocal($announcement["publish_at"]);
$expires_at_input = toDatetimeLocal($announcement["expires_at"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requireValidCsrfToken();

    $type = trim($_POST["type"] ?? "");
    $title = trim($_POST["title"] ?? "");
    $body = trim($_POST["body"] ?? "");
    $publish_at_input = trim($_POST["publish_at"] ?? "");
    $expires_at_input = trim($_POST["expires_at"] ?? "");

    $target_department = $is_admin
        ? trim($_POST["department"] ?? "")
        : ($announcement["department"] ?? "");

    $effective_target = $target_department === "" ? null : $target_department;

    if (!in_array($type, announcementTypes(), true)) {
        $error = "Select a valid announcement type.";
    } elseif (!canManageAnnouncement($role, $department, $effective_target)) {
        $error = "You don't have permission to target that audience.";
    } elseif ($title === "" || $body === "") {
        $error = "Title and message are both required.";
    } else {
        $publish_at_ts = $publish_at_input !== "" ? strtotime($publish_at_input) : false;
        if ($publish_at_ts === false) {
            $error = "Enter a valid publish date/time.";
        } else {
            $publish_at = date("Y-m-d H:i:s", $publish_at_ts);

            $expires_at = null;
            if ($expires_at_input !== "") {
                $expires_at_ts = strtotime($expires_at_input);
                if ($expires_at_ts === false) {
                    $error = "Enter a valid expiration date/time, or leave it blank.";
                } elseif ($expires_at_ts <= $publish_at_ts) {
                    $error = "Expiration must be after the publish time.";
                } else {
                    $expires_at = date("Y-m-d H:i:s", $expires_at_ts);
                }
            }

            if (!$error) {
                $update_stmt = $conn->prepare("
                    UPDATE announcements
                    SET type = ?, title = ?, body = ?, department = ?, publish_at = ?, expires_at = ?
                    WHERE id = ?
                ");
                $update_stmt->bind_param(
                    "ssssssi",
                    $type,
                    $title,
                    $body,
                    $effective_target,
                    $publish_at,
                    $expires_at,
                    $announcement_id
                );
                $update_stmt->execute();
                $update_stmt->close();

                logActivity(
                    $conn,
                    $user_id,
                    "Edit",
                    "Announcement",
                    $announcement_id,
                    "Edited announcement \"" . $title . "\".",
                    "announcements.php"
                );

                $_SESSION["announcement_message"] = "Announcement updated.";
                header("Location: announcements.php");
                exit;
            }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Announcement</title>
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
            <h1>EDIT ANNOUNCEMENT</h1>
            <div class="sub">Current status: <?php echo e(announcementLifecycleState($announcement)); ?></div>
        </div>
        <a class="button" href="announcements.php">BACK TO ANNOUNCEMENTS</a>
    </div>

    <div class="content">

        <div class="card">

            <?php if ($error): ?>
                <div class="error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$announcement_id; ?>">

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
                        <input type="text" value="<?php echo $target_department !== "" ? e($target_department) : "Organization-wide"; ?>" disabled>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <label for="publish_at">Publish Date/Time</label>
                    <input type="datetime-local" id="publish_at" name="publish_at" value="<?php echo e($publish_at_input); ?>" required>
                    <div class="help">Move this earlier to publish a scheduled announcement now.</div>
                </div>

                <div class="form-row">
                    <label for="expires_at">Expiration Date/Time (optional)</label>
                    <input type="datetime-local" id="expires_at" name="expires_at" value="<?php echo e($expires_at_input); ?>">
                    <div class="help">Extend this to keep an active announcement visible longer. Leave blank for no expiration.</div>
                </div>

                <div class="actions">
                    <button class="button primary" type="submit">SAVE CHANGES</button>
                </div>

            </form>

        </div>

    </div>

</div>

</div>

</body>
</html>
