<?php
/*
    announcement_action.php

    REPLACES the earlier version, which used a different field name
    ("id") and only supported deactivate/reactivate. dashboard.php
    already posts here with action=dismiss and announcement_id=<id> -
    this version matches that exactly, and adds deactivate/reactivate
    for the management list (announcements.php) using the same
    announcement_id field name for consistency.

    Dismiss is available to any authenticated user for any
    announcement they can currently see - it only ever affects their
    own view (via announcement_dismissals), so it needs no special
    permission check beyond being logged in. Deactivate/reactivate are
    real changes to the record and go through canManageAnnouncement(),
    the same rule new/edit announcement pages use.
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";
require_once "activity_helpers.php";
require_once "announcement_helpers.php";

requireValidCsrfToken();

$user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("SELECT role, department FROM users WHERE id = ? AND active = 1");
$me_stmt->bind_param("i", $user_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();
$me_stmt->close();

if (!$me) {
    header("Location: login.php");
    exit;
}

$role = $me["role"];
$department = $me["department"] ?? "";

$announcement_id = (int)($_POST["announcement_id"] ?? 0);
$action = $_POST["action"] ?? "";

if ($announcement_id <= 0 || !in_array($action, ["dismiss", "deactivate", "reactivate"], true)) {
    header("Location: dashboard.php");
    exit;
}

if ($action === "dismiss") {
    dismissAnnouncementForUser($conn, $user_id, $announcement_id);
    header("Location: dashboard.php");
    exit;
}

/* deactivate / reactivate - management action */

$stmt = $conn->prepare("SELECT id, title, department FROM announcements WHERE id = ?");
$stmt->bind_param("i", $announcement_id);
$stmt->execute();
$announcement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$announcement || !canManageAnnouncement($role, $department, $announcement["department"])) {
    $_SESSION["announcement_message"] = "You don't have permission to change that announcement.";
    header("Location: announcements.php");
    exit;
}

$new_flag = $action === "deactivate" ? 0 : 1;

$update_stmt = $conn->prepare("UPDATE announcements SET is_active = ? WHERE id = ?");
$update_stmt->bind_param("ii", $new_flag, $announcement_id);
$update_stmt->execute();
$update_stmt->close();

logActivity(
    $conn,
    $user_id,
    $action === "deactivate" ? "Disable" : "Reactivate",
    "Announcement",
    $announcement_id,
    ($action === "deactivate" ? "Disabled" : "Reactivated") . " announcement \"" . $announcement["title"] . "\".",
    "announcements.php"
);

$_SESSION["announcement_message"] = $action === "deactivate" ? "Announcement disabled." : "Announcement reactivated.";
header("Location: announcements.php");
exit;
