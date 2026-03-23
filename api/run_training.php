<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');

include_once '../config/Database.php';
include_once '../models/Training.php';

$database = new Database();
$db = $database->connect();
$training = new Training($db);

// Citim datele JSON trimise din Postman
$data = json_decode(file_get_contents("php://input"));

if(!empty($data->team_id)) {
    // Dacă am primit și setări noi, le salvăm întâi
    if(!empty($data->focus) && !empty($data->intensity)) {
        $training->updateSettings($data->team_id, $data->focus, $data->intensity);
    }

    // Rulăm procedura de antrenament
    $result = $training->runWeekly($data->team_id);

    if($result === true) {
        echo json_encode([
            "status" => "success",
            "message" => "Antrenament finalizat! Jucătorii au evoluat, dar sunt mai obosiți."
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => $result]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Lipsesc datele (team_id)."]);
}
?>