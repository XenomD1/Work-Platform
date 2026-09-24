<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "notification_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

/*
 * Trash restore is Administrator-only, or an IT Administrator
 * restoring something that belongs to their own department.
 */
if (!isAnyAdministrator($current_user["role"])) {
    http_response_code(403);
    exit("Only Administrators can restore files from Trash.");
}

/*
 * Resolves the department a file "belongs to" for the purposes of
 * IT Administrator scoping, based on its location type. Returns
 * null for Organization-type files, which stay Administrator-only,
 * matching canTrashFile()'s same rule.
 */
function resolveFileDepartment($conn, $file) {

    if ($file["location_type"] === "Department") {
        return $file["location_id"];
    }

    if ($file["location_type"] === "Project") {
        $stmt = $conn->prepare("SELECT department FROM projects WHERE id = ?");
        $stmt->bind_param("i", $file["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($file["location_type"] === "Task") {
        $stmt = $conn->prepare("
            SELECT p.department
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $file["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($file["location_type"] === "Team") {
        $stmt = $conn->prepare("
            SELECT p.department
            FROM teams t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $file["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($file["location_type"] === "Event") {
        $stmt = $conn->prepare("SELECT department FROM events WHERE id = ?");
        $stmt->bind_param("i", $file["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($file["location_type"] === "Training") {
        $stmt = $conn->prepare("SELECT department FROM training WHERE id = ?");
        $stmt->bind_param("i", $file["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    // Organization files: no department, Administrator-only.
    return null;
}

$file_id = (int)($_GET["id"] ?? $_POST["file_id"] ?? 0);

if ($file_id <= 0) {
    header("Location: file_trash.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
 * Load the trashed file and its current version.
 */
$stmt = $conn->prepare("
    SELECT
        f.id,
        f.name,
        f.folder_id,
        f.location_type,
        f.location_id,
        f.current_version,
        f.trashed_at,
        f.uploaded_by,
        CONCAT(u.first_name, ' ', u.last_name) AS trashed_by_name,
        fv.original_filename
    FROM files f
    LEFT JOIN users u
        ON u.id = f.trashed_by
    LEFT JOIN file_versions fv
        ON fv.file_id = f.id
       AND fv.version_number = f.current_version
    WHERE f.id = ?
      AND f.is_trashed = 1
");
$stmt->bind_param("i", $file_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    header("Location: file_trash.php");
    exit;
}

if (
    !canAdministerDepartment(
        $current_user["role"],
        $current_user["department"] ?? "",
        resolveFileDepartment($conn, $file)
    )
) {
    http_response_code(403);
    exit("You do not have permission to restore this file.");
}

/*
 * If this file belongs to a folder, make sure that folder is active.
 * A file should not be restored into a folder that is still in Trash.
 */
$folder_is_available = true;

if (!empty($file["folder_id"])) {
    $stmt = $conn->prepare("
        SELECT id, is_trashed
        FROM file_folders
        WHERE id = ?
    ");
    $stmt->bind_param("i", $file["folder_id"]);
    $stmt->execute();
    $folder = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$folder || (int)$folder["is_trashed"] === 1) {
        $folder_is_available = false;
    }
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    if (!$folder_is_available) {
        $error =
            "This file belongs to a folder that is still in Trash. "
            . "Restore the folder first.";
    } else {

        $stmt = $conn->prepare("
            UPDATE files
            SET
                is_trashed = 0,
                trashed_by = NULL,
                trashed_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND is_trashed = 1
        ");
        $stmt->bind_param("i", $file_id);
        $stmt->execute();

        if ($stmt->affected_rows === 1) {
            $stmt->close();

            if ((int)$file["uploaded_by"] !== $user_id) {
                createNotification(
                    $conn,
                    (int)$file["uploaded_by"],
                    "File",
                    "File Restored",
                    "Your file \"" . $file["name"] . "\" was restored.",
                    "Files",
                    $file_id,
                    "view_file.php?id=" . $file_id
                );
            }

            header("Location: view_file.php?id=" . $file_id);
            exit;
        }

        $stmt->close();
        $error = "The file could not be restored.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Restore File</title>

<link rel="stylesheet" href="app.css?v=9">

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
            <a href="org_chart.php">Organization Chart</a>
        </div>
        <div class="sidebar-item active">
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

        <?php if (canPostAnnouncements($current_user["role"])): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($current_user["role"])): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($current_user["role"] === "Administrator"): ?>
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

<div class="main">

    <div class="header">
        <h1>RESTORE FILE</h1>

        <div class="sub">
            Return this file to the active file library
        </div>
    </div>

    <div class="content">

    <div class="card">

        <?php if ($error): ?>
            <div class="error">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <div class="notice">
            Restoring this file keeps its complete version history.
        </div>

        <?php if (!$folder_is_available): ?>
            <div class="warning">
                This file belongs to a folder that is still in Trash.
                Restore that folder before restoring this file.
            </div>
        <?php endif; ?>

        <div class="detail">
            <div class="label">File</div>
            <div><?php echo e($file["name"]); ?></div>
        </div>

        <div class="detail">
            <div class="label">Filename</div>
            <div><?php echo e($file["original_filename"] ?: "—"); ?></div>
        </div>

        <div class="detail">
            <div class="label">Category</div>
            <div><?php echo e($file["location_type"]); ?></div>
        </div>

        <div class="detail">
            <div class="label">Current Version</div>
            <div>Version <?php echo (int)$file["current_version"]; ?></div>
        </div>

        <div class="detail">
            <div class="label">Trashed By</div>
            <div><?php echo e($file["trashed_by_name"] ?: "Unknown"); ?></div>
        </div>

        <div class="detail">
            <div class="label">Trashed</div>
            <div>
                <?php
                echo $file["trashed_at"]
                    ? e(date("M j, Y g:i A", strtotime($file["trashed_at"])))
                    : "—";
                ?>
            </div>
        </div>

        <form method="POST">

            <?= csrfField() ?>

            <input
                type="hidden"
                name="file_id"
                value="<?php echo (int)$file_id; ?>"
            >

            <div class="actions">

                <a
                    class="button"
                    href="file_trash.php"
                >
                    CANCEL
                </a>

                <button
                    class="button primary"
                    type="submit"
                    <?php echo !$folder_is_available ? "disabled" : ""; ?>
                >
                    RESTORE FILE
                </button>

            </div>

        </form>

    </div>

</div>

</div>

</div>

</body>
</html>
