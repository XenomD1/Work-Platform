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

$statuses = ["Active", "Inactive"];
$errors = [];

/*
 * Load active users for team lead/member selection.
 */
$users = [];
$user_list = $conn->query("
    SELECT id, first_name, last_name, department
    FROM users
    WHERE active = 1
    ORDER BY last_name, first_name
");

if ($user_list) {
    while ($row = $user_list->fetch_assoc()) {
        $users[] = $row;
    }
}

/*
 * Load projects the user is allowed to create teams for.
 *
 * Administrator: any project
 * Manager: projects in their department
 * Project Manager: projects where projects.manager_id matches their account
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

if (!$projects) {
    header("Location: teams.php");
    exit;
}

$name = "";
$project_id = 0;
$description = "";
$responsibilities = "";
$team_lead_id = 0;
$status = "Active";
$members = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $project_id = (int)($_POST["project_id"] ?? 0);
    $description = trim($_POST["description"] ?? "");
    $responsibilities = trim($_POST["responsibilities"] ?? "");
    $team_lead_id = (int)($_POST["team_lead_id"] ?? 0);
    $status = trim($_POST["status"] ?? "Active");

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

    if (count($members) === 0) {
        $errors[] = "Select at least one team member.";
    }

    if ($team_lead_id > 0 && !in_array($team_lead_id, $members, true)) {
        $errors[] = "The Team Lead must also be selected as a team member.";
    }

    /*
     * Validate selected project is one the current user can manage.
     */
    $allowed_project_ids = array_map(
        fn($project) => (int)$project["id"],
        $projects
    );

    if (
        $project_id > 0
        && !in_array($project_id, $allowed_project_ids, true)
    ) {
        $errors[] = "You do not have permission to create a team for that project.";
    }

    /*
     * Validate lead/member IDs are active users.
     */
    $valid_user_ids = [];

    foreach ($users as $person) {
        $valid_user_ids[(int)$person["id"]] = true;
    }

    if ($team_lead_id > 0 && !isset($valid_user_ids[$team_lead_id])) {
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

            $team_stmt = $conn->prepare("
                INSERT INTO teams
                (
                    project_id,
                    name,
                    description,
                    responsibilities,
                    team_lead_id,
                    status,
                    created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $team_stmt->bind_param(
                "isssisi",
                $project_id,
                $name,
                $description,
                $responsibilities,
                $team_lead_id,
                $status,
                $user_id
            );

            if (!$team_stmt->execute()) {
                throw new Exception("Unable to create team.");
            }

            $team_id = $team_stmt->insert_id;
            $team_stmt->close();

            $member_stmt = $conn->prepare("
                INSERT INTO team_members
                (team_id, user_id)
                VALUES (?, ?)
            ");

            foreach ($members as $member_id) {
                $member_stmt->bind_param("ii", $team_id, $member_id);

                if (!$member_stmt->execute()) {
                    throw new Exception("Unable to add team members.");
                }
            }

            $member_stmt->close();

            $conn->commit();

            foreach ($members as $member_id) {
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

            header("Location: view_team.php?id=" . $team_id);
            exit;

        } catch (Throwable $e) {

            $conn->rollback();
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>New Team</title>

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
            <h1>NEW TEAM</h1>
            <div class="sub">
                Create a project-based team
            </div>
        </div>

        <a class="button" href="teams.php">
            BACK TO TEAMS
        </a>

    </div>

    <div class="content">

    <?php if ($errors): ?>

        <div class="error">
            <strong>Please correct the following:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <form method="POST">

        <?= csrfField() ?>

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
                        value="<?php echo htmlspecialchars($name); ?>"
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
                                echo htmlspecialchars(
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
                                value="<?php echo htmlspecialchars($item); ?>"
                                <?php echo $status === $item ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars($item); ?>
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
                    ><?php echo htmlspecialchars($description); ?></textarea>
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
                    ><?php echo htmlspecialchars($responsibilities); ?></textarea>
                </div>

            </div>
        </div>

        <div class="card">
            <h2>Team Members</h2>

            <div class="field full">

                <label>
                    Members <span class="required">*</span>
                </label>

                <div class="note">
                    Members can come from multiple departments.
                    An employee may belong to multiple teams.
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
                                echo htmlspecialchars(
                                    $person["first_name"]
                                    . " "
                                    . $person["last_name"]
                                );
                                ?>

                                <small style="color:#6b7280;">
                                    —
                                    <?php echo htmlspecialchars($person["department"]); ?>
                                </small>

                            </label>
                        </div>

                    <?php endforeach; ?>

                </div>

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
                            echo htmlspecialchars(
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
                    The selected Team Lead must also be checked in the Members list above.
                </div>

            </div>
        </div>

        <div class="actions">

            <a class="button" href="teams.php">
                CANCEL
            </a>

            <button
                class="button primary"
                type="submit"
            >
                CREATE TEAM
            </button>

        </div>

    </form>

</div>

</div>

</div>

</body>
</html>
