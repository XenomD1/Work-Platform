<?php
/*
    save_role_permissions.php

    #28 Configurable Roles & Permissions - handles the checkbox grid
    posted from roles.php. Administrator-only, re-checked here
    independently of roles.php's own gate (never trust that a POST
    only ever arrives from a page that already checked). The actual
    protection against a role gaining admin.manage_roles /
    admin.manage_users lives in setRolePermissions()
    (permission_helpers.php) itself, so it applies no matter what a
    tampered request submits.
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: roles.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";
require_once "activity_helpers.php";
require_once "permission_helpers.php";

requireValidCsrfToken();

$user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND active = 1");
$me_stmt->bind_param("i", $user_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();
$me_stmt->close();

if (!$me || $me["role"] !== "Administrator") {
    http_response_code(403);
    echo "Only an Administrator can manage roles and permissions.";
    exit;
}

$role_name = trim($_POST["role_name"] ?? "");
$submitted_codes = $_POST["permissions"] ?? [];

if (!is_array($submitted_codes)) {
    $submitted_codes = [];
}
$submitted_codes = array_map("strval", $submitted_codes);

$role_check_stmt = $conn->prepare("SELECT name FROM custom_roles WHERE name = ?");
$role_check_stmt->bind_param("s", $role_name);
$role_check_stmt->execute();
$role_exists = $role_check_stmt->get_result()->fetch_assoc();
$role_check_stmt->close();

if (!$role_exists) {
    $_SESSION["roles_message"] = "That role no longer exists.";
    header("Location: roles.php");
    exit;
}

// Only known permission codes are honored - anything else in the
// submitted array (tampered or stale) is silently dropped rather
// than inserted and failing the foreign key, or worse, being trusted.
$valid_codes_result = $conn->query("SELECT code FROM permissions");
$valid_codes = [];
while ($row = $valid_codes_result->fetch_assoc()) {
    $valid_codes[] = $row["code"];
}
$submitted_codes = array_values(array_intersect($submitted_codes, $valid_codes));

setRolePermissions($conn, $role_name, $submitted_codes);

logActivity(
    $conn,
    $user_id,
    "Edit",
    "Role",
    0,
    "Updated permissions for role \"" . $role_name . "\".",
    "roles.php?role=" . urlencode($role_name)
);

$_SESSION["roles_message"] = "Permissions updated for \"" . $role_name . "\".";
header("Location: roles.php?role=" . urlencode($role_name));
exit;
