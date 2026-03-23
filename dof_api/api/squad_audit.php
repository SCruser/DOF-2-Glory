<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';

$database = new Database();
$db = $database->connect();

$team_id = 1;

try {
    $queryMgr = "SELECT f.*, m.name as manager_name 
                 FROM managers m 
                 JOIN formations f ON m.preferred_formation_id = f.formation_id 
                 WHERE m.team_id = :tid";
    $stmtMgr = $db->prepare($queryMgr);
    $stmtMgr->execute(['tid' => $team_id]);
    $formation = $stmtMgr->fetch(PDO::FETCH_ASSOC);

    if (!$formation) throw new Exception("Echipa nu are manager!");

    $queryPlayers = "SELECT pc.player_id, pc.name, ao.* FROM player_core pc
                     JOIN attributes_outfield ao ON pc.player_id = ao.player_id
                     WHERE pc.team_id = :tid AND pc.is_GK = 0";
    $stmtPlayers = $db->prepare($queryPlayers);
    $stmtPlayers->execute(['tid' => $team_id]);
    $players = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);

    $squad_distribution = [
        'GK' => [], 'ST' => [], 'Winger' => [], 'CAM' => [],
        'CM' => [], 'CDM' => [], 'CB' => [], 'Fullback' => []
    ];

    // Preluăm portarii
    $queryGK = "SELECT pc.player_id, pc.name,
                (ag.reflexes + ag.handling + ag.one_on_ones + ag.aerial_reach + ag.command_of_area) as gk_score
                FROM player_core pc
                JOIN attributes_goalkeeper ag ON pc.player_id = ag.player_id
                WHERE pc.team_id = :tid";
    $stmtGK = $db->prepare($queryGK);
    $stmtGK->execute(['tid' => $team_id]);
    while ($gk = $stmtGK->fetch(PDO::FETCH_ASSOC)) {
        $squad_distribution['GK'][] = [
            'name' => $gk['name'],
            'score' => $gk['gk_score']
        ];
    }

    foreach ($players as $p) {
        $scores = [
            'ST'       => $p['finishing'] + $p['dribbling'] + $p['first_touch'] + $p['heading'],
            'Winger'   => $p['pace'] + $p['dribbling'] + $p['crossing'] + $p['stamina_max'],
            'CAM'      => $p['passing'] + $p['first_touch'] + $p['long_shots'] + $p['dribbling'],
            'CM'       => $p['passing'] + $p['first_touch'] + $p['stamina_max'] + $p['marking'],
            'CDM'      => $p['tackling'] + $p['marking'] + $p['passing'] + $p['strength'],
            'CB'       => $p['tackling'] + $p['marking'] + $p['strength'] + $p['heading'],
            'Fullback' => $p['marking'] + $p['pace'] + $p['tackling'] + $p['stamina_max']
        ];

        arsort($scores);
        $best_pos = key($scores);
        $best_score = current($scores);

        $squad_distribution[$best_pos][] = [
            "name" => $p['name'],
            "score" => $best_score
        ];
    }

    $needed = [
        "GK" => 1,
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