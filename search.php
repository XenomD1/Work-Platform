<?php
session_start();
if(!isset($_SESSION["user_id"])){header("Location: login.php");exit;}
require "db.php"; require_once "role_helpers.php"; require_once "access_helpers.php"; require_once "announcement_helpers.php";
$user_id=(int)$_SESSION["user_id"];
$stmt=$conn->prepare("SELECT role,department FROM users WHERE id=? AND active=1");$stmt->bind_param("i",$user_id);$stmt->execute();$me=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$me){header("Location: login.php");exit;}
$role=$me["role"];$department=$me["department"];$q=trim($_GET["q"]??"");$type=trim($_GET["type"]??"");
$types=["Project","Task","Event","Team","File","Report","Training","Time Off"];$results=[];
if($q!==""){
 $like="%".$q."%";
 $queries=[
 "Project"=>["SELECT id,name title,description detail,CONCAT('projects.php?project=',id) url FROM projects WHERE name LIKE ? OR description LIKE ?","ss"],
 "Task"=>["SELECT id,name title,description detail,CONCAT('view_task.php?id=',id) url FROM tasks WHERE name LIKE ? OR description LIKE ?","ss"],
 "Event"=>["SELECT id,name title,description detail,CONCAT('view_event.php?id=',id) url FROM events WHERE deleted=0 AND (name LIKE ? OR description LIKE ?)","ss"],
 "Team"=>["SELECT id,name title,description detail,CONCAT('view_team.php?id=',id) url FROM teams WHERE name LIKE ? OR description LIKE ? OR responsibilities LIKE ?","sss"],
 "File"=>["SELECT id,name title,location_type detail,CONCAT('view_file.php?id=',id) url FROM files WHERE is_trashed=0 AND name LIKE ?","s"],
 "Report"=>["SELECT id,title,summary detail,CONCAT('view_report.php?id=',id) url FROM reports WHERE title LIKE ? OR summary LIKE ? OR accomplishments LIKE ? OR issues_risks LIKE ? OR next_steps LIKE ?","sssss"],
 "Training"=>["SELECT id,name title,description detail,CONCAT('view_training.php?id=',id) url FROM training WHERE deleted=0 AND (name LIKE ? OR description LIKE ?)","ss"],
 "Time Off"=>["SELECT id,CONCAT(time_off_type,' - ',start_date) title,reason_notes detail,CONCAT('view_time_off.php?id=',id) url FROM time_off WHERE time_off_type LIKE ? OR reason_notes LIKE ?","ss"]
 ];
 foreach($queries as $module=>[$sql,$bind]){
   if($type!==""&&$type!==$module)continue;
   $st=$conn->prepare($sql);$vals=array_fill(0,strlen($bind),$like);$st->bind_param($bind,...$vals);$st->execute();$r=$st->get_result();
   while($row=$r->fetch_assoc()) if(userCanAccessWorkItem($conn,$user_id,$role,$department,$module,(int)$row["id"])){$row["module"]=$module;$results[]=$row;}
   $st->close();
 }
}
function e($v){return htmlspecialchars((string)($v??""),ENT_QUOTES,"UTF-8");}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Search</title><link rel="stylesheet" href="app.css?v=21">
</head><body><div class="page">
<div class="sidebar"><div class="sidebar-spacer"></div><div class="sidebar-item"><a href="dashboard.php">Dashboard</a></div><div class="sidebar-item"><a href="tasks.php">Tasks</a></div><div class="sidebar-item"><a href="projects.php">Projects</a></div><div class="sidebar-item"><a href="calendar.php">Calendar</a></div><div class="sidebar-item"><a href="events.php">Meetings / Events</a></div><div class="sidebar-item"><a href="teams.php">Teams</a></div><div class="sidebar-item"><a href="org_chart.php">Organization Chart</a></div><div class="sidebar-item"><a href="files.php">Files</a></div><div class="sidebar-item"><a href="reports.php">Reports</a></div><div class="sidebar-item"><a href="analytics.php">Analytics</a></div><div class="sidebar-item"><a href="training.php">Training</a></div><div class="sidebar-item"><a href="time_off.php">Time Off</a></div><div class="sidebar-item"><a href="timesheets.php">Timesheets</a></div><div class="sidebar-item"><a href="notifications.php">Notifications</a></div><?php if(canPostAnnouncements($role)):?><div class="sidebar-item"><a href="announcements.php">Announcements</a></div><?php endif;?><?php if(isAnyAdministrator($role)):?><div class="sidebar-item"><a href="users.php">User Management</a></div><?php endif;?><?php if($role==="Administrator"):?><div class="sidebar-item"><a href="roles.php">Roles &amp; Permissions</a></div><?php endif;?><div class="sidebar-bottom"><div class="sidebar-item"><a href="account.php">Account</a></div><div class="sidebar-item"><a href="settings.php">Settings</a></div></div></div>
<main class="main"><div class="header"><div><h1>GLOBAL SEARCH</h1><div class="sub">Search work you are allowed to access</div></div></div><div class="content">
<form method="get" class="card"><div class="feature-grid"><div><label>Search</label><input name="q" value="<?=e($q)?>" placeholder="Search projects, tasks, files, reports..."></div><div><label>Type</label><select name="type"><option value="">All Types</option><?php foreach($types as $t):?><option value="<?=e($t)?>" <?=$type===$t?"selected":""?>><?=e($t)?></option><?php endforeach;?></select></div><button class="button primary" type="submit">SEARCH</button></div></form>
<?php if($q===""):?><div class="card">Enter a search term.</div><?php elseif(!$results):?><div class="card">No accessible results found.</div><?php else:foreach($results as $r):?><div class="card"><a class="result-card" href="<?=e($r["url"])?>"><div class="result-type"><?=e($r["module"])?></div><div class="result-title"><?=e($r["title"])?></div><div class="result-detail"><?=e(mb_strimwidth((string)($r["detail"]??""),0,250,"..."))?></div></a></div><?php endforeach;endif;?>
</div></main></div></body></html>
