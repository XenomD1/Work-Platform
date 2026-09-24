<?php
/*
    analytics_helpers.php

    Shared aggregation helpers for the Analytics Dashboard (#20).
    Requires role_helpers.php and time_entry_helpers.php to already be
    loaded.

    Permission model, consistent with the rest of the platform:
      - Administrator: organization-wide, can filter by any department.
      - Manager / IT Administrator: locked to their own department -
        the department filter is not exposed to them as a choice.
      - Employee / Team Lead: locked to their own individual data only.
        No cross-employee comparison (Workload/Department sections are
        not shown to them).

    Every function here takes the viewer's already-DB-fetched $role/
    $department/$user_id rather than trusting session data - callers
    are expected to have re-fetched those the same way every other
    page on this platform does.
*/

function analyticsIsOrgWide(string $role): bool {
    return $role === "Administrator";
}

function analyticsIsDepartmentScoped(string $role): bool {
    return $role === "Manager" || $role === "IT Administrator";
}

/**
 * Task counts (by status, priority, and overdue) for the given scope.
 * $scope_user_id: when set, restricts to tasks assigned to that user.
 * $scope_department: when set, restricts to tasks whose project is in
 * that department. Both may be combined with $project_id/date filters.
 * Passing neither scope value means organization-wide (Administrator
 * only - callers must not do this for a non-Administrator).
 */
function getTaskAnalytics(
    mysqli $conn,
    ?int $scope_user_id,
    ?string $scope_department,
    int $project_id = 0,
    string $date_from = "",
    string $date_to = ""
): array {
    $sql = "
        SELECT DISTINCT t.id, t.status, t.priority, t.due_date
        FROM tasks t
        INNER JOIN projects p ON p.id = t.project_id
    ";
    $where = [];
    $params = [];
    $types = "";

    if ($scope_user_id !== null) {
        $sql .= " INNER JOIN task_assignments ta ON ta.task_id = t.id ";
        $where[] = "ta.user_id = ?";
        $params[] = $scope_user_id;
        $types .= "i";
    }

    if ($scope_department !== null) {
        $where[] = "p.department = ?";
        $params[] = $scope_department;
        $types .= "s";
    }

    if ($project_id > 0) {
        $where[] = "t.project_id = ?";
        $params[] = $project_id;
        $types .= "i";
    }

    if ($date_from !== "") {
        $where[] = "t.due_date >= ?";
        $params[] = $date_from;
        $types .= "s";
    }

    if ($date_to !== "") {
        $where[] = "t.due_date <= ?";
        $params[] = $date_to;
        $types .= "s";
    }

    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $counts = [
        "not_started" => 0, "in_progress" => 0, "complete" => 0,
        "low" => 0, "medium" => 0, "high" => 0,
        "overdue" => 0, "total" => 0,
    ];

    $today = date("Y-m-d");

    while ($row = $result->fetch_assoc()) {
        $counts["total"]++;

        switch ($row["status"]) {
            case "Not Started": $counts["not_started"]++; break;
            case "In Progress": $counts["in_progress"]++; break;
            case "Complete": $counts["complete"]++; break;
        }

        switch ($row["priority"]) {
            case "Low": $counts["low"]++; break;
            case "Medium": $counts["medium"]++; break;
            case "High": $counts["high"]++; break;
        }

        if (
            !empty($row["due_date"])
            && $row["due_date"] < $today
            && $row["status"] !== "Complete"
        ) {
            $counts["overdue"]++;
        }
    }

    $stmt->close();

    return $counts;
}

/**
 * Per-employee workload rows for a set of users already known to be in
 * scope (the caller decides who belongs in $user_rows - org-wide for
 * Administrator, department members for Manager/IT Administrator).
 */
function getWorkloadByEmployee(
    mysqli $conn,
    array $user_rows,
    int $project_id = 0
): array {
    $rows = [];

    foreach ($user_rows as $user) {
        $uid = (int)$user["id"];
        $stats = getTaskAnalytics($conn, $uid, null, $project_id);
        $hours = getTrackedHoursForUser($conn, $uid, $project_id);

        $rows[] = [
            "user_id" => $uid,
            "name" => trim($user["first_name"] . " " . $user["last_name"]),
            "active" => $stats["not_started"] + $stats["in_progress"],
            "overdue" => $stats["overdue"],
            "complete" => $stats["complete"],
            "tracked_hours" => $hours,
        ];
    }

    return $rows;
}

/**
 * Total tracked hours for one user, optionally scoped to a project and
 * a date range.
 */
