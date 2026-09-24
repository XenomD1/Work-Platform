<?php
/*
    saved_view_helpers.php

    Shared helpers for the Saved Views / Saved Filters feature (#13).
    A "saved view" is just a name plus a frozen query string (the same
    GET params the module's own filter form produces), scoped to one
    module and one user.
*/

const SAVED_VIEW_MODULES = ["Tasks", "Projects", "TimeOff", "Training", "Reports"];

/**
 * All of a user's saved views for one module, newest first.
 */
function getSavedViews(mysqli $conn, int $user_id, string $module): array {
    if (!in_array($module, SAVED_VIEW_MODULES, true)) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT id, name, query_string, created_at
        FROM saved_views
        WHERE user_id = ? AND module = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("is", $user_id, $module);
    $stmt->execute();
    $result = $stmt->get_result();

    $views = [];

    while ($row = $result->fetch_assoc()) {
        $views[] = $row;
    }

    $stmt->close();

    return $views;
}

/**
 * Save the current filter combination for a module. $query_string
 * should be the query string of GET params the filter form produces
 * (no leading "?"), e.g. "status=Not+Started&overdue=1".
 */
function createSavedView(
    mysqli $conn,
    int $user_id,
    string $module,
    string $name,
    string $query_string
): bool {
    if (!in_array($module, SAVED_VIEW_MODULES, true)) {
        return false;
    }

    $name = trim($name);

    if ($name === "" || mb_strlen($name) > 100) {
        return false;
    }

    if (mb_strlen($query_string) > 1000) {
        $query_string = mb_substr($query_string, 0, 1000);
    }

    $stmt = $conn->prepare("
        INSERT INTO saved_views (user_id, module, name, query_string)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $user_id, $module, $name, $query_string);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Delete a saved view - only if it belongs to $user_id, so one user
 * can never delete another user's saved view even by guessing an id.
 */
function deleteSavedView(mysqli $conn, int $user_id, int $view_id): bool {
    $stmt = $conn->prepare("
        DELETE FROM saved_views
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $view_id, $user_id);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}
