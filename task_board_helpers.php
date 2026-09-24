<?php
/*
    task_board_helpers.php

    Shared logic for the Kanban Board (#24) - kept separate so
    kanban_board.php (rendering) and update_task_status.php (the
    server-side status-change handler) agree on exactly the same
    permission and completion rules, and so the same rules can be
    reused anywhere else a single task's status needs to change
    outside the full Edit Task page.
*/

/**
 * Whether the current user is allowed to change $task's status /
 * reassignment on the Board. Mirrors the rule bulk_task_action.php
 * uses for "Edit Task": department admin, the project's manager, or
 * (status only, not reassignment) one of the task's own assignees.
 */
function canBoardManageTask(
    string $role,
    string $department,
    int $user_id,
    array $project,
    array $assigned_user_ids
): bool {
    if (canAdministerDepartment($role, $department, $project["department"])) {
        return true;
    }

    if ($project["manager_id"] !== null && (int)$project["manager_id"] === $user_id) {
        return true;
    }

    return in_array($user_id, $assigned_user_ids, true);
}

/**
 * Keeps the completion percentage synchronized with a status change,
 * the same way the normal Task edit workflow does: Complete always
 * means 100%, Not Started always means 0%. Moving into In Progress
 * doesn't force a specific number - it leaves whatever completion the
 * task already had (or 0, if it had none) so a manually-set progress
 * percentage isn't clobbered by a column move.
 */
function taskCompletionForStatus(string $new_status, $current_completion): int {
    if ($new_status === "Complete") {
        return 100;
    }

    if ($new_status === "Not Started") {
        return 0;
    }

    $current = (int)($current_completion ?? 0);

    if ($current < 0) {
        return 0;
    }

    if ($current > 99) {
        // Was already at/near 100 from a previous Complete status but
        // the task was just moved back to In Progress - pull it back
        // under 100 so the number and the column stay honest.
        return 99;
    }

    return $current;
}

/**
 * The three allowed board columns/statuses, in display order.
 */
function boardStatusColumns(): array {
    return ["Not Started", "In Progress", "Complete"];
}
