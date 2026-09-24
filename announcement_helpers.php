<?php
/*
    announcement_helpers.php

    REPLACES the version delivered earlier for #27, which invented a
    parallel schema and function names that didn't match the
    Announcements feature already wired into dashboard.php. This
    version matches the REAL table (confirmed via SHOW CREATE TABLE):

        announcements(id, type, department, title, body, is_active,
                       publish_at, expires_at,   <- added by
                                                     announcements_schema_update.sql
                       created_by, created_at, updated_at)
        announcement_dismissals(id, announcement_id, user_id, dismissed_at)

    and keeps the exact function names/signatures dashboard.php
    already calls: getVisibleAnnouncements($conn, $user_id,
    $user_department) and canPostAnnouncements($role).

    `type` is a fixed 5-value enum in the real table - Organization
    Announcement, Department Announcement, Scheduled Maintenance,
    Office Closure, Policy Update - not a freeform label, and
    `department` being NULL is what makes an announcement
    organization-wide (matches the existing idx_announcements_active_dept
    index on (is_active, department)).
*/

function announcementTypes(): array {
    return [
        "Organization Announcement",
        "Department Announcement",
        "Scheduled Maintenance",
        "Office Closure",
        "Policy Update",
    ];
}

/**
 * One row's lifecycle state: Inactive (manually disabled regardless
 * of dates), Scheduled (publish time hasn't arrived), Active
 * (published and not expired), or Expired.
 */
function announcementLifecycleState(array $announcement): string {
    if (!(int)$announcement["is_active"]) {
        return "Inactive";
    }

    $now = time();
    $publish_at = strtotime($announcement["publish_at"]);
    $expires_at = !empty($announcement["expires_at"]) ? strtotime($announcement["expires_at"]) : null;

    if ($publish_at > $now) {
        return "Scheduled";
    }

    if ($expires_at !== null && $expires_at <= $now) {
        return "Expired";
    }

    return "Active";
}

/**
 * Who is allowed to create/manage announcements at all. The original
 * definition of this function wasn't recoverable (it was overwritten
 * before I saw it), so this is a best-effort reconstruction matching
 * how the rest of the platform draws this line: Administrator and IT
 * Administrator organization-wide, Manager for their own department.
 * If that doesn't match how it actually behaved before, this is the
 * one function in this file to double check.
 */
function canPostAnnouncements(string $role): bool {
    return isAnyAdministrator($role) || $role === "Manager";
}

/**
 * Whether $role/$user_department may manage (edit/deactivate) an
 * announcement targeted at $target_department (null = org-wide).
 * Org-wide announcements are Administrator-only, since they reach
 * every department at once; a department-scoped announcement follows
 * the same canAdministerDepartment() rule used everywhere else.
 */
function canManageAnnouncement(string $role, string $user_department, ?string $target_department): bool {
    if ($target_department === null || $target_department === "") {
        return isAnyAdministrator($role);
    }
    return canAdministerDepartment($role, $user_department, $target_department);
}

/**
 * dashboard.php's own function, kept with its exact original
 * signature: the announcements currently eligible to show a given
 * user - Active (published, not expired, not manually disabled),
 * matching their department or organization-wide, AND not already
 * dismissed by them. This is the only query that should ever decide
 * what a user's dashboard shows, so nothing scheduled, expired,
 * inactive, wrong-department, or already-dismissed can leak through
 * a second slightly-different query somewhere else.
 */
function getVisibleAnnouncements(mysqli $conn, int $user_id, string $user_department): array {
    $stmt = $conn->prepare("
        SELECT
            a.id, a.type, a.department, a.title, a.body, a.created_at,
            CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
        FROM announcements a
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN announcement_dismissals d
            ON d.announcement_id = a.id AND d.user_id = ?
        WHERE a.is_active = 1
          AND a.publish_at <= NOW()
          AND (a.expires_at IS NULL OR a.expires_at > NOW())
          AND (a.department IS NULL OR a.department = ?)
          AND d.id IS NULL
        ORDER BY a.created_at DESC
    ");
    $stmt->bind_param("is", $user_id, $user_department);
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
 * Records that $user_id has dismissed $announcement_id - per-user,
 * via announcement_dismissals, exactly as dashboard.php's existing
 * DISMISS button already implies (it wouldn't make sense for one
 * person dismissing a notice to also hide it for everyone else).
 * INSERT IGNORE because of the table's own uniq_announcement_user
 * constraint - dismissing twice is a harmless no-op.
 */
function dismissAnnouncementForUser(mysqli $conn, int $user_id, int $announcement_id): void {
    $stmt = $conn->prepare("INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $announcement_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Every announcement a manager is allowed to administer - all
 * lifecycle states included (Scheduled/Active/Expired/Inactive),
 * since the management list needs to show all of them, not just
 * what's currently visible on the Dashboard. Administrators see
 * everything; department-scoped managers see their own department's
 * announcements plus org-wide ones.
 */
function getManageableAnnouncements(mysqli $conn, string $role, string $department): array {
    if (isAnyAdministrator($role)) {
        $result = $conn->query("
            SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
            FROM announcements a
            LEFT JOIN users u ON u.id = a.created_by
            ORDER BY a.created_at DESC
        ");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    $stmt = $conn->prepare("
        SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
        FROM announcements a
        LEFT JOIN users u ON u.id = a.created_by
        WHERE a.department = ? OR a.department IS NULL
        ORDER BY a.created_at DESC
    ");
    $stmt->bind_param("s", $department);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}
