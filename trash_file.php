<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

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

$file_id = (int)($_GET["id"] ?? $_POST["file_id"] ?? 0);

if ($file_id <= 0) {
    header("Location: files.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
 * Load the file.
 */
$stmt = $conn->prepare("
    SELECT
        f.id,
        f.name,
        f.location_type,
        f.location_id,
        f.is_trashed,
        f.uploaded_by,
        f.created_at,
        f.updated_at,
        CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
    FROM files f
    INNER JOIN users u ON u.id = f.uploaded_by
    WHERE f.id = ?
");
$stmt->bind_param("i", $file_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file || (int)$file["is_trashed"] === 1) {
    header("Location: files.php");
    exit;
}

/*
 * Rules discussed:
 *
 * Administrators can move files to Trash.
 *
 * Managers can delete files only in areas/projects they manage.
 *
 * Project Managers can delete files only for projects they manage.
 *
 * Team Leads can delete files for teams they lead.
 *
 * Regular employees cannot delete files.
 */
function getProjectIdForFile($conn, $file) {

    if ($file["location_type"] === "Project") {
        return (int)$file["location_id"];
    }

    if ($file["location_type"] === "Task") {
        $stmt = $conn->prepare("
            SELECT project_id
            FROM tasks
            WHERE id = ?
        ");
        $stmt->bind_param("i", $file["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row["project_id"] ?? 0);
    }

    if ($file["location_type"] === "Team") {
        $stmt = $conn->prepare("
            SELECT project_id
            FROM teams
            WHERE id = ?
        ");
        $stmt->bind_param("i", $file["location_id"]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row["project_id"] ?? 0);
    }

    return 0;
}

function canTrashFile(
    $conn,
    $file,
    $user_id,
    $role,
    $user_department
) {
    if ($role === "Administrator") {
        return true;
    }

    /*
     * Department-level file:
     * only a Manager for that department.
     */
    if ($file["location_type"] === "Department") {
        return (
            ($role === "Manager" || $role === "IT Administrator")
            && (string)$file["location_id"] === (string)$user_department
        );
    }

    /*
     * Organization files are Administrator-only.
     */
    if ($file["location_type"] === "Organization") {
        return false;
    }

    /*
     * Team Leads can delete files belonging directly to a team they lead.
     */
    if ($file["location_type"] === "Team") {

        $stmt = $conn->prepare("
            SELECT team_lead_id
            FROM teams
            WHERE id = ?
        ");
        $stmt->bind_param("i", $file["location_id"]);
        $stmt->execute();
        $team = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            $team
            && (int)$team["team_lead_id"] === $user_id
        ) {
            return true;
        }
    }

    /*
     * Project Manager:
     * project, task, or team files only when the project is theirs.
     */
    $project_id = getProjectIdForFile($conn, $file);

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

        if ($project) {

            if (
                $project["manager_id"] !== null
                && (int)$project["manager_id"] === $user_id
            ) {
                return true;
            }

            /*
             * Department Manager can delete files for projects
             * inside their own department.
             */
            if (
                ($role === "Manager" || $role === "IT Administrator")
                && $project["department"] === $user_department
            ) {
                return true;
            }
        }
    }

    /*
     * Event and Training files:
     * Managers may delete only when they manage that department.
     */
    if ($file["location_type"] === "Event") {

        $stmt = $conn->prepare("
            SELECT department
            FROM events
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $file["location_id"]);
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

    if ($file["location_type"] === "Training") {

        $stmt = $conn->prepare("
            SELECT department
            FROM training
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $file["location_id"]);
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

$can_trash = canTrashFile(
    $conn,
    $file,
    $user_id,
    $role,
    $user_department
);

if (!$can_trash) {
    http_response_code(403);
    exit("You do not have permission to move this file to Trash.");
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $stmt = $conn->prepare("
        UPDATE files
        SET
            is_trashed = 1,
            trashed_by = ?,
            trashed_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND is_trashed = 0
    ");

    $stmt->bind_param(
        "ii",
        $user_id,
        $file_id
    );

    if ($stmt->execute()) {

        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed > 0) {
            header("Location: files.php");
            exit;
        }

        $error = "The file could not be moved to Trash.";

    } else {
        $error = "The file could not be moved to Trash.";
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Move File to Trash</title>

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
        <h1>MOVE FILE TO TRASH</h1>

        <div class="sub">
            Confirm that this file should be removed from the active file library
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
            This does <strong>not</strong> permanently delete the file.
            The file and all of its previous versions will stay in Trash
            until an Administrator permanently deletes it. It can be restored
            before then.
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
            <div class="label">Uploaded By</div>
            <div><?php echo e($file["uploaded_by_name"]); ?></div>
        </div>

        <form method="POST">

            <input
                type="hidden"
                name="file_id"
                value="<?php echo (int)$file_id; ?>"
            >

            <div class="actions">

                <a
                    class="button"
                    href="view_file.php?id=<?php echo (int)$file_id; ?>"
                >
                    CANCEL
                </a>

                <button
                    class="button danger"
                    type="submit"
                >
                    MOVE TO TRASH
                </button>

            </div>

        </form>

    </div>

</div>

</div>

</div>

</body>
</html>
