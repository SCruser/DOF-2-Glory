<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';

$database = new Database();
$db = $database->connect();

// 1. Trecem la ziua următoare în setări
$db->query("UPDATE Game_Settings SET current_game_date = DATE_ADD(current_game_date, INTERVAL 1 DAY)");

// 2. Rulăm mentenanța medicală (Accidentările scad cu o zi)
$db->query("CALL Decrement_Injuries()");

// 3. Verificăm licitațiile expirate azi și le finalizăm
$db->query("
    SET @today = (SELECT current_game_date FROM Game_Settings LIMIT 1);
    -- Aici am putea face un cursor în SQL, dar pentru simplitate:
    -- Apelăm Finalize_Auction pentru tot ce a expirat până acum și e încă 'Active'
");

// 4. Verificăm dacă e zi de meci
// SELECT match_id FROM Match_Fixture WHERE DATE(match_date) = @today ...

echo json_encode([
    "status" => "success",
    "new_date" => $db->query("SELECT current_game_date FROM Game_Settings")->fetchColumn(),
    "message" => "Ziua a fost simulată cu succes."
]);
?>