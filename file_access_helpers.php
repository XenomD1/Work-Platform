<?php
/*
    file_access_helpers.php

    Single source of truth for "can this user see a file stored at this
    location" - previously this exact function was defined twice,
    separately, in files.php and view_file.php. Two independently
    maintained copies of the same authorization logic is how they drift
    apart over time (a fix applied to one silently never reaches the
    other), so both files now require this one instead of keeping
    their own copy.

    canAccessFileLocation() mirrors the location-type rules used
    elsewhere on the platform (Organization / Department / Project /
    Task / Team / Event / Training):
      - Organization-wide files are visible to everyone.
      - Administrator sees everything.
      - Department files are visible to members of that department.
      - Project/Task/Team files are visible to the Manager/IT
        Administrator of that department, the project's manager, or
        anyone on a team attached to that project/task/team.
      - Event/Training files are visible to the organizer, anyone in
        the event's/training's department, or an invited attendee.
*/
function canAccessFileLocation(
    mysqli $conn,
    string $location_type,
    $location_id,
    int $user_id,
    string $role,
    string $user_department
): bool {
    if ($location_type === "Organization") {
        return true;
    }

    if ($role === "Administrator") {
        return true;
    }

    if ($location_type === "Department") {
        return (string)$location_id === (string)$user_department;
    }

    if ($location_type === "Project") {
        $stmt = $conn->prepare("
            SELECT department, manager_id
            FROM projects
            WHERE id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$project) return false;

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $project["department"] === $user_department
        ) {
            return true;
        }

        if (
            $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            return true;
        }

        $member_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM teams t
            INNER JOIN team_members tm ON tm.team_id = t.id
            WHERE t.project_id = ?
              AND tm.user_id = ?
        ");
        $member_stmt->bind_param("ii", $location_id, $user_id);
        $member_stmt->execute();
        $total = (int)$member_stmt->get_result()->fetch_assoc()["total"];
        $member_stmt->close();

        return $total > 0;
    }

    if ($location_type === "Task") {
        $stmt = $conn->prepare("
            SELECT
                t.project_id,
                p.department,
                p.manager_id
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$task) return false;

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $task["department"] === $user_department
        ) {
            return true;
        }

        if (
            $task["manager_id"] !== null
            && (int)$task["manager_id"] === $user_id
        ) {
            return true;
        }

        $assign_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM task_assignments
            WHERE task_id = ?
              AND user_id = ?
        ");
        $assign_stmt->bind_param("ii", $location_id, $user_id);
        $assign_stmt->execute();
        $total = (int)$assign_stmt->get_result()->fetch_assoc()["total"];
        $assign_stmt->close();

        return $total > 0;
    }

    if ($location_type === "Team") {
        $stmt = $conn->prepare("
            SELECT
                t.team_lead_id,
                p.department,
                p.manager_id
            FROM teams t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $team = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$team) return false;

        if ((int)$team["team_lead_id"] === $user_id) {
            return true;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $team["department"] === $user_department
        ) {
            return true;
        }

        if (
            $team["manager_id"] !== null
            && (int)$team["manager_id"] === $user_id
        ) {
            return true;
        }

        $member_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM team_members
            WHERE team_id = ?
              AND user_id = ?
        ");
        $member_stmt->bind_param("ii", $location_id, $user_id);
        $member_stmt->execute();
        $total = (int)$member_stmt->get_result()->fetch_assoc()["total"];
        $member_stmt->close();

        return $total > 0;
    }

    if ($location_type === "Event") {
        $stmt = $conn->prepare("
            SELECT department, type, organizer
            FROM events
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$event) return false;

        if ($event["type"] === "Organization-Wide Event") return true;
        if ((int)$event["organizer"] === $user_id) return true;
        if ($event["department"] === $user_department) return true;

        $attendee_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM event_attendees
            WHERE event_id = ?
              AND user_id = ?
        ");
        $attendee_stmt->bind_param("ii", $location_id, $user_id);
        $attendee_stmt->execute();
        $total = (int)$attendee_stmt->get_result()->fetch_assoc()["total"];
        $attendee_stmt->close();

        return $total > 0;
    }

    if ($location_type === "Training") {
        $stmt = $conn->prepare("
            SELECT department, organizer
            FROM training
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $training = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$training) return false;

        if ((int)$training["organizer"] === $user_id) return true;
        if ($training["department"] === $user_department) return true;

        $attendee_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM training_attendees
            WHERE training_id = ?
              AND (user_id = ? OR department = ?)
        ");
        $attendee_stmt->bind_param(
            "iis",
            $location_id,
            $user_id,
            $user_department
        );
        $attendee_stmt->execute();
        $total = (int)$attendee_stmt->get_result()->fetch_assoc()["total"];
        $attendee_stmt->close();

        return $total > 0;
    }

    return false;
}
