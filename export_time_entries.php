<?php
/*
    export_time_entries.php

    CSV export for Timesheets, using the exact same filters and
    per-row visibility check (canViewTimeEntry) as timesheets.php.
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "time_entry_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("
    SELECT role, department
    FROM users
    WHERE id = ? AND active = 1
");
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
$is_management = ($role === "Administrator" || $role === "Manager" || $role === "IT Administrator");

$date_from = trim($_GET["date_from"] ?? date("Y-m-01"));
$date_to = trim($_GET["date_to"] ?? date("Y-m-d"));

if (DateTime::createFromFormat("Y-m-d", $date_from) === false) {
    $date_from = date("Y-m-01");
}
if (DateTime::createFromFormat("Y-m-d", $date_to) === false) {
    $date_to = date("Y-m-d");
}

$employee_filter = $is_management ? (int)($_GET["employee"] ?? 0) : 0;
$project_filter = (int)($_GET["project"] ?? 0);

$sql = "
    SELECT
        te.id, te.user_id, te.project_id, te.task_id, te.work_date,
        te.hours, te.description,
        p.name AS project_name, p.department AS project_department,
        p.manager_id AS project_manager_id,
        t.name AS task_name,
        CONCAT(u.first_name, ' ', u.last_name) AS user_name
    FROM time_entries te
    INNER JOIN projects p ON p.id = te.project_id
    LEFT JOIN tasks t ON t.id = te.task_id
    INNER JOIN users u ON u.id = te.user_id
    WHERE te.work_date BETWEEN ? AND ?
";
$params = [$date_from, $date_to];
$types = "ss";

if ($project_filter > 0) {
    $sql .= " AND te.project_id = ? ";
    $params[] = $project_filter;
    $types .= "i";
}

if ($employee_filter > 0) {
    $sql .= " AND te.user_id = ? ";
    $params[] = $employee_filter;
    $types .= "i";
}

$sql .= " ORDER BY te.work_date ASC, te.id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$entries = [];

while ($row = $result->fetch_assoc()) {
    if (!canViewTimeEntry($row, $user_id, $role, $department)) {
        continue;
    }
    $entries[] = $row;
}

$stmt->close();

while (ob_get_level()) {
    ob_end_clean();
}

$filename = "time_entries_" . $date_from . "_to_" . $date_to . ".csv";

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
header("Cache-Control: private, no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("X-Content-Type-Options: nosniff");

$out = fopen("php://output", "w");
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ["Date", "Employee", "Project", "Task", "Hours", "Description"]);

foreach ($entries as $entry) {
    fputcsv($out, [
        $entry["work_date"],
        $entry["user_name"],
        $entry["project_name"],
        $entry["task_name"] ?? "",
        $entry["hours"],
        $entry["description"] ?? "",
    ]);
}

fclose($out);
exit;
