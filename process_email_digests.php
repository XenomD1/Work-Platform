<?php
/*
    process_email_digests.php

    Email digest worker. CLI only - schedule with Windows Task
    Scheduler, the same way process_event_reminders.php and
    process_event_statuses.php already run. A sensible schedule is
    once every hour; this script only actually sends a digest to a
    user when their chosen frequency (daily/weekly) is actually due,
    using last_digest_sent_at, so running it more often than that is
    harmless - it just checks and finds nothing due yet.
*/

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("This digest worker can only run from the command line.\n");
}

require __DIR__ . DIRECTORY_SEPARATOR . "db.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "email_helpers.php";

function digestIsDue(?string $last_sent_at, string $frequency): bool {
    if ($frequency === "disabled") {
        return false;
    }

    if ($last_sent_at === null) {
        return true;
    }

    $elapsed_hours = (time() - strtotime($last_sent_at)) / 3600;

    if ($frequency === "daily") {
        return $elapsed_hours >= 24;
    }

    if ($frequency === "weekly") {
        return $elapsed_hours >= (24 * 7);
    }

    return false;
}

$result = $conn->query("
    SELECT
        u.id, u.first_name, u.last_name, u.email, u.active,
        p.digest_frequency, p.last_digest_sent_at
    FROM users u
    INNER JOIN user_preferences p ON p.user_id = u.id
    WHERE u.active = 1
      AND p.digest_frequency IN ('daily', 'weekly')
");

if (!$result) {
    fwrite(STDERR, "Could not load digest recipients: " . $conn->error . PHP_EOL);
    exit(1);
}

$sent = 0;
$skipped_not_due = 0;
$skipped_empty = 0;

while ($user = $result->fetch_assoc()) {

    if (!digestIsDue($user["last_digest_sent_at"], $user["digest_frequency"])) {
        $skipped_not_due++;
        continue;
    }

    $delivered = sendDigestForUser($conn, $user);

    // Update last_digest_sent_at regardless of whether there was
    // content, so an empty week doesn't cause an email the moment
    // something small finally appears mid-cycle.
    $update_stmt = $conn->prepare("
        UPDATE user_preferences
        SET last_digest_sent_at = NOW()
        WHERE user_id = ?
    ");
    $update_stmt->bind_param("i", $user["id"]);
    $update_stmt->execute();
    $update_stmt->close();

    if ($delivered) {
        $sent++;
        echo "Sent digest to user #{$user['id']}." . PHP_EOL;
    } else {
        $skipped_empty++;
    }
}

echo "Digest run complete. Sent: {$sent}; Skipped (not due): {$skipped_not_due}; Skipped (nothing to report): {$skipped_empty}." . PHP_EOL;
