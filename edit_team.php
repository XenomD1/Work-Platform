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

$user_id = (int)$_SESSION["user_id"];

$user_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$role = $current_user["role"];
$user_department = $current_user["department"];
$current_full_name = trim(
    $current_user["first_name"] . " " . $current_user["last_name"]
);

$team_id = (int)($_GET["id"] ?? $_POST["team_id"] ?? 0);

if ($team_id <= 0) {
    header("Location: teams.php");
    exit;
}

/*
 * Load team and project information.
 */
$team_stmt = $conn->prepare("
    SELECT
        t.id,
        t.project_id,
        t.name,
        t.description,
        t.responsibilities,
        t.team_lead_id,
        t.status,
        p.name AS project_name,
        p.department AS project_department,
        p.manager_id AS project_manager_id
    FROM teams t
    INNER JOIN projects p
        ON p.id = t.project_id
    WHERE t.id = ?
");

$team_stmt->bind_param("i", $team_id);
$team_stmt->execute();
$team = $team_stmt->get_result()->fetch_assoc();
$team_stmt->close();

if (!$team) {
    header("Location: teams.php");
    exit;
}

/*
 * Edit permissions:
 * Administrator -> any team
 * Manager -> teams tied to projects in their department
 * Project Manager -> teams for projects they manage
 */
$can_edit = false;

if ($role === "Administrator") {
    $can_edit = true;
} elseif (
    ($role === "Manager" || $role === "IT Administrator")
    && $team["project_department"] === $user_department
) {
    $can_edit = true;
} elseif (
    $team["project_manager_id"] !== null
    && (int)$team["project_manager_id"] === $user_id
) {
    $can_edit = true;
}

if (!$can_edit) {
    header("Location: view_team.php?id=" . $team_id);
    exit;
}

/*
 * Load active users.
 */
$users = [];

$user_result = $conn->query("
    SELECT id, first_name, last_name, department
    FROM users
    WHERE active = 1
    ORDER BY last_name, first_name
");

if ($user_result) {
    while ($row = $user_result->fetch_assoc()) {
        $users[] = $row;
    }
}

/*
 * Load projects this user is allowed to manage teams for.
 */
$projects = [];

$project_result = $conn->query("
    SELECT id, name, department, manager_id
    FROM projects
    ORDER BY name ASC
");

if ($project_result) {
    while ($project = $project_result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif (
            ($role === "Manager" || $role === "IT Administrator")
            && $project["department"] === $user_department
        ) {
            $allowed = true;
        } elseif (
            $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            $allowed = true;
        }

        if ($allowed) {
            $projects[] = $project;
        }
    }
}

/*
 * Load current team members.
 */
$current_members = [];

$member_stmt = $conn->prepare("
    SELECT user_id
    FROM team_members
    WHERE team_id = ?
");

$member_stmt->bind_param("i", $team_id);
$member_stmt->execute();
$member_result = $member_stmt->get_result();

while ($row = $member_result->fetch_assoc()) {
    $current_members[] = (int)$row["user_id"];
}

$member_stmt->close();

$statuses = ["Active", "Inactive"];
$errors = [];

$name = $team["name"];
$project_id = (int)$team["project_id"];
$description = $team["description"];
$responsibilities = $team["responsibilities"];
$team_lead_id = (int)$team["team_lead_id"];
$status = $team["status"];
$members = $current_members;

$previous_member_ids = $current_members;
$previous_team_lead_id = (int)$team["team_lead_id"];
$previous_team_name = $team["name"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $project_id = (int)($_POST["project_id"] ?? 0);
    $description = trim($_POST["description"] ?? "");
    $responsibilities = trim($_POST["responsibilities"] ?? "");
    $team_lead_id = (int)($_POST["team_lead_id"] ?? 0);
    $status = trim($_POST["status"] ?? "");

    $members = isset($_POST["members"]) && is_array($_POST["members"])
        ? array_values(array_unique(array_map("intval", $_POST["members"])))
        : [];

    if ($name === "") {
        $errors[] = "Team Name is required.";
    }

    if ($project_id <= 0) {
        $errors[] = "Project is required.";
    }

    if ($description === "") {
        $errors[] = "Team Description / Purpose is required.";
    }

    if ($responsibilities === "") {
        $errors[] = "Responsibilities are required.";
    }

    if ($team_lead_id <= 0) {
        $errors[] = "Team Lead is required.";
    }

    if (!in_array($status, $statuses, true)) {
        $errors[] = "Please select a valid status.";
    }

    if (!$members) {
        $errors[] = "Select at least one team member.";
    }

    if (
        $team_lead_id > 0
        && !in_array($team_lead_id, $members, true)
    ) {
        $errors[] = "The Team Lead must also be selected as a team member.";
    }

    /*
     * Validate project permission.
     */
    $allowed_project_ids = array_map(
        fn($project) => (int)$project["id"],
        $projects
    );

    if (
        $project_id > 0
        && !in_array($project_id, $allowed_project_ids, true)
    ) {
        $errors[] = "You do not have permission to move this team to that project.";
    }

    /*
     * Validate users.
     */
    $valid_user_ids = [];

    foreach ($users as $person) {
        $valid_user_ids[(int)$person["id"]] = true;
    }

    if (
        $team_lead_id > 0
        && !isset($valid_user_ids[$team_lead_id])
    ) {
        $errors[] = "Please select a valid active Team Lead.";
    }

    foreach ($members as $member_id) {
        if (!isset($valid_user_ids[$member_id])) {
            $errors[] = "One or more selected team members are invalid.";
            break;
        }
    }

    if (!$errors) {

        $conn->begin_transaction();

        try {

            /*
             * Update team.
             */
            $update_stmt = $conn->prepare("
                UPDATE teams
                SET
                    project_id = ?,
                    name = ?,
                    description = ?,
                    responsibilities = ?,
                    team_lead_id = ?,
                    status = ?
                WHERE id = ?
            ");

            $update_stmt->bind_param(
                "isssisi",
                $project_id,
                $name,
                $description,
                $responsibilities,
                $team_lead_id,
                $status,
                $team_id
            );

            if (!$update_stmt->execute()) {
                throw new Exception("Unable to update team.");
            }

            $update_stmt->close();

            /*
             * Replace membership list.
             */
            $delete_stmt = $conn->prepare("
                DELETE FROM team_members
                WHERE team_id = ?
            ");

            $delete_stmt->bind_param("i", $team_id);

            if (!$delete_stmt->execute()) {
                throw new Exception("Unable to update team members.");
            }

            $delete_stmt->close();

            $insert_stmt = $conn->prepare("
                INSERT INTO team_members
                (team_id, user_id)
                VALUES (?, ?)
            ");

            foreach ($members as $member_id) {

                $insert_stmt->bind_param(
                    "ii",
                    $team_id,
                    $member_id
                );

                if (!$insert_stmt->execute()) {
                    throw new Exception("Unable to save team members.");
                }
            }

            $insert_stmt->close();

            $conn->commit();

            $new_member_ids = array_values(
                array_diff($members, $previous_member_ids)
            );
            $removed_member_ids = array_values(
                array_diff($previous_member_ids, $members)
            );

            foreach ($new_member_ids as $member_id) {
                $member_id = (int)$member_id;

                if ($member_id === $user_id) {
                    continue;
                }

                if ($member_id === $team_lead_id) {
                    createNotification(
                        $conn,
                        $member_id,
                        "Team",
                        "Team Lead Assignment",
                        "You were assigned as the Team Lead for \"" . $name . "\".",
                        "Teams",
                        $team_id,
                        "view_team.php?id=" . $team_id
                    );
                } else {
                    createNotification(
                        $conn,
                        $member_id,
                        "Team",
                        "Added to Team",
                        "You were added to the team \"" . $name . "\".",
                        "Teams",
                        $team_id,
                        "view_team.php?id=" . $team_id
                    );
                }
            }

            foreach ($removed_member_ids as $member_id) {
                if ((int)$member_id === $user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    (int)$member_id,
                    "Team",
                    "Removed from Team",
                    "You were removed from the team \"" . $name . "\".",
                    "Teams",
                    $team_id,
                    "teams.php"
                );
            }

            if (
                $team_lead_id > 0
                && $team_lead_id !== $previous_team_lead_id
                && !in_array($team_lead_id, $new_member_ids, true)
                && $team_lead_id !== $user_id
            ) {
                createNotification(
                    $conn,
                    $team_lead_id,
                    "Team",
                    "Team Lead Assignment",
                    "You were assigned as the Team Lead for \"" . $name . "\".",
                    "Teams",
                    $team_id,
                    "view_team.php?id=" . $team_id
                );
            }

            if (
                $previous_team_lead_id > 0
                && $previous_team_lead_id !== $team_lead_id
                && !in_array($previous_team_lead_id, $removed_member_ids, true)
                && $previous_team_lead_id !== $user_id
            ) {
                createNotification(
                    $conn,
                    $previous_team_lead_id,
                    "Team",
                    "Team Lead Assignment Removed",
                    "You are no longer the Team Lead for \"" . $name . "\".",
                    "Teams",
                    $team_id,
                    "view_team.php?id=" . $team_id
                );
            }

            foreach ($members as $member_id) {
                $member_id = (int)$member_id;

                if (in_array($member_id, $new_member_ids, true)) {
                    continue;
                }

                if ($member_id === $user_id) {
                    continue;
                }

                if (
                    $member_id === $team_lead_id
                    && $team_lead_id !== $previous_team_lead_id
                ) {
                    continue;
                }

                createNotification(
                    $conn,
                    $member_id,
                    "Team",
                    "Team Updated",
                    "The team \"" . $name . "\" was updated.",
                    "Teams",
                    $team_id,
                    "view_team.php?id=" . $team_id
                );
            }

            header("Location: view_team.php?id=" . $team_id);
            exit;

        } catch (Throwable $e) {

            $conn->rollback();
            $errors[] = $e->getMessage();
        }
    }
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Edit Team</title>

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
        <div class="sidebar-item">
            <a href="events.php">Meetings / Events</a>
        </div>
        <div class="sidebar-item active">
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

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
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

<div class="main">

    <div class="header">

        <div>
            <h1>EDIT TEAM</h1>

            <div class="sub">
                <?php echo e($team["name"]); ?>
                &nbsp;·&nbsp;
                <?php echo e($team["project_name"]); ?>
            </div>
        </div>

        <a
            class="button"
            href="view_team.php?id=<?php echo (int)$team_id; ?>"
        >
            BACK TO TEAM
        </a>

    </div>

    <div class="content">

    <?php if ($errors): ?>

        <div class="error">
            <strong>Please correct the following:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <form method="POST">

        <?php echo csrfField(); ?>

        <input
            type="hidden"
            name="team_id"
            value="<?php echo (int)$team_id; ?>"
        >

        <div class="card">

            <h2>Team Information</h2>

            <div class="grid">

                <div class="field full">
                    <label for="name">
                        Team Name <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?php echo e($name); ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="project_id">
                        Project <span class="required">*</span>
                    </label>

                    <select
                        id="project_id"
                        name="project_id"
                        required
                    >
                        <option value="">Select Project</option>

                        <?php foreach ($projects as $project): ?>

                            <option
                                value="<?php echo (int)$project["id"]; ?>"
                                <?php echo $project_id === (int)$project["id"] ? "selected" : ""; ?>
                            >
                                <?php
                                echo e(
                                    $project["name"]
                                    . " — "
                                    . $project["department"]
                                );
                                ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="field">
                    <label for="status">
                        Status <span class="required">*</span>
                    </label>

                    <select
                        id="status"
                        name="status"
                        required
                    >

                        <?php foreach ($statuses as $item): ?>

                            <option
                                value="<?php echo e($item); ?>"
                                <?php echo $status === $item ? "selected" : ""; ?>
                            >
                                <?php echo e($item); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="field full">
                    <label for="description">
                        Team Description / Purpose
                        <span class="required">*</span>
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        required
                    ><?php echo e($description); ?></textarea>
                </div>

                <div class="field full">
                    <label for="responsibilities">
                        Responsibilities
                        <span class="required">*</span>
                    </label>

                    <textarea
                        id="responsibilities"
                        name="responsibilities"
                        required
                    ><?php echo e($responsibilities); ?></textarea>
                </div>

            </div>

        </div>

        <div class="card">

            <h2>Team Members</h2>

            <div class="note" style="margin-bottom:12px;">
                Members can come from multiple departments and can belong to
                multiple teams within the same project.
            </div>

            <div class="checkbox-grid">

                <?php foreach ($users as $person): ?>

                    <?php $person_id = (int)$person["id"]; ?>

                    <div class="checkbox-item">

                        <label>

                            <input
                                type="checkbox"
                                name="members[]"
                                value="<?php echo $person_id; ?>"
                                <?php echo in_array($person_id, $members, true) ? "checked" : ""; ?>
                            >

                            <?php
                            echo e(
                                $person["first_name"]
                                . " "
                                . $person["last_name"]
                            );
                            ?>

                            <small style="color:#6b7280;">
                                —
                                <?php echo e($person["department"]); ?>
                            </small>

                        </label>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <div class="card">

            <h2>Team Lead</h2>

            <div class="field">

                <label for="team_lead_id">
                    Team Lead <span class="required">*</span>
                </label>

                <select
                    id="team_lead_id"
                    name="team_lead_id"
                    required
                >

                    <option value="">Select Team Lead</option>

                    <?php foreach ($users as $person): ?>

                        <option
                            value="<?php echo (int)$person["id"]; ?>"
                            <?php echo $team_lead_id === (int)$person["id"] ? "selected" : ""; ?>
                        >
                            <?php
                            echo e(
                                $person["first_name"]
                                . " "
                                . $person["last_name"]
                                . " — "
                                . $person["department"]
                            );
                            ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <div class="note">
                    The Team Lead must also be checked in the Team Members list.
                </div>

            </div>

        </div>

        <div class="actions">

            <a
                class="button"
                href="view_team.php?id=<?php echo (int)$team_id; ?>"
            >
                DISCARD CHANGES
            </a>

            <button
                class="button primary"
                type="submit"
            >
                SAVE CHANGES
            </button>

        </div>

    </form>

</div>

</div>

</div>

</body>
</html>
