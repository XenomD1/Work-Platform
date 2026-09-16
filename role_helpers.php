<?php

/*
 * IT Administrator permission model.
 *
 * Administrator: full access everywhere, no department restriction.
 * IT Administrator: same *kind* of access as Administrator, but only
 *                    for records belonging to their own department.
 *
 * Include this file (require_once "role_helpers.php";) wherever an
 * "Administrator"-only check needs to also allow a department-scoped
 * IT Administrator.
 */

/**
 * True if this role is one of the two administrator tiers at all
 * (before considering department scope). Useful for "can this role
 * even reach this feature" checks, e.g. showing a nav link.
 */
function isAnyAdministrator($role) {
    return $role === "Administrator" || $role === "IT Administrator";
}

/**
 * True if this user can administer a record belonging to
 * $target_department. An Administrator can administer any
 * department (including records with no department at all).
 * An IT Administrator can only administer records in their own
 * department.
 */
function canAdministerDepartment($role, $user_department, $target_department) {

    if ($role === "Administrator") {
        return true;
    }

    if ($role === "IT Administrator") {
        return $target_department === $user_department;
    }

    return false;
}
