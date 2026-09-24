<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "access_helpers.php";

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

$file_id = (int)($_GET["id"] ?? 0);
$version_id = (int)($_GET["version"] ?? 0);

if ($file_id <= 0 && $version_id <= 0) {
    header("Location: files.php");
    exit;
}

/*
    Only these types are ever streamed inline. Anything else (Word
    docs, zips, executables, etc.) is not eligible for preview -
    the caller should link to download_file.php instead.
*/
const PREVIEWABLE_TYPES = [
    "application/pdf",
    "image/png",
    "image/jpeg",
    "image/gif",
    "image/webp",
];

/*
    If a specific version was requested, use that version. Otherwise
    use the file's current version. Same two-path lookup as
    download_file.php.
*/
if ($version_id > 0) {
    $stmt = $conn->prepare("
        SELECT
            f.id AS file_id, f.is_trashed,
            fv.id AS version_id, fv.version_number,
            fv.original_filename, fv.file_path, fv.file_type
        FROM file_versions fv
        INNER JOIN files f ON f.id = fv.file_id
        WHERE fv.id = ?
    ");
    $stmt->bind_param("i", $version_id);
    $stmt->execute();
    $preview = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $stmt = $conn->prepare("
        SELECT
            f.id AS file_id, f.is_trashed,
            fv.id AS version_id, fv.version_number,
            fv.original_filename, fv.file_path, fv.file_type
        FROM files f
        INNER JOIN file_versions fv
            ON fv.file_id = f.id
           AND fv.version_number = f.current_version
        WHERE f.id = ?
    ");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $preview = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$preview) {
    http_response_code(404);
    exit("File not found.");
}

if ((int)$preview["is_trashed"] === 1) {
    http_response_code(404);
    exit("File not found.");
}

if (!userCanAccessWorkItem($conn, $user_id, $role, $user_department, "File", (int)$preview["file_id"])) {
    http_response_code(403);
    exit("You do not have permission to view this file.");
}

$content_type = $preview["file_type"] ?? "";

if (!in_array($content_type, PREVIEWABLE_TYPES, true)) {
    http_response_code(415);
    exit("This file type can't be previewed. Use Download instead.");
}

/*
    Same path-safety pattern as download_file.php: strip any ".."
    segments, resolve to a real path, and confirm it's still inside
    the uploads/files directory before ever touching the filesystem.
*/
$relative_path = str_replace(["../", "..\\"], "", $preview["file_path"]);

$base_directory = realpath(__DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "files");

$full_path = realpath(
    __DIR__
    . DIRECTORY_SEPARATOR
    . str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $relative_path)
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

while (ob_get_level()) {
    ob_end_clean();
}

header("Content-Type: " . $content_type);
header("Content-Disposition: inline; filename=\"" . addcslashes(basename($preview["original_filename"]), "\"\\") . "\"");
header("Content-Length: " . filesize($full_path));
header("Cache-Control: private, no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'none'; sandbox;");

readfile($full_path);
exit;
