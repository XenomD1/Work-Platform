<?php
/*
    time_entry_helpers.php

    Shared helpers for the Time Tracking feature (#21). Requires
    role_helpers.php to already be loaded for isAnyAdministrator().

    Permission model (per the #21 spec):
      - Viewing: Employee/Team Lead see their own entries. A Project
        Manager sees entries on projects they manage. Manager/IT
        Administrator see entries in their own department. Administrator
        sees everything.
      - Editing/Deleting: only the entry's own author, or an
        Administrator (for corrections). Broader *viewing* access does
        not imply the ability to rewrite someone else's time entry -
        that is intentionally a separate, narrower check.
*/

function canViewTimeEntry(
    array $entry,
    int $viewer_id,
    string $role,
    string $viewer_department
): bool {
    if ($role === "Administrator") {
        return true;
    }

    if ((int)$entry["user_id"] === $viewer_id) {
        return true;
    }

    if (
        ($role === "Manager" || $role === "IT Administrator")
        && $entry["project_department"] === $viewer_department
    ) {
        return true;
    }

    if (
        $entry["project_manager_id"] !== null
        && (int)$entry["project_manager_id"] === $viewer_id
    ) {
        return true;
    }

    return false;
}

function canManageTimeEntry(array $entry, int $viewer_id, string $role): bool {
    if ($role === "Administrator") {
        return true;
    }

    return (int)$entry["user_id"] === $viewer_id;
}

/**
 * Every project the given user is allowed to log time against: any
 * project in their own department (if Manager/IT Administrator), any
 * project they manage, any project they're on a team for, or - for an
 * Administrator - every project. Employees/Team Leads can only log
 * time to projects they're actually attached to via a team.
 */
function getLoggableProjects(
    mysqli $conn,
    int $user_id,
    string $role,
    string $department
): array {
    if ($role === "Administrator") {
        $stmt = $conn->prepare("SELECT id, name FROM projects ORDER BY name ASC");
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    $stmt = $conn->prepare("
        SELECT DISTINCT p.id, p.name
        FROM projects p
        LEFT JOIN teams t ON t.project_id = p.id
        LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
        WHERE
            ((? = 'Manager' OR ? = 'IT Administrator') AND p.department = ?)
            OR p.manager_id = ?
            OR tm.user_id = ?
            OR t.team_lead_id = ?
        ORDER BY p.name ASC
    ");
    $stmt->bind_param(
        "issiii",
        $user_id,
        $role,
        $role,
        $department,
        $user_id,
        $user_id,
        $user_id
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/**
 * Tasks belonging to $project_id, for the task dropdown once a project
 * has been chosen on the time entry form.
 */
function getProjectTasksForEntry(mysqli $conn, int $project_id): array {
    $stmt = $conn->prepare("
        SELECT id, name
        FROM tasks
        WHERE project_id = ?
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/**
 * True if $user_id is allowed to log time against $project_id, per the
 * same rule getLoggableProjects() uses to build the dropdown - checked
 * again server-side on submit so the dropdown is a convenience, not the
 * actual authorization boundary.
 */
function userCanLogTimeForProject(
    mysqli $conn,
    int $user_id,
    string $role,
    string $department,
    int $project_id
): bool {
    if ($role === "Administrator") {
        return true;
    }

    $stmt = $conn->prepare("
        SELECT
            p.department,
            p.manager_id,
            EXISTS(
                SELECT 1 FROM teams t
                INNER JOIN team_members tm ON tm.team_id = t.id
                WHERE t.project_id = p.id AND tm.user_id = ?
            ) AS is_member,
            EXISTS(
                SELECT 1 FROM teams t
                WHERE t.project_id = p.id AND t.team_lead_id = ?
            ) AS is_lead
        FROM projects p
        WHERE p.id = ?
    ");
    $stmt->bind_param("iii", $user_id, $user_id, $project_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return false;
    }

    if (($role === "Manager" || $role === "IT Administrator") && $row["department"] === $department) {
        return true;
    }

    if ($row["manager_id"] !== null && (int)$row["manager_id"] === $user_id) {
        return true;
    }

    return (int)$row["is_member"] === 1 || (int)$row["is_lead"] === 1;
}

/**
 * True if $task_id actually belongs to $project_id - guards against a
 * submitted task_id that doesn't match the chosen project.
 */
function taskBelongsToProject(mysqli $conn, int $task_id, int $project_id): bool {
    $stmt = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND project_id = ?");
    $stmt->bind_param("ii", $task_id, $project_id);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

/**
 * Total tracked hours recorded against a project (all its tasks plus
 * any entries logged directly at the project level).
 */
function getProjectTrackedHours(mysqli $conn, int $project_id): float {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(hours), 0) AS total
        FROM time_entries
        WHERE project_id = ?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $total = (float)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();
    return $total;
}

/**
 * Total tracked hours recorded against a single task.
 */
function getTaskTrackedHours(mysqli $conn, int $task_id): float {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(hours), 0) AS total
        FROM time_entries
        WHERE task_id = ?
    ");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $total = (float)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();
    return $total;
}

/**
 * Monday-Sunday bounds (Y-m-d strings) for the week containing $date.
 */
function getWeekBounds(string $date): array {
    $dt = new DateTime($date);
    $day_of_week = (int)$dt->format("N"); // 1 (Mon) .. 7 (Sun)
    $start = clone $dt;
    $start->modify("-" . ($day_of_week - 1) . " days");
    $end = clone $start;
    $end->modify("+6 days");
    return [$start->format("Y-m-d"), $end->format("Y-m-d")];
}

/**
 * First/last day (Y-m-d strings) of the month containing $date.
 */
function getMonthBounds(string $date): array {
    $dt = new DateTime($date);
    $start = new DateTime($dt->format("Y-m-01"));
    $end = clone $start;
    $end->modify("last day of this month");
    return [$start->format("Y-m-d"), $end->format("Y-m-d")];
}
