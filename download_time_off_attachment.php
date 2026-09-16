<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require "storage_config.php";

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
$self_service_roles = ["Employee"];

$time_off_id = (int)($_GET["id"] ?? 0);

if ($time_off_id <= 0) {
    http_response_code(404);
    exit("Request not found.");
}

/*
 * Load the time off record and its attachment path.
 */
$stmt = $conn->prepare("
    SELECT
        t.id,
        t.user_id,
        t.attachment,
        u.department
    FROM time_off t
    INNER JOIN users u ON u.id = t.user_id
    WHERE t.id = ?
");
$stmt->bind_param("i", $time_off_id);
$stmt->execute();
$time_off = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$time_off || empty($time_off["attachment"])) {
    http_response_code(404);
    exit("Attachment not found.");
}

/*
 * Visibility must match view_time_off.php / time_off.php exactly:
 * Employee -> own request only
 * Manager / IT Administrator -> same department
 * Administrator -> any request
 */
$can_view = false;

if ($role === "Administrator") {
    $can_view = true;
} elseif (
    ($role === "Manager" || $role === "IT Administrator")
    && $time_off["department"] === $user_department
) {
    $can_view = true;
} elseif (
    in_array($role, $self_service_roles, true)
    && (int)$time_off["user_id"] === $user_id
) {
    $can_view = true;
}

if (!$can_view) {
    http_response_code(403);
    exit("You do not have permission to view this attachment.");
}

/*
 * Resolve the stored filename against the attachment directory,
 * which sits outside the document root. basename() also safely
 * handles older records that still have the legacy
 * "uploads/time_off/" prefix from before this fix.
 */
$base_directory = realpath(TIME_OFF_ATTACHMENT_DIR);

$full_path = realpath(
    $base_directory
    . DIRECTORY_SEPARATOR
    . basename($time_off["attachment"])
);

if (
    $base_directory === false
    || $full_path === false
    || !is_file($full_path)
    || strpos($full_path, $base_directory . DIRECTORY_SEPARATOR) !== 0
) {
    http_response_code(404);
    exit("The stored attachment could not be found.");
}

$download_name = basename($full_path);

$extension = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));

$content_types = [
    "pdf" => "application/pdf",
    "doc" => "application/msword",
    "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png" => "image/png"
];

$content_type = $content_types[$extension] ?? "application/octet-stream";

/*
 * Prevent previously generated output from corrupting the download.
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
