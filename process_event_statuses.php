<?php
/*
 * Event finishing #4: automatic completion.
 * CLI only. Schedule this with Windows Task Scheduler (for example every 5 minutes).
 */
if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . DIRECTORY_SEPARATOR . "db.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "notification_helpers.php";

$now = new DateTime();
$now_sql = $now->format("Y-m-d H:i:s");
$completed_occurrences = 0;
$completed_events = 0;

function completionNotificationRecipients(mysqli $conn, int $event_id, int $organizer_id): array {
    $ids = [$organizer_id];

    $stmt = $conn->prepare("
        SELECT user_id
        FROM event_attendees
        WHERE event_id = ?
    ");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row["user_id"];
    }

    $stmt->close();

    return array_values(array_unique(array_filter(array_map("intval", $ids))));
}

function createCompletionNotifications(mysqli $conn, int $event_id, int $organizer_id, string $event_name): void {
    foreach (completionNotificationRecipients($conn, $event_id, $organizer_id) as $recipient_id) {
        createNotification(
            $conn,
            $recipient_id,
            "Event",
            "Event Completed",
            "The event \"" . $event_name . "\" has been marked completed.",
            "Meetings / Events",
            $event_id,
            "view_event.php?id=" . $event_id
        );
    }
}

$conn->begin_transaction();

