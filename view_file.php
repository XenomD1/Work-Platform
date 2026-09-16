<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$user_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$role = $current_user["role"];
$user_department = $current_user["department"];
$current_full_name = trim(
    $current_user["first_name"] . " " . $current_user["last_name"]
);

$file_id = (int)($_GET["id"] ?? 0);

if ($file_id <= 0) {
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

        if (!$project) return false;

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

        $member_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM teams t
            INNER JOIN team_members tm ON tm.team_id = t.id
            WHERE t.project_id = ?
              AND tm.user_id = ?
        ");
        $member_stmt->bind_param("ii", $location_id, $user_id);
        $member_stmt->execute();
        $total = (int)$member_stmt->get_result()->fetch_assoc()["total"];
        $member_stmt->close();

        return $total > 0;
    }

    if ($location_type === "Task") {
        $stmt = $conn->prepare("
            SELECT
                p.department,
                p.manager_id
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$task) return false;

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

        $assign_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM task_assignments
            WHERE task_id = ?
              AND user_id = ?
        ");
        $assign_stmt->bind_param("ii", $location_id, $user_id);
        $assign_stmt->execute();
        $total = (int)$assign_stmt->get_result()->fetch_assoc()["total"];
        $assign_stmt->close();

        return $total > 0;
    }

    if ($location_type === "Team") {
        $stmt = $conn->prepare("
            SELECT
                t.team_lead_id,
                p.department,
                p.manager_id
            FROM teams t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $team = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$team) return false;

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

        $member_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM team_members
            WHERE team_id = ?
              AND user_id = ?
        ");
        $member_stmt->bind_param("ii", $location_id, $user_id);
        $member_stmt->execute();
        $total = (int)$member_stmt->get_result()->fetch_assoc()["total"];
        $member_stmt->close();

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

        if (!$event) return false;

        if ($event["type"] === "Organization-Wide Event") return true;
        if ((int)$event["organizer"] === $user_id) return true;
        if ($event["department"] === $user_department) return true;

        $attendee_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM event_attendees
            WHERE event_id = ?
              AND user_id = ?
        ");
        $attendee_stmt->bind_param("ii", $location_id, $user_id);
        $attendee_stmt->execute();
        $total = (int)$attendee_stmt->get_result()->fetch_assoc()["total"];
        $attendee_stmt->close();

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

        if (!$training) return false;

        if ((int)$training["organizer"] === $user_id) return true;
        if ($training["department"] === $user_department) return true;

        $attendee_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM training_attendees
            WHERE training_id = ?
              AND (user_id = ? OR department = ?)
        ");
        $attendee_stmt->bind_param(
            "iis",
            $location_id,
            $user_id,
            $user_department
        );
        $attendee_stmt->execute();
        $total = (int)$attendee_stmt->get_result()->fetch_assoc()["total"];
        $attendee_stmt->close();

        return $total > 0;
    }

    return false;
}

