<?php
session_start();
include "../db_connect.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit();
}

$uid = intval($_SESSION["user_id"]);
$stmt = $conn->prepare("UPDATE users SET has_seen_tour = TRUE WHERE id = ?");
$stmt->execute([$uid]);

echo json_encode(["success" => true]);
?>