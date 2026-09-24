<?php
session_start();
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "notification_helpers.php";
require_once "csrf_helpers.php";

$user_id = (int)$_SESSION["user_id"];
$stmt = $conn->prepare("SELECT id, first_name, last_name, role, department FROM users WHERE id=? AND active=1");
$stmt->bind_param("i",$user_id); $stmt->execute();
$user=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$user){header("Location: login.php");exit;}

$role = $user["role"];
$user_department = $user["department"];
$current_full_name = trim(
    $user["first_name"] . " " . $user["last_name"]
);

$file_id=(int)($_GET["id"] ?? $_POST["file_id"] ?? 0);
if($file_id<=0){header("Location: files.php");exit;}

function e($v){return htmlspecialchars($v??"",ENT_QUOTES,"UTF-8");}

$stmt=$conn->prepare("
 SELECT f.*, fv.original_filename
 FROM files f
 LEFT JOIN file_versions fv ON fv.file_id=f.id AND fv.version_number=f.current_version
 WHERE f.id=? AND f.is_trashed=0
");
$stmt->bind_param("i",$file_id); $stmt->execute();
$file=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$file){header("Location: files.php");exit;}

/*
 * Re-check file access here.
 *
 * Never rely on view_file.php having been visited first because a user can
 * type this page's URL directly. These rules match the file viewing rules.
 */
