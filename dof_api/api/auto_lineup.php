<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';

$database = new Database();
$db = $database->connect();

$team_id = 1;

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

    // 2. Extragem jucătorii de câmp (Acum aducem și preferred_foot)
    $queryOutfield = "SELECT pc.player_id, pc.name, pc.preferred_foot, ao.* FROM player_core pc
                 JOIN attributes_outfield ao ON pc.player_id = ao.player_id
                 WHERE pc.team_id = :tid AND pc.is_GK = 0";
    $stmtOutfield = $db->prepare($queryOutfield);
    $stmtOutfield->execute(['tid' => $team_id]);
    $roster_outfield = $stmtOutfield->fetchAll(PDO::FETCH_ASSOC);

    // 3. Extragem portarii (GK)
    $queryGK = "SELECT pc.player_id, pc.name, 
                (ag.reflexes + ag.handling + ag.one_on_ones + ag.aerial_reach + ag.command_of_area + ag.communication) as gk_score 
                FROM player_core pc
                JOIN attributes_goalkeeper ag ON pc.player_id = ag.player_id
                WHERE pc.team_id = :tid
                ORDER BY gk_score DESC";
    $stmtGK = $db->prepare($queryGK);
    $stmtGK->execute(['tid' => $team_id]);
    $roster_gk = $stmtGK->fetchAll(PDO::FETCH_ASSOC);

    $total_players = count($roster_outfield) + count($roster_gk);
    if ($total_players < 11) throw new Exception("Nu ai suficienți jucători în lot pentru un prim 11!");
    if (count($roster_gk) < 1) throw new Exception("Eroare critică: Nu ai niciun portar în echipă!");

    $starting_xi = [];
    $bench = [];

    // --- SELECȚIA PORTARULUI ---
    $best_gk = $roster_gk[0];
    $starting_xi['GK'][] = ['name' => $best_gk['name'], 'score' => $best_gk['gk_score']];
    for ($i = 1; $i < count($roster_gk); $i++) { $bench[] = $roster_gk[$i]['name'] . ' (GK)'; }

    // --- CARNETUL DE NOTE ---
    $available_players = [];
    foreach ($roster_outfield as $p) {
        $available_players[$p['player_id']] = [
            'name' => $p['name'],
            'foot' => $p['preferred_foot'],
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

    // --- MAPARE ȘI DRAFT GREEDY ---
    $positions_map = [
        'st' => 'ST', 'lw' => 'LW', 'rw' => 'RW', 'lm' => 'LM', 'rm' => 'RM',
        'cam' => 'CAM', 'cm' => 'CM', 'cdm' => 'CDM', 'lb' => 'LB', 'cb' => 'CB', 'rb' => 'RB'
    ];
    $drafted = [];

    foreach ($positions_map as $db_col => $exact_pos) {
        $count = $formation[$db_col];
        if ($count == 0) continue;

        $drafted[$exact_pos] = [];
        $formula_key = (in_array($exact_pos, ['LW', 'RW', 'LM', 'RM'])) ? 'Winger' :
            ((in_array($exact_pos, ['LB', 'RB'])) ? 'Fullback' : $exact_pos);

        // Selectăm cei mai buni jucători pentru această poziție (ex: cei mai buni 2 CB)
        for ($i = 0; $i < $count; $i++) {
            $best_id = null; $highest = -1;
            foreach ($available_players as $id => $p) {
                if ($p['scores'][$formula_key] > $highest) {
                    $highest = $p['scores'][$formula_key];
                    $best_id = $id;
                }
            }
            if ($best_id) {
                $drafted[$exact_pos][] = [
                    'name' => $available_players[$best_id]['name'],
                    'foot' => $available_players[$best_id]['foot'],
                    'score' => $highest
                ];
                unset($available_players[$best_id]);
            }
        }
    }

    // --- REZERVELE ---
    foreach ($available_players as $id => $p) { $bench[] = $p['name']; }

    // --- FORMATORUL L/C/R (Aplicarea regulilor tale tactice) ---
    foreach ($drafted as $pos => $players) {
        // Pozițiile de bandă deja definite din formație rămân la fel
        if (in_array($pos, ['LB', 'RB', 'LM', 'RM', 'LW', 'RW'])) {
            $starting_xi[$pos] = $players;
        } else {
            // Pozițiile centrale (CB, CDM, CM, CAM, ST) sunt procesate dinamic
            $p_count = count($players);

            if ($p_count == 1) {
                $starting_xi[$pos] = $players;
            } elseif ($p_count == 2) {
                $p1 = $players[0]; // p1 e mereu cel mai bun datorită modului în care au fost draftați
                $p2 = $players[1];

                // Regula: Stângacii pe Dreapta, Dreptacii pe Stânga
                if ($p1['foot'] != $p2['foot']) {
                    $starting_xi['L'.$pos] = [($p1['foot'] == 'Right') ? $p1 : $p2];
                    $starting_xi['R'.$pos] = [($p1['foot'] == 'Left') ? $p1 : $p2];
                } else {
                    // Dacă au același picior, cel mai bun (p1) merge pe stânga
                    $starting_xi['L'.$pos] = [$p1];
                    $starting_xi['R'.$pos] = [$p2];
                }
            } elseif ($p_count == 3) {
                $p1 = $players[0]; $p2 = $players[1]; $p3 = $players[2];

                // Cel mai bun ia centrul (ex: CCB)
                $starting_xi['C'.$pos] = [$p1];

                // Ceilalți doi se împart pe L și R după aceleași reguli
                if ($p2['foot'] != $p3['foot']) {
                    $starting_xi['L'.$pos] = [($p2['foot'] == 'Right') ? $p2 : $p3];
                    $starting_xi['R'.$pos] = [($p2['foot'] == 'Left') ? $p2 : $p3];
                } else {
                    $starting_xi['L'.$pos] = [$p2];
                    $starting_xi['R'.$pos] = [$p3];
                }
            }
        }
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