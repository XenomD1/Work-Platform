<?php
session_start();if(!isset($_SESSION["user_id"])){header("Location: login.php");exit;}
if($_SERVER["REQUEST_METHOD"]!=="POST"){header("Location: dashboard.php");exit;}
require "db.php";require_once "access_helpers.php";require_once "comment_helpers.php";require_once "csrf_helpers.php";
requireValidCsrfToken();
$user_id=(int)$_SESSION["user_id"];$st=$conn->prepare("SELECT role,department FROM users WHERE id=? AND active=1");$st->bind_param("i",$user_id);$st->execute();$me=$st->get_result()->fetch_assoc();$st->close();if(!$me){header("Location: login.php");exit;}
$module=trim($_POST["module"]??"");$id=(int)($_POST["record_id"]??0);$text=trim($_POST["comment_text"]??"");$allowed=["Project","Task","Event","Team","File","Report","Training","Time Off"];
if(!in_array($module,$allowed,true)||!userCanAccessWorkItem($conn,$user_id,$me["role"],$me["department"],$module,$id)){http_response_code(403);exit("You do not have permission to comment on this record.");}
$item=workItemInfo($conn,$module,$id);if(!$item){http_response_code(404);exit("Record not found.");}
if($text!=="" && mb_strlen($text)<=5000) createWorkComment($conn,$user_id,$module,$id,$text,$item["title"],$item["url"]);
header("Location: comments.php?".http_build_query(["module"=>$module,"id"=>$id]));exit;
