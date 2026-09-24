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

if (!isAnyAdministrator($user["role"])) {
    http_response_code(403);
    exit("Only Administrators can manage the file Trash.");
}

/*
 * Resolves the department a file/folder "belongs to" for the
 * purposes of IT Administrator scoping, based on its location
 * type. Returns null for Organization-type items, which stay
 * Administrator-only. Both files and folders share the same
 * location_type/location_id shape, so one function covers both.
 */
function resolveItemDepartment($conn, $item) {

    if ($item["location_type"] === "Department") {
        return $item["location_id"];
    }

    if ($item["location_type"] === "Project") {
        $stmt = $conn->prepare("SELECT department FROM projects WHERE id = ?");
        $stmt->bind_param("i", $item["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($item["location_type"] === "Task") {
        $stmt = $conn->prepare("
            SELECT p.department
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $item["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($item["location_type"] === "Team") {
        $stmt = $conn->prepare("
            SELECT p.department
            FROM teams t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $item["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($item["location_type"] === "Event") {
        $stmt = $conn->prepare("SELECT department FROM events WHERE id = ?");
        $stmt->bind_param("i", $item["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    if ($item["location_type"] === "Training") {
        $stmt = $conn->prepare("SELECT department FROM training WHERE id = ?");
        $stmt->bind_param("i", $item["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row["department"] ?? null;
    }

    // Organization items: no department, Administrator-only.
    return null;
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

$search = trim($_GET["search"] ?? "");
$location_type = trim($_GET["location_type"] ?? "");
$item_type = trim($_GET["item_type"] ?? "");

$location_types = [
    "Organization",
    "Department",
    "Project",
    "Task",
    "Team",
    "Event",
    "Training"
];

/*
 * ----------------------------
 * TRASHED FILES
 * ----------------------------
 */
$file_sql = "
    SELECT
        f.id,
        f.name,
        f.location_type,
        f.location_id,
        f.current_version,
        f.trashed_at,
        CONCAT(upload_user.first_name, ' ', upload_user.last_name)
            AS uploaded_by_name,
        CONCAT(trash_user.first_name, ' ', trash_user.last_name)
            AS trashed_by_name,
        fv.original_filename,
        fv.file_size,
        ff.name AS folder_name
    FROM files f
    INNER JOIN users upload_user
        ON upload_user.id = f.uploaded_by
    LEFT JOIN users trash_user
        ON trash_user.id = f.trashed_by
    LEFT JOIN file_versions fv
        ON fv.file_id = f.id
       AND fv.version_number = f.current_version
    LEFT JOIN file_folders ff
        ON ff.id = f.folder_id
    WHERE f.is_trashed = 1
";

$file_params = [];
$file_types = "";

if ($location_type !== "" && in_array($location_type, $location_types, true)) {
    $file_sql .= " AND f.location_type = ? ";
    $file_params[] = $location_type;
    $file_types .= "s";
}

if ($search !== "") {
    $file_sql .= "
        AND (
            f.name LIKE ?
            OR fv.original_filename LIKE ?
            OR ff.name LIKE ?
        )
    ";

    $search_like = "%" . $search . "%";

    $file_params[] = $search_like;
    $file_params[] = $search_like;
    $file_params[] = $search_like;
    $file_types .= "sss";
}

$file_sql .= " ORDER BY f.trashed_at DESC, f.name ASC ";

$file_stmt = $conn->prepare($file_sql);

if ($file_params) {
    $file_stmt->bind_param($file_types, ...$file_params);
}

$file_stmt->execute();
$file_result = $file_stmt->get_result();

$trashed_files = [];

while ($row = $file_result->fetch_assoc()) {
    if (
        canAdministerDepartment(
            $user["role"],
            $user["department"] ?? "",
            resolveItemDepartment($conn, $row)
        )
    ) {
        $trashed_files[] = $row;
    }
}

$file_stmt->close();

/*
 * ----------------------------
 * TRASHED FOLDERS
 * ----------------------------
 *
 * Show only top-level trashed folders in the Trash page.
 * If a child folder's parent is also trashed, it will be restored/deleted
 * through the parent folder tree rather than appearing separately.
 */
$folder_sql = "
    SELECT
        ff.id,
        ff.name,
        ff.parent_folder_id,
        ff.location_type,
        ff.location_id,
        ff.created_at,
        ff.updated_at,
        CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
    FROM file_folders ff
    INNER JOIN users u
        ON u.id = ff.created_by
    LEFT JOIN file_folders parent
        ON parent.id = ff.parent_folder_id
    WHERE ff.is_trashed = 1
      AND (
            ff.parent_folder_id IS NULL
            OR parent.id IS NULL
            OR parent.is_trashed = 0
      )
";

$folder_params = [];
$folder_types = "";

if ($location_type !== "" && in_array($location_type, $location_types, true)) {
    $folder_sql .= " AND ff.location_type = ? ";
    $folder_params[] = $location_type;
    $folder_types .= "s";
}

if ($search !== "") {
    $folder_sql .= " AND ff.name LIKE ? ";
    $folder_params[] = "%" . $search . "%";
    $folder_types .= "s";
}

$folder_sql .= " ORDER BY ff.updated_at DESC, ff.name ASC ";

$folder_stmt = $conn->prepare($folder_sql);

if ($folder_params) {
    $folder_stmt->bind_param($folder_types, ...$folder_params);
}

$folder_stmt->execute();
$folder_result = $folder_stmt->get_result();

$trashed_folders = [];

while ($row = $folder_result->fetch_assoc()) {
    if (
        canAdministerDepartment(
            $user["role"],
            $user["department"] ?? "",
            resolveItemDepartment($conn, $row)
        )
    ) {
        $trashed_folders[] = $row;
    }
}

$folder_stmt->close();

/*
 * Count folder descendants and trashed files for display.
 */
foreach ($trashed_folders as &$folder) {

    $folder["subfolder_count"] = 0;
    $folder["file_count"] = 0;

    $queue = [(int)$folder["id"]];
    $all_ids = [(int)$folder["id"]];

    while ($queue) {

        $current = array_shift($queue);

        $stmt = $conn->prepare("
            SELECT id
            FROM file_folders
            WHERE parent_folder_id = ?
              AND is_trashed = 1
        ");
        $stmt->bind_param("i", $current);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $child_id = (int)$row["id"];

            if (!in_array($child_id, $all_ids, true)) {
                $all_ids[] = $child_id;
                $queue[] = $child_id;
            }
        }

        $stmt->close();
    }

    $folder["subfolder_count"] = max(0, count($all_ids) - 1);

    foreach ($all_ids as $folder_tree_id) {

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM files
            WHERE folder_id = ?
              AND is_trashed = 1
        ");
        $stmt->bind_param("i", $folder_tree_id);
        $stmt->execute();

        $folder["file_count"] +=
            (int)$stmt->get_result()->fetch_assoc()["total"];

        $stmt->close();
    }
}
unset($folder);

/*
 * Apply item type filter after loading.
 */
if ($item_type === "file") {
    $trashed_folders = [];
} elseif ($item_type === "folder") {
    $trashed_files = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>File Trash</title>

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

        <div>
            <h1>FILE TRASH</h1>

            <div class="sub">
                Restore trashed files and folders, or permanently delete them.
            </div>
        </div>

        <a class="button" href="files.php">
            BACK TO FILES
        </a>

    </div>

    <div class="content">

    <div class="notice">
        Files and folders stay in Trash until an Administrator permanently
        deletes them. File version history is preserved while items remain
        in Trash.
    </div>

    <form class="filters" method="GET">

        <div class="filter-grid">

            <div class="field">
                <label for="search">Search Trash</label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?php echo e($search); ?>"
                    placeholder="File, folder, or original filename"
                >
            </div>

            <div class="field">
                <label for="item_type">Item Type</label>

                <select id="item_type" name="item_type">
                    <option value="">Files & Folders</option>

                    <option
                        value="file"
                        <?php echo $item_type === "file" ? "selected" : ""; ?>
                    >
                        Files Only
                    </option>

                    <option
                        value="folder"
                        <?php echo $item_type === "folder" ? "selected" : ""; ?>
                    >
                        Folders Only
                    </option>
                </select>
            </div>

            <div class="field">
                <label for="location_type">Category</label>

                <select
                    id="location_type"
                    name="location_type"
                >
                    <option value="">All Categories</option>

                    <?php foreach ($location_types as $type): ?>

                        <option
                            value="<?php echo e($type); ?>"
                            <?php echo $location_type === $type ? "selected" : ""; ?>
                        >
                            <?php echo e($type); ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="filter-actions">

                <button
                    class="button primary"
                    type="submit"
                >
                    APPLY
                </button>

                <a
                    class="button"
                    href="file_trash.php"
                >
                    CLEAR
                </a>

            </div>

        </div>

    </form>

    <?php if (!$trashed_files && !$trashed_folders): ?>

        <div class="empty">
            Trash is empty.
        </div>

    <?php endif; ?>

    <?php if ($trashed_folders): ?>

        <div class="section">

            <h2>Folders</h2>

            <div class="table-card">

                <table>

                    <thead>
                        <tr>
                            <th>Folder</th>
                            <th>Category</th>
                            <th>Location</th>
                            <th>Subfolders</th>
                            <th>Files</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($trashed_folders as $folder): ?>

                        <tr>

                            <td>
                                <div class="item-name">
                                    <?php echo e($folder["name"]); ?>
                                </div>

                                <div class="meta">
                                    Folder ID <?php echo (int)$folder["id"]; ?>
                                </div>
                            </td>

                            <td>
                                <?php echo e($folder["location_type"]); ?>
                            </td>

                            <td>
                                <?php
                                echo $folder["location_type"] === "Organization"
                                    ? "Organization-wide"
                                    : e($folder["location_id"]);
                                ?>
                            </td>

                            <td>
                                <?php echo (int)$folder["subfolder_count"]; ?>
                            </td>

                            <td>
                                <?php echo (int)$folder["file_count"]; ?>
                            </td>

                            <td>
                                <?php echo e($folder["created_by_name"]); ?>
                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        class="button"
                                        href="restore_folder.php?id=<?php echo (int)$folder["id"]; ?>"
                                    >
                                        RESTORE
                                    </a>

                                    <a
                                        class="button danger"
                                        href="delete_folder.php?id=<?php echo (int)$folder["id"]; ?>"
                                    >
                                        PERMANENTLY DELETE
                                    </a>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>

    <?php if ($trashed_files): ?>

        <div class="section">

            <h2>Files</h2>

            <div class="table-card">

                <table>

                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Category</th>
                            <th>Folder</th>
                            <th>Version</th>
                            <th>Size</th>
                            <th>Trashed By</th>
                            <th>Trashed</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($trashed_files as $file): ?>

                        <tr>

                            <td>
                                <div class="item-name">
                                    <?php echo e($file["name"]); ?>
                                </div>

                                <div class="meta">
                                    <?php echo e($file["original_filename"]); ?>
                                </div>
                            </td>

                            <td>
                                <?php echo e($file["location_type"]); ?>
                            </td>

                            <td>
                                <?php echo e($file["folder_name"] ?: "No Folder"); ?>
                            </td>

                            <td>
                                Version <?php echo (int)$file["current_version"]; ?>
                            </td>

                            <td>
                                <?php echo e(formatFileSize($file["file_size"])); ?>
                            </td>

                            <td>
                                <?php echo e($file["trashed_by_name"] ?: "Unknown"); ?>
                            </td>

                            <td>
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
                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        class="button"
                                        href="restore_file.php?id=<?php echo (int)$file["id"]; ?>"
                                    >
                                        RESTORE
                                    </a>

                                    <a
                                        class="button danger"
                                        href="delete_file.php?id=<?php echo (int)$file["id"]; ?>"
                                    >
                                        PERMANENTLY DELETE
                                    </a>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>

</div>

</div>

</div>

</body>
</html>
