<?php
/*
    permission_helpers.php

    #28 Configurable Roles & Permissions - the centralized permission
    check the spec describes: instead of a future page writing
    `$role === "Administrator"`, it calls userCan($conn, $user_id,
    "tasks.assign") and gets back whether that user's currently
    assigned role grants that capability.

    This is additive infrastructure. It does NOT retroactively change
    any of the platform's existing pages - none of their source was
    available to safely migrate, and the spec itself frames this as
    "how FUTURE PHP pages make authorization decisions" rather than a
    rewrite of the existing ones. Every existing page keeps working
    exactly as it does today; new pages going forward can opt into
    calling userCan() instead of hard-coding role names.

    Scope still matters. userCan() answers "does this role generally
    have this capability" - it is deliberately NOT a replacement for
    canAdministerDepartment()/department and record-level checks,
    which still decide WHICH records that capability applies to. The
    spec is explicit about this: "Edit Tasks + Department scope"
    doesn't mean editing every task in the organization. A typical
    call site looks like:

        if (userCan($conn, $user_id, "tasks.edit")
            && canAdministerDepartment($role, $department, $task_department)) {
            // allowed to edit this specific task
        }
*/

/**
 * The two permission codes that are never assignable to a non-system
 * role, regardless of what an Administrator submits through
 * roles.php - so a custom role can never be built up into
 * unrestricted authority over the permission system itself.
 */
function protectedPermissionCodes(): array {
    return ["admin.manage_roles", "admin.manage_users"];
}

/**
 * Whether $user_id's currently assigned role grants $permission_code.
 * Always re-fetches the role fresh from the database (never trusts
 * $_SESSION), the same way every other permission check in this
 * codebase does.
 */
function userCan(mysqli $conn, int $user_id, string $permission_code): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM users u
        INNER JOIN role_permissions rp ON rp.role_name = u.role
        WHERE u.id = ? AND u.active = 1 AND rp.permission_code = ?
        LIMIT 1
    ");
    $stmt->bind_param("is", $user_id, $permission_code);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}

/**
 * Same check, but against an already-known role name instead of a
 * user id - useful when the caller already re-fetched the user's role
 * for its own purposes and doesn't want a second query.
 */
function roleCan(mysqli $conn, string $role_name, string $permission_code): bool {
    $stmt = $conn->prepare("
        SELECT 1 FROM role_permissions WHERE role_name = ? AND permission_code = ? LIMIT 1
    ");
    $stmt->bind_param("ss", $role_name, $permission_code);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}

/**
 * All permission codes granted to $role_name, as a flat array -
 * handy for rendering a role's checkbox grid in roles.php.
 */
function getRolePermissionCodes(mysqli $conn, string $role_name): array {
    $stmt = $conn->prepare("SELECT permission_code FROM role_permissions WHERE role_name = ?");
    $stmt->bind_param("s", $role_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $codes = [];
    while ($row = $result->fetch_assoc()) {
        $codes[] = $row["permission_code"];
    }
    $stmt->close();
    return $codes;
}

/**
 * The full permission catalog, grouped by module, for building the
 * checkbox grid in roles.php. $include_protected controls whether
 * admin.manage_roles/admin.manage_users are included at all - pass
 * false when rendering the grid for a non-system role, since those
 * two are never offered as togglable outside the system
 * Administrator role.
 */
function getPermissionCatalog(mysqli $conn, bool $include_protected = true): array {
    $sql = "SELECT code, module, label, description, is_protected FROM permissions";
    if (!$include_protected) {
        $sql .= " WHERE is_protected = 0";
    }
    $sql .= " ORDER BY module ASC, label ASC";

    $result = $conn->query($sql);
    $catalog = [];
    while ($row = $result->fetch_assoc()) {
        $catalog[$row["module"]][] = $row;
    }
    return $catalog;
}

/**
 * All roles (system and custom), for roles.php's role list.
 */
function getAllRoles(mysqli $conn): array {
    $result = $conn->query("SELECT id, name, description, is_system FROM custom_roles ORDER BY is_system DESC, name ASC");
    $roles = [];
    while ($row = $result->fetch_assoc()) {
        $roles[] = $row;
    }
    return $roles;
}

/**
 * Overwrites $role_name's permission set to exactly $permission_codes,
 * silently dropping any protected code that isn't already legitimately
 * assignable (system Administrator role only) - this is the server-
 * side backstop mentioned above: even a tampered form submission
 * can't grant admin.manage_roles/admin.manage_users to a role that
 * shouldn't have them.
 */
function setRolePermissions(mysqli $conn, string $role_name, array $permission_codes): void {
    $is_system_admin = ($role_name === "Administrator");
    $protected = protectedPermissionCodes();

    $safe_codes = array_values(array_unique(array_filter(
        $permission_codes,
        function ($code) use ($is_system_admin, $protected) {
            if (in_array($code, $protected, true)) {
                return $is_system_admin;
            }
            return true;
        }
    )));

    // The Administrator system role can never lose admin.manage_roles
    // through this form - that would be a permanent lockout with no
    // account left able to grant it back.
    if ($is_system_admin && !in_array("admin.manage_roles", $safe_codes, true)) {
        $safe_codes[] = "admin.manage_roles";
    }

    $conn->begin_transaction();

    $delete_stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_name = ?");
    $delete_stmt->bind_param("s", $role_name);
    $delete_stmt->execute();
    $delete_stmt->close();

    if ($safe_codes) {
        $insert_stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_name, permission_code) VALUES (?, ?)");
        foreach ($safe_codes as $code) {
            $insert_stmt->bind_param("ss", $role_name, $code);
            $insert_stmt->execute();
        }
        $insert_stmt->close();
    }

    $conn->commit();
}

/**
 * #26/#28 integration point: whether accounts with $role_name should
 * be treated as privileged enough to require 2FA, even if the role
 * isn't literally named "Administrator"/"IT Administrator" - a
 * custom role that's been granted a sufficiently sensitive
 * administrative capability counts too. two_factor_enroll.php still
 * only actively OFFERS enrollment to the two built-in privileged role
 * names for now; this helper is what a future version of that
 * gate would call instead, once a custom role has actually been
 * granted one of these capabilities.
 */
function roleRequiresTwoFactor(mysqli $conn, string $role_name): bool {
    if (in_array($role_name, ["Administrator", "IT Administrator"], true)) {
        return true;
    }

    $sensitive = ["admin.manage_users", "admin.manage_roles", "admin.view_activity"];
    foreach ($sensitive as $code) {
        if (roleCan($conn, $role_name, $code)) {
            return true;
        }
    }
    return false;
}
