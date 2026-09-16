<?php

function createNotification(
    mysqli $conn,
    int $user_id,
    string $type,
    string $title,
    string $message,
    ?string $related_module = null,
    ?int $related_id = null,
    ?string $related_url = null
): bool {
    if ($user_id <= 0) {
        return false;
    }

    if (!userCanReceiveNotifications($conn, $user_id)) {
        return false;
    }

    $type = trim($type);
    $title = trim($title);
    $message = trim($message);
    $related_module = $related_module !== null ? trim($related_module) : null;
    $related_url = $related_url !== null ? trim($related_url) : null;

    if ($type === "" || $title === "" || $message === "") {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO notifications (
            user_id,
            type,
            title,
            message,
            related_module,
            related_id,
            related_url,
            is_read,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "issssis",
        $user_id,
        $type,
        $title,
        $message,
        $related_module,
        $related_id,
        $related_url
    );

    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function createNotifications(
    mysqli $conn,
    array $user_ids,
    string $type,
    string $title,
    string $message,
    ?string $related_module = null,
    ?int $related_id = null,
    ?string $related_url = null
): int {
    $created = 0;
    $unique_user_ids = [];

    foreach ($user_ids as $user_id) {
        $user_id = (int)$user_id;

        if ($user_id > 0) {
            $unique_user_ids[$user_id] = $user_id;
        }
    }

    foreach ($unique_user_ids as $user_id) {
        if (
            createNotification(
                $conn,
                $user_id,
                $type,
                $title,
                $message,
                $related_module,
                $related_id,
                $related_url
            )
        ) {
            $created++;
        }
    }

    return $created;
}

function getUnreadNotificationCount(mysqli $conn, int $user_id): int {
    if ($user_id <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM notifications
        WHERE user_id = ?
          AND is_read = 0
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return (int)($row["total"] ?? 0);
}

/*
 * Used internally by createNotification() above, so every
 * notification creation path is protected centrally - an
 * inactive/deactivated user never accumulates notification rows
 * they can no longer act on.
 */
function userCanReceiveNotifications(mysqli $conn, int $user_id): bool {
    if ($user_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT active
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $stmt->close();

    return $user && (int)$user["active"] === 1;
}


/*
 * Shared by upload_file.php and new_file_version.php - previously
 * duplicated verbatim in both files. Resolves who should be notified
 * about a file event based on its location, excluding the acting
 * user (the person who just performed the action).
 */
function fileNotificationRecipientIds(
    mysqli $conn,
    string $location_type,
    $location_id,
    int $exclude_user_id = 0
): array {
    $ids = [];

    if ($location_type === "Department") {
        $stmt = $conn->prepare("
            SELECT id FROM users
            WHERE active = 1 AND department = ?
        ");
        $location_text = (string)$location_id;
        $stmt->bind_param("s", $location_text);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row["id"];
        }
        $stmt->close();
    } elseif ($location_type === "Project") {
        $location_int = (int)$location_id;

        $stmt = $conn->prepare("
            SELECT manager_id FROM projects WHERE id = ?
        ");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($project && !empty($project["manager_id"])) {
            $ids[] = (int)$project["manager_id"];
        }

        $stmt = $conn->prepare("
            SELECT DISTINCT tm.user_id
            FROM teams t
            INNER JOIN team_members tm ON tm.team_id = t.id
            WHERE t.project_id = ?
        ");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row["user_id"];
        }
        $stmt->close();
    } elseif ($location_type === "Task") {
        $location_int = (int)$location_id;

        $stmt = $conn->prepare("
            SELECT user_id FROM task_assignments WHERE task_id = ?
        ");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row["user_id"];
        }
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT p.manager_id
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($project && !empty($project["manager_id"])) {
            $ids[] = (int)$project["manager_id"];
        }
    } elseif ($location_type === "Team") {
        $location_int = (int)$location_id;

        $stmt = $conn->prepare("
            SELECT user_id FROM team_members WHERE team_id = ?
        ");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row["user_id"];
        }
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT t.team_lead_id, p.manager_id
            FROM teams t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $team = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($team) {
            if (!empty($team["team_lead_id"])) {
                $ids[] = (int)$team["team_lead_id"];
            }
            if (!empty($team["manager_id"])) {
                $ids[] = (int)$team["manager_id"];
            }
        }
    } elseif ($location_type === "Event") {
        $location_int = (int)$location_id;

        $stmt = $conn->prepare("SELECT organizer FROM events WHERE id = ?");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($event && !empty($event["organizer"])) {
            $ids[] = (int)$event["organizer"];
        }

        $stmt = $conn->prepare("
            SELECT user_id FROM event_attendees WHERE event_id = ?
        ");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row["user_id"];
        }
        $stmt->close();
    } elseif ($location_type === "Training") {
        $location_int = (int)$location_id;

        $stmt = $conn->prepare("SELECT organizer FROM training WHERE id = ?");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $training = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($training && !empty($training["organizer"])) {
            $ids[] = (int)$training["organizer"];
        }

        $stmt = $conn->prepare("
            SELECT user_id, department
            FROM training_attendees
            WHERE training_id = ?
        ");
        $stmt->bind_param("i", $location_int);
        $stmt->execute();
        $result = $stmt->get_result();
        $departments = [];

        while ($row = $result->fetch_assoc()) {
            if (!empty($row["user_id"])) {
                $ids[] = (int)$row["user_id"];
            } elseif (!empty($row["department"])) {
                $departments[] = $row["department"];
            }
        }
        $stmt->close();

        foreach (array_unique($departments) as $department) {
            $stmt = $conn->prepare("
                SELECT id FROM users
                WHERE active = 1 AND department = ?
            ");
            $stmt->bind_param("s", $department);
            $stmt->execute();
            $department_result = $stmt->get_result();
            while ($row = $department_result->fetch_assoc()) {
                $ids[] = (int)$row["id"];
            }
            $stmt->close();
        }
    }

    $ids = array_values(array_unique(array_filter(array_map("intval", $ids))));

    if ($exclude_user_id > 0) {
        $ids = array_values(
            array_filter(
                $ids,
                static fn($id) => $id !== $exclude_user_id
            )
        );
    }

    return $ids;
}
