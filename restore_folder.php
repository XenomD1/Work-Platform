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

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: login.php");
    exit;
}

/*
 * Folder restoration from Trash is Administrator-only, or an IT
 * Administrator restoring a folder that belongs to their own
 * department.
 */
if (!isAnyAdministrator($user["role"])) {
    http_response_code(403);
    exit("Only Administrators can restore folders from Trash.");
}

/*
 * Resolves the department a folder "belongs to" for the purposes
 * of IT Administrator scoping, based on its location type. Returns
 * null for Organization-type folders, which stay Administrator-only.
 */
function resolveFolderDepartment($conn, $folder) {

    if ($folder["location_type"] === "Department") {
        return $folder["location_id"];
    }

    if ($folder["location_type"] === "Project") {
        $stmt = $conn->prepare("SELECT department FROM projects WHERE id = ?");
        $stmt->bind_param("i", $folder["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($folder["location_type"] === "Task") {
        $stmt = $conn->prepare("
            SELECT p.department
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $folder["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($folder["location_type"] === "Team") {
        $stmt = $conn->prepare("
            SELECT p.department
            FROM teams t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $folder["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($folder["location_type"] === "Event") {
        $stmt = $conn->prepare("SELECT department FROM events WHERE id = ?");
        $stmt->bind_param("i", $folder["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($folder["location_type"] === "Training") {
        $stmt = $conn->prepare("SELECT department FROM training WHERE id = ?");
        $stmt->bind_param("i", $folder["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    // Organization folders: no department, Administrator-only.
    return null;
}

$folder_id = (int)($_GET["id"] ?? $_POST["folder_id"] ?? 0);

if ($folder_id <= 0) {
    header("Location: file_trash.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
 * Load the trashed root folder.
 */
$stmt = $conn->prepare("
    SELECT
        id,
        name,
        parent_folder_id,
        location_type,
        location_id,
        created_at,
        updated_at
    FROM file_folders
    WHERE id = ?
      AND is_trashed = 1
");
$stmt->bind_param("i", $folder_id);
$stmt->execute();
$folder = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$folder) {
    header("Location: file_trash.php");
    exit;
}

if (
    !canAdministerDepartment(
        $user["role"],
        $user["department"] ?? "",
        resolveFolderDepartment($conn, $folder)
    )
) {
    http_response_code(403);
    exit("You do not have permission to restore this folder.");
}

/*
 * Collect this folder and all descendant folders,
 * including descendants that are currently trashed.
 */
function collectFolderIds($conn, $root_id) {
    $ids = [$root_id];
    $queue = [$root_id];

    while ($queue) {
        $current = array_shift($queue);

        $stmt = $conn->prepare("
            SELECT id
            FROM file_folders
            WHERE parent_folder_id = ?
        ");
        $stmt->bind_param("i", $current);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $child_id = (int)$row["id"];

            if (!in_array($child_id, $ids, true)) {
                $ids[] = $child_id;
                $queue[] = $child_id;
            }
        }

        $stmt->close();
    }

    return $ids;
}

$folder_ids = collectFolderIds($conn, $folder_id);

$subfolder_count = max(0, count($folder_ids) - 1);
$trashed_file_count = 0;

foreach ($folder_ids as $id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM files
        WHERE folder_id = ?
          AND is_trashed = 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $trashed_file_count +=
        (int)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $conn->begin_transaction();

    try {

        /*
         * Restore folder tree first.
         */
        foreach ($folder_ids as $id) {
            $stmt = $conn->prepare("
                UPDATE file_folders
                SET
                    is_trashed = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND is_trashed = 1
            ");
            $stmt->bind_param("i", $id);

            if (!$stmt->execute()) {
                throw new Exception(
                    "Unable to restore one or more folders."
                );
            }

            $stmt->close();
        }

        /*
         * Restore all trashed files inside the folder tree.
         * File versions remain untouched.
         */
        foreach ($folder_ids as $id) {
            $stmt = $conn->prepare("
                UPDATE files
                SET
                    is_trashed = 0,
                    trashed_by = NULL,
                    trashed_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE folder_id = ?
                  AND is_trashed = 1
            ");
            $stmt->bind_param("i", $id);

            if (!$stmt->execute()) {
                throw new Exception(
                    "Unable to restore one or more files."
                );
            }

            $stmt->close();
        }

        $conn->commit();

        header("Location: view_folder.php?id=" . $folder_id);
        exit;

    } catch (Throwable $exception) {

        $conn->rollback();
        $error = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Restore Folder</title>

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

        <?php if (canPostAnnouncements($user["role"])): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($user["role"])): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($user["role"] === "Administrator"): ?>
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
        <h1>RESTORE FOLDER</h1>

        <div class="sub">
            Restore this folder, its subfolders, and trashed files
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
            Restoring this folder returns the full folder tree to the active
            file library. Trashed files inside the folder tree are also restored,
            and all file version history remains intact.
        </div>

        <div class="detail">
            <div class="label">Folder</div>
            <div><?php echo e($folder["name"]); ?></div>
        </div>

        <div class="detail">
            <div class="label">Category</div>
            <div><?php echo e($folder["location_type"]); ?></div>
        </div>

        <div class="detail">
            <div class="label">Subfolders</div>
            <div><?php echo (int)$subfolder_count; ?></div>
        </div>

        <div class="detail">
            <div class="label">Trashed Files</div>
            <div><?php echo (int)$trashed_file_count; ?></div>
        </div>

        <form method="POST">

            <?= csrfField() ?>

            <input
                type="hidden"
                name="folder_id"
                value="<?php echo (int)$folder_id; ?>"
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
                >
                    RESTORE FOLDER
                </button>

            </div>

        </form>

    </div>

</div>

</div>

</div>

</body>
</html>
