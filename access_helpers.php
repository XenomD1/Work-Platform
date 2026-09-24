<?php

function workItemInfo(mysqli $conn, string $module, int $record_id): ?array {
    $map = [
        "Project" => ["SELECT id, name AS title FROM projects WHERE id=?", "projects.php?project="],
        "Task" => ["SELECT id, name AS title FROM tasks WHERE id=?", "view_task.php?id="],
        "Event" => ["SELECT id, name AS title FROM events WHERE id=? AND deleted=0", "view_event.php?id="],
        "Team" => ["SELECT id, name AS title FROM teams WHERE id=?", "view_team.php?id="],
        "File" => ["SELECT id, name AS title FROM files WHERE id=? AND is_trashed=0", "view_file.php?id="],
        "Report" => ["SELECT id, title FROM reports WHERE id=?", "view_report.php?id="],
        "Training" => ["SELECT id, name AS title FROM training WHERE id=? AND deleted=0", "view_training.php?id="],
        "Time Off" => ["SELECT id, CONCAT(time_off_type, ' - ', start_date) AS title FROM time_off WHERE id=?", "view_time_off.php?id="]
    ];
    if (!isset($map[$module]) || $record_id <= 0) return null;
    [$sql, $prefix] = $map[$module];
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $row["url"] = $prefix . $record_id;
    return $row;
}

function userCanAccessWorkItem(mysqli $conn, int $user_id, string $role, string $department, string $module, int $record_id): bool {
    if ($record_id <= 0 || $user_id <= 0) return false;

    if ($module === "Project") {
        $stmt=$conn->prepare("SELECT department,manager_id FROM projects WHERE id=?");
        $stmt->bind_param("i",$record_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$row) return false;
        if($role==="Administrator") return true;
        if(($role==="Manager"||$role==="IT Administrator") && $row["department"]===$department) return true;
        if((int)($row["manager_id"]??0)===$user_id) return true;
        $stmt=$conn->prepare("SELECT COUNT(*) total FROM teams t LEFT JOIN team_members tm ON tm.team_id=t.id WHERE t.project_id=? AND (t.team_lead_id=? OR tm.user_id=?)");
        $stmt->bind_param("iii",$record_id,$user_id,$user_id); $stmt->execute(); $ok=(int)$stmt->get_result()->fetch_assoc()["total"]>0; $stmt->close();
        if($ok) return true;
        $stmt=$conn->prepare("SELECT COUNT(*) total FROM tasks t INNER JOIN task_assignments ta ON ta.task_id=t.id WHERE t.project_id=? AND ta.user_id=?");
        $stmt->bind_param("ii",$record_id,$user_id); $stmt->execute(); $ok=(int)$stmt->get_result()->fetch_assoc()["total"]>0; $stmt->close();
        return $ok;
    }

    if ($module === "Task") {
        $stmt=$conn->prepare("SELECT p.department,p.manager_id,EXISTS(SELECT 1 FROM task_assignments ta WHERE ta.task_id=t.id AND ta.user_id=?) assigned FROM tasks t INNER JOIN projects p ON p.id=t.project_id WHERE t.id=?");
        $stmt->bind_param("ii",$user_id,$record_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$row) return false;
        return $role==="Administrator" || (($role==="Manager"||$role==="IT Administrator")&&$row["department"]===$department) || (int)($row["manager_id"]??0)===$user_id || (int)$row["assigned"]===1;
    }

    if ($module === "Event") {
        $stmt=$conn->prepare("SELECT e.department,e.type,e.organizer,EXISTS(SELECT 1 FROM event_attendees ea WHERE ea.event_id=e.id AND ea.user_id=?) attendee FROM events e WHERE e.id=? AND e.deleted=0");
        $stmt->bind_param("ii",$user_id,$record_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$row) return false;
        return $role==="Administrator" || $row["type"]==="Organization-Wide Event" || $row["department"]===$department || (int)$row["organizer"]===$user_id || (int)$row["attendee"]===1;
    }

    if ($module === "Team") {
        $stmt=$conn->prepare("SELECT p.department,p.manager_id,t.team_lead_id,EXISTS(SELECT 1 FROM team_members tm WHERE tm.team_id=t.id AND tm.user_id=?) member FROM teams t INNER JOIN projects p ON p.id=t.project_id WHERE t.id=?");
        $stmt->bind_param("ii",$user_id,$record_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$row) return false;
        return $role==="Administrator" || (($role==="Manager"||$role==="IT Administrator")&&$row["department"]===$department) || (int)($row["manager_id"]??0)===$user_id || (int)$row["team_lead_id"]===$user_id || (int)$row["member"]===1;
    }

    if ($module === "Training") {
        $stmt=$conn->prepare("SELECT t.department,t.organizer,EXISTS(SELECT 1 FROM training_attendees ta WHERE ta.training_id=t.id AND (ta.user_id=? OR ta.department=?)) attendee FROM training t WHERE t.id=? AND t.deleted=0");
        $stmt->bind_param("isi",$user_id,$department,$record_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$row) return false;
        return $role==="Administrator" || $row["department"]===$department || (int)$row["organizer"]===$user_id || (int)$row["attendee"]===1;
    }

    if ($module === "Time Off") {
        $stmt=$conn->prepare("SELECT t.user_id,u.department FROM time_off t INNER JOIN users u ON u.id=t.user_id WHERE t.id=?");
        $stmt->bind_param("i",$record_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$row) return false;
        return $role==="Administrator" || (int)$row["user_id"]===$user_id || (($role==="Manager"||$role==="IT Administrator")&&$row["department"]===$department);
    }

    if ($module === "Report") {
        $stmt=$conn->prepare("SELECT r.created_by,r.creator_level,r.project_id,p.department,p.manager_id FROM reports r INNER JOIN projects p ON p.id=r.project_id WHERE r.id=?");
        $stmt->bind_param("i",$record_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$row) return false;
        if($role==="Administrator" || (int)$row["created_by"]===$user_id) return true;
        if($role==="IT Administrator" && $row["department"]===$department) return true;
        if($role==="Manager" && in_array($row["creator_level"],["Employee","Team Lead"],true) && ($row["department"]===$department || (int)($row["manager_id"]??0)===$user_id)) return true;
        if($role==="Manager") return false;
        $creator=(int)$row["created_by"]; $project=(int)$row["project_id"];
        $stmt=$conn->prepare("SELECT COUNT(*) total FROM teams t INNER JOIN team_members tm ON tm.team_id=t.id WHERE t.project_id=? AND t.team_lead_id=? AND tm.user_id=?");
        $stmt->bind_param("iii",$project,$user_id,$creator); $stmt->execute(); $ok=(int)$stmt->get_result()->fetch_assoc()["total"]>0 && in_array($row["creator_level"],["Employee","Team Lead"],true); $stmt->close();
        return $ok;
    }

    if ($module === "File") {
        $stmt=$conn->prepare("SELECT location_type,location_id FROM files WHERE id=? AND is_trashed=0");
        $stmt->bind_param("i",$record_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$row) return false;
        if($role==="Administrator" || $row["location_type"]==="Organization") return true;
        if($row["location_type"]==="Department") return (string)$row["location_id"]===(string)$department;
        if(in_array($row["location_type"],["Project","Task","Team","Event","Training"],true))
            return userCanAccessWorkItem($conn,$user_id,$role,$department,$row["location_type"],(int)$row["location_id"]);
        return false;
    }
    return false;
}