function getTrackedHoursForUser(
    mysqli $conn,
    int $user_id,
    int $project_id = 0,
    string $date_from = "",
    string $date_to = ""
): float {
    $sql = "SELECT COALESCE(SUM(hours), 0) AS total FROM time_entries WHERE user_id = ?";
    $params = [$user_id];
    $types = "i";

    if ($project_id > 0) {
        $sql .= " AND project_id = ?";
        $params[] = $project_id;
        $types .= "i";
    }

    if ($date_from !== "") {
        $sql .= " AND work_date >= ?";
        $params[] = $date_from;
        $types .= "s";
    }

    if ($date_to !== "") {
        $sql .= " AND work_date <= ?";
        $params[] = $date_to;
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (float)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    return $total;
}

/**
 * Projects in scope, each with a live task-overdue count alongside
 * its stored completion percentage.
 */
function getProjectAnalytics(
    mysqli $conn,
    string $role,
    string $department,
    int $user_id,
    int $project_id = 0
): array {
    if ($role === "Administrator") {
        $sql = "SELECT id, name, department, completion, status, manager_id FROM projects";
        $where = [];
        $params = [];
        $types = "";
    } elseif ($role === "Manager" || $role === "IT Administrator") {
        $sql = "SELECT id, name, department, completion, status, manager_id FROM projects WHERE department = ?";
        $where = [];
        $params = [$department];
        $types = "s";
    } else {
        // Employee/Team Lead: projects they're actually attached to.
        $sql = "
            SELECT DISTINCT p.id, p.name, p.department, p.completion, p.status, p.manager_id
            FROM projects p
            LEFT JOIN teams t ON t.project_id = p.id
            LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
            WHERE (tm.user_id = ? OR t.team_lead_id = ? OR p.manager_id = ?)
        ";
        $where = [];
        $params = [$user_id, $user_id, $user_id, $user_id];
        $types = "iiii";
    }

    if ($project_id > 0) {
        $where[] = ($role === "Administrator" || $role === "Manager" || $role === "IT Administrator")
            ? "id = ?"
            : "p.id = ?";
        $params[] = $project_id;
        $types .= "i";
    }

    if ($where) {
        $sql .= (strpos($sql, "WHERE") !== false ? " AND " : " WHERE ") . implode(" AND ", $where);
    }

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $overdue_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM tasks
            WHERE project_id = ?
              AND status <> 'Complete'
              AND due_date IS NOT NULL
              AND due_date < CURDATE()
        ");
        $overdue_stmt->bind_param("i", $row["id"]);
        $overdue_stmt->execute();
        $row["overdue_tasks"] = (int)$overdue_stmt->get_result()->fetch_assoc()["total"];
        $overdue_stmt->close();

        $row["tracked_hours"] = getProjectTrackedHours($conn, (int)$row["id"]);

        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

/**
 * Time Off request counts by status within a date range (filtered on
 * submitted_at), for the given scope.
 */
function getTimeOffAnalytics(
    mysqli $conn,
    ?int $scope_user_id,
    ?string $scope_department,
    string $date_from = "",
    string $date_to = ""
): array {
    $sql = "SELECT t.status FROM time_off t INNER JOIN users u ON u.id = t.user_id WHERE 1=1";
    $params = [];
    $types = "";

    if ($scope_user_id !== null) {
        $sql .= " AND t.user_id = ?";
        $params[] = $scope_user_id;
        $types .= "i";
    }

    if ($scope_department !== null) {
        $sql .= " AND u.department = ?";
        $params[] = $scope_department;
        $types .= "s";
    }

    if ($date_from !== "") {
        $sql .= " AND t.submitted_at >= ?";
        $params[] = $date_from . " 00:00:00";
        $types .= "s";
    }

    if ($date_to !== "") {
        $sql .= " AND t.submitted_at <= ?";
        $params[] = $date_to . " 23:59:59";
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $counts = ["Pending" => 0, "Approved" => 0, "Rejected" => 0, "total" => 0];

    while ($row = $result->fetch_assoc()) {
        $counts["total"]++;
        if (isset($counts[$row["status"]])) {
            $counts[$row["status"]]++;
        }
    }

    $stmt->close();

    return $counts;
}

/**
 * Training counts (upcoming vs. completed) for the given scope.
 */
function getTrainingAnalytics(
    mysqli $conn,
    ?string $scope_department
): array {
    $sql = "SELECT id, start_date, department FROM training WHERE deleted = 0";
    $params = [];
    $types = "";

    if ($scope_department !== null) {
        $sql .= " AND department = ?";
        $params[] = $scope_department;
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $counts = ["upcoming" => 0, "completed" => 0, "total" => 0];
    $today = date("Y-m-d");

    while ($row = $result->fetch_assoc()) {
        $counts["total"]++;
        if (!empty($row["start_date"]) && $row["start_date"] >= $today) {
            $counts["upcoming"]++;
        } else {
            $counts["completed"]++;
        }
    }

    $stmt->close();

    return $counts;
}

/**
 * Departments to compare, drawn from active users so the list always
 * reflects real organizational data rather than a hardcoded set.
 */
function getKnownDepartments(mysqli $conn): array {
    $result = $conn->query("
        SELECT DISTINCT department
        FROM users
        WHERE active = 1 AND department IS NOT NULL AND department <> ''
        ORDER BY department ASC
    ");

    $departments = [];
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row["department"];
    }

    return $departments;
}

/**
 * A small dependency-free horizontal bar, rendered as styled divs.
 * $value/$max produce the fill width; label/count are printed beside it.
 */
function renderBar(string $label, int $value, int $max, string $color = "#2f6fed"): string {
    $pct = $max > 0 ? round(($value / $max) * 100) : 0;
    $safe_label = htmlspecialchars($label, ENT_QUOTES, "UTF-8");

    return '<div style="margin-bottom:8px;">'
        . '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">'
        . '<span>' . $safe_label . '</span><span>' . (int)$value . '</span>'
        . '</div>'
        . '<div style="background:rgba(0,0,0,.08);border-radius:4px;height:10px;overflow:hidden;">'
        . '<div style="width:' . $pct . '%;background:' . htmlspecialchars($color, ENT_QUOTES, "UTF-8") . ';height:100%;"></div>'
        . '</div>'
        . '</div>';
}