function canTrashFile(
    $conn,
    $file,
    $user_id,
    $role,
    $user_department,
    $current_full_name
) {
    if ($role === "Administrator") {
        return true;
    }

    if ($role === "Manager" || $role === "IT Administrator") {
        if ($file["location_type"] === "Department") {
            return (string)$file["location_id"] === (string)$user_department;
        }

        if (in_array($file["location_type"], ["Project", "Task", "Team"], true)) {
            $project_id = 0;

            if ($file["location_type"] === "Project") {
                $project_id = (int)$file["location_id"];
            } elseif ($file["location_type"] === "Task") {
                $stmt = $conn->prepare("
                    SELECT project_id
                    FROM tasks
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $file["location_id"]);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $project_id = (int)($row["project_id"] ?? 0);
            } elseif ($file["location_type"] === "Team") {
                $stmt = $conn->prepare("
                    SELECT project_id
                    FROM teams
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $file["location_id"]);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $project_id = (int)($row["project_id"] ?? 0);
            }

            if ($project_id > 0) {
                $stmt = $conn->prepare("
                    SELECT department
                    FROM projects
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $project_id);
                $stmt->execute();
                $project = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($project && $project["department"] === $user_department) {
                    return true;
                }
            }
        }
    }

    if (in_array($file["location_type"], ["Project", "Task", "Team"], true)) {

        $project_id = 0;

        if ($file["location_type"] === "Project") {
            $project_id = (int)$file["location_id"];
        } elseif ($file["location_type"] === "Task") {
            $stmt = $conn->prepare("
                SELECT project_id
                FROM tasks
                WHERE id = ?
            ");
            $stmt->bind_param("i", $file["location_id"]);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $project_id = (int)($row["project_id"] ?? 0);
        } elseif ($file["location_type"] === "Team") {
            $stmt = $conn->prepare("
                SELECT project_id, team_lead_id
                FROM teams
                WHERE id = ?
            ");
            $stmt->bind_param("i", $file["location_id"]);
            $stmt->execute();
            $team = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($team && (int)$team["team_lead_id"] === $user_id) {
                return true;
            }

            $project_id = (int)($team["project_id"] ?? 0);
        }

        if ($project_id > 0) {
            $stmt = $conn->prepare("
                SELECT manager_id
                FROM projects
                WHERE id = ?
            ");
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $project = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (
                $project
                && $project["manager_id"] !== null
                && (int)$project["manager_id"] === $user_id
            ) {
                return true;
            }
        }
    }

    return false;
}

/*
 * Load file.
 */
$file_stmt = $conn->prepare("
    SELECT
        f.id,
        f.name,
        f.folder_id,
        f.location_type,
        f.location_id,
        f.current_version,
        f.uploaded_by,
        f.created_at,
        f.updated_at,
        f.is_trashed,
        CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name,
        ff.name AS folder_name
    FROM files f
    INNER JOIN users u ON u.id = f.uploaded_by
    LEFT JOIN file_folders ff ON ff.id = f.folder_id
    WHERE f.id = ?
");
$file_stmt->bind_param("i", $file_id);
$file_stmt->execute();
$file = $file_stmt->get_result()->fetch_assoc();
$file_stmt->close();

if (!$file || (int)$file["is_trashed"] === 1) {
    header("Location: files.php");
    exit;
}

if (
    !canAccessLocation(
        $conn,
        $file["location_type"],
        $file["location_id"],
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
 * Load versions.
 */
$versions = [];

$version_stmt = $conn->prepare("
    SELECT
        fv.id,
        fv.version_number,
        fv.original_filename,
        fv.stored_filename,
        fv.file_path,
        fv.file_size,
        fv.file_type,
        fv.uploaded_at,
        CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
    FROM file_versions fv
    INNER JOIN users u ON u.id = fv.uploaded_by
    WHERE fv.file_id = ?
    ORDER BY fv.version_number DESC
");
$version_stmt->bind_param("i", $file_id);
$version_stmt->execute();
$version_result = $version_stmt->get_result();

while ($row = $version_result->fetch_assoc()) {
    $versions[] = $row;
}

$version_stmt->close();

$current_version = null;

foreach ($versions as $version) {
    if ((int)$version["version_number"] === (int)$file["current_version"]) {
        $current_version = $version;
        break;
    }
}

$can_trash = canTrashFile(
    $conn,
    $file,
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

<title>View File</title>

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

        <div>
            <h1><?php echo e($file["name"]); ?></h1>

            <div class="sub">
                Version <?php echo (int)$file["current_version"]; ?>
                &nbsp;·&nbsp;
                <?php echo e($file["location_type"]); ?>
            </div>
        </div>

        <div class="header-actions">

            <a class="button" href="files.php">
                BACK TO FILES
            </a>

            <a
                class="button primary"
                href="download_file.php?id=<?php echo (int)$file["id"]; ?>"
            >
                DOWNLOAD CURRENT
            </a>

            <a
                class="button"
                href="new_file_version.php?id=<?php echo (int)$file["id"]; ?>"
            >
                UPLOAD NEW VERSION
            </a>

            <?php if ($can_trash): ?>

                <a
                    class="button danger"
                    href="trash_file.php?id=<?php echo (int)$file["id"]; ?>"
                >
                    MOVE TO TRASH
                </a>

            <?php endif; ?>

        </div>

    </div>

    <div class="content">

    <div class="card">

        <h2>File Information</h2>

        <div class="grid info-grid">

            <div class="detail">
                <span class="label">Display Name</span>
                <span class="value">
                    <?php echo e($file["name"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Current Version</span>
                <span class="value">
                    Version <?php echo (int)$file["current_version"]; ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Original Filename</span>
                <span class="value">
                    <?php echo e($current_version["original_filename"] ?? "—"); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">File Type</span>
                <span class="value">
                    <?php echo e($current_version["file_type"] ?? "Unknown"); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">File Size</span>
                <span class="value">
                    <?php echo e(formatFileSize($current_version["file_size"] ?? 0)); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Folder</span>
                <span class="value">
                    <?php echo e($file["folder_name"] ?: "No Folder"); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Category</span>
                <span class="value">
                    <?php echo e($file["location_type"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Location ID</span>
                <span class="value">
                    <?php
                    echo $file["location_type"] === "Organization"
                        ? "Organization-wide"
                        : e($file["location_id"]);
                    ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Originally Uploaded By</span>
                <span class="value">
                    <?php echo e($file["uploaded_by_name"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Created</span>
                <span class="value">
                    <?php echo e(date("M j, Y g:i A", strtotime($file["created_at"]))); ?>
                </span>
            </div>

            <div class="detail full">
                <span class="label">Last Updated</span>
                <span class="value">
                    <?php echo e(date("M j, Y g:i A", strtotime($file["updated_at"]))); ?>
                </span>
            </div>

        </div>

    </div>

    <div class="card">

        <h2>Version History</h2>

        <table class="version-table">

            <thead>
                <tr>
                    <th>Version</th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Uploaded By</th>
                    <th>Uploaded</th>
                    <th>Download</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($versions as $version): ?>

                    <tr>

                        <td>
                            Version <?php echo (int)$version["version_number"]; ?>

                            <?php if (
                                (int)$version["version_number"]
                                === (int)$file["current_version"]
                            ): ?>
                                <span class="current-tag">
                                    CURRENT
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php echo e($version["original_filename"]); ?>
                        </td>

                        <td>
                            <?php echo e(formatFileSize($version["file_size"])); ?>
                        </td>

                        <td>
                            <?php echo e($version["uploaded_by_name"]); ?>
                        </td>

                        <td>
                            <?php
                            echo e(
                                date(
                                    "M j, Y g:i A",
                                    strtotime($version["uploaded_at"])
                                )
                            );
                            ?>
                        </td>

                        <td>
                            <a
                                class="button"
                                href="download_file.php?version=<?php echo (int)$version["id"]; ?>"
                            >
                                DOWNLOAD
                            </a>
                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

</div>

</div>

</body>
</html>