try {
    /* Complete individual recurring occurrences whose own end time has passed. */
    $occurrence_query = $conn->query("
        SELECT
            eo.id,
            eo.event_id,
            eo.occurrence_date,
            eo.end_time,
            e.end_date,
            e.start_date,
            e.organizer
        FROM event_occurrences eo
        INNER JOIN events e ON e.id = eo.event_id
        WHERE e.deleted = 0
          AND e.status <> 'Cancelled'
          AND eo.status NOT IN ('Cancelled', 'Completed')
    ");

    $complete_occurrence = $conn->prepare("
        UPDATE event_occurrences
        SET status = 'Completed'
        WHERE id = ?
          AND status NOT IN ('Cancelled', 'Completed')
    ");

    $history_occurrence = $conn->prepare("
        INSERT INTO event_history
            (event_id, action_type, occurrence_date, field_name, old_value, new_value, changed_by)
        VALUES (?, 'Auto Completed', ?, 'occurrence_status', 'Scheduled', 'Completed', ?)
    ");

    while ($row = $occurrence_query->fetch_assoc()) {
        $base_start = new DateTime($row["start_date"]);
        $base_end = new DateTime($row["end_date"]);
        $duration_days = (int)$base_start->diff($base_end)->format("%a");

        $occurrence_end_date = new DateTime($row["occurrence_date"]);
        if ($duration_days > 0) {
            $occurrence_end_date->modify("+" . $duration_days . " days");
        }

        $end_time = $row["end_time"] ?: "23:59:59";
        $occurrence_end = new DateTime($occurrence_end_date->format("Y-m-d") . " " . $end_time);

        if ($occurrence_end <= $now) {
            $occurrence_id = (int)$row["id"];
            $event_id = (int)$row["event_id"];
            $occurrence_date = $row["occurrence_date"];

            $complete_occurrence->bind_param("i", $occurrence_id);
            $complete_occurrence->execute();

            if ($complete_occurrence->affected_rows > 0) {
                $system_user_id = null;
                $history_occurrence->bind_param("isi", $event_id, $occurrence_date, $system_user_id);
                $history_occurrence->execute();
                $completed_occurrences++;
            }
        }
    }

    $complete_occurrence->close();
    $history_occurrence->close();

    /* Complete non-recurring events after their end date/time. */
    $single_query = $conn->query("
        SELECT id, name, end_date, end_time, status, organizer
        FROM events
        WHERE deleted = 0
          AND recurrence_type = 'none'
          AND status NOT IN ('Cancelled', 'Completed')
    ");

    $complete_event = $conn->prepare("
        UPDATE events
        SET status = 'Completed'
        WHERE id = ?
          AND status NOT IN ('Cancelled', 'Completed')
    ");

    $history_event = $conn->prepare("
        INSERT INTO event_history
            (event_id, action_type, occurrence_date, field_name, old_value, new_value, changed_by)
        VALUES (?, 'Auto Completed', NULL, 'status', ?, 'Completed', ?)
    ");

    while ($row = $single_query->fetch_assoc()) {
        $event_end = new DateTime(
            $row["end_date"] . " " . ($row["end_time"] ?: "23:59:59")
        );

        if ($event_end <= $now) {
            $event_id = (int)$row["id"];
            $old_status = $row["status"];

            $complete_event->bind_param("i", $event_id);
            $complete_event->execute();

            if ($complete_event->affected_rows > 0) {
                $organizer_id = (int)$row["organizer"];
                $history_changed_by = null;
                $history_event->bind_param("isi", $event_id, $old_status, $history_changed_by);
                $history_event->execute();
                createCompletionNotifications(
                    $conn,
                    $event_id,
                    $organizer_id,
                    $row["name"]
                );
                $completed_events++;
            }
        }
    }

    /* Complete a recurring parent series only once every remaining
       occurrence is finished, AND at least one occurrence was
       actually completed (not just cancelled). */
    $series_query = $conn->query("
        SELECT e.id, e.name, e.status, e.organizer
        FROM events e
        WHERE e.deleted = 0
          AND e.recurrence_type <> 'none'
          AND e.status NOT IN ('Cancelled', 'Completed')
          AND EXISTS (
              SELECT 1 FROM event_occurrences eo WHERE eo.event_id = e.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM event_occurrences eo
              WHERE eo.event_id = e.id
                AND eo.status NOT IN ('Completed', 'Cancelled')
          )
          AND EXISTS (
              SELECT 1
              FROM event_occurrences eo
              WHERE eo.event_id = e.id
                AND eo.status = 'Completed'
          )
    ");

    while ($row = $series_query->fetch_assoc()) {
        $event_id = (int)$row["id"];
        $old_status = $row["status"];

        $complete_event->bind_param("i", $event_id);
        $complete_event->execute();

        if ($complete_event->affected_rows > 0) {
            $organizer_id = (int)$row["organizer"];
            $history_changed_by = null;
            $history_event->bind_param("isi", $event_id, $old_status, $history_changed_by);
            $history_event->execute();
            createCompletionNotifications(
                $conn,
                $event_id,
                $organizer_id,
                $row["name"]
            );
            $completed_events++;
        }
    }

    /* A series where every remaining occurrence was individually
       cancelled (none ever completed) should be marked Cancelled,
       not Completed - a fully-cancelled series was never held. */
    $cancel_series_query = $conn->query("
        SELECT e.id, e.name, e.status, e.organizer
        FROM events e
        WHERE e.deleted = 0
          AND e.recurrence_type <> 'none'
          AND e.status NOT IN ('Cancelled', 'Completed')
          AND EXISTS (
              SELECT 1 FROM event_occurrences eo WHERE eo.event_id = e.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM event_occurrences eo
              WHERE eo.event_id = e.id
                AND eo.status <> 'Cancelled'
          )
    ");

    $cancel_event = $conn->prepare("
        UPDATE events
        SET status = 'Cancelled'
        WHERE id = ?
          AND status NOT IN ('Cancelled', 'Completed')
    ");

    $history_cancel_event = $conn->prepare("
        INSERT INTO event_history
            (event_id, action_type, occurrence_date, field_name, old_value, new_value, changed_by)
        VALUES (?, 'Auto Cancelled', NULL, 'status', ?, 'Cancelled', ?)
    ");

    $cancelled_series = 0;

    while ($row = $cancel_series_query->fetch_assoc()) {
        $event_id = (int)$row["id"];
        $old_status = $row["status"];

        $cancel_event->bind_param("i", $event_id);
        $cancel_event->execute();

        if ($cancel_event->affected_rows > 0) {
            $history_changed_by = null;
            $history_cancel_event->bind_param("isi", $event_id, $old_status, $history_changed_by);
            $history_cancel_event->execute();
            $cancelled_series++;
        }
    }

    $cancel_event->close();
    $history_cancel_event->close();

    $complete_event->close();
    $history_event->close();

    $conn->commit();

    echo "Event status processing complete at {$now_sql}.\n";
    echo "Occurrences completed: {$completed_occurrences}\n";
    echo "Events completed: {$completed_events}\n";
    echo "Series cancelled (all occurrences cancelled): {$cancelled_series}\n";

} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "Event status processing failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
