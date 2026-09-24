<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "notification_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

function eventNotificationRecipientIds(mysqli $conn, int $event_id, int $organizer_id, ?int $occurrence_id = null): array {
    $ids = [$organizer_id];

    if ($occurrence_id !== null) {
        $stmt = $conn->prepare("
            SELECT user_id
            FROM event_occurrence_attendees
            WHERE occurrence_id = ?
        ");
        $stmt->bind_param("i", $occurrence_id);
    } else {
        $stmt = $conn->prepare("
            SELECT user_id
            FROM event_attendees
            WHERE event_id = ?
        ");
        $stmt->bind_param("i", $event_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row["user_id"];
    }

    $stmt->close();

    return array_values(array_unique(array_filter(array_map("intval", $ids))));
}

$user_id = (int)$_SESSION["user_id"];
$event_id = (int)($_GET["id"] ?? $_POST["event_id"] ?? 0);
$occurrence_date = trim($_GET["occurrence"] ?? $_POST["occurrence_date"] ?? "");

if ($event_id <= 0) {
    die("Invalid event.");
}

/* ---------------------------------------------------------
   CURRENT USER
--------------------------------------------------------- */
$user_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$user_result = $user_stmt->get_result();
$current_user = $user_result->fetch_assoc();

$user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requireValidCsrfToken();
}

$role = $current_user["role"];
$user_department = $current_user["department"];

/* ---------------------------------------------------------
   LOAD EVENT
--------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        e.id,
        e.name,
        e.description,
        e.type,
        e.organizer,
        e.department,
        e.location,
        e.start_date,
        e.end_date,
        e.start_time,
        e.end_time,
        e.status,
        e.recurrence_type,
        e.repeat_until,
        e.reminder,
        e.reminder_delivery,
        CONCAT(u.first_name, ' ', u.last_name) AS organizer_name
    FROM events e
    INNER JOIN users u ON e.organizer = u.id
    WHERE e.id = ?
          AND e.deleted = 0
");

$stmt->bind_param("i", $event_id);
$stmt->execute();

$result = $stmt->get_result();
$event = $result->fetch_assoc();

$stmt->close();

if (!$event) {
    die("Event not found.");
}

/* ---------------------------------------------------------
   LOAD SELECTED RECURRING OCCURRENCE
--------------------------------------------------------- */
$occurrence = null;

if (
    $occurrence_date !== ""
    && $event["recurrence_type"] !== "none"
) {
    $occurrence_stmt = $conn->prepare("
        SELECT id, occurrence_date, start_time, end_time, status
        FROM event_occurrences
        WHERE event_id = ?
          AND occurrence_date = ?
        LIMIT 1
    ");
    $occurrence_stmt->bind_param("is", $event_id, $occurrence_date);
    $occurrence_stmt->execute();
    $occurrence = $occurrence_stmt->get_result()->fetch_assoc();
    $occurrence_stmt->close();

    if (!$occurrence) {
        $occurrence_date = "";
    }
}

/* ---------------------------------------------------------
   ENSURE PER-OCCURRENCE ATTENDEE ROWS
--------------------------------------------------------- */
if ($occurrence) {
    $seed_occurrence_attendees = $conn->prepare("
        INSERT INTO event_occurrence_attendees
            (occurrence_id, user_id, rsvp_status, attendance_status, attendance_comment)
        SELECT ?, ea.user_id, ea.rsvp_status, 'Not Recorded', NULL
        FROM event_attendees ea
        WHERE ea.event_id = ?
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
    ");
    $occurrence_id_for_seed = (int)$occurrence["id"];
    $seed_occurrence_attendees->bind_param("ii", $occurrence_id_for_seed, $event_id);
    $seed_occurrence_attendees->execute();
    $seed_occurrence_attendees->close();
}

/* ---------------------------------------------------------
   SOFT DELETE / RECURRING DELETE
   Only Administrators, or the Manager of the event's own
   department, may delete an event - matching the same rule
   used to decide whether the Delete/Edit buttons are shown.
--------------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_event"])) {

    $can_delete = false;

    if ($role === "Administrator") {
        $can_delete = true;
    } elseif (
        ($role === "Manager" || $role === "IT Administrator") &&
        (
            $event["department"] === $user_department
            || (int)$event["organizer"] === $user_id
        )
    ) {
        $can_delete = true;
    }

    if (!$can_delete) {
        die("You do not have permission to delete this event.");
    }

    $delete_scope = trim($_POST["delete_scope"] ?? "series");

    /*
     * Fail safe: a narrower scope (occurrence/future) requires valid
     * occurrence context. If that context is missing - a stale form,
     * a tampered request, an occurrence that no longer matches - do
     * not silently fall through to deleting the whole series. Reject
     * the request instead.
     */
    if (
        $event["recurrence_type"] !== "none"
        && !$occurrence
        && $delete_scope !== "series"
    ) {
        die("This occurrence could not be found. Please try again.");
    }

    $delete_action = "Event Deleted";
    $delete_occurrence_for_history = null;
    $delete_old_value = $event["status"];
    $delete_new_value = "Deleted";

    if ($event["recurrence_type"] !== "none" && $occurrence) {
        $delete_occurrence_for_history = $occurrence_date;
        if ($delete_scope === "occurrence") {
            $delete_action = "Occurrence Cancelled";
            $delete_old_value = $occurrence["status"];
            $delete_new_value = "Cancelled";
        } elseif ($delete_scope === "future") {
            $delete_action = "Future Occurrences Removed";
            $delete_old_value = $occurrence_date;
            $delete_new_value = "This and future occurrences removed";
        }
    }

    $delete_history_stmt = $conn->prepare("
        INSERT INTO event_history
            (event_id, action_type, occurrence_date, field_name, old_value, new_value, changed_by)
        VALUES (?, ?, ?, 'status', ?, ?, ?)
    ");
    $delete_history_stmt->bind_param(
        "issssi",
        $event_id,
        $delete_action,
        $delete_occurrence_for_history,
        $delete_old_value,
        $delete_new_value,
        $user_id
    );
    $delete_history_stmt->execute();
    $delete_history_stmt->close();

    if (
        $event["recurrence_type"] !== "none"
        && $occurrence
    ) {
        if ($delete_scope === "occurrence") {

            $delete_stmt = $conn->prepare("
                UPDATE event_occurrences
                SET status = 'Cancelled'
                WHERE event_id = ?
                  AND occurrence_date = ?
            ");
            $delete_stmt->bind_param(
                "is",
                $event_id,
                $occurrence_date
            );
            $delete_stmt->execute();
            $delete_stmt->close();

            $recipient_ids = eventNotificationRecipientIds(
                $conn,
                $event_id,
                (int)$event["organizer"],
                (int)$occurrence["id"]
            );

            foreach ($recipient_ids as $recipient_id) {
                if ($recipient_id === $user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    $recipient_id,
                    "Event",
                    "Event Occurrence Cancelled",
                    "The " . $occurrence_date . " occurrence of \"" . $event["name"] . "\" was cancelled.",
                    "Meetings / Events",
                    $event_id,
                    "view_event.php?id=" . $event_id . "&occurrence=" . urlencode($occurrence_date)
                );
            }

            header(
                "Location: view_event.php?id="
                . $event_id
                . "&occurrence="
                . urlencode($occurrence_date)
            );
            exit;

        } elseif ($delete_scope === "future") {

            $previous_stmt = $conn->prepare("
                SELECT MAX(occurrence_date) AS previous_date
                FROM event_occurrences
                WHERE event_id = ?
                  AND occurrence_date < ?
            ");
            $previous_stmt->bind_param(
                "is",
                $event_id,
                $occurrence_date
            );
            $previous_stmt->execute();
            $previous_date =
                $previous_stmt->get_result()->fetch_assoc()["previous_date"]
                ?? null;
            $previous_stmt->close();

            if ($previous_date === null) {

                $delete_stmt = $conn->prepare("
                    UPDATE events
                    SET deleted = 1,
                        deleted_at = NOW(),
                        deleted_by = ?
                    WHERE id = ?
                      AND deleted = 0
                ");
                $delete_stmt->bind_param(
                    "ii",
                    $user_id,
                    $event_id
                );
                $delete_stmt->execute();
                $delete_stmt->close();

            } else {

                $conn->begin_transaction();

                try {
                    $update_series_stmt = $conn->prepare("
                        UPDATE events
                        SET repeat_until = ?
                        WHERE id = ?
                    ");
                    $update_series_stmt->bind_param(
                        "si",
                        $previous_date,
                        $event_id
                    );
                    $update_series_stmt->execute();
                    $update_series_stmt->close();

                    $delete_future_stmt = $conn->prepare("
                        DELETE FROM event_occurrences
                        WHERE event_id = ?
                          AND occurrence_date >= ?
                    ");
                    $delete_future_stmt->bind_param(
                        "is",
                        $event_id,
                        $occurrence_date
                    );
                    $delete_future_stmt->execute();
                    $delete_future_stmt->close();

                    $conn->commit();

                } catch (Throwable $e) {
                    $conn->rollback();
                    die("The recurring event could not be updated.");
                }
            }

            $recipient_ids = eventNotificationRecipientIds(
                $conn,
                $event_id,
                (int)$event["organizer"]
            );

            foreach ($recipient_ids as $recipient_id) {
                if ($recipient_id === $user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    $recipient_id,
                    "Event",
                    "Future Event Occurrences Removed",
                    "Future occurrences of \"" . $event["name"] . "\" beginning " . $occurrence_date . " were removed.",
                    "Meetings / Events",
                    $event_id,
                    "events.php"
                );
            }

            header("Location: events.php");
            exit;

        } elseif ($delete_scope !== "series") {
            die("Invalid recurring event delete option.");
        }
    }

    $delete_stmt = $conn->prepare("
        UPDATE events
        SET deleted = 1,
            deleted_at = NOW(),
            deleted_by = ?
        WHERE id = ?
          AND deleted = 0
    ");

    $delete_stmt->bind_param("ii", $user_id, $event_id);
    $delete_stmt->execute();
    $deleted = $delete_stmt->affected_rows > 0;
    $delete_stmt->close();

    if ($deleted) {
        $recipient_ids = eventNotificationRecipientIds(
            $conn,
            $event_id,
            (int)$event["organizer"]
        );

        foreach ($recipient_ids as $recipient_id) {
            if ($recipient_id === $user_id) {
                continue;
            }

            createNotification(
                $conn,
                $recipient_id,
                "Event",
                "Event Deleted",
                "The event \"" . $event["name"] . "\" was deleted.",
                "Meetings / Events",
                $event_id,
                "events.php"
            );
        }
    }

    header("Location: events.php");
    exit;
}

/* ---------------------------------------------------------
   VISIBILITY
--------------------------------------------------------- */

$can_view = false;

if ($role === "Administrator") {

    $can_view = true;

} elseif ($event["type"] === "Organization-Wide Event") {

    $can_view = true;

} elseif ($event["department"] === $user_department) {

    $can_view = true;

} elseif ((int)$event["organizer"] === $user_id) {

    $can_view = true;

} else {

    $attendee_check = $conn->prepare("
        SELECT id
        FROM event_attendees
        WHERE event_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $attendee_check->bind_param(
        "ii",
        $event_id,
        $user_id
    );

    $attendee_check->execute();

    $attendee_result = $attendee_check->get_result();

    if ($attendee_result->num_rows > 0) {
        $can_view = true;
    }

    $attendee_check->close();
}

if (!$can_view) {
    die("You do not have permission to view this event.");
}

/* ---------------------------------------------------------
   RSVP / ATTENDEE RESPONSE
--------------------------------------------------------- */
$rsvp_options = ["Going", "Maybe", "Not Going"];
$current_rsvp = null;

if ($occurrence) {
    $current_rsvp_stmt = $conn->prepare("
        SELECT rsvp_status
        FROM event_occurrence_attendees
        WHERE occurrence_id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $occurrence_id_for_rsvp = (int)$occurrence["id"];
    $current_rsvp_stmt->bind_param("ii", $occurrence_id_for_rsvp, $user_id);
} else {
    $current_rsvp_stmt = $conn->prepare("
        SELECT rsvp_status
        FROM event_attendees
        WHERE event_id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $current_rsvp_stmt->bind_param("ii", $event_id, $user_id);
}
$current_rsvp_stmt->execute();
$current_rsvp_row = $current_rsvp_stmt->get_result()->fetch_assoc();
$current_rsvp_stmt->close();

if ($current_rsvp_row) {
    $current_rsvp = $current_rsvp_row["rsvp_status"] ?: "No Response";
}

$effective_event_status = $event["status"];
if ($occurrence && !empty($occurrence["status"])) {
    $effective_event_status = $occurrence["status"];
}

$can_respond =
    $current_rsvp !== null
    && !in_array($effective_event_status, ["Cancelled", "Completed"], true);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_rsvp"])) {
    if (!$can_respond) {
        http_response_code(403);
        exit("You cannot respond to this event.");
    }

    $new_rsvp = trim($_POST["rsvp_status"] ?? "");
    if (!in_array($new_rsvp, $rsvp_options, true)) {
        http_response_code(400);
        exit("Invalid RSVP response.");
    }

    $old_rsvp = $current_rsvp ?: "No Response";

    if ($occurrence) {
        $rsvp_update_stmt = $conn->prepare("
            UPDATE event_occurrence_attendees
            SET rsvp_status = ?
            WHERE occurrence_id = ?
              AND user_id = ?
        ");
        $occurrence_id_for_rsvp = (int)$occurrence["id"];
        $rsvp_update_stmt->bind_param("sii", $new_rsvp, $occurrence_id_for_rsvp, $user_id);
    } else {
        $rsvp_update_stmt = $conn->prepare("
            UPDATE event_attendees
            SET rsvp_status = ?
            WHERE event_id = ?
              AND user_id = ?
        ");
        $rsvp_update_stmt->bind_param("sii", $new_rsvp, $event_id, $user_id);
    }
    $rsvp_update_stmt->execute();
    $rsvp_update_stmt->close();

    if ($old_rsvp !== $new_rsvp) {
        $history_occurrence_date = $occurrence ? $occurrence["occurrence_date"] : null;
        $history_stmt = $conn->prepare("
            INSERT INTO event_history
                (event_id, action_type, occurrence_date, field_name, old_value, new_value, changed_by)
            VALUES (?, 'RSVP Changed', ?, 'rsvp_status', ?, ?, ?)
        ");
        $history_stmt->bind_param("isssi", $event_id, $history_occurrence_date, $old_rsvp, $new_rsvp, $user_id);
        $history_stmt->execute();
        $history_stmt->close();

        if ((int)$event["organizer"] !== $user_id) {
            $rsvp_user_name = trim(
                $current_user["first_name"] . " " . $current_user["last_name"]
            );
            $rsvp_message = $rsvp_user_name . " responded " . $new_rsvp
                . " to \"" . $event["name"] . "\".";

            if ($occurrence) {
                $rsvp_message .= " Occurrence: " . $occurrence["occurrence_date"] . ".";
            }

            createNotification(
                $conn,
                (int)$event["organizer"],
                "Event",
                "Event RSVP Updated",
                $rsvp_message,
                "Meetings / Events",
                $event_id,
                "view_event.php?id=" . $event_id
            );
        }
    }

    $redirect = "view_event.php?id=" . $event_id;
    if ($occurrence_date !== "") {
        $redirect .= "&occurrence=" . urlencode($occurrence_date);
    }
    header("Location: " . $redirect);
    exit;
}

$can_view_all_rsvps =
    $role === "Administrator"
    || (int)$event["organizer"] === $user_id
    || (
        ($role === "Manager" || $role === "IT Administrator")
        && $event["department"] === $user_department
    );

/* ---------------------------------------------------------
   ATTENDANCE TRACKING
--------------------------------------------------------- */
$attendance_options = ["Attended", "Absent", "Excused"];

if ($occurrence) {
    $base_start = new DateTime($event["start_date"]);
    $base_end = new DateTime($event["end_date"]);
    $duration_days = (int)$base_start->diff($base_end)->format("%a");
    $attendance_end_date = new DateTime($occurrence["occurrence_date"]);
    if ($duration_days > 0) {
        $attendance_end_date->modify("+" . $duration_days . " days");
    }
    $attendance_end_timestamp = strtotime(
        $attendance_end_date->format("Y-m-d") . " " .
        ($occurrence["end_time"] !== "" ? $occurrence["end_time"] : "23:59:59")
    );
    $event_has_ended =
        $occurrence["status"] === "Completed"
        || ($attendance_end_timestamp !== false && $attendance_end_timestamp <= time());
    $attendance_target_cancelled = $occurrence["status"] === "Cancelled";
} else {
    $attendance_end_timestamp = strtotime(
        $event["end_date"] . " " .
        ($event["end_time"] !== "" ? $event["end_time"] : "23:59:59")
    );
    $event_has_ended =
        $event["status"] === "Completed"
        || ($attendance_end_timestamp !== false && $attendance_end_timestamp <= time());
    $attendance_target_cancelled = $event["status"] === "Cancelled";
}

$can_manage_attendance =
    $event_has_ended
    && !$attendance_target_cancelled
    && (
        $role === "Administrator"
        || (int)$event["organizer"] === $user_id
        || (
            ($role === "Manager" || $role === "IT Administrator")
            && $event["department"] === $user_department
        )
    );

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_attendance"])) {
    if (!$can_manage_attendance) {
        http_response_code(403);
        exit("You do not have permission to record attendance for this event.");
    }

    $attendance_user_id = (int)($_POST["attendance_user_id"] ?? 0);
    $attendance_status = trim($_POST["attendance_status"] ?? "");
    $attendance_comment = trim($_POST["attendance_comment"] ?? "");

    if (!in_array($attendance_status, $attendance_options, true)) {
        http_response_code(400);
        exit("Invalid attendance status.");
    }

    $attendance_comment_db = $attendance_comment !== "" ? $attendance_comment : null;
    $old_attendance = "Not Recorded";
    $old_comment = null;

    if ($occurrence) {
        $occurrence_id_for_attendance = (int)$occurrence["id"];
        $old_stmt = $conn->prepare("
            SELECT attendance_status, attendance_comment
            FROM event_occurrence_attendees
            WHERE occurrence_id = ? AND user_id = ?
            LIMIT 1
        ");
        $old_stmt->bind_param("ii", $occurrence_id_for_attendance, $attendance_user_id);
        $old_stmt->execute();
        $old_row = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();
        if (!$old_row) {
            http_response_code(400);
            exit("That user is not an attendee for this occurrence.");
        }
        $old_attendance = $old_row["attendance_status"] ?: "Not Recorded";
        $old_comment = $old_row["attendance_comment"];

        $attendance_update_stmt = $conn->prepare("
            UPDATE event_occurrence_attendees
            SET attendance_status = ?, attendance_comment = ?
            WHERE occurrence_id = ? AND user_id = ?
        ");
        $attendance_update_stmt->bind_param("ssii", $attendance_status, $attendance_comment_db, $occurrence_id_for_attendance, $attendance_user_id);
    } else {
        $old_stmt = $conn->prepare("
            SELECT attendance_status, attendance_comment
            FROM event_attendees
            WHERE event_id = ? AND user_id = ?
            LIMIT 1
        ");
        $old_stmt->bind_param("ii", $event_id, $attendance_user_id);
        $old_stmt->execute();
        $old_row = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();
        if (!$old_row) {
            http_response_code(400);
            exit("That user is not an attendee for this event.");
        }
        $old_attendance = $old_row["attendance_status"] ?: "Not Recorded";
        $old_comment = $old_row["attendance_comment"];

        $attendance_update_stmt = $conn->prepare("
            UPDATE event_attendees
            SET attendance_status = ?, attendance_comment = ?
            WHERE event_id = ? AND user_id = ?
        ");
        $attendance_update_stmt->bind_param("ssii", $attendance_status, $attendance_comment_db, $event_id, $attendance_user_id);
    }

    $attendance_update_stmt->execute();
    $attendance_update_stmt->close();

    if ($old_attendance !== $attendance_status || (string)$old_comment !== (string)$attendance_comment_db) {
        $history_occurrence_date = $occurrence ? $occurrence["occurrence_date"] : null;
        $old_text = $old_attendance . ($old_comment ? " - " . $old_comment : "");
        $new_text = $attendance_status . ($attendance_comment_db ? " - " . $attendance_comment_db : "");
        $history_field = "attendance_user_" . $attendance_user_id;
        $history_stmt = $conn->prepare("
            INSERT INTO event_history
                (event_id, action_type, occurrence_date, field_name, old_value, new_value, changed_by)
            VALUES (?, 'Attendance Recorded', ?, ?, ?, ?, ?)
        ");
        $history_stmt->bind_param("issssi", $event_id, $history_occurrence_date, $history_field, $old_text, $new_text, $user_id);
        $history_stmt->execute();
        $history_stmt->close();

        if ($attendance_user_id !== $user_id) {
            $attendance_message =
                "Your attendance for \"" . $event["name"] . "\" was recorded as "
                . $attendance_status . ".";

            if ($occurrence) {
                $attendance_message .= " Occurrence: " . $occurrence["occurrence_date"] . ".";
            }

            createNotification(
                $conn,
                $attendance_user_id,
                "Event",
                "Event Attendance Recorded",
                $attendance_message,
                "Meetings / Events",
                $event_id,
                "view_event.php?id=" . $event_id
            );
        }
    }

    $redirect = "view_event.php?id=" . $event_id;
    if ($occurrence_date !== "") {
        $redirect .= "&occurrence=" . urlencode($occurrence_date);
    }
    header("Location: " . $redirect);
    exit;
}

/* ---------------------------------------------------------
   LOAD ATTENDEES
--------------------------------------------------------- */
$attendees = [];

if ($occurrence) {
    $attendee_stmt = $conn->prepare("
        SELECT
            eoa.user_id, eoa.rsvp_status, eoa.attendance_status, eoa.attendance_comment,
            CONCAT(u.first_name, ' ', u.last_name) AS attendee_name
        FROM event_occurrence_attendees eoa
        INNER JOIN users u ON eoa.user_id = u.id
        WHERE eoa.occurrence_id = ?
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $occurrence_id_for_list = (int)$occurrence["id"];
    $attendee_stmt->bind_param("i", $occurrence_id_for_list);
} else {
    $attendee_stmt = $conn->prepare("
        SELECT
            ea.user_id, ea.rsvp_status, ea.attendance_status, ea.attendance_comment,
            CONCAT(u.first_name, ' ', u.last_name) AS attendee_name
        FROM event_attendees ea
        INNER JOIN users u ON ea.user_id = u.id
        WHERE ea.event_id = ?
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $attendee_stmt->bind_param("i", $event_id);
}
$attendee_stmt->execute();
$attendee_result = $attendee_stmt->get_result();
while ($row = $attendee_result->fetch_assoc()) {
    $attendees[] = $row;
}
$attendee_stmt->close();

/* ---------------------------------------------------------
   LOAD HISTORY
--------------------------------------------------------- */
$history = [];

$history_stmt = $conn->prepare("
    SELECT
        eh.action_type,
        eh.occurrence_date,
        eh.field_name,
        eh.old_value,
        eh.new_value,
        eh.changed_at,
        COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'System') AS changed_by_name
    FROM event_history eh
    LEFT JOIN users u ON eh.changed_by = u.id
    WHERE eh.event_id = ?
    ORDER BY eh.changed_at DESC, eh.id DESC
");

$history_stmt->bind_param("i", $event_id);
$history_stmt->execute();

$history_result = $history_stmt->get_result();

while ($row = $history_result->fetch_assoc()) {
    $history[] = $row;
}

$history_stmt->close();

/* ---------------------------------------------------------
   STATUS CLASS
--------------------------------------------------------- */
$status_class = "status-" . strtolower($event["status"]);

/* ---------------------------------------------------------
   RECURRENCE DISPLAY
--------------------------------------------------------- */
$recurrence_display = "Does not repeat";

if ($event["recurrence_type"] === "daily") {
    $recurrence_display = "Every day";
} elseif ($event["recurrence_type"] === "weekly") {
    $recurrence_display = "Every week";
} elseif ($event["recurrence_type"] === "monthly") {
    $recurrence_display = "Every month";
}

/* ---------------------------------------------------------
   REMINDER DISPLAY
--------------------------------------------------------- */
$reminder_display = "None";

if ($event["reminder"] !== "none") {
    $reminder_display = $event["reminder"] . " before";
}

$display_status =
    ($occurrence && $occurrence["status"] === "Cancelled")
        ? "Cancelled"
        : $event["status"];

$display_start_date =
    $occurrence
        ? $occurrence["occurrence_date"]
        : $event["start_date"];

$display_end_date = $event["end_date"];

if ($occurrence) {
    $base_start = new DateTime($event["start_date"]);
    $base_end = new DateTime($event["end_date"]);
    $duration_days = (int)$base_start->diff($base_end)->format("%a");
    $display_end_obj = new DateTime($display_start_date);

    if ($duration_days > 0) {
        $display_end_obj->modify("+" . $duration_days . " days");
    }

    $display_end_date = $display_end_obj->format("Y-m-d");
}

$display_start_time =
    $occurrence
        ? $occurrence["start_time"]
        : $event["start_time"];

$display_end_time =
    $occurrence
        ? $occurrence["end_time"]
        : $event["end_time"];

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>View Event</title>

    <link rel="stylesheet" href="app.css?v=21">

</head>

<body>

<div class="page">

    <div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item">
            <a href="dashboard.php">Dashboard</a>
        </div>
        <div class="sidebar-item">
            <a href="tasks.php">Tasks</a>
        </div>
        <div class="sidebar-item">
            <a href="projects.php">Projects</a>
        </div>
        <div class="sidebar-item">
            <a href="calendar.php">Calendar</a>
        </div>
        <div class="sidebar-item active">
            <a href="events.php">Meetings / Events</a>
        </div>
        <div class="sidebar-item">
            <a href="teams.php">Teams</a>
        </div>
        <div class="sidebar-item">
            <a href="org_chart.php">Organization Chart</a>
        </div>
        <div class="sidebar-item">
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item">
            <a href="analytics.php">Analytics</a>
        </div>
        <div class="sidebar-item">
            <a href="training.php">Training</a>
        </div>
        <div class="sidebar-item">
            <a href="time_off.php">Time Off</a>
        </div>
        <div class="sidebar-item">
            <a href="timesheets.php">Timesheets</a>
        </div>
        <div class="sidebar-item">
            <a href="notifications.php">Notifications</a>
        </div>

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
        <div class="sidebar-item">
            <a href="roles.php">Roles &amp; Permissions</a>
        </div>
        <?php endif; ?>

        <div class="sidebar-bottom">
        <div class="sidebar-item">
            <a href="account.php">Account</a>
        </div>
        <div class="sidebar-item">
            <a href="settings.php">Settings</a>
        </div>
        </div>
    </div>

    <main class="main">

        <div class="header">

            <h1>
                <?php echo htmlspecialchars($event["name"]); ?>
            </h1>

        </div>

        <div class="content">

        <!-- -------------------------------------------------
             BASIC INFORMATION
        -------------------------------------------------- -->

        <div class="card">

            <h2>Event Information</h2>

            <div class="details-grid">

                <div class="detail">
                    <span class="detail-label">Event Name</span>
                    <?php echo htmlspecialchars($event["name"]); ?>
                </div>

                <div class="detail">
                    <span class="detail-label">Type</span>
                    <?php echo htmlspecialchars($event["type"]); ?>
                </div>

                <div class="detail">
                    <span class="detail-label">Organizer</span>
                    <?php echo htmlspecialchars($event["organizer_name"]); ?>
                </div>

                <div class="detail">
                    <span class="detail-label">Department</span>
                    <?php echo htmlspecialchars($event["department"]); ?>
                </div>

                <div class="detail">
                    <span class="detail-label">Location</span>
                    <?php echo htmlspecialchars($event["location"]); ?>
                </div>

                <div class="detail">
                    <span class="detail-label">Status</span>

                    <span class="status <?php echo htmlspecialchars($status_class); ?>">
                        <?php echo htmlspecialchars($display_status); ?>
                    </span>

                </div>

                <div class="detail">

                    <span class="detail-label">Date</span>

                    <?php
                    if ($display_start_date === $display_end_date) {
                        echo htmlspecialchars($display_start_date);
                    } else {
                        echo htmlspecialchars($display_start_date)
                            . " - "
                            . htmlspecialchars($display_end_date);
                    }
                    ?>

                </div>

                <div class="detail">

                    <span class="detail-label">Time</span>

                    <?php
                    echo htmlspecialchars(
                        date("g:i A", strtotime($display_start_time))
                    );
                    ?>

                    -

                    <?php
                    echo htmlspecialchars(
                        date("g:i A", strtotime($display_end_time))
                    );
                    ?>

                </div>

                <div class="detail">

                    <span class="detail-label">Recurrence</span>

                    <?php echo htmlspecialchars($recurrence_display); ?>

                    <?php if ($event["repeat_until"] !== null): ?>

                        until
                        <?php echo htmlspecialchars($event["repeat_until"]); ?>

                    <?php endif; ?>

                </div>

                <div class="detail">

                    <span class="detail-label">Reminder</span>

                    <?php echo htmlspecialchars($reminder_display); ?>

                </div>

            </div>

        </div>

        <!-- -------------------------------------------------
             DESCRIPTION
        -------------------------------------------------- -->

        <div class="card">

            <h2>Description</h2>

            <div class="description">
                <?php echo htmlspecialchars($event["description"]); ?>
            </div>

        </div>

        <!-- -------------------------------------------------
             RSVP
        -------------------------------------------------- -->

        <?php if ($current_rsvp !== null): ?>

            <div class="card">

                <h2>Your RSVP</h2>

                <div class="attendance">
                    Current response:
                    <strong><?php echo htmlspecialchars($current_rsvp); ?></strong>
                </div>

                <?php if ($event["recurrence_type"] !== "none"): ?>
                    <div class="attendance-comment">
                        This response currently applies to the event series.
                    </div>
                <?php endif; ?>

                <?php if ($can_respond): ?>

                    <form method="post" action="view_event.php?id=<?php echo (int)$event_id; ?><?php echo $occurrence_date !== "" ? "&amp;occurrence=" . urlencode($occurrence_date) : ""; ?>">

                        <?php echo csrfField(); ?>

                        <input type="hidden" name="event_id" value="<?php echo (int)$event_id; ?>">

                        <?php if ($occurrence_date !== ""): ?>
                            <input type="hidden" name="occurrence_date" value="<?php echo htmlspecialchars($occurrence_date); ?>">
                        <?php endif; ?>

                        <div class="rsvp-options">

                            <?php foreach ($rsvp_options as $rsvp_option): ?>

                                <label class="rsvp-option">
                                    <input
                                        type="radio"
                                        name="rsvp_status"
                                        value="<?php echo htmlspecialchars($rsvp_option); ?>"
                                        <?php echo $current_rsvp === $rsvp_option ? "checked" : ""; ?>
                                        required
                                    >
                                    <?php echo htmlspecialchars($rsvp_option); ?>
                                </label>

                            <?php endforeach; ?>

                        </div>

                        <button
                            type="submit"
                            name="update_rsvp"
                            value="1"
                            class="button button-primary"
                        >
                            SAVE RSVP
                        </button>

                    </form>

                <?php else: ?>

                    <div class="attendance-comment">
                        RSVP changes are closed for this event.
                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>

        <!-- -------------------------------------------------
             ATTENDEES
        -------------------------------------------------- -->

        <div class="card">

            <h2>Attendees</h2>

            <?php if (empty($attendees)): ?>

                <div class="empty">
                    No attendees found.
                </div>

            <?php else: ?>

                <ul class="attendee-list">

                    <?php foreach ($attendees as $attendee): ?>

                        <li class="attendee">

                            <div class="attendee-name">
                                <?php echo htmlspecialchars($attendee["attendee_name"]); ?>
                            </div>

                            <?php if (
                                $can_view_all_rsvps
                                || (int)$attendee["user_id"] === $user_id
                            ): ?>

                                <div class="attendance">
                                    <?php echo (int)$attendee["user_id"] === $user_id ? "Your RSVP:" : "RSVP:"; ?>
                                    <?php echo htmlspecialchars($attendee["rsvp_status"]); ?>
                                </div>

                            <?php endif; ?>

                            <?php if (
                                $can_view_all_rsvps
                                && !empty($attendee["attendance_comment"])
                            ): ?>

                                <div class="attendance-comment">
                                    Comment:
                                    <?php echo htmlspecialchars($attendee["attendance_comment"]); ?>
                                </div>

                            <?php endif; ?>

                        </li>

                    <?php endforeach; ?>

                </ul>

            <?php endif; ?>

        </div>

        <!-- -------------------------------------------------
             ATTENDANCE TRACKING
        -------------------------------------------------- -->

        <?php if ($can_view_all_rsvps): ?>

            <div class="card">

                <h2>Attendance</h2>

                <?php if ($event["recurrence_type"] !== "none"): ?>

                    <div class="attendance-comment">
                        Attendance for recurring events will be recorded per occurrence when recurring-event attendance is added.
                    </div>

                <?php elseif (!$event_has_ended && $event["status"] !== "Completed"): ?>

                    <div class="attendance-comment">
                        Attendance can be recorded after this event ends.
                    </div>

                <?php elseif ($event["status"] === "Cancelled"): ?>

                    <div class="attendance-comment">
                        Attendance cannot be recorded for a cancelled event.
                    </div>

                <?php elseif (empty($attendees)): ?>

                    <div class="empty">
                        No attendees found.
                    </div>

                <?php else: ?>

                    <ul class="attendee-list">

                        <?php foreach ($attendees as $attendee): ?>

                            <li class="attendee">

                                <div class="attendee-name">
                                    <?php echo htmlspecialchars($attendee["attendee_name"]); ?>
                                </div>

                                <div class="attendance">
                                    Attendance:
                                    <strong><?php echo htmlspecialchars($attendee["attendance_status"] ?: "Not Recorded"); ?></strong>
                                </div>

                                <?php if (!empty($attendee["attendance_comment"])): ?>
                                    <div class="attendance-comment">
                                        Comment:
                                        <?php echo htmlspecialchars($attendee["attendance_comment"]); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($can_manage_attendance): ?>

                                    <form method="post" action="view_event.php?id=<?php echo (int)$event_id; ?><?php echo $occurrence_date !== "" ? "&amp;occurrence=" . urlencode($occurrence_date) : ""; ?>">

                                        <?php echo csrfField(); ?>

                                        <?php if ($occurrence_date !== ""): ?>
                                            <input type="hidden" name="occurrence_date" value="<?php echo htmlspecialchars($occurrence_date); ?>">
                                        <?php endif; ?>

                                        <input
                                            type="hidden"
                                            name="attendance_user_id"
                                            value="<?php echo (int)$attendee["user_id"]; ?>"
                                        >

                                        <div class="rsvp-options">

                                            <?php foreach ($attendance_options as $attendance_option): ?>

                                                <label class="rsvp-option">
                                                    <input
                                                        type="radio"
                                                        name="attendance_status"
                                                        value="<?php echo htmlspecialchars($attendance_option); ?>"
                                                        <?php echo $attendee["attendance_status"] === $attendance_option ? "checked" : ""; ?>
                                                        required
                                                    >
                                                    <?php echo htmlspecialchars($attendance_option); ?>
                                                </label>

                                            <?php endforeach; ?>

                                        </div>

                                        <div class="form-row">
                                            <label>Attendance Comment</label>
                                            <textarea
                                                name="attendance_comment"
                                                rows="2"
                                                maxlength="500"
                                            ><?php echo htmlspecialchars($attendee["attendance_comment"] ?? ""); ?></textarea>
                                        </div>

                                        <button
                                            type="submit"
                                            name="update_attendance"
                                            value="1"
                                            class="button button-primary"
                                        >
                                            SAVE ATTENDANCE
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </li>

                        <?php endforeach; ?>

                    </ul>

                <?php endif; ?>

            </div>

        <?php endif; ?>

        <!-- -------------------------------------------------
             HISTORY
        -------------------------------------------------- -->

        <div class="card">

            <h2>Event History</h2>

            <?php if (empty($history)): ?>

                <div class="empty">
                    No history found.
                </div>

            <?php else: ?>

                <table class="history-table">

                    <thead>

                        <tr>
                            <th>Action</th>
                            <th>Occurrence</th>
                            <th>Field</th>
                            <th>Previous Value</th>
                            <th>New Value</th>
                            <th>Changed By</th>
                            <th>Date / Time</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($history as $entry): ?>

                            <tr>

                                <td>
                                    <?php echo htmlspecialchars($entry["action_type"] ?? "Updated"); ?>
                                </td>

                                <td>
                                    <?php echo $entry["occurrence_date"] ? htmlspecialchars($entry["occurrence_date"]) : "—"; ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($entry["field_name"]); ?>
                                </td>

                                <td class="history-value">
                                    <?php
                                    echo $entry["old_value"] === null
                                        ? "—"
                                        : htmlspecialchars($entry["old_value"]);
                                    ?>
                                </td>

                                <td class="history-value">
                                    <?php
                                    echo $entry["new_value"] === null
                                        ? "—"
                                        : htmlspecialchars($entry["new_value"]);
                                    ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($entry["changed_by_name"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($entry["changed_at"]); ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

        <!-- -------------------------------------------------
             ACTIONS
        -------------------------------------------------- -->

        <div class="actions">

            <a href="comments.php?module=Event&id=<?php echo (int)$event["id"]; ?>" class="button button-secondary">COMMENTS</a>

            <?php
            $can_edit = false;

            if ($role === "Administrator") {
                $can_edit = true;
            } elseif (
                ($role === "Manager" || $role === "IT Administrator") &&
                ($event["department"] === $user_department || (int)$event["organizer"] === $user_id)
            ) {
                $can_edit = true;
            }
            ?>

            <?php if ($can_edit): ?>

                <?php if ($occurrence): ?>

                    <a
                        href="edit_event.php?id=<?php echo (int)$event["id"]; ?>&occurrence=<?php echo urlencode($occurrence_date); ?>&scope=occurrence"
                        class="button button-primary"
                    >
                        EDIT THIS OCCURRENCE
                    </a>

                    <a
                        href="edit_event.php?id=<?php echo (int)$event["id"]; ?>&occurrence=<?php echo urlencode($occurrence_date); ?>&scope=future"
                        class="button button-primary"
                    >
                        EDIT THIS + FUTURE
                    </a>

                    <a
                        href="edit_event.php?id=<?php echo (int)$event["id"]; ?>&occurrence=<?php echo urlencode($occurrence_date); ?>&scope=series"
                        class="button button-primary"
                    >
                        EDIT ENTIRE SERIES
                    </a>

                <?php else: ?>

                    <a
                        href="edit_event.php?id=<?php echo (int)$event["id"]; ?>"
                        class="button button-primary"
                    >
                        EDIT EVENT
                    </a>

                <?php endif; ?>

                <form method="post" style="display:inline-flex; gap:8px; align-items:center;">

                    <?php echo csrfField(); ?>

                    <input type="hidden" name="event_id" value="<?php echo (int)$event_id; ?>">

                    <?php if ($occurrence): ?>
                        <input
                            type="hidden"
                            name="occurrence_date"
                            value="<?php echo htmlspecialchars($occurrence_date); ?>"
                        >

                        <select name="delete_scope" required>
                            <option value="occurrence">This occurrence only</option>
                            <option value="future">This and all future occurrences</option>
                            <option value="series">Entire series</option>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="delete_scope" value="series">
                    <?php endif; ?>

                    <button
                        type="submit"
                        name="delete_event"
                        value="1"
                        class="button button-danger"
                    >
                        DELETE EVENT
                    </button>
                </form>

            <?php endif; ?>

            <a
                href="events.php"
                class="button button-secondary"
            >
                BACK TO EVENTS
            </a>

        </div>

        </div>

    </main>

</div>

</body>

</html>
