<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

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
    header("Location: time_off.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT
        t.id, t.user_id, t.time_off_type, t.start_date, t.end_date,
        t.total_days, t.reason_notes, t.status, t.submitted_at,
        t.approver_id, t.approval_date, t.rejection_reason,
        t.created_at,
        u.first_name, u.last_name, u.department,
        CONCAT(a.first_name, ' ', a.last_name) AS approver_name
    FROM time_off t
    INNER JOIN users u ON u.id = t.user_id
    LEFT JOIN users a ON a.id = t.approver_id
    WHERE t.id = ?
");
$stmt->bind_param("i", $time_off_id);
$stmt->execute();
$time_off = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$time_off) {
    header("Location: time_off.php");
    exit;
}

/*
    Visibility - identical to view_time_off.php: Employee sees only
    their own request, Manager/IT Administrator see their own
    department, Administrator sees everything.
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
    header("Location: time_off.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
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
<title>Print - Time Off Request #<?= (int)$time_off["id"] ?></title>
<link rel="stylesheet" href="print.css">
</head>
<body>

<div class="print-toolbar">
    <div><strong>Print / Export</strong></div>
    <div>
        <a class="secondary" href="view_time_off.php?id=<?= (int)$time_off["id"] ?>">BACK TO REQUEST</a>
        <button onclick="window.print()">PRINT / SAVE AS PDF</button>
    </div>
</div>

<div class="print-header">
    <h1>Time Off Request #<?= (int)$time_off["id"] ?></h1>
    <div class="sub">
        <?= e($time_off["first_name"] . " " . $time_off["last_name"]) ?>
        &nbsp;·&nbsp; <?= e($time_off["status"]) ?>
    </div>
</div>

<div class="print-section">
    <h2>Request Information</h2>
    <div class="print-grid">
        <div class="print-field">
            <span class="label">Employee</span>
            <span class="value"><?= e($time_off["first_name"] . " " . $time_off["last_name"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Department</span>
            <span class="value"><?= e($time_off["department"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Time Off Type</span>
            <span class="value"><?= e($time_off["time_off_type"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Total Days</span>
            <span class="value"><?= e($time_off["total_days"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Start Date</span>
            <span class="value"><?= e(fmtDate($time_off["start_date"])) ?></span>
        </div>
        <div class="print-field">
            <span class="label">End Date</span>
            <span class="value"><?= e(fmtDate($time_off["end_date"])) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Status</span>
            <span class="value"><?= e($time_off["status"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Submitted</span>
            <span class="value"><?= e(fmtDateTime($time_off["submitted_at"])) ?></span>
        </div>
        <div class="print-field full">
            <span class="label">Reason / Notes</span>
            <span class="value"><?= $time_off["reason_notes"] !== "" ? e($time_off["reason_notes"]) : "—" ?></span>
        </div>
    </div>
</div>

<?php if (!empty($time_off["approver_name"]) || !empty($time_off["rejection_reason"])): ?>
<div class="print-section">
    <h2>Review Information</h2>
    <div class="print-grid">
        <div class="print-field">
            <span class="label">Reviewed By</span>
            <span class="value"><?= e($time_off["approver_name"] ?: "—") ?></span>
        </div>
        <div class="print-field">
            <span class="label">Reviewed Date</span>
            <span class="value"><?= e(fmtDateTime($time_off["approval_date"])) ?></span>
        </div>
        <?php if (!empty($time_off["rejection_reason"])): ?>
        <div class="print-field full">
            <span class="label">Rejection Reason</span>
            <span class="value"><?= e($time_off["rejection_reason"]) ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="print-footer">
    Printed by <?= e($current_user["first_name"] . " " . $current_user["last_name"]) ?>
    on <?= e(date("M j, Y g:i A")) ?>
</div>

</body>
</html>
