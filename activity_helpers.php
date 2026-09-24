<?php
function logActivity(mysqli $conn, int $user_id, string $action_type, string $module, ?int $record_id, string $description, ?string $related_url=null): bool {
    if($user_id<=0 || trim($action_type)==="" || trim($module)==="" || trim($description)==="") return false;
    $stmt=$conn->prepare("INSERT INTO activity_log (user_id,action_type,module,record_id,description,related_url,created_at) VALUES (?,?,?,?,?,?,NOW())");
    if(!$stmt) return false;
    $stmt->bind_param("ississ",$user_id,$action_type,$module,$record_id,$description,$related_url);
    $ok=$stmt->execute(); $stmt->close(); return $ok;
}
