<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "file_access_helpers.php";
require_once "announcement_helpers.php";

const PREVIEWABLE_FILE_TYPES = [
    "application/pdf",
    "image/png",
    "image/jpeg",
    "image/gif",
    "image/webp",
];

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
$user_department = $current_user["department"] ?? "";
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
    !canAccessFileLocation(
        $conn,
        $file["location_type"],
        $file["location_id"],
        $user_id,
        $role,
        $user_department
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

<link rel="stylesheet" href="app.css?v=22">

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
            <h1><?php echo e($file["name"]); ?></h1>

            <div class="sub">
                Version <?php echo (int)$file["current_version"]; ?>
                &nbsp;·&nbsp;
                <?php echo e($file["location_type"]); ?>
            </div>
        </div>

        <div class="header-actions">

            <a class="button" href="comments.php?module=File&id=<?php echo (int)$file["id"]; ?>">COMMENTS</a>

            <a class="button" href="files.php">
                BACK TO FILES
            </a>

            <?php if ($current_version !== null && in_array($current_version["file_type"] ?? "", PREVIEWABLE_FILE_TYPES, true)): ?>
                <button
                    type="button"
                    class="button"
                    onclick="openFilePreview('preview_file.php?id=<?php echo (int)$file["id"]; ?>', '<?php echo e($current_version["file_type"]); ?>', '<?php echo e($file["name"]); ?>')"
                >
                    PREVIEW
                </button>
            <?php endif; ?>

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
                            <?php if (in_array($version["file_type"] ?? "", PREVIEWABLE_FILE_TYPES, true)): ?>
                                <button
                                    type="button"
                                    class="button"
                                    onclick="openFilePreview('preview_file.php?version=<?php echo (int)$version["id"]; ?>', '<?php echo e($version["file_type"]); ?>', '<?php echo e($version["original_filename"]); ?>')"
                                >
                                    PREVIEW
                                </button>
                            <?php endif; ?>

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

<!-- FILE PREVIEW MODAL -->
<div id="file-preview-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:30px;">
    <div style="background:#fff;border-radius:6px;max-width:90vw;max-height:90vh;width:900px;display:flex;flex-direction:column;overflow:hidden;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid rgba(0,0,0,.12);">
            <strong id="file-preview-title">Preview</strong>
            <button type="button" class="button" onclick="closeFilePreview()">CLOSE</button>
        </div>
        <div id="file-preview-body" style="flex:1;overflow:auto;background:#2b2b2b;display:flex;align-items:center;justify-content:center;min-height:400px;">
        </div>
    </div>
</div>

<script>
function openFilePreview(url, fileType, name) {
    var overlay = document.getElementById("file-preview-overlay");
    var body = document.getElementById("file-preview-body");
    var title = document.getElementById("file-preview-title");

    title.textContent = "Preview - " + name;
    body.innerHTML = "";

    if (fileType === "application/pdf") {
        var iframe = document.createElement("iframe");
        iframe.src = url;
        iframe.style.width = "100%";
        iframe.style.height = "80vh";
        iframe.style.border = "none";
        body.appendChild(iframe);
    } else {
        var img = document.createElement("img");
        img.src = url;
        img.style.maxWidth = "100%";
        img.style.maxHeight = "80vh";
        img.style.objectFit = "contain";
        body.appendChild(img);
    }

    overlay.style.display = "flex";
}

function closeFilePreview() {
    var overlay = document.getElementById("file-preview-overlay");
    var body = document.getElementById("file-preview-body");
    overlay.style.display = "none";
    body.innerHTML = "";
}

document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
        closeFilePreview();
    }
});
</script>

</body>
</html>
