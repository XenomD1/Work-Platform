<?php
/*
    export_reports.php

    CSV export for the Reports list, using the exact same filters and
    visibility rules as reports.php - the same GET params, applied in
    the same order, against the same query. A user can only ever export
    what they could already see on the Reports page itself.
*/

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

/*
    Filters - identical set/semantics to reports.php.
*/
$search = trim($_GET["search"] ?? "");
$project_filter = (int)($_GET["project"] ?? 0);
$type_filter = trim($_GET["report_type"] ?? "");
$creator_filter = (int)($_GET["creator"] ?? 0);
$date_from = trim($_GET["date_from"] ?? "");
$date_to = trim($_GET["date_to"] ?? "");
$my_team_only = isset($_GET["my_team"]) && $_GET["my_team"] === "1";

function userLeadsTeamOnProject($conn, $user_id, $project_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams
        WHERE project_id = ?
          AND team_lead_id = ?
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();
    return $total > 0;
}

function creatorIsOnLedTeam($conn, $viewer_id, $creator_id, $project_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams t
        INNER JOIN team_members tm ON tm.team_id = t.id
        WHERE t.project_id = ?
          AND t.team_lead_id = ?
          AND tm.user_id = ?
    ");
    $stmt->bind_param("iii", $project_id, $viewer_id, $creator_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();
    return $total > 0;
}

function managerCanViewProject($project_department, $project_manager_id, $user_department, $user_id) {
    return (
        $project_department === $user_department
        || ($project_manager_id !== null && (int)$project_manager_id === $user_id)
    );
}

$sql = "
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
    ORDER BY r.updated_at DESC, r.created_at DESC
";

$result = $conn->query($sql);
$reports = [];

if ($result) {
    while ($report = $result->fetch_assoc()) {

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
            && managerCanViewProject(
                $report["project_department"],
                $report["project_manager_id"],
                $user_department,
                $user_id
            )
        ) {
            $can_view = true;
        }

        if (
            !$can_view
            && $role !== "Manager"
            && $role !== "Administrator"
            && in_array($report["creator_level"], ["Employee", "Team Lead"], true)
            && userLeadsTeamOnProject($conn, $user_id, (int)$report["project_id"])
            && creatorIsOnLedTeam($conn, $user_id, (int)$report["created_by"], (int)$report["project_id"])
        ) {
            $can_view = true;
        }

        if (!$can_view) {
            continue;
        }

        if ($project_filter > 0 && (int)$report["project_id"] !== $project_filter) {
            continue;
        }

        if ($type_filter !== "" && $report["report_type"] !== $type_filter) {
            continue;
        }

        if ($creator_filter > 0 && (int)$report["created_by"] !== $creator_filter) {
            continue;
        }

        if ($date_from !== "" && substr($report["created_at"], 0, 10) < $date_from) {
            continue;
        }

        if ($date_to !== "" && substr($report["created_at"], 0, 10) > $date_to) {
            continue;
        }

        if (
            $my_team_only
            && !(
                userLeadsTeamOnProject($conn, $user_id, (int)$report["project_id"])
                && creatorIsOnLedTeam($conn, $user_id, (int)$report["created_by"], (int)$report["project_id"])
            )
        ) {
            continue;
        }

        if ($search !== "") {
            $haystack = strtolower(
                $report["title"] . " " . $report["project_name"] . " " . $report["report_type"]
                . " " . $report["summary"] . " " . $report["accomplishments"] . " "
                . $report["issues_risks"] . " " . $report["next_steps"] . " "
                . $report["first_name"] . " " . $report["last_name"]
            );
            if (strpos($haystack, strtolower($search)) === false) {
                continue;
            }
        }

        $reports[] = $report;
    }
}

while (ob_get_level()) {
    ob_end_clean();
}

$filename = "reports_export_" . date("Y-m-d") . ".csv";

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
header("Cache-Control: private, no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("X-Content-Type-Options: nosniff");

$out = fopen("php://output", "w");

// UTF-8 BOM so Excel opens the file with correct encoding.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    "Title", "Project", "Department", "Report Type", "Level",
    "Created By", "Period Start", "Period End", "Created At", "Updated At",
]);

foreach ($reports as $report) {
    fputcsv($out, [
        $report["title"],
        $report["project_name"],
        $report["project_department"],
        $report["report_type"],
        $report["creator_level"],
        $report["first_name"] . " " . $report["last_name"],
        $report["period_start"],
        $report["period_end"],
        $report["created_at"],
        $report["updated_at"],
    ]);
}

fclose($out);
exit;
