<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';
include_once '../models/MatchEngine.php'; // Asigură-te că ai salvat clasa de mai devreme aici

$database = new Database();
$db = $database->connect();

// Funcție helper care preia logica ta din auto_lineup și o face reutilizabilă pt orice echipă
function generateLineupForTeam($db, $team_id) {
    // Luăm tactica
    $stmtMgr = $db->prepare("SELECT f.*, m.name as manager_name, m.passing_style, m.tempo, m.pressing_intensity, m.mentality 
                             FROM managers m JOIN formations f ON m.preferred_formation_id = f.formation_id WHERE m.team_id = :tid");
    $stmtMgr->execute(['tid' => $team_id]);
    $tactics = $stmtMgr->fetch(PDO::FETCH_ASSOC);

    // Luăm jucătorii
    $stmtOutfield = $db->prepare("SELECT pc.player_id, pc.name, pc.preferred_foot, ao.* FROM player_core pc JOIN attributes_outfield ao ON pc.player_id = ao.player_id WHERE pc.team_id = :tid AND pc.is_GK = 0");
    $stmtOutfield->execute(['tid' => $team_id]);
    $outfield = $stmtOutfield->fetchAll(PDO::FETCH_ASSOC);

    $stmtGK = $db->prepare("SELECT pc.player_id, pc.name, (ag.reflexes + ag.handling + ag.one_on_ones + ag.aerial_reach) as gk_score, ag.* FROM player_core pc JOIN attributes_goalkeeper ag ON pc.player_id = ag.player_id WHERE pc.team_id = :tid ORDER BY gk_score DESC");
    $stmtGK->execute(['tid' => $team_id]);
    $gks = $stmtGK->fetchAll(PDO::FETCH_ASSOC);

    $starting_xi = [];

    // 1. Portarul
    if (count($gks) > 0) {
        $best_gk = $gks[0];
        $best_gk['preferred_foot'] = 'Right'; // Default pentru GK ca să nu dea eroare
        $starting_xi['GK'][] = $best_gk;
    }

    // 2. Carnetul de note
    $available = [];
    foreach ($outfield as $p) {
        $available[$p['player_id']] = [
            'player' => $p,
            'scores' => [
                'ST' => $p['finishing'] + $p['dribbling'] + $p['first_touch'] + $p['heading'],
                'Winger' => $p['pace'] + $p['dribbling'] + $p['crossing'],
                'CAM' => $p['passing'] + $p['long_shots'] + $p['dribbling'],
                'CM' => $p['passing'] + $p['first_touch'] + $p['marking'],
                'CDM' => $p['tackling'] + $p['marking'] + $p['strength'],
                'CB' => $p['tackling'] + $p['marking'] + $p['heading'],
                'Fullback' => $p['marking'] + $p['pace'] + $p['tackling']
            ]
        ];
    }

    // 3. Draft Greedy (fără formatarea L/C/R deocamdată, doar extragem jucătorii necesari pt test)
    $positions_map = ['st'=>'ST', 'lw'=>'LW', 'rw'=>'RW', 'lm'=>'LM', 'rm'=>'RM', 'cam'=>'CAM', 'cm'=>'CM', 'cdm'=>'CDM', 'lb'=>'LB', 'cb'=>'CB', 'rb'=>'RB'];
    foreach ($positions_map as $col => $pos) {
        $count = $tactics[$col];
        for ($i=0; $i<$count; $i++) {
            $best_id = null; $highest = -1;
            $formula_key = (in_array($pos, ['LW', 'RW', 'LM', 'RM'])) ? 'Winger' : ((in_array($pos, ['LB', 'RB'])) ? 'Fullback' : $pos);

            foreach ($available as $id => $data) {
                if ($data['scores'][$formula_key] > $highest) {
                    $highest = $data['scores'][$formula_key];
                    $best_id = $id;
                }
            }
            if ($best_id) {
                // Dacă e CB și cerem mai mulți, îi transformăm manual în LCB/RCB pentru test
                $final_pos = $pos;
                if ($pos == 'CB') $final_pos = ($i == 0) ? 'LCB' : 'RCB';
                if ($pos == 'CM') $final_pos = ($i == 0) ? 'LCM' : 'RCM';
                if ($pos == 'ST' && $count > 1) $final_pos = ($i == 0) ? 'LST' : 'RST';

                $starting_xi[$final_pos][] = $available[$best_id]['player'];
                unset($available[$best_id]);
            }
        }
    }

    return [
        'tactics' => $tactics,
        'starting_11' => $starting_xi
    ];
}

try {
    // 1. Pregătim echipele
    $home_data = generateLineupForTeam($db, 1);
    $away_data = generateLineupForTeam($db, 2);

    // 2. Inițializăm Motorul
    $engine = new MatchEngine($db, 1, 2);
    $engine->setLineups($home_data, $away_data);

    // 3. SIMULĂM MECIUL
    $result = $engine->simulateMatch();

    // 4. Afișăm rezultatul frumos în Postman sau Browser
    echo json_encode([
        "status" => "success",
        "match_info" => [
            "home_team" => "Cosmopolitan FC (" . $home_data['tactics']['name'] . ")",
            "away_team" => "Academica Test (" . $away_data['tactics']['name'] . ")"
        ],
        "final_stats" => $result['stats'],
        "top_performers" => (arsort($result['ratings'])) ? array_slice($result['ratings'], 0, 3, true) : [],
        "play_by_play_logs" => $result['logs'] // Aici vei vedea faza cu faza ce s-a întâmplat!
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>