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

$project_id = (int)($_GET["id"] ?? 0);

if ($project_id <= 0) {
    header("Location: projects.php");
    exit;
}

/*
    No extra visibility gate here beyond being logged in - projects.php
    itself shows every project to every authenticated user (only
    editing is department-restricted), so print/export matches that.
*/
$stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    header("Location: projects.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function fmtDate($value) {
    return $value ? date("M j, Y", strtotime($value)) : "—";
}

/*
    $table/$column are never taken from user input at any call site
    below - they're one of the four hardcoded pairs used right after
    this function. Even so, string-interpolating them straight into
    SQL is a trap for whoever reuses this helper later with anything
    less fixed, so callers are restricted to an explicit whitelist
    instead of being trusted to keep passing constants.
*/
function loadListColumn(mysqli $conn, string $table, string $column, int $project_id): array {
    $allowed_columns = [
        "objectives" => "objective",
        "deliverables" => "deliverable",
        "priorities" => "priority",
        "risks" => "risk",
    ];

    if (($allowed_columns[$table] ?? null) !== $column) {
        throw new InvalidArgumentException("loadListColumn: unsupported table/column pair.");
    }

    $stmt = $conn->prepare("SELECT `$column` AS v FROM `$table` WHERE project_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row["v"];
    }
    $stmt->close();
    return $items;
}

$objectives = loadListColumn($conn, "objectives", "objective", $project_id);
$deliverables = loadListColumn($conn, "deliverables", "deliverable", $project_id);
$priorities = loadListColumn($conn, "priorities", "priority", $project_id);
$risks = loadListColumn($conn, "risks", "risk", $project_id);

$stmt = $conn->prepare("
    SELECT milestone, due_date, status, completion
    FROM milestones
    WHERE project_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$milestones = [];
$milestone_result = $stmt->get_result();
while ($row = $milestone_result->fetch_assoc()) {
    $milestones[] = $row;
}
$stmt->close();

$stmt = $conn->prepare("
    SELECT
        t.name, t.priority, t.status, t.completion, t.due_date,
        GROUP_CONCAT(
            CONCAT(u.first_name, ' ', u.last_name)
            ORDER BY u.last_name, u.first_name
            SEPARATOR ', '
        ) AS assigned_to
    FROM tasks t
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    LEFT JOIN users u ON ta.user_id = u.id
    WHERE t.project_id = ?
    GROUP BY t.id, t.name, t.priority, t.status, t.completion, t.due_date
    ORDER BY t.id ASC
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project_tasks = [];
$task_result = $stmt->get_result();
while ($row = $task_result->fetch_assoc()) {
    $project_tasks[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Print - <?= e($project["name"]) ?></title>
<link rel="stylesheet" href="print.css">
</head>
<body>

<div class="print-toolbar">
    <div><strong>Print / Export</strong></div>
    <div>
        <a class="secondary" href="projects.php?project=<?= (int)$project["id"] ?>">BACK TO PROJECT</a>
        <button onclick="window.print()">PRINT / SAVE AS PDF</button>
    </div>
</div>

<div class="print-header">
    <h1><?= e($project["name"]) ?></h1>
    <div class="sub">
        <?= e($project["department"]) ?> &nbsp;·&nbsp; <?= e($project["status"]) ?>
        &nbsp;·&nbsp; <?= e(round((float)($project["completion"] ?? 0), 1)) ?>% complete
    </div>
</div>

<div class="print-section">
    <h2>Project Information</h2>
    <div class="print-grid">
        <div class="print-field">
            <span class="label">Type</span>
            <span class="value"><?= e($project["type"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Manager</span>
            <span class="value"><?= e($project["manager"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Start Date</span>
            <span class="value"><?= e(fmtDate($project["start_date"])) ?></span>
        </div>
        <div class="print-field">
            <span class="label">End Date</span>
            <span class="value"><?= e(fmtDate($project["end_date"])) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Phase</span>
            <span class="value"><?= e($project["phase"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Complexity</span>
            <span class="value"><?= e($project["complexity"]) ?></span>
        </div>
        <div class="print-field">
            <span class="label">Cost</span>
            <span class="value"><?= $project["cost"] !== null ? "$" . e(number_format((float)$project["cost"], 2)) : "—" ?></span>
        </div>
        <div class="print-field">
            <span class="label">Projected ROI</span>
            <span class="value"><?= e($project["roi"]) ?></span>
        </div>
        <div class="print-field full">
            <span class="label">Description</span>
            <span class="value"><?= $project["description"] !== "" ? e($project["description"]) : "—" ?></span>
        </div>
        <div class="print-field full">
            <span class="label">Overview</span>
            <span class="value"><?= $project["overview"] !== "" ? e($project["overview"]) : "—" ?></span>
        </div>
    </div>
</div>

<?php if ($objectives): ?>
<div class="print-section">
    <h2>Objectives</h2>
    <ul>
        <?php foreach ($objectives as $item): ?>
            <li><?= e($item) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($deliverables): ?>
<div class="print-section">
    <h2>Deliverables</h2>
    <ul>
        <?php foreach ($deliverables as $item): ?>
            <li><?= e($item) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($priorities): ?>
<div class="print-section">
    <h2>Priorities</h2>
    <ul>
        <?php foreach ($priorities as $item): ?>
            <li><?= e($item) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($risks): ?>
<div class="print-section">
    <h2>Risks</h2>
    <ul>
        <?php foreach ($risks as $item): ?>
            <li><?= e($item) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($milestones): ?>
<div class="print-section">
    <h2>Milestones</h2>
    <table class="print-table">
        <thead>
            <tr>
                <th>Milestone</th>
                <th>Due Date</th>
                <th>Status</th>
                <th>Completion</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($milestones as $milestone): ?>
                <tr>
                    <td><?= e($milestone["milestone"]) ?></td>
                    <td><?= e(fmtDate($milestone["due_date"])) ?></td>
                    <td><?= e($milestone["status"]) ?></td>
                    <td><?= e(round((float)$milestone["completion"], 1)) ?>%</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($project_tasks): ?>
<div class="print-section">
    <h2>Tasks</h2>
    <table class="print-table">
        <thead>
            <tr>
                <th>Task</th>
                <th>Assigned To</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Due Date</th>
                <th>Completion</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($project_tasks as $task): ?>
                <tr>
                    <td><?= e($task["name"]) ?></td>
                    <td><?= e($task["assigned_to"] ?: "—") ?></td>
                    <td><?= e($task["priority"]) ?></td>
                    <td><?= e($task["status"]) ?></td>
                    <td><?= e(fmtDate($task["due_date"])) ?></td>
                    <td><?= e($task["completion"] ?? 0) ?>%</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="print-footer">
    Printed by <?= e($current_user["first_name"] . " " . $current_user["last_name"]) ?>
    on <?= e(date("M j, Y g:i A")) ?>
</div>

</body>
</html>
