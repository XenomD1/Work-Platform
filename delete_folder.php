<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
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
 * An Administrator can permanently delete any folder. An IT
 * Administrator may only permanently delete a folder that belongs
 * to their own department.
 */
if (!isAnyAdministrator($user["role"])) {
    http_response_code(403);
    exit("Only Administrators can permanently delete folders.");
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
 * The root folder must already be in Trash.
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
    exit("You do not have permission to permanently delete this folder.");
}

/*
 * Collect the selected folder and all descendant folders.
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

/*
 * Load all files inside the folder tree.
 * Because the folder is in Trash, permanent deletion only includes
 * files that are also currently in Trash.
 */
$files = [];

foreach ($folder_ids as $id) {

    $stmt = $conn->prepare("
        SELECT
            id,
            name,
            current_version
        FROM files
        WHERE folder_id = ?
          AND is_trashed = 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $files[(int)$row["id"]] = $row;
    }

    $stmt->close();
}

$file_count = count($files);

/*
 * Load every version for every file in the folder tree.
 */
$versions = [];
$total_size = 0;

foreach ($files as $file) {

    $file_id = (int)$file["id"];

    $stmt = $conn->prepare("
        SELECT
            id,
            file_id,
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
        $total_size += (int)$row["file_size"];
    }

    $stmt->close();
}

$version_count = count($versions);
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    if (!isset($_POST["confirm_delete"])) {
        $error = "You must confirm permanent deletion.";
    }

    if ($error === "") {

        /*
         * Resolve physical paths before database deletion.
         * Only files located inside uploads/files may be removed.
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

        $conn->begin_transaction();

        try {

            /*
             * Verify that the selected root folder is still in Trash.
             */
            $lock = $conn->prepare("
                SELECT id
                FROM file_folders
                WHERE id = ?
                  AND is_trashed = 1
                FOR UPDATE
            ");
            $lock->bind_param("i", $folder_id);
            $lock->execute();
            $locked = $lock->get_result()->fetch_assoc();
            $lock->close();

            if (!$locked) {
                throw new Exception(
                    "The folder is no longer available in Trash."
                );
            }

            /*
             * Delete file version records and file records.
             */
            foreach ($files as $file) {

                $current_file_id = (int)$file["id"];

                $stmt = $conn->prepare("
                    DELETE FROM file_versions
                    WHERE file_id = ?
                ");
                $stmt->bind_param("i", $current_file_id);

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to delete one or more file versions."
                    );
                }

                $stmt->close();

                $stmt = $conn->prepare("
                    DELETE FROM files
                    WHERE id = ?
                      AND is_trashed = 1
                ");
                $stmt->bind_param("i", $current_file_id);

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to delete one or more files."
                    );
                }

                $stmt->close();
            }

            /*
             * Delete folders from deepest child back to root.
             */
            $reverse_folder_ids = array_reverse($folder_ids);

            foreach ($reverse_folder_ids as $current_folder_id) {

                $stmt = $conn->prepare("
                    DELETE FROM file_folders
                    WHERE id = ?
                      AND is_trashed = 1
                ");
                $stmt->bind_param("i", $current_folder_id);

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to delete one or more folders."
                    );
                }

                /*
                 * If a descendant was restored separately, do not silently
                 * delete it. This also protects active folder records.
                 */
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();

                    throw new Exception(
                        "One or more folders are no longer in Trash. "
                        . "Permanent deletion was cancelled."
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            /*
             * Remove the physical stored versions after the database
             * transaction succeeds.
             */
            $failed_paths = [];

            foreach (array_unique($paths_to_delete) as $path) {
                if (file_exists($path) && !@unlink($path)) {
                    $failed_paths[] = $path;
                }
            }

            if ($failed_paths) {
                http_response_code(500);

                exit(
                    "The folder records were permanently deleted, "
                    . "but one or more stored files could not be removed "
                    . "from the server. Check the permissions on uploads/files."
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

<title>Permanently Delete Folder</title>

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
        <h1>PERMANENTLY DELETE FOLDER</h1>

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
            Permanently deleting this folder removes the folder tree,
            all trashed files inside it, every version of those files,
            and their stored copies from the server.
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
            <div class="label">Files</div>
            <div><?php echo (int)$file_count; ?></div>
        </div>

        <div class="detail">
            <div class="label">File Versions</div>
            <div><?php echo (int)$version_count; ?></div>
        </div>

        <div class="detail">
            <div class="label">Stored Size</div>
            <div><?php echo e(formatFileSize($total_size)); ?></div>
        </div>

        <form method="POST">
            <?= csrfField() ?>

            <input
                type="hidden"
                name="folder_id"
                value="<?php echo (int)$folder_id; ?>"
            >

            <label class="confirm">

                <input
                    type="checkbox"
                    name="confirm_delete"
                    value="1"
                    required
                >

                <span>
                    I understand that this permanently deletes this folder,
                    its subfolders, its trashed files, and all file versions,
                    and that they cannot be restored.
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
