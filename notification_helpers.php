<?php

function createNotification(
    mysqli $conn,
    int $user_id,
    string $type,
    string $title,
    string $message,
    ?string $related_module = null,
    ?int $related_id = null,
    ?string $related_url = null,
    ?string $category = null
): bool {
    if ($user_id <= 0) {
        return false;
    }

    if (!userCanReceiveNotifications($conn, $user_id)) {
        return false;
    }

    /*
        Notification preferences (Settings > Notification Preferences).
        Only the categories a user can actually toggle are gated here;
        a $category is only used when it was passed explicitly (see
        process_event_reminders.php for "Event Reminders") or when it
        can be derived from $type (Task/Project/File/Report). Anything
        else - Team, Training, Mention, and non-reminder Event
        notifications - is always sent, since there's no preference
        for it yet.
    */
    $effective_category = $category ?? notificationCategoryFromType($type);

    if (
        $effective_category !== null
        && !userWantsNotificationCategory($conn, $user_id, $effective_category)
    ) {
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
    ?string $related_url = null,
    ?string $category = null
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
                $related_url,
                $category
            )
        ) {
            $created++;
        }
    }

    return $created;
}

/*
 * Maps a notification's $type to a user-facing preference category,
 * for the types that have one. Returns null for types with no
 * preference toggle (Team, Training, Mention, and Event - Event
 * notifications are only gated when process_event_reminders.php
 * passes the "Event Reminders" category explicitly, since that's the
 * one recurring/automated nudge, as opposed to a one-time invitation
 * or organizer assignment).
 */
function notificationCategoryFromType(string $type): ?string {
    switch ($type) {
        case "Task":
            return "Task Assignment";
        case "Project":
            return "Project Changes";
        case "File":
            return "File Activity";
        case "Report":
            return "Report Activity";
        default:
            return null;
    }
}

/*
 * Whether $user_id currently wants notifications in $category,
 * per their Settings > Notification Preferences. Falls back to the
 * documented defaults (Task Assignment/Project Changes/Event
 * Reminders/Report Activity = On, File Activity = Off) when the user
 * has no user_preferences row yet.
 */
function userWantsNotificationCategory(
    mysqli $conn,
    int $user_id,
    string $category
): bool {
    $defaults = [
        "Task Assignment" => true,
        "Project Changes" => true,
        "Event Reminders" => true,
        "File Activity" => false,
        "Report Activity" => true,
    ];

    if (!array_key_exists($category, $defaults)) {
        return true;
    }

    switch ($category) {
        case "Task Assignment":
            $sql = "SELECT notify_task_assignment AS wants FROM user_preferences WHERE user_id = ? LIMIT 1";
            break;
        case "Project Changes":
            $sql = "SELECT notify_project_changes AS wants FROM user_preferences WHERE user_id = ? LIMIT 1";
            break;
        case "Event Reminders":
            $sql = "SELECT notify_event_reminders AS wants FROM user_preferences WHERE user_id = ? LIMIT 1";
            break;
        case "File Activity":
            $sql = "SELECT notify_file_activity AS wants FROM user_preferences WHERE user_id = ? LIMIT 1";
            break;
        case "Report Activity":
        default:
            $sql = "SELECT notify_report_activity AS wants FROM user_preferences WHERE user_id = ? LIMIT 1";
            break;
    }

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return $defaults[$category];
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return $defaults[$category];
    }

    return (int)$row["wants"] === 1;
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
