<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';

$database = new Database();
$db = $database->connect();

$team_id = 1; // Otelul Galati

try {
    // 1. Preluăm cerințele tactice ale managerului
    $queryMgr = "SELECT f.*, m.name as manager_name 
                 FROM managers m 
                 JOIN formations f ON m.preferred_formation_id = f.formation_id 
                 WHERE m.team_id = :tid";
    $stmtMgr = $db->prepare($queryMgr);
    $stmtMgr->execute(['tid' => $team_id]);
    $formation = $stmtMgr->fetch(PDO::FETCH_ASSOC);

    if (!$formation) throw new Exception("Echipa nu are manager!");

    // 2. Preluăm toți jucătorii cu atributele lor tehnice
    $queryPlayers = "SELECT pc.player_id, pc.name, ao.* FROM player_core pc
                     JOIN attributes_outfield ao ON pc.player_id = ao.player_id
                     WHERE pc.team_id = :tid";
    $stmtPlayers = $db->prepare($queryPlayers);
    $stmtPlayers->execute(['tid' => $team_id]);
    $players = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);

    $squad_distribution = [
        'ST' => [], 'Winger' => [], 'CAM' => [],
        'CM' => [], 'CDM' => [], 'CB' => [], 'Fullback' => []
    ];

    foreach ($players as $p) {
        // Formulele tale de calcul pentru scorul de poziție
        $scores = [
            'ST'       => $p['finishing'] + $p['dribbling'] + $p['first_touch'] + $p['heading'],
            'Winger'   => $p['pace'] + $p['dribbling'] + $p['crossing'] + $p['stamina_max'],
            'CAM'      => $p['passing'] + $p['first_touch'] + $p['long_shots'] + $p['dribbling'],
            'CM'       => $p['passing'] + $p['first_touch'] + $p['stamina_max'] + $p['marking'],
            'CDM'      => $p['tackling'] + $p['marking'] + $p['passing'] + $p['strength'],
            'CB'       => $p['tackling'] + $p['marking'] + $p['strength'] + $p['heading'],
            'Fullback' => $p['marking'] + $p['pace'] + $p['tackling'] + $p['stamina_max']
        ];

        // Găsim cea mai bună poziție pentru acest jucător
        arsort($scores);
        $best_pos = key($scores);
        $best_score = current($scores);

        $squad_distribution[$best_pos][] = [
            "name" => $p['name'],
            "score" => $best_score
        ];
    }

    // 3. Calculăm necesarul bazat pe formația managerului
    $needed = [
        "ST" => $formation['st'],
        "Winger" => $formation['lw'] + $formation['rw'] + $formation['lm'] + $formation['rm'],
        "CAM" => $formation['cam'],
        "CM" => $formation['cm'],
        "CDM" => $formation['cdm'],
        "CB" => $formation['cb'],
        "Fullback" => $formation['lb'] + $formation['rb']
    ];

    $audit_report = [];
    foreach ($needed as $role => $count) {
        $found = count($squad_distribution[$role]);
        $diff = $found - $count;

        $status = "Echilibrat";
        if ($diff < 0) $status = "DEFICIT (Ai nevoie de " . abs($diff) . ")";
        if ($diff > 2) $status = "SURPLUS (Vinde " . ($diff - 1) . ")";

        $audit_report[$role] = [
            "required_by_tactic" => $count,
            "identified_in_squad" => $found,
            "status" => $status,
            "players" => $squad_distribution[$role]
        ];
    }

    echo json_encode([
        "manager" => $formation['manager_name'],
        "tactic" => $formation['name'],
        "audit" => $audit_report
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>