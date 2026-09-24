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
 * An Administrator can permanently delete any file. An IT
 * Administrator may only permanently delete a file that belongs
 * to their own department.
 */
if (!isAnyAdministrator($user["role"])) {
    http_response_code(403);
    exit("Only Administrators can permanently delete files.");
}

/*
 * Resolves the department a file "belongs to" for the purposes of
 * IT Administrator scoping, based on its location type. Returns
 * null for Organization-type files, which stay Administrator-only.
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

function formatFileSize($bytes) {
    $bytes = (int)$bytes;

    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . " GB";
    }

    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . " MB";
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . " KB";
    }

    return $bytes . " B";
}

/*
 * Only trashed files can be permanently deleted.
 */
$stmt = $conn->prepare("
    SELECT
        f.id,
        f.name,
        f.location_type,
        f.location_id,
        f.current_version,
        f.trashed_at,
        f.uploaded_by,
        CONCAT(u.first_name, ' ', u.last_name) AS trashed_by_name
    FROM files f
    LEFT JOIN users u
        ON u.id = f.trashed_by
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
        $user["role"],
        $user["department"] ?? "",
        resolveFileDepartment($conn, $file)
    )
) {
    http_response_code(403);
    exit("You do not have permission to permanently delete this file.");
}

/*
 * Load every stored version so they can all be deleted from disk.
 */
$versions = [];

