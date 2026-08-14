<?php
session_start();
include "../db.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit();
}

$uid = intval($_SESSION["user_id"]);
$conn->query("UPDATE users SET has_seen_tour = 1 WHERE id = $uid");

echo json_encode(["success" => true]);
?>