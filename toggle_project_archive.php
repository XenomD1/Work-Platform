<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: projects.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";

requireValidCsrfToken();

$user_id = (int)$_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT role, department
    FROM users
    WHERE id = ? AND active = 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
    header("Location: login.php");
    exit;
}

$project_id = (int)($_POST["project_id"] ?? 0);

if ($project_id <= 0) {
    header("Location: projects.php");
    exit;
}

$stmt = $conn->prepare("SELECT id, department, archived FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    header("Location: projects.php");
    exit;
}

/*
    Same permission as Edit Project - Administrator, Manager/IT
    Administrator in the project's department, or the project's own
    manager.
*/
$can_administer = canAdministerDepartment($me["role"], $me["department"] ?? "", $project["department"]);

if (!$can_administer) {
    $stmt = $conn->prepare("SELECT manager_id FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $manager_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $can_administer = $manager_row && (int)($manager_row["manager_id"] ?? 0) === $user_id;
}

if (!$can_administer) {
    http_response_code(403);
    exit("You do not have permission to archive this project.");
}

$new_state = (int)$project["archived"] === 1 ? 0 : 1;

if ($new_state === 1) {
    $stmt = $conn->prepare("
        UPDATE projects
        SET archived = 1, archived_at = NOW(), archived_by = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $user_id, $project_id);
} else {
    $stmt = $conn->prepare("
        UPDATE projects
        SET archived = 0, archived_at = NULL, archived_by = NULL
        WHERE id = ?
    ");
    $stmt->bind_param("i", $project_id);
}

$stmt->execute();
$stmt->close();

header("Location: projects.php?project=" . $project_id);
exit;