$stmt = $conn->prepare("
    SELECT
        id,
        version_number,
        original_filename,
        file_path,
        file_size
    FROM file_versions
    WHERE file_id = ?
    ORDER BY version_number DESC
");
$stmt->bind_param("i", $file_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $versions[] = $row;
}

$stmt->close();

$total_size = 0;

foreach ($versions as $version) {
    $total_size += (int)$version["file_size"];
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    /*
     * Verify the confirmation checkbox.
     */
    if (!isset($_POST["confirm_delete"])) {
        $error = "You must confirm permanent deletion.";
    }

    if ($error === "") {

        /*
         * Resolve all physical file paths before changing the database.
         * Only files inside uploads/files are eligible for deletion.
         */
        $base_directory = realpath(
            __DIR__
            . DIRECTORY_SEPARATOR
            . "uploads"
            . DIRECTORY_SEPARATOR
            . "files"
        );

        $paths_to_delete = [];

        if ($base_directory !== false) {

            foreach ($versions as $version) {

                $relative_path = str_replace(
                    ["../", "..\\"],
                    "",
                    $version["file_path"]
                );

                $full_path = realpath(
                    __DIR__
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        ["/", "\\"],
                        DIRECTORY_SEPARATOR,
                        $relative_path
                    )
                );

                if (
                    $full_path !== false
                    && is_file($full_path)
                    && strpos(
                        $full_path,
                        $base_directory . DIRECTORY_SEPARATOR
                    ) === 0
                ) {
                    $paths_to_delete[] = $full_path;
                }
            }
        }

        /*
         * Remove the database records first in a transaction.
         *
         * file_versions is deleted explicitly so this works even if
         * the foreign key was not configured with ON DELETE CASCADE.
         */
        $conn->begin_transaction();

        try {

            /*
             * Lock the file row and verify that it is still in Trash.
             */
            $lock = $conn->prepare("
                SELECT id
                FROM files
                WHERE id = ?
                  AND is_trashed = 1
                FOR UPDATE
            ");
            $lock->bind_param("i", $file_id);
            $lock->execute();
            $locked = $lock->get_result()->fetch_assoc();
            $lock->close();

            if (!$locked) {
                throw new Exception(
                    "The file is no longer available in Trash."
                );
            }

            $delete_versions = $conn->prepare("
                DELETE FROM file_versions
                WHERE file_id = ?
            ");
            $delete_versions->bind_param("i", $file_id);

            if (!$delete_versions->execute()) {
                throw new Exception(
                    "The file versions could not be deleted."
                );
            }

            $delete_versions->close();

            $delete_file = $conn->prepare("
                DELETE FROM files
                WHERE id = ?
                  AND is_trashed = 1
            ");
            $delete_file->bind_param("i", $file_id);

            if (!$delete_file->execute()) {
                throw new Exception(
                    "The file record could not be deleted."
                );
            }

            if ($delete_file->affected_rows !== 1) {
                $delete_file->close();

                throw new Exception(
                    "The file record could not be deleted."
                );
            }

            $delete_file->close();

            $conn->commit();

            if ((int)$file["uploaded_by"] !== $user_id) {
                createNotification(
                    $conn,
                    (int)$file["uploaded_by"],
                    "File",
                    "File Permanently Deleted",
                    "Your file \"" . $file["name"] . "\" was permanently deleted.",
                    "Files",
                    null,
                    "file_trash.php"
                );
            }

            /*
             * The database deletion succeeded.
             * Now remove each physical version from disk.
             */
            $failed_paths = [];

            foreach ($paths_to_delete as $path) {
                if (file_exists($path) && !@unlink($path)) {
                    $failed_paths[] = $path;
                }
            }

            /*
             * Database records are already permanently deleted.
             * If a physical file could not be removed, report it instead
             * of pretending everything was removed successfully.
             */
            if ($failed_paths) {
                http_response_code(500);

                exit(
                    "The database records were permanently deleted, "
                    . "but one or more stored files could not be removed "
                    . "from the server. Check the permissions on "
                    . "uploads/files."
                );
            }

            header("Location: file_trash.php");
            exit;

        } catch (Throwable $exception) {

            $conn->rollback();
            $error = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Permanently Delete File</title>

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
        <div class="sidebar-item active">
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item">
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
        <h1>PERMANENTLY DELETE FILE</h1>

        <div class="sub">
            Administrator permanent deletion
        </div>
    </div>

    <div class="content">

    <div class="card">

        <?php if ($error): ?>

            <div class="error">
                <?php echo e($error); ?>
            </div>

        <?php endif; ?>

        <div class="warning">
            <strong>This action cannot be undone.</strong>
            Permanently deleting this file removes the file record,
            every version in its version history, and the stored copies
            from the server.
        </div>

        <div class="detail">
            <div class="label">File</div>
            <div><?php echo e($file["name"]); ?></div>
        </div>

        <div class="detail">
            <div class="label">Category</div>
            <div><?php echo e($file["location_type"]); ?></div>
        </div>

        <div class="detail">
            <div class="label">Versions</div>
            <div><?php echo count($versions); ?></div>
        </div>

        <div class="detail">
            <div class="label">Total Stored Size</div>
            <div><?php echo e(formatFileSize($total_size)); ?></div>
        </div>

        <div class="detail">
            <div class="label">Trashed By</div>
            <div>
                <?php echo e($file["trashed_by_name"] ?: "Unknown"); ?>
            </div>
        </div>

        <div class="detail">
            <div class="label">Trashed</div>
            <div>
                <?php
                echo $file["trashed_at"]
                    ? e(
                        date(
                            "M j, Y g:i A",
                            strtotime($file["trashed_at"])
                        )
                    )
                    : "—";
                ?>
            </div>
        </div>

        <div class="version-list">

            <h3>Versions that will be deleted</h3>

            <?php if (!$versions): ?>

                <div>No stored versions were found.</div>

            <?php else: ?>

                <?php foreach ($versions as $version): ?>

                    <div class="version">
                        <strong>
                            Version <?php echo (int)$version["version_number"]; ?>
                        </strong>
                        —
                        <?php echo e($version["original_filename"]); ?>
                        (<?php echo e(formatFileSize($version["file_size"])); ?>)
                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

        <form method="POST">
            <?= csrfField() ?>

            <input
                type="hidden"
                name="file_id"
                value="<?php echo (int)$file_id; ?>"
            >

            <label class="confirm">

                <input
                    type="checkbox"
                    name="confirm_delete"
                    value="1"
                    required
                >

                <span>
                    I understand that this permanently deletes the file
                    and all of its versions and that they cannot be restored.
                </span>

            </label>

            <div class="actions">

                <a
                    class="button"
                    href="file_trash.php"
                >
                    CANCEL
                </a>

                <button
                    class="button danger"
                    type="submit"
                >
                    PERMANENTLY DELETE
                </button>

            </div>

        </form>

    </div>

</div>

</div>

</div>

</body>
</html>
