<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";

$user_id = (int)$_SESSION["user_id"];
$notification_id = (int)($_GET["id"] ?? 0);

if ($notification_id <= 0) {
    header("Location: notifications.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT related_url
    FROM notifications
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $notification_id, $user_id);
$stmt->execute();
$notification = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$notification) {
    header("Location: notifications.php");
    exit;
}

$stmt = $conn->prepare("
    UPDATE notifications
    SET is_read = 1,
        read_at = COALESCE(read_at, NOW())
    WHERE id = ?
      AND user_id = ?
");
$stmt->bind_param("ii", $notification_id, $user_id);
$stmt->execute();
$stmt->close();

$related_url = trim((string)($notification["related_url"] ?? ""));

if ($related_url === "") {
    header("Location: notifications.php");
    exit;
}

$parts = parse_url($related_url);

if (
    $parts === false
    || isset($parts["scheme"])
    || isset($parts["host"])
    || str_starts_with($related_url, "//")
    || str_contains($related_url, "\r")
    || str_contains($related_url, "\n")
) {
    header("Location: notifications.php");
    exit;
}

$path = (string)($parts["path"] ?? "");

if (
    $path === ""
    || str_starts_with($path, "/")
    || str_contains($path, "\\")
    || str_contains($path, "..")
    || !preg_match('/^[A-Za-z0-9_-]+\\.php$/', $path)
) {
    header("Location: notifications.php");
    exit;
}

header("Location: " . $related_url);
exit;
