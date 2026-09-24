<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
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
$current_full_name = trim(
    $user["first_name"] . " " . $user["last_name"]
);

$folder_id = (int)($_GET["id"] ?? 0);

if ($folder_id <= 0) {
    header("Location: files.php");
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

function canAccessLocation(
    $conn,
    $location_type,
    $location_id,
    $user_id,
    $role,
    $user_department,
    $current_full_name
) {
    if ($location_type === "Organization") {
        return true;
    }

    if ($role === "Administrator") {
        return true;
    }

    if ($location_type === "Department") {
        return (string)$location_id === (string)$user_department;
    }

    if ($location_type === "Project") {

        $stmt = $conn->prepare("
            SELECT department, manager_id
            FROM projects
            WHERE id = ?
        ");
        $stmt->bind_param("i", $location_id);
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

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM teams t
            INNER JOIN team_members tm
                ON tm.team_id = t.id
            WHERE t.project_id = ?
              AND tm.user_id = ?
        ");
        $stmt->bind_param("ii", $location_id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Task") {

        $stmt = $conn->prepare("
            SELECT p.department, p.manager_id
            FROM tasks t
            INNER JOIN projects p
                ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$task) {
            return false;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $task["department"] === $user_department
        ) {
            return true;
        }

        if (
            $task["manager_id"] !== null
            && (int)$task["manager_id"] === $user_id
        ) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM task_assignments
            WHERE task_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $location_id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Team") {

        $stmt = $conn->prepare("
            SELECT
                t.team_lead_id,
                p.department,
                p.manager_id
            FROM teams t
            INNER JOIN projects p
                ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $team = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$team) {
            return false;
        }

        if ((int)$team["team_lead_id"] === $user_id) {
            return true;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $team["department"] === $user_department
        ) {
            return true;
        }

        if (
            $team["manager_id"] !== null
            && (int)$team["manager_id"] === $user_id
        ) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM team_members
            WHERE team_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $location_id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Event") {

        $stmt = $conn->prepare("
            SELECT department, type, organizer
            FROM events
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$event) {
            return false;
        }

        if ($event["type"] === "Organization-Wide Event") {
            return true;
        }

        if ((int)$event["organizer"] === $user_id) {
            return true;
        }

        if ($event["department"] === $user_department) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM event_attendees
            WHERE event_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $location_id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Training") {

        $stmt = $conn->prepare("
            SELECT department, organizer
            FROM training
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $training = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$training) {
            return false;
        }

        if ((int)$training["organizer"] === $user_id) {
            return true;
        }

        if ($training["department"] === $user_department) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM training_attendees
            WHERE training_id = ?
              AND (
                    user_id = ?
                    OR department = ?
              )
        ");
        $stmt->bind_param(
            "iis",
            $location_id,
            $user_id,
            $user_department
        );
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    return false;
}

/*
 * Folder Trash permissions match the file deletion rules:
 *
 * - Administrator: any folder.
 * - Manager: Department folders for their own department, plus
 *   Project/Task/Team folders tied to a project in their department.
 * - Project Manager: Project/Task/Team folders tied to a project they manage.
 * - Team Lead: Team folders for a team they lead.
 *
 * Organization, Event, and Training folders are Administrator-only.
 */
function canTrashFolder(
    $conn,
    $folder,
    $user_id,
    $role,
    $user_department,
    $current_full_name
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
 * Load folder.
 */
$stmt = $conn->prepare("
    SELECT
        ff.id,
        ff.name,
        ff.parent_folder_id,
        ff.location_type,
        ff.location_id,
        ff.created_by,
        ff.created_at,
        ff.updated_at,
        CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
    FROM file_folders ff
    INNER JOIN users u
        ON u.id = ff.created_by
    WHERE ff.id = ?
      AND ff.is_trashed = 0
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
    !canAccessLocation(
        $conn,
        $folder["location_type"],
        $folder["location_id"],
        $user_id,
        $role,
        $user_department,
        $current_full_name
    )
) {
    header("Location: files.php");
    exit;
}

/*
 * Parent folder information.
 */
$parent_folder = null;

if (!empty($folder["parent_folder_id"])) {
    $stmt = $conn->prepare("
        SELECT id, name
        FROM file_folders
        WHERE id = ?
          AND is_trashed = 0
    ");
    $stmt->bind_param("i", $folder["parent_folder_id"]);
    $stmt->execute();
    $parent_folder = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/*
 * Load subfolders.
 */
$subfolders = [];

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        created_at,
        updated_at
    FROM file_folders
    WHERE parent_folder_id = ?
      AND is_trashed = 0
    ORDER BY name ASC
");
$stmt->bind_param("i", $folder_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $subfolders[] = $row;
}

$stmt->close();

/*
 * Load active files in this folder.
 */
$files = [];

$stmt = $conn->prepare("
    SELECT
        f.id,
        f.name,
        f.current_version,
        f.updated_at,
        CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name,
        fv.original_filename,
        fv.file_size,
        fv.file_type
    FROM files f
    INNER JOIN users u
        ON u.id = f.uploaded_by
    LEFT JOIN file_versions fv
        ON fv.file_id = f.id
       AND fv.version_number = f.current_version
    WHERE f.folder_id = ?
      AND f.is_trashed = 0
    ORDER BY f.name ASC
");
$stmt->bind_param("i", $folder_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $files[] = $row;
}

$stmt->close();

/*
 * Folder creation permissions for creating subfolders.
 */
$can_create_subfolder = in_array(
    $role,
    ["Administrator", "Manager", "IT Administrator"],
    true
);

if (!$can_create_subfolder) {

    if ($folder["location_type"] === "Project") {
        $stmt = $conn->prepare("
            SELECT manager_id
            FROM projects
            WHERE id = ?
        ");
        $stmt->bind_param("i", $folder["location_id"]);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            $project
            && $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            $can_create_subfolder = true;
        }
    }

    if ($folder["location_type"] === "Team") {
        $stmt = $conn->prepare("
            SELECT team_lead_id
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
            $can_create_subfolder = true;
        }
    }
}

$can_trash = canTrashFolder(
    $conn,
    $folder,
    $user_id,
    $role,
    $user_department,
    $current_full_name
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>View Folder</title>

<link rel="stylesheet" href="app.css?v=12">

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

<div class="main">

    <div class="header">

        <div>
            <h1><?php echo e($folder["name"]); ?></h1>

            <div class="sub">
                <?php echo e($folder["location_type"]); ?>
                <?php if ($folder["location_type"] !== "Organization"): ?>
                    &nbsp;·&nbsp;
                    Location <?php echo e($folder["location_id"]); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="header-actions">

            <?php if ($parent_folder): ?>
                <a
                    class="button"
                    href="view_folder.php?id=<?php echo (int)$parent_folder["id"]; ?>"
                >
                    PARENT FOLDER
                </a>
            <?php else: ?>
                <a
                    class="button"
                    href="files.php"
                >
                    BACK TO FILES
                </a>
            <?php endif; ?>

            <?php if ($can_create_subfolder): ?>
                <a
                    class="button primary"
                    href="new_folder.php"
                >
                    + NEW FOLDER
                </a>
            <?php endif; ?>

            <?php if ($can_trash): ?>
                <a
                    class="button danger"
                    href="trash_folder.php?id=<?php echo (int)$folder["id"]; ?>"
                >
                    MOVE TO TRASH
                </a>
            <?php endif; ?>

        </div>

    </div>

    <div class="content">

    <div class="card">

        <h2>Folder Information</h2>

        <div class="grid info-grid">

            <div class="detail">
                <span class="label">Folder Name</span>
                <span class="value">
                    <?php echo e($folder["name"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Category</span>
                <span class="value">
                    <?php echo e($folder["location_type"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Created By</span>
                <span class="value">
                    <?php echo e($folder["created_by_name"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Created</span>
                <span class="value">
                    <?php echo e(date("M j, Y g:i A", strtotime($folder["created_at"]))); ?>
                </span>
            </div>

        </div>

    </div>

    <div class="card">

        <h2>Subfolders</h2>

        <?php if (!$subfolders): ?>

            <div class="empty">
                No subfolders are inside this folder.
            </div>

        <?php else: ?>

            <div class="folder-grid">

                <?php foreach ($subfolders as $subfolder): ?>

                    <div class="folder-card">

                        <div class="folder-name">
                            <?php echo e($subfolder["name"]); ?>
                        </div>

                        <div class="meta">
                            Updated:
                            <?php echo e(date("M j, Y", strtotime($subfolder["updated_at"]))); ?>
                        </div>

                        <div class="actions">

                            <a
                                class="button"
                                href="view_folder.php?id=<?php echo (int)$subfolder["id"]; ?>"
                            >
                                OPEN FOLDER
                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

    <div class="card">

        <h2>Files</h2>

        <?php if (!$files): ?>

            <div class="empty">
                No files are inside this folder.
            </div>

        <?php else: ?>

            <div class="file-grid">

                <?php foreach ($files as $file): ?>

                    <div class="file-card">

                        <div class="file-name">
                            <?php echo e($file["name"]); ?>
                        </div>

                        <div class="meta">
                            <?php echo e($file["original_filename"] ?: "Unknown filename"); ?>
                            <br>
                            Version <?php echo (int)$file["current_version"]; ?>
                            <br>
                            <?php echo e(formatFileSize($file["file_size"])); ?>
                            <br>
                            Uploaded by <?php echo e($file["uploaded_by_name"]); ?>
                        </div>

                        <div class="actions">

                            <a
                                class="button"
                                href="view_file.php?id=<?php echo (int)$file["id"]; ?>"
                            >
                                VIEW
                            </a>

                            <a
                                class="button"
                                href="download_file.php?id=<?php echo (int)$file["id"]; ?>"
                            >
                                DOWNLOAD
                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>

</div>

</div>

</body>
</html>
