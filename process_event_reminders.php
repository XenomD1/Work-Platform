<?php
/*
 * Event reminder worker.
 *
 * Run this file from the command line / Windows Task Scheduler only.
 * It sends due event reminders by email and records delivery attempts so
 * a recipient is never sent the same reminder twice after a successful send.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("This reminder worker can only run from the command line.\n");
}

require __DIR__ . DIRECTORY_SEPARATOR . "db.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "notification_helpers.php";

const MAX_REMINDER_ATTEMPTS = 5;

function reminderIntervalSpec($value) {
    switch ($value) {
        case "15 minutes": return "PT15M";
        case "30 minutes": return "PT30M";
        case "1 hour":     return "PT1H";
        case "1 day":      return "P1D";
        default:            return null;
    }
}

function cleanHeaderText($value) {
    return trim(str_replace(["\r", "\n"], " ", (string)$value));
}

function formatEventDateTime(DateTime $start) {
    return $start->format("F j, Y g:i A");
}

function loadRecipients($conn, $eventId, $organizerId, $occurrenceDate = null, $isRecurring = false) {
    $recipients = [];

    /* Organizer always receives the event reminder. */
    $organizerStmt = $conn->prepare("
        SELECT id, first_name, last_name, email
        FROM users
        WHERE id = ?
          AND active = 1
        LIMIT 1
    ");
    $organizerStmt->bind_param("i", $organizerId);
    $organizerStmt->execute();
    $organizer = $organizerStmt->get_result()->fetch_assoc();
    $organizerStmt->close();

    if ($organizer) {
        $recipients[(int)$organizer["id"]] = $organizer;
    }

    /* Invitees receive reminders unless they explicitly answered Not Going. */
    if ($isRecurring && $occurrenceDate !== null) {
        $attendeeStmt = $conn->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email
            FROM event_occurrences eo
            INNER JOIN event_occurrence_attendees eoa ON eoa.occurrence_id = eo.id
            INNER JOIN users u ON u.id = eoa.user_id
            WHERE eo.event_id = ?
              AND eo.occurrence_date = ?
              AND u.active = 1
              AND COALESCE(eoa.rsvp_status, 'No Response') <> 'Not Going'
        ");
        $attendeeStmt->bind_param("is", $eventId, $occurrenceDate);
    } else {
        $attendeeStmt = $conn->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email
            FROM event_attendees ea
            INNER JOIN users u ON u.id = ea.user_id
            WHERE ea.event_id = ?
              AND u.active = 1
              AND COALESCE(ea.rsvp_status, 'No Response') <> 'Not Going'
        ");
        $attendeeStmt->bind_param("i", $eventId);
    }
    $attendeeStmt->execute();
    $result = $attendeeStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recipients[(int)$row["id"]] = $row;
    }

    $attendeeStmt->close();

    return array_values($recipients);
}

