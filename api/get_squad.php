<?php
// Header-e necesare pentru a comunica cu Front-End-ul
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';
include_once '../models/Player.php';

// Inițializăm DB & Player object
$database = new Database();
$db = $database->connect();
$player = new Player($db);

// Luăm ID-ul echipei din URL
$team_id = isset($_GET['team_id']) ? $_GET['team_id'] : die();

// Rulăm query-ul
$result = $player->getTeamSquad($team_id);
$num = $result->rowCount();

if($num > 0) {
    $squad_arr = array();
    $squad_arr['data'] = array();

    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
        extract($row);
        $player_item = array(
            'id' => $player_id,
            'name' => $name,
            'age' => $age,
            'nat' => $country_code,
            'is_gk' => (bool)$is_GK,
            'finishing' => $finishing,
            'pace' => $pace,
            'stamina' => $current_stamina,
            'injured' => $injury_days_left > 0 ? true : false
        );
        array_push($squad_arr['data'], $player_item);
    }
    echo json_encode($squad_arr);
} else {
    echo json_encode(array('message' => 'No players found in this team.'));
}
?>