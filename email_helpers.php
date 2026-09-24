<?php
/*
    email_helpers.php

    Shared email sending for the Email Notifications & Digests feature
    (#23). Uses PHP's mail(), the same low-level mechanism already used
    by process_event_reminders.php, so no new mail transport is
    introduced.

    Two delivery paths:
      - Immediate email: sent right away for the one category the spec
        calls out as urgent enough not to wait - a task being assigned
        to you (notifyTaskAssignmentByEmail(), gated on the recipient's
        email_task_assignment preference).
      - Digest email: a periodic (daily/weekly) summary built by
        buildDigestForUser() and sent by process_email_digests.php,
        gated on the recipient's digest_frequency preference.

    Both paths only ever include information the recipient could
    already see while logged in - due/overdue tasks assigned to them,
    tasks recently assigned to them, and comments that mention them.
    Nothing here bypasses the platform's normal access rules, because
    everything in a digest is inherently scoped to "belongs to this
    user" rather than pulled from someone else's data.
*/

function cleanEmailHeaderText($value) {
    return trim(str_replace(["\r", "\n"], " ", (string)$value));
}

/**
 * Low-level send. Returns true/false rather than throwing, matching
 * process_event_reminders.php's sendReminderEmail() pattern.
 */
function sendPlatformEmail(string $to_email, string $subject, string $body): bool {
    if ($to_email === "" || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = cleanEmailHeaderText($subject);

    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
    ];

    return @mail($to_email, $subject, $body, implode("\r\n", $headers));
}

/**
 * Immediate email for a task assignment - the one category the #23
 * spec calls out as not needing to wait for a digest. Silently does
 * nothing if the recipient has this preference off, is inactive, or
 * has no usable email address.
 */
function notifyTaskAssignmentByEmail(mysqli $conn, int $user_id, array $task): bool {
    $stmt = $conn->prepare("
        SELECT u.email, u.first_name, u.active,
               COALESCE(p.email_task_assignment, 0) AS wants_email
        FROM users u
        LEFT JOIN user_preferences p ON p.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (int)$row["active"] !== 1 || (int)$row["wants_email"] !== 1) {
        return false;
    }

    $subject = "You were assigned: " . $task["name"];
    $body = "Hello " . $row["first_name"] . ",\n\n"
        . "You were assigned to a task in WorkPlatform:\n\n"
        . "Task: " . $task["name"] . "\n"
        . (!empty($task["due_date"]) ? "Due: " . date("F j, Y", strtotime($task["due_date"])) . "\n" : "")
        . "\nView it here: view_task.php?id=" . (int)$task["id"] . "\n\n"
        . "This message was sent automatically by the Work Management Platform.\n";

    return sendPlatformEmail($row["email"], $subject, $body);
}

/**
 * Gathers everything a digest email for $user should contain, or
 * returns null if there's genuinely nothing worth sending (an empty
 * digest is skipped rather than sent as a blank email).
 */
