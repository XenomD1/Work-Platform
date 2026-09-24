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

$role = $user["role"];
$user_department = $user["department"];

$folder_id = (int)($_GET["id"] ?? $_POST["folder_id"] ?? 0);

if ($folder_id <= 0) {
    header("Location: files.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
 * Same Trash permission rules used by view_folder.php.
 */
function canTrashFolder(
    $conn,
    $folder,
    $user_id,
    $role,
    $user_department
) {
    if ($role === "Administrator") {
        return true;
    }

    if (
        ($role === "Manager" || $role === "IT Administrator")
        && $folder["location_type"] === "Department"
    ) {
        return
            (string)$folder["location_id"]
            === (string)$user_department;
    }

    if (
        in_array(
            $folder["location_type"],
            ["Project", "Task", "Team"],
            true
        )
    ) {
        $project_id = 0;

        if ($folder["location_type"] === "Project") {
            $project_id = (int)$folder["location_id"];

        } elseif ($folder["location_type"] === "Task") {
            $stmt = $conn->prepare("
                SELECT project_id
                FROM tasks
                WHERE id = ?
            ");
            $stmt->bind_param("i", $folder["location_id"]);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $project_id = (int)($row["project_id"] ?? 0);

        } elseif ($folder["location_type"] === "Team") {
            $stmt = $conn->prepare("
                SELECT project_id, team_lead_id
                FROM teams
                WHERE id = ?
            ");
            $stmt->bind_param("i", $folder["location_id"]);
            $stmt->execute();
            $team = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (
                $team
                && (int)$team["team_lead_id"] === $user_id
            ) {
                return true;
            }

            $project_id = (int)($team["project_id"] ?? 0);
        }

        if ($project_id > 0) {
            $stmt = $conn->prepare("
                SELECT department, manager_id
                FROM projects
                WHERE id = ?
            ");
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $project = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$project) {
                return false;
            }

            if (
                ($role === "Manager" || $role === "IT Administrator")
                && $project["department"] === $user_department
            ) {
                return true;
            }

            if (
                $project["manager_id"] !== null
                && (int)$project["manager_id"] === $user_id
            ) {
                return true;
            }
        }
    }

    /*
     * Event folders: Managers may trash only when they manage
     * that department, matching canTrashFile()'s rule for
     * individual Event files.
     */
    if ($folder["location_type"] === "Event") {

        $stmt = $conn->prepare("
            SELECT department
            FROM events
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $folder["location_id"]);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            $event
            && ($role === "Manager" || $role === "IT Administrator")
            && $event["department"] === $user_department
        ) {
            return true;
        }
    }

    /*
     * Training folders: same rule as Event folders above.
     */
    if ($folder["location_type"] === "Training") {

        $stmt = $conn->prepare("
            SELECT department
            FROM training
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $folder["location_id"]);
        $stmt->execute();
        $training = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            $training
            && ($role === "Manager" || $role === "IT Administrator")
            && $training["department"] === $user_department
        ) {
            return true;
        }
    }

    return false;
}

/*
 * Load active folder.
 */
$stmt = $conn->prepare("
    SELECT
        id,
        name,
        parent_folder_id,
        location_type,
        location_id,
        created_by,
        created_at,
        updated_at
    FROM file_folders
    WHERE id = ?
      AND is_trashed = 0
");
$stmt->bind_param("i", $folder_id);
$stmt->execute();
$folder = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$folder) {
    header("Location: files.php");
    exit;
}

if (
    !canTrashFolder(
        $conn,
        $folder,
        $user_id,
        $role,
        $user_department
    )
) {
    http_response_code(403);
    exit("You do not have permission to move this folder to Trash.");
}

/*
 * Collect selected folder and all descendants.
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
              AND is_trashed = 0
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

$file_count = 0;

foreach ($folder_ids as $current_folder_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM files
        WHERE folder_id = ?
          AND is_trashed = 0
    ");
    $stmt->bind_param("i", $current_folder_id);
    $stmt->execute();

    $file_count +=
        (int)$stmt->get_result()->fetch_assoc()["total"];

    $stmt->close();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    if (!isset($_POST["confirm_trash"])) {
        $error = "You must confirm moving this folder to Trash.";
    }

    if ($error === "") {

        $conn->begin_transaction();

        try {

            /*
             * Re-check root folder inside the transaction.
             */
            $stmt = $conn->prepare("
                SELECT id
                FROM file_folders
                WHERE id = ?
                  AND is_trashed = 0
                FOR UPDATE
            ");
            $stmt->bind_param("i", $folder_id);
            $stmt->execute();
            $locked_folder =
                $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$locked_folder) {
                throw new Exception(
                    "The folder is no longer available."
                );
            }

            /*
             * Move every active file in the folder tree to Trash.
             * File version records are deliberately left untouched.
             */
            foreach ($folder_ids as $current_folder_id) {

                $stmt = $conn->prepare("
                    UPDATE files
                    SET
                        is_trashed = 1,
                        trashed_by = ?,
                        trashed_at = NOW(),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE folder_id = ?
                      AND is_trashed = 0
                ");
                $stmt->bind_param(
                    "ii",
                    $user_id,
                    $current_folder_id
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to move one or more files to Trash."
                    );
                }

                $stmt->close();
            }

            /*
             * Mark folder tree as trashed.
             * file_folders currently tracks the Trash state with
             * is_trashed; restore_folder.php reverses this state.
             */
            foreach ($folder_ids as $current_folder_id) {

                $stmt = $conn->prepare("
                    UPDATE file_folders
                    SET
                        is_trashed = 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                      AND is_trashed = 0
                ");
                $stmt->bind_param(
                    "i",
                    $current_folder_id
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to move one or more folders to Trash."
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            /*
             * Return to parent when possible; otherwise Files.
             */
            if (!empty($folder["parent_folder_id"])) {
                header(
                    "Location: view_folder.php?id="
                    . (int)$folder["parent_folder_id"]
                );
            } else {
                header("Location: files.php");
            }

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

<title>Move Folder to Trash</title>

<link rel="stylesheet" href="app.css?v=20">

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

        <?php if (canPostAnnouncements($role)): ?>
            <div class="sidebar-item">
                <a href="announcements.php">Announcements</a>
            </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
            <div class="sidebar-item">
                <a href="users.php">User Management</a>
            </div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
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

    <main class="main">

        <div class="header">
            <h1>MOVE FOLDER TO TRASH</h1>

            <div class="sub">
                Move this folder, its subfolders, and their files to Trash
            </div>
        </div>

        <div class="content">

        <div class="card">

            <?php if ($error !== ""): ?>
                <div class="error">
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>

            <div class="warning">
                This does <strong>not</strong> permanently delete anything.
                The folder tree and all file versions stay in Trash until an
                Administrator permanently deletes them. An Administrator can
                restore the folder before then.
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
                <div class="label">Active Files</div>
                <div><?php echo (int)$file_count; ?></div>
            </div>

            <form method="POST">

                <?= csrfField() ?>

                <input
                    type="hidden"
                    name="folder_id"
                    value="<?php echo (int)$folder_id; ?>"
                >

                <div class="confirm">
                    <label>
                        <input
                            type="checkbox"
                            name="confirm_trash"
                            value="1"
                            required
                        >
                        I understand that this entire folder tree will move
                        to Trash.
                    </label>
                </div>

                <div class="actions">

                    <a
                        class="button"
                        href="view_folder.php?id=<?php echo (int)$folder_id; ?>"
                    >
                        CANCEL
                    </a>

                    <button
                        class="button button-danger"
                        type="submit"
                    >
                        MOVE TO TRASH
                    </button>

                </div>

            </form>

        </div>

    </main>

</div>

</div>

</body>
</html>
