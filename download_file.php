<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";

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
$version_id = (int)($_GET["version"] ?? 0);

if ($file_id <= 0 && $version_id <= 0) {
    header("Location: files.php");
    exit;
}

function denyDownload() {
    http_response_code(403);
    exit("You do not have permission to download this file.");
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

        if (!$event) return false;

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

        if (!$training) return false;

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
 * If a specific version was requested, use that version.
 * Otherwise use the file's current version.
 */
if ($version_id > 0) {

    $stmt = $conn->prepare("
        SELECT
            f.id AS file_id,
            f.location_type,
            f.location_id,
            f.is_trashed,
            fv.id AS version_id,
            fv.version_number,
            fv.original_filename,
            fv.file_path,
            fv.file_type
        FROM file_versions fv
        INNER JOIN files f
            ON f.id = fv.file_id
        WHERE fv.id = ?
    ");

    $stmt->bind_param("i", $version_id);
    $stmt->execute();
    $download = $stmt->get_result()->fetch_assoc();
    $stmt->close();

} else {

    $stmt = $conn->prepare("
        SELECT
            f.id AS file_id,
            f.location_type,
            f.location_id,
            f.is_trashed,
            fv.id AS version_id,
            fv.version_number,
            fv.original_filename,
            fv.file_path,
            fv.file_type
        FROM files f
        INNER JOIN file_versions fv
            ON fv.file_id = f.id
           AND fv.version_number = f.current_version
        WHERE f.id = ?
    ");

    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $download = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$download) {
    http_response_code(404);
    exit("File not found.");
}

/*
 * Files in Trash are not downloaded through the normal file page.
 */
if ((int)$download["is_trashed"] === 1) {
    http_response_code(404);
    exit("File not found.");
}

if (
    !canAccessLocation(
        $conn,
        $download["location_type"],
        $download["location_id"],
        $user_id,
        $role,
        $user_department,
        $current_full_name
    )
) {
    denyDownload();
}

$relative_path = str_replace(
    ["../", "..\\"],
    "",
    $download["file_path"]
);

$base_directory = realpath(__DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "files");

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
    $base_directory === false
    || $full_path === false
    || !is_file($full_path)
    || strpos($full_path, $base_directory . DIRECTORY_SEPARATOR) !== 0
) {
    http_response_code(404);
    exit("The stored file could not be found.");
}

$download_name = basename($download["original_filename"]);

if ($download_name === "") {
    $download_name =
        "download_version_"
        . (int)$download["version_number"];
}

$content_type = $download["file_type"];

if (!$content_type) {
    $content_type = "application/octet-stream";
}

/*
 * Prevent previously generated page output from corrupting the download.
 */
while (ob_get_level()) {
    ob_end_clean();
}

header("Content-Description: File Transfer");
header("Content-Type: " . $content_type);
header("Content-Disposition: attachment; filename=\"" . addcslashes($download_name, "\"\\") . "\"");
header("Content-Length: " . filesize($full_path));
header("Cache-Control: private, no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Content-Type-Options: nosniff");

readfile($full_path);
exit;
?>
