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

$current_user_id = (int)$_SESSION["user_id"];

/* ---------------------------------------------------------
   GET EVENT ID
--------------------------------------------------------- */
$event_id = (int)($_GET["id"] ?? $_POST["event_id"] ?? 0);

if ($event_id <= 0) {
    header("Location: events.php");
    exit;
}

/* ---------------------------------------------------------
   CURRENT USER
--------------------------------------------------------- */
$user_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
    LIMIT 1
");

$user_stmt->bind_param("i", $current_user_id);
$user_stmt->execute();

$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$current_role = $current_user["role"];
$current_department = $current_user["department"];

/* ---------------------------------------------------------
   LOAD EVENT
--------------------------------------------------------- */
$event_stmt = $conn->prepare("
    SELECT
        id,
        name,
        description,
        type,
        organizer,
        department,
        location,
        start_date,
        end_date,
        start_time,
        end_time,
        status,
        recurrence_type,
        repeat_until,
        reminder
    FROM events
    WHERE id = ?
    LIMIT 1
");

$event_stmt->bind_param("i", $event_id);
$event_stmt->execute();

$event = $event_stmt->get_result()->fetch_assoc();
$event_stmt->close();

if (!$event) {
    header("Location: events.php");
    exit;
}

/* ---------------------------------------------------------
   RECURRING OCCURRENCE CONTEXT
--------------------------------------------------------- */
$occurrence_date = trim(
    $_GET["occurrence"]
    ?? $_POST["occurrence_date"]
    ?? ""
);

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
        header("Location: view_event.php?id=" . $event_id);
        exit;
    }
}

/* ---------------------------------------------------------
   PERMISSION CHECK
--------------------------------------------------------- */

$is_admin = ($current_role === "Administrator");
$is_manager = ($current_role === "Manager");
$is_it_admin = ($current_role === "IT Administrator");

if (!$is_admin && !$is_manager && !$is_it_admin) {
    header("Location: view_event.php?id=" . $event_id);
    exit;
}

/*
   Administrators can edit any event.

   Managers and IT Administrators can edit events belonging to
   their own department, or events they personally organize
   regardless of department - matching the visibility rule in
   events.php.
*/
if (
    ($is_manager || $is_it_admin) &&
    $event["department"] !== $current_department &&
    (int)$event["organizer"] !== $current_user_id
) {
    header("Location: view_event.php?id=" . $event_id);
    exit;
}

/* ---------------------------------------------------------
   LOAD ACTIVE USERS
--------------------------------------------------------- */
$users = [];

$user_list_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE active = 1
    ORDER BY first_name ASC, last_name ASC
");

$user_list_stmt->execute();

$user_result = $user_list_stmt->get_result();

while ($row = $user_result->fetch_assoc()) {
    $users[] = $row;
}

$user_list_stmt->close();

/* ---------------------------------------------------------
   LOAD EXISTING ATTENDEES
--------------------------------------------------------- */
$existing_attendees = [];

