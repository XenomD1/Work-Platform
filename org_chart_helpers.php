<?php
/*
    org_chart_helpers.php

    Shared queries and permission checks for the Organization & Team
    Chart (#25). Nothing here is a new data source - every function
    reads straight from users/departments-as-a-field, projects, teams
    and team_members, so the chart can never say something different
    from the normal Users/Projects/Teams pages, and there's no second
    set of reporting relationships to keep in sync.
*/

/**
 * Whether $role/$user_department may VIEW $target_department's chart.
 * Deliberately separate from canAdministerDepartment() (role_helpers.php),
 * which governs edit rights - viewing your own department's people and
 * teams is not an administrative action, so an Employee or Team Lead
 * can see their own department's chart even though they can't
 * administer it.
 */
function canViewDepartmentChart(string $role, string $user_department, string $target_department): bool {
    if (isAnyAdministrator($role)) {
        return true;
    }
    return $user_department !== "" && $user_department === $target_department;
}

/**
 * Distinct departments in use, derived from the users table (there is
 * no separate departments table - department is a field on users and
 * projects), each with a quick head count.
 */
function getOrgDepartments(mysqli $conn): array {
    $result = $conn->query("
        SELECT department, COUNT(*) AS headcount
        FROM users
        WHERE active = 1 AND department IS NOT NULL AND department <> ''
        GROUP BY department
        ORDER BY department ASC
    ");
    $departments = [];
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    return $departments;
}

/**
 * Everyone active in $department, split into management (Manager /
 * IT Administrator / Administrator) and everyone else, for the
 * department-level view.
 */
function getDepartmentRoster(mysqli $conn, string $department): array {
    $stmt = $conn->prepare("
        SELECT id, first_name, last_name, role, email
        FROM users
        WHERE active = 1 AND department = ?
        ORDER BY role ASC, last_name ASC, first_name ASC
    ");
    $stmt->bind_param("s", $department);
    $stmt->execute();
    $result = $stmt->get_result();

    $management = [];
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        if (in_array($row["role"], ["Administrator", "IT Administrator", "Manager"], true)) {
            $management[] = $row;
        } else {
            $employees[] = $row;
        }
    }
    $stmt->close();

    return ["management" => $management, "employees" => $employees];
}

/**
 * Projects belonging to $department, each with their manager's name,
 * for the "Relevant project relationships" section of a department
 * chart.
 */
function getDepartmentProjects(mysqli $conn, string $department): array {
    $stmt = $conn->prepare("
        SELECT p.id, p.name, p.status, p.manager AS manager_name, p.manager_id
        FROM projects p
        WHERE p.department = ? AND (p.archived IS NULL OR p.archived = 0)
        ORDER BY p.name ASC
    ");
    $stmt->bind_param("s", $department);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = [];
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }
    $stmt->close();
    return $projects;
}

/**
 * Teams whose project belongs to $department, with their lead's name
 * and member count, for the department chart's team list.
 */
function getDepartmentTeams(mysqli $conn, string $department): array {
    $stmt = $conn->prepare("
        SELECT
            tm.id, tm.name, tm.project_id, tm.team_lead_id,
            p.name AS project_name,
            lead.first_name AS lead_first_name, lead.last_name AS lead_last_name,
            (SELECT COUNT(*) FROM team_members WHERE team_id = tm.id) AS member_count
        FROM teams tm
        INNER JOIN projects p ON p.id = tm.project_id
        LEFT JOIN users lead ON lead.id = tm.team_lead_id
        WHERE p.department = ?
        ORDER BY tm.name ASC
    ");
    $stmt->bind_param("s", $department);
    $stmt->execute();
    $result = $stmt->get_result();
    $teams = [];
    while ($row = $result->fetch_assoc()) {
        $teams[] = $row;
    }
    $stmt->close();
    return $teams;
}

/**
 * Full detail for one team: its project, lead, and members - for the
 * team-level chart.
 */
function getTeamDetail(mysqli $conn, int $team_id): ?array {
    $stmt = $conn->prepare("
        SELECT
            tm.id, tm.name, tm.description, tm.responsibilities,
            tm.project_id, tm.team_lead_id,
            p.name AS project_name, p.department AS project_department,
            p.manager AS project_manager_name, p.manager_id AS project_manager_id,
            lead.id AS lead_id, lead.first_name AS lead_first_name, lead.last_name AS lead_last_name
        FROM teams tm
        INNER JOIN projects p ON p.id = tm.project_id
        LEFT JOIN users lead ON lead.id = tm.team_lead_id
        WHERE tm.id = ?
    ");
    $stmt->bind_param("i", $team_id);
    $stmt->execute();
    $team = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$team) {
        return null;
    }

    $member_stmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.role, u.department
        FROM team_members tmem
        INNER JOIN users u ON u.id = tmem.user_id
        WHERE tmem.team_id = ?
        ORDER BY u.last_name ASC, u.first_name ASC
    ");
    $member_stmt->bind_param("i", $team_id);
    $member_stmt->execute();
    $member_result = $member_stmt->get_result();
    $members = [];
    while ($row = $member_result->fetch_assoc()) {
        $members[] = $row;
    }
    $member_stmt->close();

    $team["members"] = $members;
    return $team;
}

/**
 * Whether $user_id is the lead or a member of $team_id - used to let
 * someone see their own team's chart even in an edge case where the
 * team's project department doesn't match the viewer's own
 * department field (e.g. cross-department project teams).
 */
function userBelongsToTeam(mysqli $conn, int $user_id, int $team_id): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM teams
        WHERE id = ? AND team_lead_id = ?
        UNION
        SELECT 1
        FROM team_members
        WHERE team_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("iiii", $team_id, $user_id, $team_id, $user_id);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}
