<?php

/*
 * Task dependencies ("Blocked by" / "Blocking").
 *
 * A row (task_id, depends_on_task_id) means task_id is BLOCKED BY
 * depends_on_task_id - task_id cannot leave "Not Started" until
 * depends_on_task_id's own status is "Complete".
 *
 * Dependencies are scoped to a single project by convention (enforced
 * by the caller when building the list of selectable blockers, not by
 * the schema itself).
 */

/**
 * True if $task_id has at least one blocker whose own status is not
 * yet "Complete". Used to gate a task leaving "Not Started".
 */
function taskHasOpenBlockers(mysqli $conn, int $task_id): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM task_dependencies td
        INNER JOIN tasks t ON t.id = td.depends_on_task_id
        WHERE td.task_id = ?
          AND t.status <> 'Complete'
    ");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    return $total > 0;
}

/**
 * True if replacing $task_id's blockers with $proposed_blocker_ids
 * would create a circular dependency anywhere in the project's
 * dependency graph (not just a direct A-blocks-B-blocks-A cycle, but
 * any longer chain that eventually loops back to $task_id).
 *
 * Existing edges belonging to $task_id itself are excluded from the
 * graph before the proposed edges are added, since those are being
 * replaced wholesale by this save.
 */
function taskDependencyWouldCreateCycle(
    mysqli $conn,
    int $project_id,
    int $task_id,
    array $proposed_blocker_ids
): bool {
    $edges = [];

    $stmt = $conn->prepare("
        SELECT td.task_id, td.depends_on_task_id
        FROM task_dependencies td
        INNER JOIN tasks t ON t.id = td.task_id
        WHERE t.project_id = ?
          AND td.task_id <> ?
    ");
    $stmt->bind_param("ii", $project_id, $task_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $edges[(int)$row["task_id"]][] = (int)$row["depends_on_task_id"];
    }

    $stmt->close();

    $edges[$task_id] = $proposed_blocker_ids;

    /*
     * Depth-first walk starting from each proposed blocker, following
     * "depends on" edges. If the walk ever reaches $task_id again,
     * a cycle exists.
     */
    $visited = [];
    $stack = $proposed_blocker_ids;

    while ($stack) {
        $current = array_pop($stack);

        if ($current === $task_id) {
            return true;
        }

        if (isset($visited[$current])) {
            continue;
        }

        $visited[$current] = true;

        foreach ($edges[$current] ?? [] as $next) {
            $stack[] = $next;
        }
    }

    return false;
}

/**
 * Tasks that $task_id is blocked by, with enough info to display
 * "Blocked by" and know whether each one is still open.
 */
function getTaskBlockers(mysqli $conn, int $task_id): array {
    $stmt = $conn->prepare("
        SELECT t.id, t.name, t.status
        FROM task_dependencies td
        INNER JOIN tasks t ON t.id = td.depends_on_task_id
        WHERE td.task_id = ?
        ORDER BY t.name ASC
    ");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

/**
 * Tasks that depend on $task_id (the reverse direction) - shown as
 * "Blocking" on the task page.
 */
function getTasksBlockedBy(mysqli $conn, int $task_id): array {
    $stmt = $conn->prepare("
        SELECT t.id, t.name, t.status
        FROM task_dependencies td
        INNER JOIN tasks t ON t.id = td.task_id
        WHERE td.depends_on_task_id = ?
        ORDER BY t.name ASC
    ");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}
