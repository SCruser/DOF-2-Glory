<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';

$database = new Database();
$db = $database->connect();

$team_id = 1; // Otelul Galati

try {
    // 1. Aflăm formația managerului
    $queryMgr = "SELECT f.*, m.name as manager_name 
                 FROM managers m 
                 JOIN formations f ON m.preferred_formation_id = f.formation_id 
                 WHERE m.team_id = :tid";
    $stmtMgr = $db->prepare($queryMgr);
    $stmtMgr->execute(['tid' => $team_id]);
    $formation = $stmtMgr->fetch(PDO::FETCH_ASSOC);

    if (!$formation) throw new Exception("Echipa nu are manager angajat!");

    // 2. Extragem jucătorii de câmp (Outfield)
    $queryOutfield = "SELECT pc.player_id, pc.name, ao.* FROM player_core pc
                     JOIN attributes_outfield ao ON pc.player_id = ao.player_id
                     WHERE pc.team_id = :tid";
    $stmtOutfield = $db->prepare($queryOutfield);
    $stmtOutfield->execute(['tid' => $team_id]);
    $roster_outfield = $stmtOutfield->fetchAll(PDO::FETCH_ASSOC);

    // 3. Extragem portarii (GK) și calculăm scorul direct din SQL
    $queryGK = "SELECT pc.player_id, pc.name, 
                (ag.reflexes + ag.handling + ag.one_on_ones + ag.aerial_reach + ag.command_of_area + ag.communication) as gk_score 
                FROM player_core pc
                JOIN attributes_goalkeeper ag ON pc.player_id = ag.player_id
                WHERE pc.team_id = :tid
                ORDER BY gk_score DESC";
    $stmtGK = $db->prepare($queryGK);
    $stmtGK->execute(['tid' => $team_id]);
    $roster_gk = $stmtGK->fetchAll(PDO::FETCH_ASSOC);

    // Validări de lot
    $total_players = count($roster_outfield) + count($roster_gk);
    if ($total_players < 11) throw new Exception("Nu ai suficienți jucători în lot pentru un prim 11!");
    if (count($roster_gk) < 1) throw new Exception("Eroare critică: Nu ai niciun portar în echipă!");

    $starting_xi = [];
    $bench = [];

    // --- SELECȚIA PORTARULUI (GK) ---
    // Primul din array este cel mai bun, datorită ORDER BY DESC din interogare
    $best_gk = $roster_gk[0];
    $starting_xi['GK'][] = [
        'name' => $best_gk['name'],
        'performance_score' => $best_gk['gk_score']
    ];

    // Restul portarilor merg pe bancă
    for ($i = 1; $i < count($roster_gk); $i++) {
        $bench[] = $roster_gk[$i]['name'] . ' (GK)';
    }

    // --- CALCULĂM CARNETUL DE NOTE PENTRU OUTFIELD ---
    $available_players = [];
    foreach ($roster_outfield as $p) {
        $available_players[$p['player_id']] = [
            'name' => $p['name'],
            'scores' => [
                'ST'       => $p['finishing'] + $p['dribbling'] + $p['first_touch'] + $p['heading'],
                'Winger'   => $p['pace'] + $p['dribbling'] + $p['crossing'] + $p['stamina_max'],
                'CAM'      => $p['passing'] + $p['first_touch'] + $p['long_shots'] + $p['dribbling'],
                'CM'       => $p['passing'] + $p['first_touch'] + $p['stamina_max'] + $p['marking'],
                'CDM'      => $p['tackling'] + $p['marking'] + $p['passing'] + $p['strength'],
                'CB'       => $p['tackling'] + $p['marking'] + $p['strength'] + $p['heading'],
                'Fullback' => $p['marking'] + $p['pace'] + $p['tackling'] + $p['stamina_max']
            ]
        ];
    }

    // --- MAPAREA POZIȚIILOR NECESARE DIN FORMAȚIE ---
    $slots_needed = [];
    $positions_map = [
        'st' => 'ST', 'lw' => 'LW', 'rw' => 'RW', 'lm' => 'LM', 'rm' => 'RM',
        'cam' => 'CAM', 'cm' => 'CM', 'cdm' => 'CDM', 'lb' => 'LB', 'cb' => 'CB', 'rb' => 'RB'
    ];

    foreach ($positions_map as $db_col => $exact_pos) {
        $count = $formation[$db_col];
        for ($i = 0; $i < $count; $i++) {
            $slots_needed[] = $exact_pos;
        }
    }

    // --- ALGORITMUL GREEDY (SELECȚIA OUTFIELD) ---
    foreach ($slots_needed as $exact_position) {
        $best_player_id = null;
        $highest_score = -1;

        // Mapăm poziția exactă pe formula potrivită
        $formula_key = '';
        if (in_array($exact_position, ['LW', 'RW', 'LM', 'RM'])) $formula_key = 'Winger';
        elseif (in_array($exact_position, ['LB', 'RB'])) $formula_key = 'Fullback';
        else $formula_key = $exact_position; // ST, CAM, CM, CDM, CB

        // Căutăm cel mai bun jucător pentru acest post
        foreach ($available_players as $id => $p) {
            if ($p['scores'][$formula_key] > $highest_score) {
                $highest_score = $p['scores'][$formula_key];
                $best_player_id = $id;
            }
        }

        // Asignăm jucătorul
        if (!isset($starting_xi[$exact_position])) {
            $starting_xi[$exact_position] = [];
        }

        $starting_xi[$exact_position][] = [
            'name' => $available_players[$best_player_id]['name'],
            'performance_score' => $highest_score
        ];

        // Îl scoatem din lista de disponibili ca să nu-l punem pe 2 posturi
        unset($available_players[$best_player_id]);
    }

    // --- REZERVELE DE CÂMP ---
    foreach ($available_players as $id => $p) {
        $bench[] = $p['name'];
    }

    echo json_encode([
        "manager" => $formation['manager_name'],
        "tactic" => $formation['name'],
        "starting_11" => $starting_xi,
        "bench" => $bench
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>