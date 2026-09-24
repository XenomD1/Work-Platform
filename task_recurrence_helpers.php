<?php

/*
 * Recurring tasks.
 *
 * When a recurring task is marked Complete, the next occurrence is
 * spawned immediately (not pre-generated on a schedule the way
 * Events are) using a rigid interval measured from the ORIGINAL due
 * date, not from whenever it actually got completed.
 */

const TASK_RECURRENCE_TYPES = ["none", "daily", "weekly", "monthly", "quarterly", "yearly"];

/**
 * Adds $months calendar months to $date, clamping to the last valid
 * day of the target month instead of overflowing into the next one.
 * PHP's own DateTime::modify("+1 month") on Jan 31 rolls over to
 * March 3 - this avoids that.
 */
function addMonthsClamped(string $date, int $months): string {
    $dt = new DateTime($date);
    $day = (int)$dt->format("j");

    $dt->modify("first day of this month");
    $dt->modify("+" . $months . " month");

    $last_day_of_target_month = (int)$dt->format("t");
    $final_day = min($day, $last_day_of_target_month);

    $dt->modify("+" . ($final_day - 1) . " days");

    return $dt->format("Y-m-d");
}

/**
 * Returns the next date after $date for the given recurrence type,
 * or null if $recurrence_type is "none" or unrecognized.
 */
function nextRecurrenceDate(string $date, string $recurrence_type): ?string {
    switch ($recurrence_type) {
        case "daily":
            $dt = new DateTime($date);
            $dt->modify("+1 day");
            return $dt->format("Y-m-d");

        case "weekly":
            $dt = new DateTime($date);
            $dt->modify("+7 days");
            return $dt->format("Y-m-d");

        case "monthly":
            return addMonthsClamped($date, 1);

        case "quarterly":
            return addMonthsClamped($date, 3);

        case "yearly":
            return addMonthsClamped($date, 12);

        default:
            return null;
    }
}

/**
 * True if a recurring task has reached its end condition and should
 * NOT spawn another occurrence - either because the next due date
 * would fall after recurrence_end_date, or because
 * recurrence_max_occurrences has already been reached.
 */
function taskRecurrenceHasEnded(array $task, string $next_due_date): bool {
    if (
        !empty($task["recurrence_end_date"])
        && $next_due_date > $task["recurrence_end_date"]
    ) {
        return true;
    }

    if (
        $task["recurrence_max_occurrences"] !== null
        && (int)$task["recurrence_occurrence_number"] >= (int)$task["recurrence_max_occurrences"]
    ) {
        return true;
    }

    return false;
}

/**
 * Spawns the next occurrence of a completed recurring task, copying
 * name/description/priority/project/assignees, shifting start_date
 * and due_date by one interval from the ORIGINAL due date (not the
 * completion date), and resetting status/completion. Does nothing
 * (returns null) if the task isn't recurring, has no due date to
 * anchor the schedule to, or has reached its end condition.
 *
 * Checklist items are intentionally NOT copied - they tend to be
 * specific to one instance of a recurring task, not the series.
 */
function spawnNextTaskOccurrence(mysqli $conn, array $task): ?int {
    if (
        !in_array($task["recurrence_type"], TASK_RECURRENCE_TYPES, true)
        || $task["recurrence_type"] === "none"
        || empty($task["due_date"])
    ) {
        return null;
    }

    $next_due_date = nextRecurrenceDate($task["due_date"], $task["recurrence_type"]);

    if ($next_due_date === null) {
        return null;
    }

    if (taskRecurrenceHasEnded($task, $next_due_date)) {
        return null;
    }

    $next_start_date = null;

    if (!empty($task["start_date"])) {
        $days_between = (new DateTime($task["due_date"]))->diff(new DateTime($task["start_date"]))->days;
        $direction = $task["start_date"] <= $task["due_date"] ? -1 : 1;
        $next_start_dt = new DateTime($next_due_date);
        $next_start_dt->modify(($direction * $days_between) . " days");
        $next_start_date = $next_start_dt->format("Y-m-d");
    }

    $next_occurrence_number = (int)$task["recurrence_occurrence_number"] + 1;

    $insert_stmt = $conn->prepare("
        INSERT INTO tasks (
            project_id, name, description, priority, status,
            start_date, due_date, completion,
            recurrence_type, recurrence_end_date, recurrence_max_occurrences,
            recurrence_occurrence_number, recurrence_parent_task_id
        ) VALUES (
            ?, ?, ?, ?, 'Not Started',
            ?, ?, 0,
            ?, ?, ?,
            ?, ?
        )
    ");

    $insert_stmt->bind_param(
        "isssssssiii",
        $task["project_id"],
        $task["name"],
        $task["description"],
        $task["priority"],
        $next_start_date,
        $next_due_date,
        $task["recurrence_type"],
        $task["recurrence_end_date"],
        $task["recurrence_max_occurrences"],
        $next_occurrence_number,
        $task["id"]
    );

    if (!$insert_stmt->execute()) {
        $insert_stmt->close();
        return null;
    }

    $new_task_id = (int)$insert_stmt->insert_id;
    $insert_stmt->close();

    $assignment_stmt = $conn->prepare("
        SELECT user_id FROM task_assignments WHERE task_id = ?
    ");
    $assignment_stmt->bind_param("i", $task["id"]);
    $assignment_stmt->execute();
    $assignment_result = $assignment_stmt->get_result();

    $assigned_user_ids = [];

    while ($row = $assignment_result->fetch_assoc()) {
        $assigned_user_ids[] = (int)$row["user_id"];
    }

    $assignment_stmt->close();

    if (!empty($assigned_user_ids)) {
        $insert_assignment_stmt = $conn->prepare("
            INSERT INTO task_assignments (task_id, user_id) VALUES (?, ?)
        ");

        foreach ($assigned_user_ids as $user_id) {
            $insert_assignment_stmt->bind_param("ii", $new_task_id, $user_id);
            $insert_assignment_stmt->execute();
        }

        $insert_assignment_stmt->close();
    }

    return $new_task_id;
}