$attendee_stmt = $conn->prepare("
    SELECT user_id
    FROM event_attendees
    WHERE event_id = ?
");

$attendee_stmt->bind_param("i", $event_id);
$attendee_stmt->execute();

$attendee_result = $attendee_stmt->get_result();

while ($row = $attendee_result->fetch_assoc()) {
    $existing_attendees[] = (int)$row["user_id"];
}

$attendee_stmt->close();

/* ---------------------------------------------------------
   DEPARTMENTS
--------------------------------------------------------- */

$departments = [];

$department_result = $conn->query("
    SELECT DISTINCT department
    FROM users
    WHERE department IS NOT NULL
      AND department <> ''
    ORDER BY department ASC
");

if ($department_result) {
    while ($row = $department_result->fetch_assoc()) {
        $departments[] = $row["department"];
    }
}

/* ---------------------------------------------------------
   EVENT TYPES
--------------------------------------------------------- */

$event_types = [
    "Department Meeting",
    "Organization-Wide Event",
    "Team Meeting",
    "Training",
    "Workshop",
    "Presentation",
    "Conference",
    "Planning Session",
    "Staff Development",
    "Orientation",
    "Other"
];

/* ---------------------------------------------------------
   STATUSES
--------------------------------------------------------- */

$statuses = [
    "Planned",
    "Confirmed",
    "Cancelled",
    "Completed"
];

/* ---------------------------------------------------------
   RECURRENCE TYPES
--------------------------------------------------------- */

$recurrence_types = [
    "none" => "Does not repeat",
    "daily" => "Every Day",
    "weekly" => "Every Week",
    "monthly" => "Every Month"
];

/* ---------------------------------------------------------
   REMINDERS
--------------------------------------------------------- */

$reminders = [
    "none" => "None",
    "15 minutes" => "15 minutes before",
    "30 minutes" => "30 minutes before",
    "1 hour" => "1 hour before",
    "1 day" => "1 day before"
];

/* ---------------------------------------------------------
   FORM VALUES
--------------------------------------------------------- */

$name = $event["name"];
$description = $event["description"];
$type = $event["type"];
$organizer = (int)$event["organizer"];
$department = $event["department"];
$location = $event["location"];
$start_date = $event["start_date"];
$end_date = $event["end_date"];
$start_time = $event["start_time"];
$end_time = $event["end_time"];
$status = $event["status"];
$recurrence_type = $event["recurrence_type"];
$repeat_until = $event["repeat_until"];
$reminder = $event["reminder"];

$edit_scope = $occurrence
    ? trim($_GET["scope"] ?? $_POST["edit_scope"] ?? "occurrence")
    : "series";

if ($occurrence && $edit_scope !== "series") {

    $base_start = new DateTime($event["start_date"]);
    $base_end = new DateTime($event["end_date"]);
    $duration_days = (int)$base_start->diff($base_end)->format("%a");

    $start_date = $occurrence["occurrence_date"];
    $end_date_obj = new DateTime($start_date);

    if ($duration_days > 0) {
        $end_date_obj->modify("+" . $duration_days . " days");
    }

    $end_date = $end_date_obj->format("Y-m-d");
    $start_time = $occurrence["start_time"];
    $end_time = $occurrence["end_time"];

    if ($occurrence["status"] === "Cancelled") {
        $status = "Cancelled";
    }
}

$selected_attendees = $existing_attendees;

$errors = [];

/* ---------------------------------------------------------
   PROCESS FORM
--------------------------------------------------------- */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $type = trim($_POST["type"] ?? "");
    $organizer = (int)($_POST["organizer"] ?? 0);
    $department = trim($_POST["department"] ?? "");
    $location = trim($_POST["location"] ?? "");
    $start_date = $_POST["start_date"] ?? "";
    $end_date = $_POST["end_date"] ?? "";
    $start_time = $_POST["start_time"] ?? "";
    $end_time = $_POST["end_time"] ?? "";
    $status = trim($_POST["status"] ?? "");
    $recurrence_type = trim($_POST["recurrence_type"] ?? "none");
    $repeat_until = trim($_POST["repeat_until"] ?? "");
    $reminder = trim($_POST["reminder"] ?? "none");
    $edit_scope = trim($_POST["edit_scope"] ?? "series");

    $selected_attendees = isset($_POST["attendees"])
        ? array_map("intval", $_POST["attendees"])
        : [];

    /* -----------------------------------------------------
       VALIDATION
    ----------------------------------------------------- */

    if ($name === "") {
        $errors[] = "Event name is required.";
    }

    if ($description === "") {
        $errors[] = "Description is required.";
    }

    if ($type === "") {
        $errors[] = "Event type is required.";
    }

    if ($organizer <= 0) {
        $errors[] = "Organizer is required.";
    }

    if ($department === "") {
        $errors[] = "Department is required.";
    }

    if ($location === "") {
        $errors[] = "Location is required.";
    }

    if ($start_date === "") {
        $errors[] = "Start date is required.";
    }

    if ($end_date === "") {
        $errors[] = "End date is required.";
    }

    if ($start_time === "") {
        $errors[] = "Start time is required.";
    }

    if ($end_time === "") {
        $errors[] = "End time is required.";
    }

    if ($status === "") {
        $errors[] = "Status is required.";
    }

    if (count($selected_attendees) === 0) {
        $errors[] = "At least one attendee is required.";
    }

    /* -----------------------------------------------------
       DATE VALIDATION
    ----------------------------------------------------- */

    if ($start_date !== "" && $end_date !== "") {

        if ($end_date < $start_date) {
            $errors[] = "End date cannot be before the start date.";
        }
    }

    /* -----------------------------------------------------
       TIME VALIDATION
    ----------------------------------------------------- */

    if (
        $start_date !== "" &&
        $end_date !== "" &&
        $start_time !== "" &&
        $end_time !== ""
    ) {

        if ($start_date === $end_date && $end_time <= $start_time) {
            $errors[] = "End time must be after start time.";
        }
    }

    /* -----------------------------------------------------
       RECURRENCE VALIDATION
    ----------------------------------------------------- */

    if (!array_key_exists($recurrence_type, $recurrence_types)) {
        $errors[] = "Invalid recurrence selection.";
    }

    if ($recurrence_type === "none") {
        $repeat_until = null;
    } else {

        if ($repeat_until === "") {
            $errors[] = "Repeat Until is required for recurring events.";
        } elseif ($repeat_until < $start_date) {
            $errors[] = "Repeat Until cannot be before the start date.";
        }
    }

    /* -----------------------------------------------------
       RECURRING EDIT SCOPE VALIDATION
    ----------------------------------------------------- */
    if ($occurrence) {
        $valid_edit_scopes = ["occurrence", "future", "series"];

        if (!in_array($edit_scope, $valid_edit_scopes, true)) {
            $errors[] = "Invalid recurring event edit option.";
        }
    } else {
        $edit_scope = "series";
    }

    /* -----------------------------------------------------
       STATUS VALIDATION
    ----------------------------------------------------- */

    if (!in_array($status, $statuses, true)) {
        $errors[] = "Invalid event status.";
    }

    /* -----------------------------------------------------
       TYPE VALIDATION
    ----------------------------------------------------- */

    if (!in_array($type, $event_types, true)) {
        $errors[] = "Invalid event type.";
    }

    /* -----------------------------------------------------
       MANAGER DEPARTMENT RESTRICTION
    ----------------------------------------------------- */

    if (
        ($is_manager || $is_it_admin) &&
        $department !== $current_department &&
        (int)$event["organizer"] !== $current_user_id
    ) {
        $errors[] = "You can only edit events belonging to your own department.";
    }

    /* -----------------------------------------------------
       ORGANIZER VALIDATION
    ----------------------------------------------------- */

    $organizer_check_stmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE id = ?
          AND active = 1
        LIMIT 1
    ");

    $organizer_check_stmt->bind_param("i", $organizer);
    $organizer_check_stmt->execute();

    $organizer_exists = $organizer_check_stmt
        ->get_result()
        ->fetch_assoc();

    $organizer_check_stmt->close();

    if (!$organizer_exists) {
        $errors[] = "Selected organizer is invalid.";
    }

    /* -----------------------------------------------------
       ATTENDEE VALIDATION
    ----------------------------------------------------- */

    if (count($selected_attendees) > 0) {

        $selected_attendees = array_values(
            array_unique($selected_attendees)
        );

        $placeholders = implode(
            ",",
            array_fill(0, count($selected_attendees), "?")
        );

        $types_string = str_repeat("i", count($selected_attendees));

        $attendee_check_sql = "
            SELECT id
            FROM users
            WHERE active = 1
              AND id IN ($placeholders)
        ";

        $attendee_check_stmt = $conn->prepare($attendee_check_sql);

        $bind_values = [$types_string];

        foreach ($selected_attendees as $key => $value) {
            $bind_values[] = $selected_attendees[$key];
        }

        $references = [];

        foreach ($bind_values as $key => $value) {
            $references[$key] = &$bind_values[$key];
        }

        call_user_func_array(
            [$attendee_check_stmt, "bind_param"],
            $references
        );

        $attendee_check_stmt->execute();

        $valid_attendee_count =
            $attendee_check_stmt->get_result()->num_rows;

        $attendee_check_stmt->close();

        if ($valid_attendee_count !== count($selected_attendees)) {
            $errors[] = "One or more selected attendees are invalid.";
        }
    }

    /* -----------------------------------------------------
       UPDATE DATABASE
    ----------------------------------------------------- */

    if (empty($errors)) {

        $conn->begin_transaction();

        try {

            /* ---------------------------------------------
               LOAD ORIGINAL VALUES
            --------------------------------------------- */

            $original = $event;

            /*
             * Recurring occurrence edits:
             * - occurrence: replace only this occurrence with a standalone event.
             * - future: split the series here and create a new future series.
             * - series: continue into the normal whole-series update below.
             */
            if ($occurrence && $edit_scope !== "series") {

                /* Preserve per-occurrence RSVP/attendance before rows are removed/split. */
                $preserved_occurrence_attendees = [];
                $preserve_sql = "
                    SELECT eo.occurrence_date, eoa.user_id, eoa.rsvp_status,
                           eoa.attendance_status, eoa.attendance_comment
                    FROM event_occurrences eo
                    INNER JOIN event_occurrence_attendees eoa
                        ON eoa.occurrence_id = eo.id
                    WHERE eo.event_id = ?
                      AND eo.occurrence_date " . ($edit_scope === "future" ? ">=" : "=") . " ?
                ";
                $preserve_stmt = $conn->prepare($preserve_sql);
                $preserve_stmt->bind_param("is", $event_id, $occurrence_date);
                $preserve_stmt->execute();
                $preserve_result = $preserve_stmt->get_result();
                while ($preserved = $preserve_result->fetch_assoc()) {
                    $preserved_occurrence_attendees[$preserved["occurrence_date"]][(int)$preserved["user_id"]] = $preserved;
                }
                $preserve_stmt->close();

                $repeat_until_db =
                    ($repeat_until === "") ? null : $repeat_until;

                $new_recurrence_type =
                    ($edit_scope === "occurrence")
                        ? "none"
                        : $recurrence_type;

                $new_repeat_until =
                    ($new_recurrence_type === "none")
                        ? null
                        : $repeat_until_db;

                if ($edit_scope === "occurrence") {

                    $remove_occurrence_stmt = $conn->prepare("
                        DELETE FROM event_occurrences
                        WHERE event_id = ?
                          AND occurrence_date = ?
                    ");
                    $remove_occurrence_stmt->bind_param(
                        "is",
                        $event_id,
                        $occurrence_date
                    );
                    $remove_occurrence_stmt->execute();
                    $remove_occurrence_stmt->close();

                } else {

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

                        $delete_old_occurrences = $conn->prepare("
                            DELETE FROM event_occurrences
                            WHERE event_id = ?
                        ");
                        $delete_old_occurrences->bind_param("i", $event_id);
                        $delete_old_occurrences->execute();
                        $delete_old_occurrences->close();

                        $soft_delete_old = $conn->prepare("
                            UPDATE events
                            SET deleted = 1,
                                deleted_at = NOW(),
                                deleted_by = ?
                            WHERE id = ?
                        ");
                        $soft_delete_old->bind_param(
                            "ii",
                            $current_user_id,
                            $event_id
                        );
                        $soft_delete_old->execute();
                        $soft_delete_old->close();

                    } else {

                        $shorten_stmt = $conn->prepare("
                            UPDATE events
                            SET repeat_until = ?
                            WHERE id = ?
                        ");
                        $shorten_stmt->bind_param(
                            "si",
                            $previous_date,
                            $event_id
                        );
                        $shorten_stmt->execute();
                        $shorten_stmt->close();

                        $remove_future_stmt = $conn->prepare("
                            DELETE FROM event_occurrences
                            WHERE event_id = ?
                              AND occurrence_date >= ?
                        ");
                        $remove_future_stmt->bind_param(
                            "is",
                            $event_id,
                            $occurrence_date
                        );
                        $remove_future_stmt->execute();
                        $remove_future_stmt->close();
                    }
                }

                $new_event_stmt = $conn->prepare("
                    INSERT INTO events
                    (
                        name,
                        description,
                        type,
                        organizer,
                        department,
                        location,
                        start_date,
                        end_date,
                        start_time,
                        end_time,
                        status,
                        recurrence_type,
                        repeat_until,
                        reminder,
                        reminder_delivery
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'email')
                ");

                $new_event_stmt->bind_param(
                    "sssissssssssss",
                    $name,
                    $description,
                    $type,
                    $organizer,
                    $department,
                    $location,
                    $start_date,
                    $end_date,
                    $start_time,
                    $end_time,
                    $status,
                    $new_recurrence_type,
                    $new_repeat_until,
                    $reminder
                );
                $new_event_stmt->execute();
                $new_event_id = $conn->insert_id;
                $new_event_stmt->close();

                $new_attendee_stmt = $conn->prepare("
                    INSERT INTO event_attendees
                    (
                        event_id,
                        user_id,
                        rsvp_status,
                        attendance_status,
                        attendance_comment
                    )
                    VALUES (?, ?, 'No Response', 'Not Recorded', NULL)
                ");

                foreach ($selected_attendees as $attendee_id) {
                    $new_attendee_stmt->bind_param(
                        "ii",
                        $new_event_id,
                        $attendee_id
                    );
                    $new_attendee_stmt->execute();
                }
                $new_attendee_stmt->close();

                if ($edit_scope === "occurrence" && $occurrence) {
                    $update_split_attendee = $conn->prepare("
                        UPDATE event_attendees
                        SET rsvp_status = ?, attendance_status = ?, attendance_comment = ?
                        WHERE event_id = ? AND user_id = ?
                    ");
                    foreach ($selected_attendees as $split_user_id) {
                        $saved = $preserved_occurrence_attendees[$occurrence_date][$split_user_id] ?? null;
                        if (!$saved) {
                            continue;
                        }
                        $saved_comment = $saved["attendance_comment"];
                        $update_split_attendee->bind_param(
                            "sssii",
                            $saved["rsvp_status"],
                            $saved["attendance_status"],
                            $saved_comment,
                            $new_event_id,
                            $split_user_id
                        );
                        $update_split_attendee->execute();
                    }
                    $update_split_attendee->close();
                }

                $history_stmt = $conn->prepare("
                    INSERT INTO event_history
                    (
                        event_id,
                        action_type,
                        occurrence_date,
                        field_name,
                        old_value,
                        new_value,
                        changed_by
                    )
                    VALUES (?, 'Recurring Edit', ?, ?, ?, ?, ?)
                ");

                $history_field = "recurring_edit_scope";
                $history_old = $event_id . ":" . $occurrence_date;
                $history_new = $edit_scope;

                $history_stmt->bind_param(
                    "issssi",
                    $new_event_id,
                    $occurrence_date,
                    $history_field,
                    $history_old,
                    $history_new,
                    $current_user_id
                );
                $history_stmt->execute();
                $history_stmt->close();

                if (
                    $new_recurrence_type !== "none"
                    && $new_repeat_until !== null
                ) {
                    $occurrence_dates = [];
                    $current_date = new DateTime($start_date);
                    $until_date = new DateTime($new_repeat_until);

                    while ($current_date <= $until_date) {
                        $occurrence_dates[] =
                            $current_date->format("Y-m-d");

                        if ($new_recurrence_type === "daily") {
                            $current_date->modify("+1 day");
                        } elseif ($new_recurrence_type === "weekly") {
                            $current_date->modify("+1 week");
                        } elseif ($new_recurrence_type === "monthly") {
                            $current_date->modify("+1 month");
                        } else {
                            break;
                        }
                    }

                    $new_occurrence_stmt = $conn->prepare("
                        INSERT INTO event_occurrences
                        (
                            event_id,
                            occurrence_date,
                            start_time,
                            end_time,
                            status
                        )
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    $new_occurrence_status =
                        ($status === "Cancelled")
                            ? "Cancelled"
                            : "Scheduled";

                    foreach ($occurrence_dates as $new_occurrence_date) {
                        $new_occurrence_stmt->bind_param(
                            "issss",
                            $new_event_id,
                            $new_occurrence_date,
                            $start_time,
                            $end_time,
                            $new_occurrence_status
                        );
                        $new_occurrence_stmt->execute();
                        $created_occurrence_id = $conn->insert_id;

                        $seed_occurrence_stmt = $conn->prepare("
                            INSERT INTO event_occurrence_attendees
                                (occurrence_id, user_id, rsvp_status, attendance_status, attendance_comment)
                            SELECT ?, ea.user_id, ea.rsvp_status, 'Not Recorded', NULL
                            FROM event_attendees ea
                            WHERE ea.event_id = ?
                        ");
                        $seed_occurrence_stmt->bind_param("ii", $created_occurrence_id, $new_event_id);
                        $seed_occurrence_stmt->execute();
                        $seed_occurrence_stmt->close();

                        if (!empty($preserved_occurrence_attendees[$new_occurrence_date])) {
                            $restore_occurrence_attendee = $conn->prepare("
                                UPDATE event_occurrence_attendees
                                SET rsvp_status = ?, attendance_status = ?, attendance_comment = ?
                                WHERE occurrence_id = ? AND user_id = ?
                            ");
                            foreach ($preserved_occurrence_attendees[$new_occurrence_date] as $saved_user_id => $saved) {
                                if (!in_array((int)$saved_user_id, $selected_attendees, true)) {
                                    continue;
                                }
                                $saved_comment = $saved["attendance_comment"];
                                $restore_occurrence_attendee->bind_param(
                                    "sssii",
                                    $saved["rsvp_status"],
                                    $saved["attendance_status"],
                                    $saved_comment,
                                    $created_occurrence_id,
                                    $saved_user_id
                                );
                                $restore_occurrence_attendee->execute();
                            }
                            $restore_occurrence_attendee->close();
                        }
                    }

                    $new_occurrence_stmt->close();
                }

                $conn->commit();

                foreach ($selected_attendees as $recipient_id) {
                    $recipient_id = (int)$recipient_id;

                    if ($recipient_id <= 0 || $recipient_id === $current_user_id) {
                        continue;
                    }

                    createNotification(
                        $conn,
                        $recipient_id,
                        "Event",
                        "Event Occurrence Updated",
                        "An occurrence of \"" . $event["name"] . "\" was updated.",
                        "Meetings / Events",
                        $new_event_id,
                        "view_event.php?id=" . $new_event_id
                    );
                }

                if (
                    (int)$organizer > 0
                    && (int)$organizer !== $current_user_id
                    && !in_array((int)$organizer, $selected_attendees, true)
                ) {
                    createNotification(
                        $conn,
                        (int)$organizer,
                        "Event",
                        "Event Organizer Assignment",
                        "You were assigned as the organizer for \"" . $name . "\".",
                        "Meetings / Events",
                        $new_event_id,
                        "view_event.php?id=" . $new_event_id
                    );
                }

                header(
                    "Location: view_event.php?id="
                    . $new_event_id
                    . (
                        $new_recurrence_type !== "none"
                            ? "&occurrence=" . urlencode($start_date)
                            : ""
                    )
                );
                exit;
            }

            /* ---------------------------------------------
               UPDATE EVENT
            --------------------------------------------- */

            $update_stmt = $conn->prepare("
                UPDATE events
                SET
                    name = ?,
                    description = ?,
                    type = ?,
                    organizer = ?,
                    department = ?,
                    location = ?,
                    start_date = ?,
                    end_date = ?,
                    start_time = ?,
                    end_time = ?,
                    status = ?,
                    recurrence_type = ?,
                    repeat_until = ?,
                    reminder = ?
                WHERE id = ?
            ");

            $repeat_until_db =
                ($repeat_until === "") ? null : $repeat_until;

            $update_stmt->bind_param(
                "sssissssssssssi",
                $name,
                $description,
                $type,
                $organizer,
                $department,
                $location,
                $start_date,
                $end_date,
                $start_time,
                $end_time,
                $status,
                $recurrence_type,
                $repeat_until_db,
                $reminder,
                $event_id
            );

            $update_stmt->execute();
            $update_stmt->close();

            /* ---------------------------------------------
               HISTORY HELPER
            --------------------------------------------- */

            $history_stmt = $conn->prepare("
                INSERT INTO event_history
                (
                    event_id,
                    field_name,
                    old_value,
                    new_value,
                    changed_by
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            /*
             * Record only fields that actually changed.
             */

            $changes = [
                "name" => [
                    (string)$original["name"],
                    (string)$name
                ],

                "description" => [
                    (string)$original["description"],
                    (string)$description
                ],

                "type" => [
                    (string)$original["type"],
                    (string)$type
                ],

                "organizer" => [
                    (string)$original["organizer"],
                    (string)$organizer
                ],

                "department" => [
                    (string)$original["department"],
                    (string)$department
                ],

                "location" => [
                    (string)$original["location"],
                    (string)$location
                ],

                "start_date" => [
                    (string)$original["start_date"],
                    (string)$start_date
                ],

                "end_date" => [
                    (string)$original["end_date"],
                    (string)$end_date
                ],

                "start_time" => [
                    (string)$original["start_time"],
                    (string)$start_time
                ],

                "end_time" => [
                    (string)$original["end_time"],
                    (string)$end_time
                ],

                "status" => [
                    (string)$original["status"],
                    (string)$status
                ],

                "recurrence_type" => [
                    (string)$original["recurrence_type"],
                    (string)$recurrence_type
                ],

                "repeat_until" => [
                    (string)($original["repeat_until"] ?? ""),
                    (string)($repeat_until_db ?? "")
                ],

                "reminder" => [
                    (string)$original["reminder"],
                    (string)$reminder
                ]
            ];

            foreach ($changes as $field => $values) {

                if ($values[0] !== $values[1]) {

                    $old_value = $values[0];
                    $new_value = $values[1];

                    $history_stmt->bind_param(
                        "isssi",
                        $event_id,
                        $field,
                        $old_value,
                        $new_value,
                        $current_user_id
                    );

                    $history_stmt->execute();
                }
            }

            $history_stmt->close();

            /* ---------------------------------------------
               ATTENDEE CHANGES
            --------------------------------------------- */

            $old_attendees = $existing_attendees;
            $new_attendees = $selected_attendees;

            sort($old_attendees);
            sort($new_attendees);

            if ($old_attendees !== $new_attendees) {

                /* Remove only people who are no longer invited. */
                $remove_attendee_stmt = $conn->prepare("
                    DELETE FROM event_attendees
                    WHERE event_id = ? AND user_id = ?
                ");
                foreach (array_diff($old_attendees, $new_attendees) as $removed_attendee_id) {
                    $remove_attendee_stmt->bind_param("ii", $event_id, $removed_attendee_id);
                    $remove_attendee_stmt->execute();
                }
                $remove_attendee_stmt->close();

                /* Add only new people. Existing attendee RSVP/attendance is preserved. */
                $insert_attendee_stmt = $conn->prepare("
                    INSERT IGNORE INTO event_attendees
                    (event_id, user_id, rsvp_status, attendance_status, attendance_comment)
                    VALUES (?, ?, 'No Response', 'Not Recorded', NULL)
                ");
                foreach (array_diff($new_attendees, $old_attendees) as $added_attendee_id) {
                    $insert_attendee_stmt->bind_param("ii", $event_id, $added_attendee_id);
                    $insert_attendee_stmt->execute();
                }
                $insert_attendee_stmt->close();

                /* History entry for attendee change */

                $old_attendee_text = implode(
                    ", ",
                    $old_attendees
                );

                $new_attendee_text = implode(
                    ", ",
                    $new_attendees
                );

                $field = "attendees";

                $history_attendee_stmt = $conn->prepare("
                    INSERT INTO event_history
                    (
                        event_id,
                        field_name,
                        old_value,
                        new_value,
                        changed_by
                    )
                    VALUES (?, ?, ?, ?, ?)
                ");

                $history_attendee_stmt->bind_param(
                    "isssi",
                    $event_id,
                    $field,
                    $old_attendee_text,
                    $new_attendee_text,
                    $current_user_id
                );

                $history_attendee_stmt->execute();
                $history_attendee_stmt->close();
            }

            /* ---------------------------------------------
               REBUILD OCCURRENCES
            --------------------------------------------- */

            /* Preserve per-occurrence responses/attendance for dates that survive the rebuild. */
            $preserved_series_attendees = [];
            $preserve_series_stmt = $conn->prepare("
                SELECT eo.occurrence_date, eoa.user_id, eoa.rsvp_status,
                       eoa.attendance_status, eoa.attendance_comment
                FROM event_occurrences eo
                INNER JOIN event_occurrence_attendees eoa
                    ON eoa.occurrence_id = eo.id
                WHERE eo.event_id = ?
            ");
            $preserve_series_stmt->bind_param("i", $event_id);
            $preserve_series_stmt->execute();
            $preserve_series_result = $preserve_series_stmt->get_result();
            while ($saved = $preserve_series_result->fetch_assoc()) {
                $preserved_series_attendees[$saved["occurrence_date"]][(int)$saved["user_id"]] = $saved;
            }
            $preserve_series_stmt->close();

            $delete_occurrences_stmt = $conn->prepare("
                DELETE FROM event_occurrences
                WHERE event_id = ?
            ");

            $delete_occurrences_stmt->bind_param(
                "i",
                $event_id
            );

            $delete_occurrences_stmt->execute();
            $delete_occurrences_stmt->close();

            /* ---------------------------------------------
               CREATE OCCURRENCES
            --------------------------------------------- */

            $occurrence_dates = [];

            if ($recurrence_type === "none") {

                $occurrence_dates[] = $start_date;

            } else {

                $current_date = new DateTime($start_date);
                $until_date = new DateTime($repeat_until_db);

                while ($current_date <= $until_date) {

                    $occurrence_dates[] =
                        $current_date->format("Y-m-d");

                    if ($recurrence_type === "daily") {

                        $current_date->modify("+1 day");

                    } elseif ($recurrence_type === "weekly") {

                        $current_date->modify("+1 week");

                    } elseif ($recurrence_type === "monthly") {

                        $current_date->modify("+1 month");

                    } else {
                        break;
                    }
                }
            }

            $insert_occurrence_stmt = $conn->prepare("
                INSERT INTO event_occurrences
                (
                    event_id,
                    occurrence_date,
                    start_time,
                    end_time,
                    status
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $occurrence_status =
                ($status === "Cancelled")
                    ? "Cancelled"
                    : "Scheduled";

            foreach ($occurrence_dates as $occurrence_date) {

                $insert_occurrence_stmt->bind_param(
                    "issss",
                    $event_id,
                    $occurrence_date,
                    $start_time,
                    $end_time,
                    $occurrence_status
                );

                $insert_occurrence_stmt->execute();
                $created_occurrence_id = $conn->insert_id;

                $seed_occurrence_stmt = $conn->prepare("
                    INSERT INTO event_occurrence_attendees
                        (occurrence_id, user_id, rsvp_status, attendance_status, attendance_comment)
                    SELECT ?, ea.user_id, ea.rsvp_status, 'Not Recorded', NULL
                    FROM event_attendees ea
                    WHERE ea.event_id = ?
                ");
                $seed_occurrence_stmt->bind_param("ii", $created_occurrence_id, $event_id);
                $seed_occurrence_stmt->execute();
                $seed_occurrence_stmt->close();

                if (!empty($preserved_series_attendees[$occurrence_date])) {
                    $restore_series_attendee = $conn->prepare("
                        UPDATE event_occurrence_attendees
                        SET rsvp_status = ?, attendance_status = ?, attendance_comment = ?
                        WHERE occurrence_id = ? AND user_id = ?
                    ");
                    foreach ($preserved_series_attendees[$occurrence_date] as $saved_user_id => $saved) {
                        if (!in_array((int)$saved_user_id, $selected_attendees, true)) {
                            continue;
                        }
                        $saved_comment = $saved["attendance_comment"];
                        $restore_series_attendee->bind_param(
                            "sssii",
                            $saved["rsvp_status"],
                            $saved["attendance_status"],
                            $saved_comment,
                            $created_occurrence_id,
                            $saved_user_id
                        );
                        $restore_series_attendee->execute();
                    }
                    $restore_series_attendee->close();
                }
            }

            $insert_occurrence_stmt->close();

            /* ---------------------------------------------
               COMMIT
            --------------------------------------------- */

            $conn->commit();

            $added_attendees = array_values(
                array_diff($selected_attendees, $existing_attendees)
            );
            $removed_attendees = array_values(
                array_diff($existing_attendees, $selected_attendees)
            );
            $remaining_attendees = array_values(
                array_intersect($selected_attendees, $existing_attendees)
            );

            foreach ($added_attendees as $recipient_id) {
                $recipient_id = (int)$recipient_id;
                if ($recipient_id === $current_user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    $recipient_id,
                    "Event",
                    "Event Invitation",
                    "You were invited to \"" . $name . "\".",
                    "Meetings / Events",
                    $event_id,
                    "view_event.php?id=" . $event_id
                );
            }

            foreach ($removed_attendees as $recipient_id) {
                $recipient_id = (int)$recipient_id;
                if ($recipient_id === $current_user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    $recipient_id,
                    "Event",
                    "Event Invitation Removed",
                    "You are no longer an attendee for \"" . $name . "\".",
                    "Meetings / Events",
                    $event_id,
                    "events.php"
                );
            }

            foreach ($remaining_attendees as $recipient_id) {
                $recipient_id = (int)$recipient_id;
                if ($recipient_id === $current_user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    $recipient_id,
                    "Event",
                    $status === "Cancelled" ? "Event Cancelled" : "Event Updated",
                    $status === "Cancelled"
                        ? "The event \"" . $name . "\" was cancelled."
                        : "The event \"" . $name . "\" was updated.",
                    "Meetings / Events",
                    $event_id,
                    "view_event.php?id=" . $event_id
                );
            }

            $previous_organizer = (int)$original["organizer"];

            if (
                (int)$organizer > 0
                && (int)$organizer !== $previous_organizer
                && (int)$organizer !== $current_user_id
            ) {
                createNotification(
                    $conn,
                    (int)$organizer,
                    "Event",
                    "Event Organizer Assignment",
                    "You were assigned as the organizer for \"" . $name . "\".",
                    "Meetings / Events",
                    $event_id,
                    "view_event.php?id=" . $event_id
                );
            }

            if (
                $previous_organizer > 0
                && $previous_organizer !== (int)$organizer
                && $previous_organizer !== $current_user_id
            ) {
                createNotification(
                    $conn,
                    $previous_organizer,
                    "Event",
                    "Event Organizer Assignment Removed",
                    "You are no longer the organizer for \"" . $name . "\".",
                    "Meetings / Events",
                    $event_id,
                    "view_event.php?id=" . $event_id
                );
            }

            header(
                "Location: view_event.php?id=" . $event_id
            );

            exit;

        } catch (Exception $e) {

            $conn->rollback();

            $errors[] =
                "The event could not be updated. Please try again.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Event</title>

<link rel="stylesheet" href="app.css?v=9">

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

        <?php if (canPostAnnouncements($current_role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($current_role)): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($current_role === "Administrator"): ?>
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

        <h1>Edit Event</h1>

    </div>

    <div class="content">

    <?php if (!empty($errors)): ?>

        <div class="error-box">

            <strong>Please correct the following:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li>
                        <?php echo htmlspecialchars($error); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

        </div>

    <?php endif; ?>

    <div class="form-box">

    <form method="POST">

        <?= csrfField() ?>

        <input
            type="hidden"
            name="event_id"
            value="<?php echo (int)$event_id; ?>"
        >

        <?php if ($occurrence): ?>

            <input
                type="hidden"
                name="occurrence_date"
                value="<?php echo htmlspecialchars($occurrence_date); ?>"
            >

            <div class="card">

                <h2 class="section-title">Recurring Event Edit</h2>

                <div class="form-group">

                    <label>
                        Apply changes to
                    </label>

                    <input
                        type="hidden"
                        name="edit_scope"
                        value="<?php echo htmlspecialchars($edit_scope); ?>"
                    >

                    <div style="border:2px solid black; padding:8px;">
                        <?php
                        if ($edit_scope === "occurrence") {
                            echo "This occurrence only";
                        } elseif ($edit_scope === "future") {
                            echo "This and all future occurrences";
                        } else {
                            echo "Entire series";
                        }
                        ?>
                    </div>

                    <div style="font-size:12px; margin-top:6px;">
                        Selected occurrence:
                        <?php echo htmlspecialchars($occurrence_date); ?>
                    </div>

                </div>

            </div>

        <?php endif; ?>

        <div class="card">

            <h2 class="section-title">Event Information</h2>

            <div class="form-grid">

                <div class="form-group full">

                    <label for="name">
                        Event Name
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?php echo htmlspecialchars($name); ?>"
                        required
                    >

                </div>

                <div class="form-group full">

                    <label for="description">
                        Description
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        required
                    ><?php echo htmlspecialchars($description); ?></textarea>

                </div>

                <div class="form-group">

                    <label for="type">
                        Event Type
                    </label>

                    <select
                        id="type"
                        name="type"
                        required
                    >

                        <?php foreach ($event_types as $event_type): ?>

                            <option
                                value="<?php echo htmlspecialchars($event_type); ?>"
                                <?php
                                echo ($type === $event_type)
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo htmlspecialchars($event_type); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label for="status">
                        Status
                    </label>

                    <select
                        id="status"
                        name="status"
                        required
                    >

                        <?php foreach ($statuses as $event_status): ?>

                            <option
                                value="<?php echo htmlspecialchars($event_status); ?>"
                                <?php
                                echo ($status === $event_status)
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo htmlspecialchars($event_status); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>

        </div>

        <div class="card">

            <h2 class="section-title">Organizer and Department</h2>

            <div class="form-grid">

                <div class="form-group">

                    <label for="organizer">
                        Organizer
                    </label>

                    <select
                        id="organizer"
                        name="organizer"
                        required
                    >

                        <option value="">
                            Select Organizer
                        </option>

                        <?php foreach ($users as $user): ?>

                            <option
                                value="<?php echo (int)$user["id"]; ?>"
                                <?php
                                echo (
                                    $organizer === (int)$user["id"]
                                )
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php
                                echo htmlspecialchars(
                                    $user["first_name"] . " " .
                                    $user["last_name"]
                                );
                                ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label for="department">
                        Department
                    </label>

                    <?php if ($is_manager || $is_it_admin): ?>

                        <input
                            type="text"
                            value="<?php echo htmlspecialchars($department); ?>"
                            disabled
                        >

                        <input
                            type="hidden"
                            name="department"
                            value="<?php echo htmlspecialchars($department); ?>"
                        >

                    <?php else: ?>

                        <select
                            id="department"
                            name="department"
                            required
                        >

                            <?php foreach ($departments as $dept): ?>

                                <option
                                    value="<?php echo htmlspecialchars($dept); ?>"
                                    <?php
                                    echo ($department === $dept)
                                        ? "selected"
                                        : "";
                                    ?>
                                >
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <div class="card">

            <h2 class="section-title">Date and Time</h2>

            <div class="form-grid">

                <div class="form-group">

                    <label for="start_date">
                        Start Date
                    </label>

                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        value="<?php echo htmlspecialchars($start_date); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="end_date">
                        End Date
                    </label>

                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        value="<?php echo htmlspecialchars($end_date); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="start_time">
                        Start Time
                    </label>

                    <input
                        type="time"
                        id="start_time"
                        name="start_time"
                        value="<?php echo htmlspecialchars($start_time); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="end_time">
                        End Time
                    </label>

                    <input
                        type="time"
                        id="end_time"
                        name="end_time"
                        value="<?php echo htmlspecialchars($end_time); ?>"
                        required
                    >

                </div>

                <div class="form-group full">

                    <label for="location">
                        Location
                    </label>

                    <input
                        type="text"
                        id="location"
                        name="location"
                        value="<?php echo htmlspecialchars($location); ?>"
                        required
                    >

                </div>

            </div>

        </div>

        <div class="card">

            <h2 class="section-title">Attendees</h2>

            <div class="attendees">

                <?php foreach ($users as $user): ?>

                    <div class="attendee">

                        <label>

                            <input
                                type="checkbox"
                                name="attendees[]"
                                value="<?php echo (int)$user["id"]; ?>"
                                <?php
                                echo in_array(
                                    (int)$user["id"],
                                    $selected_attendees,
                                    true
                                )
                                    ? "checked"
                                    : "";
                                ?>
                            >

                            <?php
                            echo htmlspecialchars(
                                $user["first_name"] . " " .
                                $user["last_name"]
                            );
                            ?>

                            <?php if ($user["department"] !== ""): ?>

                                —
                                <?php
                                echo htmlspecialchars(
                                    $user["department"]
                                );
                                ?>

                            <?php endif; ?>

                        </label>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <div class="card">

            <h2 class="section-title">Recurrence and Reminders</h2>

            <div class="form-grid">

                <div class="form-group">

                    <label for="recurrence_type">
                        Recurrence
                    </label>

                    <select
                        id="recurrence_type"
                        name="recurrence_type"
                        required
                    >

                        <?php foreach ($recurrence_types as $value => $label): ?>

                            <option
                                value="<?php echo htmlspecialchars($value); ?>"
                                <?php
                                echo ($recurrence_type === $value)
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo htmlspecialchars($label); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label for="repeat_until">
                        Repeat Until
                    </label>

                    <input
                        type="date"
                        id="repeat_until"
                        name="repeat_until"
                        value="<?php echo htmlspecialchars($repeat_until ?? ""); ?>"
                    >

                </div>

                <div class="form-group">

                    <label for="reminder">
                        Reminder
                    </label>

                    <select
                        id="reminder"
                        name="reminder"
                        required
                    >

                        <?php foreach ($reminders as $value => $label): ?>

                            <option
                                value="<?php echo htmlspecialchars($value); ?>"
                                <?php
                                echo ($reminder === $value)
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo htmlspecialchars($label); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>
                        Reminder Delivery
                    </label>

                    <input
                        type="text"
                        value="Email"
                        disabled
                    >

                </div>

            </div>

        </div>

        <div class="actions">

            <button
                type="submit"
                class="btn btn-primary"
            >
                SAVE CHANGES
            </button>

            <a
                href="view_event.php?id=<?php echo $event_id; ?>"
                class="btn btn-secondary"
            >
                CANCEL
            </a>

        </div>

    </form>

    </div>

    </div>

</main>

</div>

</body>
</html>