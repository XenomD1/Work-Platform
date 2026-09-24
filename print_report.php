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
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$role = $current_user["role"];
$user_department = $current_user["department"];

$report_id = (int)($_GET["id"] ?? 0);

if ($report_id <= 0) {
    header("Location: reports.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
    Load report, creator, and project information - identical query
    and visibility rules to view_report.php, so anyone who can view a
    report can also print/export it, nothing more.
*/
$stmt = $conn->prepare("
    SELECT
        r.id, r.title, r.project_id, r.report_type,
        r.period_start, r.period_end, r.summary, r.accomplishments,
        r.issues_risks, r.next_steps, r.created_by, r.creator_level,
        r.created_at, r.updated_at,
        p.name AS project_name, p.department AS project_department,
        p.manager_id AS project_manager_id,
        u.first_name, u.last_name
    FROM reports r
    INNER JOIN projects p ON p.id = r.project_id
    INNER JOIN users u ON u.id = r.created_by
    WHERE r.id = ?
");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    header("Location: reports.php");
    exit;
}

$can_view = false;

if ($role === "Administrator") {
    $can_view = true;
}

if ((int)$report["created_by"] === $user_id) {
    $can_view = true;
}

if (
    !$can_view
    && $role === "IT Administrator"
    && $report["project_department"] === $user_department
) {
    $can_view = true;
}

if (
    !$can_view
    && $role === "Manager"
    && in_array($report["creator_level"], ["Employee", "Team Lead"], true)
) {
    if (
        $report["project_department"] === $user_department
        || (
            $report["project_manager_id"] !== null
            && (int)$report["project_manager_id"] === $user_id
        )
    ) {
        $can_view = true;
    }
}

if (
    !$can_view
    && $role !== "Manager"
    && $role !== "Administrator"
    && in_array($report["creator_level"], ["Employee", "Team Lead"], true)
) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams t
        INNER JOIN team_members tm ON tm.team_id = t.id
        WHERE t.project_id = ? AND t.team_lead_id = ? AND tm.user_id = ?
    ");
    $creator_id = (int)$report["created_by"];
    $project_id = (int)$report["project_id"];
    $stmt->bind_param("iii", $project_id, $user_id, $creator_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    if ($total > 0) {
        $can_view = true;
    }
}

if (!$can_view) {
    http_response_code(403);
    exit("You do not have permission to view this report.");
}

function fmtDate($value) {
    return $value ? date("M j, Y", strtotime($value)) : "—";
}

function fmtDateTime($value) {
    return $value ? date("M j, Y g:i A", strtotime($value)) : "—";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Print - <?= e($report["title"]) ?></title>
<link rel="stylesheet" href="print.css">
</head>
<body>

<div class="print-toolbar">
    <div><strong>Print / Export</strong></div>
    <div>
        <a class="secondary" href="view_report.php?id=<?= (int)$report["id"] ?>">BACK TO REPORT</a>
        <button onclick="window.print()">PRINT / SAVE AS PDF</button>
    </div>
</div>

<div class="print-header">
    <h1><?= e($report["title"]) ?></h1>
    <div class="sub">
        <?= e($report["project_name"]) ?> &nbsp;·&nbsp; <?= e($report["report_type"]) ?>
        &nbsp;·&nbsp; <?= e($report["creator_level"]) ?> Report
    </div>
</div>

<div class="print-section">
    <h2>Report Information</h2>
    <div class="print-grid">
        <div class="print-field">
            <span class="label">Created By</span>
            <span class="value"><?= e($report["first_name"] . " " . $report["last_name"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Project</span>
            <span class="value"><?= e($report["project_name"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Reporting Period</span>
            <span class="value"><?= e(fmtDate($report["period_start"])) ?> — <?= e(fmtDate($report["period_end"])) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Created</span>
            <span class="value"><?= e(fmtDateTime($report["created_at"])) ?></span>
        </div>
    </div>
</div>

<div class="print-section">
    <h2>Summary</h2>
    <div class="print-field full">
        <span class="value"><?= e($report["summary"]) ?></span>
    </div>
</div>

<div class="print-section">
    <h2>Accomplishments</h2>
    <div class="print-field full">
        <span class="value"><?= e($report["accomplishments"]) ?></span>
    </div>
</div>

<div class="print-section">
    <h2>Issues / Risks</h2>
    <div class="print-field full">
        <span class="value"><?= e($report["issues_risks"]) ?></span>
    </div>
</div>

<div class="print-section">
    <h2>Next Steps</h2>
    <div class="print-field full">
        <span class="value"><?= e($report["next_steps"]) ?></span>
    </div>
</div>

<div class="print-footer">
    Printed by <?= e($current_user["first_name"] . " " . $current_user["last_name"]) ?>
    on <?= e(date("M j, Y g:i A")) ?>
</div>

</body>
</html>