function canAccessLocation(
    $conn,
    $location_type,
    $location_id,
    $user_id,
    $role,
    $user_department,
    $current_full_name
) {
    if ($location_type === "Organization") {
        return true;
    }

    if ($role === "Administrator") {
        return true;
    }

    if ($location_type === "Department") {
        return (string)$location_id === (string)$user_department;
    }

    if ($location_type === "Project") {
        $stmt = $conn->prepare("
            SELECT department, manager_id
            FROM projects
            WHERE id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$project) {
            return false;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $project["department"] === $user_department
        ) {
            return true;
        }

        if (
            $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM teams t
            INNER JOIN team_members tm
                ON tm.team_id = t.id
            WHERE t.project_id = ?
              AND tm.user_id = ?
        ");
        $stmt->bind_param("ii", $location_id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Task") {
        $stmt = $conn->prepare("
            SELECT
                p.department,
                p.manager_id
            FROM tasks t
            INNER JOIN projects p
                ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$task) {
            return false;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $task["department"] === $user_department
        ) {
            return true;
        }

        if (
            $task["manager_id"] !== null
            && (int)$task["manager_id"] === $user_id
        ) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM task_assignments
            WHERE task_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $location_id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Team") {
        $stmt = $conn->prepare("
            SELECT
                t.team_lead_id,
                p.department,
                p.manager_id
            FROM teams t
            INNER JOIN projects p
                ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $team = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$team) {
            return false;
        }

        if ((int)$team["team_lead_id"] === $user_id) {
            return true;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $team["department"] === $user_department
        ) {
            return true;
        }

        if (
            $team["manager_id"] !== null
            && (int)$team["manager_id"] === $user_id
        ) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM team_members
            WHERE team_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $location_id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Event") {
        $stmt = $conn->prepare("
            SELECT department, type, organizer
            FROM events
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$event) {
            return false;
        }

        if ($event["type"] === "Organization-Wide Event") {
            return true;
        }

        if ((int)$event["organizer"] === $user_id) {
            return true;
        }

        if ($event["department"] === $user_department) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM event_attendees
            WHERE event_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $location_id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Training") {
        $stmt = $conn->prepare("
            SELECT department, organizer
            FROM training
            WHERE id = ?
              AND deleted = 0
        ");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $training = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$training) {
            return false;
        }

        if ((int)$training["organizer"] === $user_id) {
            return true;
        }

        if ($training["department"] === $user_department) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM training_attendees
            WHERE training_id = ?
              AND (
                    user_id = ?
                    OR department = ?
              )
        ");
        $stmt->bind_param(
            "iis",
            $location_id,
            $user_id,
            $user_department
        );
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    return false;
}

$allowed = canAccessLocation(
    $conn,
    $file["location_type"],
    $file["location_id"],
    $user_id,
    $role,
    $user_department,
    $current_full_name
);

if (!$allowed) {
    http_response_code(403);
    exit("You do not have permission to upload a new version of this file.");
}

$errors=[];

if($_SERVER["REQUEST_METHOD"]==="POST"){
    requireValidCsrfToken();

    if(!isset($_FILES["upload_file"]) || $_FILES["upload_file"]["error"]!==UPLOAD_ERR_OK){
        $errors[]="Select a valid file to upload.";
    }

    if(!$errors){
        $original=basename($_FILES["upload_file"]["name"]);
        $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));

        /*
         * Allowlist, not a denylist: only file types actually needed
         * for business documents are accepted. Anything not on this
         * list is rejected, including any kind of executable script.
         */
        $allowed_mime_by_extension = [
            "pdf"  => ["application/pdf"],
            "doc"  => ["application/msword"],
            "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip"],
            "xls"  => ["application/vnd.ms-excel"],
            "xlsx" => ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip"],
            "ppt"  => ["application/vnd.ms-powerpoint"],
            "pptx" => ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "application/zip"],
            "txt"  => ["text/plain"],
            "csv"  => ["text/plain", "text/csv"],
            "rtf"  => ["text/rtf", "application/rtf"],
            "jpg"  => ["image/jpeg"],
            "jpeg" => ["image/jpeg"],
            "png"  => ["image/png"],
            "gif"  => ["image/gif"],
            "zip"  => ["application/zip"]
        ];

        if($original==="") {
            $errors[]="The uploaded file must have a filename.";
        } elseif(!array_key_exists($ext,$allowed_mime_by_extension)) {
            $errors[]="That file type is not allowed.";
        } else {
            $detected_mime = false;
            if (function_exists("finfo_open")) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detected_mime = finfo_file($finfo, $_FILES["upload_file"]["tmp_name"]);
                    finfo_close($finfo);
                }
            }

            if (
                $detected_mime !== false
                && !in_array($detected_mime, $allowed_mime_by_extension[$ext], true)
            ) {
                $errors[]="The file's contents don't match its extension.";
            }
        }
    }

    if(!$errors){
        $dir=__DIR__.DIRECTORY_SEPARATOR."uploads".DIRECTORY_SEPARATOR."files";
        if(!is_dir($dir) && !mkdir($dir,0775,true)){
            $errors[]="Unable to create the upload folder.";
        }
    }

    if(!$errors){
        $stored="file_".$file_id."_".time()."_".bin2hex(random_bytes(8)).($ext!==""?".".$ext:"");
        $target=$dir.DIRECTORY_SEPARATOR.$stored;

        if(!move_uploaded_file($_FILES["upload_file"]["tmp_name"],$target)){
            $errors[]="Unable to save the uploaded file.";
        }
    }

    if(!$errors){
        $relative="uploads/files/".$stored;
        $size=(int)filesize($target);
        $type=$_FILES["upload_file"]["type"] ?? null;

        $conn->begin_transaction();
        try{
            $lock=$conn->prepare("SELECT current_version FROM files WHERE id=? AND is_trashed=0 FOR UPDATE");
            $lock->bind_param("i",$file_id); $lock->execute();
            $row=$lock->get_result()->fetch_assoc(); $lock->close();
            if(!$row) throw new Exception("The file is no longer available.");

            $new_version=(int)$row["current_version"]+1;

            $v=$conn->prepare("
             INSERT INTO file_versions
             (file_id,version_number,original_filename,stored_filename,file_path,file_size,file_type,uploaded_by)
             VALUES (?,?,?,?,?,?,?,?)
            ");
            $v->bind_param("iisssisi",$file_id,$new_version,$original,$stored,$relative,$size,$type,$user_id);
            if(!$v->execute()) throw new Exception("Unable to save the new version.");
            $v->close();

            $u=$conn->prepare("UPDATE files SET current_version=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $u->bind_param("ii",$new_version,$file_id);
            if(!$u->execute()) throw new Exception("Unable to update the current version.");
            $u->close();

            $conn->commit();

            foreach (
                fileNotificationRecipientIds(
                    $conn,
                    (string)$file["location_type"],
                    $file["location_id"],
                    $user_id
                ) as $recipient_id
            ) {
                createNotification(
                    $conn,
                    $recipient_id,
                    "File",
                    "New File Version",
                    "A new version of \"" . $file["name"] . "\" was uploaded.",
                    "Files",
                    $file_id,
                    "view_file.php?id=" . $file_id
                );
            }

            if ((int)$file["uploaded_by"] !== $user_id) {
                createNotification(
                    $conn,
                    (int)$file["uploaded_by"],
                    "File",
                    "New File Version",
                    "A new version of your file \"" . $file["name"] . "\" was uploaded.",
                    "Files",
                    $file_id,
                    "view_file.php?id=" . $file_id
                );
            }

            header("Location: view_file.php?id=".$file_id);
            exit;
        }catch(Throwable $ex){
            $conn->rollback();
            if(isset($target)&&file_exists($target)) @unlink($target);
            $errors[]=$ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload New Version</title>
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
        <div class="sidebar-item">
            <a href="teams.php">Teams</a>
        </div>
        <div class="sidebar-item">
            <a href="org_chart.php">Organization Chart</a>
        </div>
        <div class="sidebar-item active">
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
<div class="header"><div><h1>UPLOAD NEW VERSION</h1><div class="sub">Keep older versions while replacing the current file</div></div><a class="button" href="view_file.php?id=<?php echo $file_id; ?>">BACK TO FILE</a></div>

<div class="content">

<?php if($errors): ?><div class="error"><strong>Please correct the following:</strong><ul><?php foreach($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="card"><h2>Current File</h2>
<div class="detail"><div class="label">File</div><div><?php echo e($file["name"]); ?></div></div>
<div class="detail"><div class="label">Current Version</div><div>Version <?php echo (int)$file["current_version"]; ?></div></div>
<div class="detail"><div class="label">Current Filename</div><div><?php echo e($file["original_filename"] ?: "—"); ?></div></div>
<div class="detail"><div class="label">Next Version</div><div>Version <?php echo (int)$file["current_version"]+1; ?></div></div>
</div>

<form method="POST" enctype="multipart/form-data">
<?= csrfField() ?>
<input type="hidden" name="file_id" value="<?php echo $file_id; ?>">
<div class="card"><h2>New Version</h2>
<label for="upload_file"><strong>Select Replacement File</strong></label>
<input type="file" id="upload_file" name="upload_file" required>
<div class="note">The previous versions remain in Version History and can still be downloaded.</div>
</div>
<div class="actions"><a class="button" href="view_file.php?id=<?php echo $file_id; ?>">CANCEL</a><button class="button primary" type="submit">UPLOAD NEW VERSION</button></div>
</form>
</div>
</div>
</div>
</body>
</html>
