<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';
include_once '../models/User.php';

$database = new Database();
$db = $database->connect();
$userObj = new User($db);

// Prinde datele trimise prin POST (format JSON)
$data = json_decode(file_get_contents("php://input"));

if(!empty($data->mail) && !empty($data->password)) {
    $auth = $userObj->login($data->mail, $data->password);

    if($auth) {
        echo json_encode([
            "status" => "success",
            "user_id" => $auth['user_id'],
            "team_id" => $auth['team_id'],
            "message" => "Autentificare reușită!"
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Date incorecte."]);
    }
}
?>