function loadReminderRecord($conn, $eventId, $occurrenceDate, $userId, $reminderValue) {
    $stmt = $conn->prepare("
        SELECT id, sent_at, attempt_count
        FROM event_reminder_log
        WHERE event_id = ?
          AND occurrence_date = ?
          AND user_id = ?
          AND reminder_value = ?
        LIMIT 1
    ");
    $stmt->bind_param("isis", $eventId, $occurrenceDate, $userId, $reminderValue);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function ensureReminderRecord($conn, $eventId, $occurrenceDate, $userId, $reminderValue) {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO event_reminder_log
            (event_id, occurrence_date, user_id, reminder_value)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isis", $eventId, $occurrenceDate, $userId, $reminderValue);
    $stmt->execute();
    $stmt->close();
}

function recordReminderResult($conn, $eventId, $occurrenceDate, $userId, $reminderValue, $sent, $error) {
    if ($sent) {
        $stmt = $conn->prepare("
            UPDATE event_reminder_log
            SET sent_at = NOW(),
                attempt_count = attempt_count + 1,
                last_attempt_at = NOW(),
                last_error = NULL
            WHERE event_id = ?
              AND occurrence_date = ?
              AND user_id = ?
              AND reminder_value = ?
        ");
        $stmt->bind_param("isis", $eventId, $occurrenceDate, $userId, $reminderValue);
    } else {
        $error = substr((string)$error, 0, 500);
        $stmt = $conn->prepare("
            UPDATE event_reminder_log
            SET attempt_count = attempt_count + 1,
                last_attempt_at = NOW(),
                last_error = ?
            WHERE event_id = ?
              AND occurrence_date = ?
              AND user_id = ?
              AND reminder_value = ?
        ");
        $stmt->bind_param("sisis", $error, $eventId, $occurrenceDate, $userId, $reminderValue);
    }

    $stmt->execute();
    $stmt->close();
}

function sendReminderEmail($recipient, $event, DateTime $start) {
    $email = trim((string)($recipient["email"] ?? ""));

    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, "User does not have a valid email address."];
    }

    $recipientName = trim(
        (string)($recipient["first_name"] ?? "") . " " .
        (string)($recipient["last_name"] ?? "")
    );

    $eventName = cleanHeaderText($event["name"]);
    $subject = "Event reminder: " . $eventName;

    $body = "Hello" . ($recipientName !== "" ? " " . $recipientName : "") . ",\n\n";
    $body .= "This is a reminder for the following event:\n\n";
    $body .= "Event: " . $eventName . "\n";
    $body .= "Date/Time: " . formatEventDateTime($start) . "\n";

    if (trim((string)$event["location"]) !== "") {
        $body .= "Location: " . trim((string)$event["location"]) . "\n";
    }

    $body .= "\nReminder setting: " . $event["reminder"] . " before.\n";
    $body .= "\nThis message was sent automatically by the Work Management Platform.\n";

    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8"
    ];

    $ok = @mail($email, $subject, $body, implode("\r\n", $headers));

    if (!$ok) {
        return [false, "PHP mail() returned false. Check the mail settings used by PHP/XAMPP."];
    }

    return [true, null];
}

/*
 * Build individual event instances. Non-recurring events use events.start_date;
 * recurring events use event_occurrences so cancelled occurrences are skipped.
 */
$instances = [];

$singleResult = $conn->query("
    SELECT
        e.id,
        e.name,
        e.organizer,
        e.location,
        e.start_date AS occurrence_date,
        e.start_time,
        e.reminder
    FROM events e
    WHERE e.deleted = 0
      AND e.recurrence_type = 'none'
      AND e.status NOT IN ('Cancelled', 'Completed')
      AND e.reminder <> 'none'
      AND e.reminder_delivery = 'email'
      AND e.start_date IS NOT NULL
      AND e.start_time IS NOT NULL
      AND e.start_time <> ''
");

if (!$singleResult) {
    fwrite(STDERR, "Could not load non-recurring reminders: " . $conn->error . PHP_EOL);
    exit(1);
}

while ($row = $singleResult->fetch_assoc()) {
    $instances[] = $row;
}

$recurringResult = $conn->query("
    SELECT
        e.id,
        e.name,
        e.organizer,
        e.location,
        eo.occurrence_date,
        COALESCE(NULLIF(eo.start_time, ''), e.start_time) AS start_time,
        e.reminder,
        e.recurrence_type
    FROM events e
    INNER JOIN event_occurrences eo ON eo.event_id = e.id
    WHERE e.deleted = 0
      AND e.recurrence_type <> 'none'
      AND e.status NOT IN ('Cancelled', 'Completed')
      AND eo.status <> 'Cancelled'
      AND e.reminder <> 'none'
      AND e.reminder_delivery = 'email'
      AND eo.occurrence_date IS NOT NULL
      AND COALESCE(NULLIF(eo.start_time, ''), e.start_time) IS NOT NULL
");

if (!$recurringResult) {
    fwrite(STDERR, "Could not load recurring reminders: " . $conn->error . PHP_EOL);
    exit(1);
}

while ($row = $recurringResult->fetch_assoc()) {
    $instances[] = $row;
}

$now = new DateTime();
$sentCount = 0;
$failedCount = 0;
$skippedCount = 0;

foreach ($instances as $event) {
    $intervalSpec = reminderIntervalSpec($event["reminder"]);

    if ($intervalSpec === null) {
        continue;
    }

    $start = DateTime::createFromFormat(
        "Y-m-d H:i:s",
        $event["occurrence_date"] . " " . $event["start_time"]
    );

    if (!$start) {
        $start = DateTime::createFromFormat(
            "Y-m-d H:i",
            $event["occurrence_date"] . " " . $event["start_time"]
        );
    }

    if (!$start) {
        $skippedCount++;
        continue;
    }

    /*
     * Previously this skipped the reminder entirely once the event
     * started, meaning a reminder due shortly before the event could
     * be permanently lost if this script's next scheduled run happened
     * to land after the event's start time (e.g. a "15 minutes before"
     * reminder with a 30-minute Task Scheduler interval).
     *
     * Instead, allow sending up to a generous grace window after the
     * event started - late is still useful, but a reminder shouldn't
     * fire for something that happened long ago (e.g. after the
     * script was broken or the scheduler was off for a while).
     */
    $reminderGraceEnd = clone $start;
    $reminderGraceEnd->add(new DateInterval("PT2H"));

    if ($now >= $reminderGraceEnd) {
        continue;
    }

    $reminderAt = clone $start;
    $reminderAt->sub(new DateInterval($intervalSpec));

    /* Not due yet. */
    if ($now < $reminderAt) {
        continue;
    }

    $eventId = (int)$event["id"];
    $organizerId = (int)$event["organizer"];
    $occurrenceDate = $event["occurrence_date"];
    $reminderValue = $event["reminder"];

    $recipients = loadRecipients(
        $conn,
        $eventId,
        $organizerId,
        $event["occurrence_date"],
        $event["recurrence_type"] !== "none"
    );

    foreach ($recipients as $recipient) {
        $recipientId = (int)$recipient["id"];

        ensureReminderRecord(
            $conn,
            $eventId,
            $occurrenceDate,
            $recipientId,
            $reminderValue
        );

        $log = loadReminderRecord(
            $conn,
            $eventId,
            $occurrenceDate,
            $recipientId,
            $reminderValue
        );

        if (!$log) {
            $failedCount++;
            continue;
        }

        if (!empty($log["sent_at"])) {
            continue;
        }

        if ((int)$log["attempt_count"] >= MAX_REMINDER_ATTEMPTS) {
            $skippedCount++;
            continue;
        }

        [$sent, $error] = sendReminderEmail($recipient, $event, $start);

        recordReminderResult(
            $conn,
            $eventId,
            $occurrenceDate,
            $recipientId,
            $reminderValue,
            $sent,
            $error
        );

        if ($sent) {
            $notification_url = "view_event.php?id=" . $eventId;

            if (($event["recurrence_type"] ?? "none") !== "none") {
                $notification_url .= "&occurrence=" . urlencode($occurrenceDate);
            }

            createNotification(
                $conn,
                $recipientId,
                "Event",
                "Event Reminder",
                "Reminder: \"" . $event["name"] . "\" starts "
                    . formatEventDateTime($start) . ".",
                "Meetings / Events",
                $eventId,
                $notification_url,
                "Event Reminders"
            );

            $sentCount++;
            echo "Sent reminder for event #{$eventId} to user #{$recipientId}." . PHP_EOL;
        } else {
            $failedCount++;
            fwrite(
                STDERR,
                "Reminder failed for event #{$eventId}, user #{$recipientId}: {$error}" . PHP_EOL
            );
        }
    }
}

echo "Reminder run complete. Sent: {$sentCount}; Failed: {$failedCount}; Skipped: {$skippedCount}." . PHP_EOL;
