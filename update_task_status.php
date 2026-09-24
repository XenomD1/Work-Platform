<?php
/*
    update_task_status.php

    Single-task status-change handler posted to by kanban_board.php's
    per-card "Move to..." buttons. This is the server-side equivalent
    of dragging a card between columns - same permission check as
    bulk_task_action.php's status action, same dependency-blocking
    rule, and the same recurring-task/completion-sync behavior as the
    rest of the platform, just for one task instead of a batch.
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: kanban_board.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";
require_once "activity_helpers.php";
require_once "notification_helpers.php";
require_once "task_dependency_helpers.php";
require_once "task_recurrence_helpers.php";
require_once "task_board_helpers.php";

requireValidCsrfToken();

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

$task_id = (int)($_POST["task_id"] ?? 0);
$new_status = trim($_POST["new_status"] ?? "");

/* return_query is only ever used to preserve the board's own filters
   on redirect - it's rebuilt from a fixed allow-list below rather
   than trusted verbatim, so it can't be used to redirect anywhere
   outside kanban_board.php. */
$return_query_raw = (string)($_POST["return_query"] ?? "");
parse_str($return_query_raw, $return_params);
$safe_return = [];
foreach (["project", "priority", "assignee", "view"] as $key) {
    if (isset($return_params[$key]) && $return_params[$key] !== "" && ctype_digit((string)$return_params[$key])) {
        $safe_return[$key] = (int)$return_params[$key];
    }
}
$redirect_url = "kanban_board.php" . ($safe_return ? ("?" . http_build_query($safe_return)) : "");

if ($task_id <= 0 || !in_array($new_status, boardStatusColumns(), true)) {
    header("Location: " . $redirect_url . (str_contains($redirect_url, "?") ? "&" : "?") . "board_error=1");
    exit;
}

$stmt = $conn->prepare("
    SELECT t.id, t.name, t.project_id, t.status, t.completion,
           t.recurrence_type, t.recurrence_end_date,
           t.recurrence_max_occurrences, t.recurrence_occurrence_number,
           t.due_date, t.start_date, t.description, t.priority,
           p.department AS project_department, p.manager_id AS project_manager_id
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
    WHERE t.id = ?
");
$stmt->bind_param("i", $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$task) {
    header("Location: " . $redirect_url);
    exit;
}

$assign_stmt = $conn->prepare("SELECT user_id FROM task_assignments WHERE task_id = ?");
$assign_stmt->bind_param("i", $task_id);
$assign_stmt->execute();
$assign_result = $assign_stmt->get_result();
$current_assignees = [];
while ($row = $assign_result->fetch_assoc()) {
    $current_assignees[] = (int)$row["user_id"];
}
$assign_stmt->close();

$project = [
    "department" => $task["project_department"],
    "manager_id" => $task["project_manager_id"],
];

if (!canBoardManageTask($role, $department, $user_id, $project, $current_assignees)) {
    header("Location: " . $redirect_url . (str_contains($redirect_url, "?") ? "&" : "?") . "board_error=1");
    exit;
}

if (
    in_array($new_status, ["In Progress", "Complete"], true)
    && taskHasOpenBlockers($conn, $task_id)
) {
    header("Location: " . $redirect_url . (str_contains($redirect_url, "?") ? "&" : "?") . "board_error=blocked");
    exit;
}

if ($task["status"] === $new_status) {
    header("Location: " . $redirect_url);
    exit;
}

$new_completion = taskCompletionForStatus($new_status, $task["completion"]);

$update_stmt = $conn->prepare("UPDATE tasks SET status = ?, completion = ? WHERE id = ?");
$update_stmt->bind_param("sii", $new_status, $new_completion, $task_id);
$update_stmt->execute();
$update_stmt->close();

logActivity(
    $conn,
    $user_id,
    "Status Change",
    "Task",
    $task_id,
    "Status changed to \"" . $new_status . "\" (Board) on \"" . $task["name"] . "\".",
    "view_task.php?id=" . $task_id
);

foreach ($current_assignees as $assignee_id) {
    if ($assignee_id === $user_id) {
        continue;
    }
    createNotification(
        $conn,
        $assignee_id,
        "Task",
        "Task Status Updated",
        "\"" . $task["name"] . "\" was moved to " . $new_status . ".",
        "Tasks",
        $task_id,
        "view_task.php?id=" . $task_id
    );
}

if (
    $new_status === "Complete"
    && ($task["recurrence_type"] ?? "none") !== "none"
) {
    spawnNextTaskOccurrence($conn, $task);
}

header("Location: " . $redirect_url);
exit;