function buildDigestForUser(mysqli $conn, array $user): ?array {
    $user_id = (int)$user["id"];
    $since = $user["last_digest_sent_at"] ?? null;
    $today = date("Y-m-d");
    $week_ahead = date("Y-m-d", strtotime("+7 days"));

    // Tasks due within the next 7 days, not yet complete.
    $due_stmt = $conn->prepare("
        SELECT t.id, t.name, t.due_date
        FROM tasks t
        INNER JOIN task_assignments ta ON ta.task_id = t.id
        WHERE ta.user_id = ?
          AND t.status <> 'Complete'
          AND t.due_date IS NOT NULL
          AND t.due_date BETWEEN ? AND ?
        ORDER BY t.due_date ASC
    ");
    $due_stmt->bind_param("iss", $user_id, $today, $week_ahead);
    $due_stmt->execute();
    $due_soon = $due_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $due_stmt->close();

    // Overdue tasks, still not complete.
    $overdue_stmt = $conn->prepare("
        SELECT t.id, t.name, t.due_date
        FROM tasks t
        INNER JOIN task_assignments ta ON ta.task_id = t.id
        WHERE ta.user_id = ?
          AND t.status <> 'Complete'
          AND t.due_date IS NOT NULL
          AND t.due_date < ?
        ORDER BY t.due_date ASC
    ");
    $overdue_stmt->bind_param("is", $user_id, $today);
    $overdue_stmt->execute();
    $overdue = $overdue_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $overdue_stmt->close();

    // Unread mentions since the last digest (or ever, on the first run).
    $mention_sql = "
        SELECT c.id, c.module, c.record_id, c.comment_text, c.created_at
        FROM comment_mentions cm
        INNER JOIN comments c ON c.id = cm.comment_id
        WHERE cm.user_id = ?
    ";
    $mention_params = [$user_id];
    $mention_types = "i";

    if ($since !== null) {
        $mention_sql .= " AND c.created_at > ?";
        $mention_params[] = $since;
        $mention_types .= "s";
    }

    $mention_sql .= " ORDER BY c.created_at DESC LIMIT 20";

    $mention_stmt = $conn->prepare($mention_sql);
    $mention_stmt->bind_param($mention_types, ...$mention_params);
    $mention_stmt->execute();
    $mentions = $mention_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mention_stmt->close();

    // Notifications accumulated since the last digest (Task/Project/Report),
    // as a simple "recent activity" section - deliberately excludes anything
    // already covered above (due/overdue/mentions) to avoid duplication.
    $activity_sql = "
        SELECT title, message, related_url, created_at
        FROM notifications
        WHERE user_id = ?
          AND type IN ('Project', 'Report')
    ";
    $activity_params = [$user_id];
    $activity_types = "i";

    if ($since !== null) {
        $activity_sql .= " AND created_at > ?";
        $activity_params[] = $since;
        $activity_types .= "s";
    }

    $activity_sql .= " ORDER BY created_at DESC LIMIT 20";

    $activity_stmt = $conn->prepare($activity_sql);
    $activity_stmt->bind_param($activity_types, ...$activity_params);
    $activity_stmt->execute();
    $activity = $activity_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $activity_stmt->close();

    if (!$due_soon && !$overdue && !$mentions && !$activity) {
        return null;
    }

    return [
        "due_soon" => $due_soon,
        "overdue" => $overdue,
        "mentions" => $mentions,
        "activity" => $activity,
    ];
}

/**
 * Renders a digest data array (from buildDigestForUser()) into a plain
 * text email body.
 */
function renderDigestEmailBody(array $user, array $digest): string {
    $lines = [];
    $lines[] = "Hello " . $user["first_name"] . ",";
    $lines[] = "";
    $lines[] = "Here's your WorkPlatform activity summary:";

    if ($digest["overdue"]) {
        $lines[] = "";
        $lines[] = "OVERDUE WORK (" . count($digest["overdue"]) . ")";
        foreach ($digest["overdue"] as $task) {
            $lines[] = "  - " . $task["name"] . " - was due " . date("M j, Y", strtotime($task["due_date"]));
        }
    }

    if ($digest["due_soon"]) {
        $lines[] = "";
        $lines[] = "TASKS DUE THIS WEEK (" . count($digest["due_soon"]) . ")";
        foreach ($digest["due_soon"] as $task) {
            $lines[] = "  - " . $task["name"] . " - due " . date("l, M j", strtotime($task["due_date"]));
        }
    }

    if ($digest["mentions"]) {
        $lines[] = "";
        $lines[] = "MENTIONS REQUIRING ATTENTION (" . count($digest["mentions"]) . ")";
        foreach ($digest["mentions"] as $mention) {
            $snippet = mb_strimwidth((string)$mention["comment_text"], 0, 100, "...");
            $lines[] = "  - " . $mention["module"] . ": " . $snippet;
        }
    }

    if ($digest["activity"]) {
        $lines[] = "";
        $lines[] = "OTHER RECENT ACTIVITY (" . count($digest["activity"]) . ")";
        foreach ($digest["activity"] as $item) {
            $lines[] = "  - " . $item["title"] . ": " . $item["message"];
        }
    }

    $lines[] = "";
    $lines[] = "Log in to WorkPlatform to view the full details of anything above.";
    $lines[] = "";
    $lines[] = "This message was sent automatically by the Work Management Platform.";

    return implode("\n", $lines);
}

/**
 * Builds and sends one user's digest, if there's anything to send.
 * Returns true if an email was actually sent.
 */
function sendDigestForUser(mysqli $conn, array $user): bool {
    $digest = buildDigestForUser($conn, $user);

    if ($digest === null) {
        return false;
    }

    $subject = "Your WorkPlatform Digest";
    $body = renderDigestEmailBody($user, $digest);

    return sendPlatformEmail($user["email"], $subject, $body);
}
