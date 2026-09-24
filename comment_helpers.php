<?php
require_once "notification_helpers.php";
require_once "activity_helpers.php";

function mentionKey(string $first, string $last): string {
    $key = trim($first) . "." . trim($last);
    $key = preg_replace('/[^A-Za-z0-9._-]+/', '.', $key);
    return trim($key, ".");
}

function extractMentionKeys(string $text): array {
    preg_match_all('/@([A-Za-z0-9._-]{2,100})/', $text, $m);
    return array_values(array_unique($m[1] ?? []));
}

function createWorkComment(mysqli $conn,int $user_id,string $module,int $record_id,string $text,string $title,string $url): int {
    $text=trim($text); if($text==="") return 0;
    $stmt=$conn->prepare("INSERT INTO comments (module,record_id,user_id,comment_text,created_at) VALUES (?,?,?,?,NOW())");
    $stmt->bind_param("siis",$module,$record_id,$user_id,$text);
    if(!$stmt->execute()){ $stmt->close(); return 0; }
    $comment_id=(int)$stmt->insert_id; $stmt->close();

    $keys=extractMentionKeys($text);
    if($keys){
        $result=$conn->query("SELECT id,first_name,last_name FROM users WHERE active=1");
        $people=[];
        while($row=$result->fetch_assoc()) $people[strtolower(mentionKey($row["first_name"],$row["last_name"]))][]=$row;
        $ins=$conn->prepare("INSERT IGNORE INTO comment_mentions (comment_id,user_id,created_at) VALUES (?,?,NOW())");
        foreach($keys as $key){
            foreach($people[strtolower($key)] ?? [] as $person){
                $mentioned=(int)$person["id"]; if($mentioned===$user_id) continue;
                $ins->bind_param("ii",$comment_id,$mentioned); $ins->execute();
                createNotification($conn,$mentioned,"Mention","You were mentioned","You were mentioned in a comment on \"" . $title . "\".",$module,$record_id,$url);
            }
        }
        $ins->close();
    }
    logActivity($conn,$user_id,"Comment Added",$module,$record_id,"Commented on \"" . $title . "\".",$url);
    return $comment_id;
}
