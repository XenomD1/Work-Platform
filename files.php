<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "file_access_helpers.php";

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

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

$search = trim($_GET["search"] ?? "");
$location_type_filter = trim($_GET["location_type"] ?? "");
$folder_filter = (int)($_GET["folder"] ?? 0);

$location_types = [
    "Organization",
    "Department",
    "Project",
    "Task",
    "Team",
    "Event",
    "Training"
];

$folders = [];
$folder_result = $conn->query("
    SELECT
        ff.id,
        ff.name,
        ff.parent_folder_id,
        ff.location_type,
        ff.location_id,
        ff.created_by,
        ff.created_at
    FROM file_folders ff
    WHERE ff.is_trashed = 0
    ORDER BY ff.name ASC
");
if ($folder_result) {
    while ($row = $folder_result->fetch_assoc()) {
        $folders[] = $row;
    }
}

$can_create_folder = in_array($role, ["Administrator", "Manager", "IT Administrator"], true);

if (!$can_create_folder) {
    $project_manager_stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM projects
        WHERE manager_id = ?
    ");
    $project_manager_stmt->bind_param("i", $user_id);
    $project_manager_stmt->execute();
    $project_manager_count =
        (int)$project_manager_stmt->get_result()->fetch_assoc()["total"];
    $project_manager_stmt->close();

    if ($project_manager_count > 0) {
        $can_create_folder = true;
    }
}

if (!$can_create_folder) {
    $team_lead_stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams
        WHERE team_lead_id = ?
          AND status = 'Active'
    ");
    $team_lead_stmt->bind_param("i", $user_id);
    $team_lead_stmt->execute();
    $team_lead_count =
        (int)$team_lead_stmt->get_result()->fetch_assoc()["total"];
    $team_lead_stmt->close();

    if ($team_lead_count > 0) {
        $can_create_folder = true;
    }
}

$sql = "
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
        CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name,
        fv.original_filename,
        fv.file_size,
        fv.file_type,
        fv.file_path,
        ff.name AS folder_name
    FROM files f
    INNER JOIN users u ON u.id = f.uploaded_by
    LEFT JOIN file_versions fv
        ON fv.file_id = f.id
       AND fv.version_number = f.current_version
    LEFT JOIN file_folders ff
        ON ff.id = f.folder_id
    WHERE f.is_trashed = 0
    ORDER BY f.updated_at DESC, f.name ASC
";

$result = $conn->query($sql);
$visible_files = [];

if ($result) {
    while ($file = $result->fetch_assoc()) {

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
            continue;
        }

        if (
            $location_type_filter !== ""
            && $file["location_type"] !== $location_type_filter
        ) {
            continue;
        }

        if (
            $folder_filter > 0
            && (int)$file["folder_id"] !== $folder_filter
        ) {
            continue;
        }

        if ($search !== "") {
            $haystack = strtolower(
                $file["name"]
                . " "
                . ($file["original_filename"] ?? "")
                . " "
                . ($file["folder_name"] ?? "")
                . " "
                . $file["location_type"]
            );

            if (strpos($haystack, strtolower($search)) === false) {
                continue;
            }
        }

        $visible_files[] = $file;
    }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Files</title>

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
            <h1>FILES</h1>
            <div class="sub">Central file library, folders, and version history</div>
        </div>

        <div class="header-actions">
            <a class="button primary" href="upload_file.php">+ UPLOAD FILE</a>

            <?php if ($can_create_folder): ?>
                <a class="button" href="new_folder.php">+ NEW FOLDER</a>
            <?php endif; ?>

            <?php if (isAnyAdministrator($role)): ?>
                <a class="button" href="file_trash.php">TRASH</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">

    <form class="filters" method="GET">
        <div class="filter-grid">

            <div class="field">
                <label for="search">Search</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?php echo e($search); ?>"
                    placeholder="File name, original file, folder"
                >
            </div>

            <div class="field">
                <label for="location_type">Category</label>
                <select id="location_type" name="location_type">
                    <option value="">All Categories</option>

                    <?php foreach ($location_types as $type): ?>
                        <option
                            value="<?php echo e($type); ?>"
                            <?php echo $location_type_filter === $type ? "selected" : ""; ?>
                        >
                            <?php echo e($type); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="folder">Folder</label>
                <select id="folder" name="folder">
                    <option value="0">All Folders</option>

                    <?php foreach ($folders as $folder): ?>
                        <option
                            value="<?php echo (int)$folder["id"]; ?>"
                            <?php echo $folder_filter === (int)$folder["id"] ? "selected" : ""; ?>
                        >
                            <?php echo e($folder["name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button class="button primary" type="submit">APPLY</button>
                <a class="button" href="files.php">CLEAR</a>
            </div>

        </div>
    </form>

    <?php if (!$visible_files): ?>

        <div class="empty">
            No files were found.
        </div>

    <?php else: ?>

        <div class="file-grid">

            <?php foreach ($visible_files as $file): ?>

                <div class="file-card">

                    <div class="file-name">
                        <?php echo e($file["name"]); ?>
                    </div>

                    <div class="file-original">
                        <?php echo e($file["original_filename"]); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Category:</span>
                        <?php echo e($file["location_type"]); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Folder:</span>
                        <?php echo e($file["folder_name"] ?: "No Folder"); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Current Version:</span>
                        Version <?php echo (int)$file["current_version"]; ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">File Size:</span>
                        <?php echo e(formatFileSize($file["file_size"])); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Uploaded By:</span>
                        <?php echo e($file["uploaded_by_name"]); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Last Updated:</span>
                        <?php
                        echo e(
                            date(
                                "M j, Y g:i A",
                                strtotime($file["updated_at"])
                            )
                        );
                        ?>
                    </div>

                    <div class="file-actions">
                        <a
                            class="button"
                            href="view_file.php?id=<?php echo (int)$file["id"]; ?>"
                        >
                            VIEW
                        </a>

                        <?php if (in_array($file["file_type"] ?? "", PREVIEWABLE_FILE_TYPES, true)): ?>
                            <button
                                type="button"
                                class="button"
                                onclick="openFilePreview('preview_file.php?id=<?php echo (int)$file["id"]; ?>', '<?php echo e($file["file_type"]); ?>', '<?php echo e($file["name"]); ?>')"
                            >
                                PREVIEW
                            </button>
                        <?php endif; ?>

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
