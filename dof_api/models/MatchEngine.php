<?php
class MatchEngine {
    private $conn;
    private $home_team_id;
    private $away_team_id;

    private $home_lineup = [];
    private $away_lineup = [];

    private $home_tactics = [];
    private $away_tactics = [];

    public $match_stats = [];
    public $match_logs = [];
    public $player_ratings = [];

    const TOTAL_PHASES = 35;

    private $fallback_matrix = [
        'RW'  => ['RM', 'RB', 'RCM', 'CM', 'CAM', 'RST'],
        'RM'  => ['RW', 'RB', 'RCM', 'CM', 'CAM'],
        'RB'  => ['RM', 'RCM', 'RCB', 'CB', 'CDM'],
        'LW'  => ['LM', 'LB', 'LCM', 'CM', 'CAM', 'LST'],
        'LM'  => ['LW', 'LB', 'LCM', 'CM', 'CAM'],
        'LB'  => ['LM', 'LCM', 'LCB', 'CB', 'CDM'],
        'CAM' => ['CM', 'LCM', 'RCM', 'ST', 'LST', 'RST'],
        'ST'  => ['LST', 'RST', 'CAM', 'LW', 'RW', 'CM'],
        'LST' => ['ST', 'RST', 'CAM', 'LW', 'LCM'],
        'RST' => ['ST', 'LST', 'CAM', 'RW', 'RCM'],
        'CM'  => ['LCM', 'RCM', 'CDM', 'CAM', 'RM', 'LM'],
        'LCM' => ['CM', 'RCM', 'CDM', 'CAM', 'LM'],
        'RCM' => ['CM', 'LCM', 'CDM', 'CAM', 'RM'],
        'CDM' => ['CM', 'LCM', 'RCM', 'CB', 'LCB', 'RCB'],
        'CB'  => ['LCB', 'RCB', 'CDM', 'LB', 'RB', 'CM'],
        'LCB' => ['CB', 'RCB', 'CDM', 'LB', 'LCM'],
        'RCB' => ['CB', 'LCB', 'CDM', 'RB', 'RCM'],
        'GK'  => ['CB', 'LCB', 'RCB', 'CDM']
    ];

    public function __construct($db, $home_id, $away_id) {
        $this->conn = $db;
        $this->home_team_id = $home_id;
        $this->away_team_id = $away_id;
        $this->initStats();
    }

    private function initStats() {
        $this->match_stats = [
            'home' => ['goals' => 0, 'possession' => 0, 'shots' => 0, 'shots_on_target' => 0, 'xg' => 0.0],
            'away' => ['goals' => 0, 'possession' => 0, 'shots' => 0, 'shots_on_target' => 0, 'xg' => 0.0]
        ];
    }

    public function setLineups($home_data, $away_data) {
        $this->home_lineup = $home_data['starting_11'];
        $this->away_lineup = $away_data['starting_11'];
        $this->home_tactics = $home_data['tactics'];
        $this->away_tactics = $away_data['tactics'];

        foreach ([$this->home_lineup, $this->away_lineup] as $team) {
            foreach ($team as $pos => $players) {
                foreach ($players as $p) {
                    $this->player_ratings[$p['player_id']] = 6.0;
                }
            }
        }
    }

    // --- FUNCȚIA DE EXTRAGERE A "ACTORULUI" ---
    public function getActor($team_side, $target_role) {
        $lineup = ($team_side === 'home') ? $this->home_lineup : $this->away_lineup;

        if (isset($lineup[$target_role]) && !empty($lineup[$target_role])) {
            return [
                'player' => $lineup[$target_role][0],
                'is_out_of_position' => false,
                'played_as' => $target_role,
                'malus' => 0.0
            ];
        }

        if (isset($this->fallback_matrix[$target_role])) {
            $fallback_level = 1;
            foreach ($this->fallback_matrix[$target_role] as $backup_role) {
                if (isset($lineup[$backup_role]) && !empty($lineup[$backup_role])) {
                    $malus = ($fallback_level == 1) ? 0.15 : (($fallback_level == 2) ? 0.30 : 0.50);
                    return [
                        'player' => $lineup[$backup_role][0],
                        'is_out_of_position' => true,
                        'played_as' => $backup_role,
                        'malus' => $malus
                    ];
                }
                $fallback_level++;
            }
        }

        foreach ($lineup as $pos => $players) {
            if ($pos !== 'GK' && !empty($players)) {
                return [
                    'player' => $players[0],
                    'is_out_of_position' => true,
                    'played_as' => 'Desperation',
                    'malus' => 0.75
                ];
            }
        }

        throw new Exception("Eroare critica MatchEngine: Nu s-a gasit niciun jucator pe teren pentru echipa $team_side care sa preia rolul de $target_role!");
    }

    // --- CAUTĂ SPECIALISTUL PENTRU FAZE FIXE ---
    private function getSetPieceTaker($team_side) {
        $lineup = ($team_side === 'home') ? $this->home_lineup : $this->away_lineup;
        $best_player = null;
        $best_score = -1;

        foreach ($lineup as $pos => $players) {
            if ($pos === 'GK' || empty($players)) continue;
            foreach ($players as $p) {
                if ($p['set_pieces'] > $best_score) {
                    $best_score = $p['set_pieces'];
                    $best_player = $p;
                }
            }
        }

        if (!$best_player) {
            $actor = $this->getActor($team_side, 'CM');
            $best_player = $actor['player'];
        }

        return ['player' => $best_player, 'played_as' => 'Specialist', 'malus' => 0, 'is_out_of_position' => false];
    }

    // --- FORMULA DE BAZĂ A DUELULUI ---
    private function calculateDuel($attacker, $defender, $att_attributes, $def_attributes) {
        $att_sum = 0;
        foreach($att_attributes as $attr) { $att_sum += $attacker['player'][$attr]; }
        $att_base = $att_sum / count($att_attributes);
        $att_score = $att_base * (1.0 - $attacker['malus']) + rand(1, 20);

        $def_sum = 0;
        foreach($def_attributes as $attr) { $def_sum += $defender['player'][$attr]; }
        $def_base = $def_sum / count($def_attributes);
        $def_score = $def_base * (1.0 - $defender['malus']) + rand(1, 20);

        return [
            'attacker_won' => ($att_score > $def_score),
            'att_score' => $att_score,
            'def_score' => $def_score,
            'is_critical' => ($att_score - $def_score > 15)
        ];
    }

    // --- BUCLA PRINCIPALĂ ---
    public function simulateMatch() {
        $this->match_logs[] = "Fluier de start!";
        $attacking_team = 'home';

        for ($phase = 1; $phase <= self::TOTAL_PHASES; $phase++) {
            $defending_team = ($attacking_team === 'home') ? 'away' : 'home';
            $this->match_stats[$attacking_team]['possession']++;

            $turnover = $this->runStage1_BuildUp($attacking_team, $defending_team);

            if ($turnover) {
                $last_log = end($this->match_logs);
                $was_goal = (strpos($last_log, 'GOOOOOL') !== false);

                if ($was_goal) {
                    $this->match_logs[] = "Faza $phase: Restart de la centru. $defending_team reia jocul.";
                    $attacking_team = $defending_team;
                } else {
                    $this->match_logs[] = "Faza $phase: Posesia este pierduta de $attacking_team.";
                    $attacking_team = $defending_team;
                }
            }
        }

        $this->match_logs[] = "Fluier final!";

        $total_phases = $this->match_stats['home']['possession'] + $this->match_stats['away']['possession'];
        if ($total_phases > 0) {
            $this->match_stats['home']['possession'] = round(($this->match_stats['home']['possession'] / $total_phases) * 100) . '%';
            $this->match_stats['away']['possession'] = round(($this->match_stats['away']['possession'] / $total_phases) * 100) . '%';
        }

        return [
            'stats' => $this->match_stats,
            'logs' => $this->match_logs,
            'ratings' => $this->player_ratings
        ];
    }

    private function getTactic($team, $setting) {
        $tactics = ($team === 'home') ? $this->home_tactics : $this->away_tactics;
        return isset($tactics[$setting]) ? $tactics[$setting] : null;
    }

    // ==========================================
    // STADIUL 1: Ieșirea din Apărare (Build-up)
    // ==========================================
    private function runStage1_BuildUp($atk_team, $def_team) {
        $this->match_logs[] = "Stadiul 1: $atk_team incepe constructia din propria jumatate.";
        $passing_style = $this->getTactic($atk_team, 'passing_style');

        $chance_short = 60;
        if ($passing_style == 'Shorter') $chance_short = 80;
        if ($passing_style == 'Direct') $chance_short = 20;

        if (rand(1, 100) <= $chance_short) {
            $this->match_logs[] = "-> Pasa scurta reusita. Jocul avanseaza.";
            return $this->runStage2_Midfield($atk_team, $def_team);
        }

        $this->match_logs[] = "-> Se incearca o minge lunga peste liniile adverse (Bypass)!";

        $passer = $this->getActor($atk_team, 'CB');
        $receiver = $this->getActor($atk_team, 'ST');
        $defender = $this->getActor($def_team, 'CB');

        if (rand(1, 20) == 20) {
            $this->match_logs[] = "🌟 NAT 20! {$passer['player']['name']} da o pasa absolut geniala peste toata apararea!";
            $this->player_ratings[$passer['player']['player_id']] += 0.5;
            return $this->runStage4_Finishing($atk_team, $def_team, 'Clean Break', $receiver);
        }

        $def_anticipation_score = $defender['player']['determination'] + $defender['player']['marking'] + rand(1, 20);
        $atk_positioning_score = $receiver['player']['workrate'] + $receiver['player']['pace'] + rand(1, 20);

        if ($def_anticipation_score > $atk_positioning_score + 5) {
            $this->match_logs[] = "❌ Minge interceptata / Offside. {$defender['player']['name']} a citit perfect pasa.";
            $this->player_ratings[$defender['player']['player_id']] += 0.2;
            return true;
        }

        $first_touch_roll = $receiver['player']['first_touch'] + rand(1, 20);
        if ($first_touch_roll > 15) {
            $this->match_logs[] = "✅ {$receiver['player']['name']} preia mingea perfect. Intram in Stadiul 3.";
            return $this->runStage3_FinalThird($atk_team, $def_team, 'Route_B_Positional', $receiver);
        }

        $this->match_logs[] = "❌ Preluare gresita a lui {$receiver['player']['name']}. Mingea este pierduta.";
        $this->player_ratings[$receiver['player']['player_id']] -= 0.1;
        return true;
    }

    // ==========================================
    // STADIUL 2: Bătălia la Mijloc
    // ==========================================
    private function runStage2_Midfield($atk_team, $def_team) {
        $this->match_logs[] = "Stadiul 2: Batalia la mijlocul terenului.";

        $atk_cm = $this->getActor($atk_team, 'CM');
        $def_cdm = $this->getActor($def_team, 'CDM');
        $atk_cam = $this->getActor($atk_team, 'CAM');

        if ($atk_cam && $def_cdm) {
            $cam_score = $atk_cam['player']['dribbling'] + rand(1, 20);
            $cdm_score = $def_cdm['player']['marking'] + $def_cdm['player']['determination'] + rand(1, 20);

            if ($cam_score > $cdm_score + 8) {
                $this->match_logs[] = "🔥 {$atk_cam['player']['name']} scapa din marcaj la 25 de metri! Are spatiu liber!";
                $this->player_ratings[$atk_cam['player']['player_id']] += 0.2;
                return $this->runStage4_Finishing($atk_team, $def_team, 'CAM Long Shot', $atk_cam);
            }
        }

        $duel = $this->calculateDuel($atk_cm, $def_cdm, ['passing', 'dribbling'], ['tackling', 'marking']);

        if ($duel['attacker_won'] && $duel['is_critical']) {
            $this->match_logs[] = "🟨 {$def_cdm['player']['name']} este depasit clar si face un fault tactic dur!";
            $this->player_ratings[$def_cdm['player']['player_id']] -= 0.3;
            return $this->runSetPiece($atk_team, $def_team, 'Direct Free Kick');
        }

        if (!$duel['attacker_won']) {
            $this->match_logs[] = "❌ {$def_cdm['player']['name']} face un tackle excelent si recupereaza mingea (Turnover).";
            $this->player_ratings[$def_cdm['player']['player_id']] += 0.2;

            if ($def_cdm['is_out_of_position'] == false && rand(1, 100) <= 40) {
                $this->match_logs[] = "🔄 {$def_cdm['player']['name']} calmeaza jocul si paseaza inapoi la fundasi.";
            } else {
                $this->match_logs[] = "⚡ Contraatac declansat!";
            }
            return true;
        }

        $this->player_ratings[$atk_cm['player']['player_id']] += 0.1;
        $route = (rand(1, 100) > 50) ? 'Route_A' : 'Route_B';

        if ($route == 'Route_B') {
            $direct_passing_bonus = ($this->getTactic($atk_team, 'passing_style') == 'Direct') ? 2 : 0;
            $pass_roll = rand(1, 20) + $direct_passing_bonus;

            if ($pass_roll >= 18) {
                $this->match_logs[] = "✨ Pasa filtrana de geniu a lui {$atk_cm['player']['name']}! (Zar: $pass_roll)";
                $receiver = $this->getActor($atk_team, 'ST');
                return $this->runStage4_Finishing($atk_team, $def_team, 'Clean Break', $receiver);
            } else {
                $this->match_logs[] = "-> {$atk_cm['player']['name']} paseaza central (Atac Pozitional).";
                $receiver = $this->getActor($atk_team, 'ST');
                return $this->runStage3_FinalThird($atk_team, $def_team, 'Route_B_Positional', $receiver);
            }
        } else {
            $this->match_logs[] = "-> {$atk_cm['player']['name']} deschide jocul pe flanc.";
            $receiver = $this->getActor($atk_team, 'RW');
            return $this->runStage3_FinalThird($atk_team, $def_team, 'Route_A', $receiver);
        }
    }

    // ==========================================
    // STADIUL 3: Faza Finală
    // ==========================================
    private function runStage3_FinalThird($atk_team, $def_team, $route, $attacker_data) {
        $attacker = $attacker_data['player'];

        if ($route == 'Route_A') {
            $this->match_logs[] = "Stadiul 3: {$attacker['name']} ataca pe flancul " . (strpos($attacker_data['played_as'], 'L') !== false ? 'Stang' : 'Drept') . ".";

            $right_positions = ['RW', 'RM', 'RB', 'RCM', 'RCB', 'RST'];
            $def_role = in_array($attacker_data['played_as'], $right_positions) ? 'LB' : 'RB';
            $defender_data = $this->getActor($def_team, $def_role);
            $defender = $defender_data['player'];

            $is_target_man_on_wing = ($attacker_data['is_out_of_position'] && $attacker['finishing'] > $attacker['crossing']);

            if ($is_target_man_on_wing) {
                $this->match_logs[] = "🛡️ {$attacker['name']} nu are viteza de extrema. Protejeaza mingea (Hold-up play) in fata lui {$defender['name']}.";
                $hold_up_roll = $attacker['strength'] + rand(1, 20);
                $def_roll = $defender['strength'] + rand(1, 20);

                if ($hold_up_roll > $def_roll) {
                    $action = rand(1, 100);
                    if ($action <= 40) {
                        $this->match_logs[] = "-> Scoate un fault inteligent! (Lovitura libera laterala)";
                        $this->player_ratings[$attacker['player_id']] += 0.1;
                        return $this->runSetPiece($atk_team, $def_team, 'Wide Free Kick');
                    } elseif ($action <= 80) {
                        $this->match_logs[] = "-> Castiga un duel la margine si obtine o lovitura de la colt!";
                        return $this->runSetPiece($atk_team, $def_team, 'Corner');
                    } else {
                        $this->match_logs[] = "🔄 Recicleaza posesia inapoi la mijloc. Adversarul e dezechilibrat!";
                        return $this->runStage2_Midfield($atk_team, $def_team);
                    }
                } else {
                    $this->match_logs[] = "❌ {$defender['name']} ii fura mingea masivului atacant iesit in banda.";
                    return true;
                }
            }

            $action = (rand(1, 100) > 40) ? 'Cross' : 'Cut_Inside';

            if ($action == 'Cross') {
                $duel = $this->calculateDuel($attacker_data, $defender_data, ['pace', 'crossing'], ['pace', 'tackling']);
                if ($duel['attacker_won']) {
                    $this->match_logs[] = "✅ {$attacker['name']} reuseste centrarea in careu!";
                    $target_man = $this->getActor($atk_team, 'ST');
                    return $this->runStage4_Finishing($atk_team, $def_team, 'Aerian', $target_man);
                } else {
                    $this->match_logs[] = "❌ {$defender['name']} pune piciorul si blocheaza centrarea lui {$attacker['name']}.";
                    $this->player_ratings[$defender['player_id']] += 0.2;
                    if (rand(1, 100) <= 50) {
                        $this->match_logs[] = "-> Mingea este deviata in afara terenului. Este Corner!";
                        return $this->runSetPiece($atk_team, $def_team, 'Corner');
                    }
                    return true;
                }
            } else {
                $duel = $this->calculateDuel($attacker_data, $defender_data, ['pace', 'dribbling'], ['determination', 'tackling']);
                if ($duel['attacker_won']) {
                    $this->match_logs[] = "⚡ {$attacker['name']} dribuleaza spre interiorul careului!";
                    return $this->runStage4_Finishing($atk_team, $def_team, 'Cut-Inside', $attacker_data);
                }
            }

            $this->match_logs[] = "❌ {$defender['name']} blocheaza actiunea pe flanc.";
            $this->player_ratings[$defender['player_id']] += 0.2;
            return true;

        } else {
            $this->match_logs[] = "Stadiul 3: Atac pozitional pe centru.";
            $defender_data = $this->getActor($def_team, 'CB');
            $defender = $defender_data['player'];

            if ($attacker['dribbling'] > $attacker['strength']) {
                $this->match_logs[] = "-> {$attacker['name']} incearca sa treaca prin dribling de {$defender['name']}.";
                $duel = $this->calculateDuel($attacker_data, $defender_data, ['pace', 'dribbling'], ['determination', 'tackling']);
            } else {
                $this->match_logs[] = "-> {$attacker['name']} foloseste forta pentru a se intoarce cu mingea la picior.";
                $duel = $this->calculateDuel($attacker_data, $defender_data, ['strength', 'workrate'], ['strength', 'marking']);
            }

            if ($duel['attacker_won']) {
                $this->match_logs[] = "✅ Trece superb! Are culoar de sut.";
                $this->player_ratings[$attacker['player_id']] += 0.2;
                return $this->runStage4_Finishing($atk_team, $def_team, 'Positional', $attacker_data);
            }

            $this->match_logs[] = "❌ {$defender['name']} opreste atacul central.";
            $this->player_ratings[$defender['player_id']] += 0.2;
            return true;
        }
    }

    // ==========================================
    // STADIUL 4: Finalizarea
    // ==========================================
    private function runStage4_Finishing($atk_team, $def_team, $shot_type, $shooter_data) {
        $shooter = $shooter_data['player'];
        $gk_data = $this->getActor($def_team, 'GK');
        $gk = $gk_data['player'];

        $this->match_stats[$atk_team]['shots']++;
        $base_xg = 0.0;
        $shot_on_target_chance = 0;

        switch ($shot_type) {
            case 'Clean Break':
                $this->match_logs[] = "🚨 SINGUR CU PORTARUL! {$shooter['name']} avanseaza spre poarta!";
                $base_xg = 0.45;
                $shot_on_target_chance = $shooter['finishing'] + $shooter['composure'] + rand(1, 20);
                break;

            case 'CAM Long Shot':
                $this->match_logs[] = "🚀 Sut puternic de la distanta expediat de {$shooter['name']}!";
                $base_xg = 0.06;
                $shot_on_target_chance = $shooter['long_shots'] + $shooter['composure'] + rand(1, 10);
                break;

            case 'Cut-Inside':
                $base_xg = 0.12;
                $is_inverted = false;
                if ($shooter_data['played_as'] == 'RW' && $shooter['preferred_foot'] == 'Left') $is_inverted = true;
                if ($shooter_data['played_as'] == 'LW' && $shooter['preferred_foot'] == 'Right') $is_inverted = true;

                if ($is_inverted) {
                    $this->match_logs[] = "📐 {$shooter['name']} si-a facut mingea perfect pe piciorul de baza ({$shooter['preferred_foot']})!";
                    $shot_on_target_chance = $shooter['long_shots'] + $shooter['finishing'] + 15 + rand(1, 20);
                    $base_xg = 0.18;
                } else {
                    $this->match_logs[] = "⚠️ {$shooter['name']} trage cu piciorul slab din unghi.";
                    $shot_on_target_chance = $shooter['long_shots'] + $shooter['finishing'] - 10 + rand(1, 20);
                }
                break;

            case 'Aerian':
                $cb_data = $this->getActor($def_team, 'CB');
                $cb = $cb_data['player'];
                $this->match_logs[] = "✈️ Duel aerian intre {$shooter['name']} si {$cb['name']}.";
                $aerial_duel = $this->calculateDuel($shooter_data, $cb_data, ['heading', 'strength'], ['heading', 'strength']);

                if (!$aerial_duel['attacker_won']) {
                    $this->match_logs[] = "❌ {$cb['name']} respinge mingea cu capul.";
                    $this->player_ratings[$cb['player_id']] += 0.2;
                    return true;
                }
                $base_xg = 0.15;
                $shot_on_target_chance = $shooter['heading'] + $shooter['composure'] + rand(1, 20);
                break;

            case 'Positional':
                $this->match_logs[] = "🎯 Sut din interiorul careului printre aparatori!";
                $base_xg = 0.25;
                $shot_on_target_chance = $shooter['finishing'] + $shooter['composure'] + rand(1, 20);
                break;
        }

        $this->match_stats[$atk_team]['xg'] += $base_xg;

        if ($shot_on_target_chance < 50) {
            $this->match_logs[] = "💨 Sutul se duce pe langa poarta / peste bara.";
            $this->player_ratings[$shooter['player_id']] -= 0.2;
            return true;
        }

        $this->match_stats[$atk_team]['shots_on_target']++;
        $this->match_logs[] = "🥅 Sutul este pe cadrul portii!";

        $gk_save_chance = (($gk['reflexes'] + $gk['handling']) / 1.6) + rand(1, 15);

        if ($gk_data['is_out_of_position']) {
            $this->match_logs[] = "🚨 {$gk['name']} este un jucator de camp fortat in poarta!";
            $gk_save_chance = $gk_save_chance * 0.25;
        }

        $shot_power = ($shot_type == 'Aerian') ? $shooter['heading'] : $shooter['finishing'] + rand(1, 20);

        if ($shot_power > $gk_save_chance) {
            $this->match_logs[] = "⚽ GOOOOOL! {$shooter['name']} marcheaza pentru $atk_team!";
            $this->match_stats[$atk_team]['goals']++;
            $this->player_ratings[$shooter['player_id']] += 1.0;
            $this->player_ratings[$gk['player_id']] -= 0.3;
        } else {
            $this->match_logs[] = "🧤 PARADA EXTRAORDINARA! {$gk['name']} respinge mingea!";
            $this->player_ratings[$gk['player_id']] += 0.8;
            $this->player_ratings[$shooter['player_id']] -= 0.1;

            if (rand(1, 100) > 40) {
                $this->match_logs[] = "-> Mingea este respinsa in corner de portar.";
                return $this->runSetPiece($atk_team, $def_team, 'Corner');
            }
        }

        return true;
    }

    // ==========================================
    // MODULUL DE FAZE FIXE
    // ==========================================
    private function runSetPiece($atk_team, $def_team, $type) {
        $taker_data = $this->getSetPieceTaker($atk_team);
        $taker = $taker_data['player'];

        if ($type == 'Corner' || $type == 'Wide Free Kick') {
            $this->match_logs[] = "🚩 {$taker['name']} executa " . ($type == 'Corner' ? "lovitura de la colt." : "lovitura libera laterala.");

            $cross_quality = $taker['set_pieces'] + rand(1, 20);

            if ($cross_quality < 15) {
                $this->match_logs[] = "💨 Centrare slaba, mingea este respinsa usor de prima linie a apararii.";
                $this->player_ratings[$taker['player_id']] -= 0.1;
                return true;
            }

            $this->match_logs[] = "✅ Centrare periculoasa in mijlocul careului!";
            $this->player_ratings[$taker['player_id']] += 0.2;

            $atk_cb_data = $this->getActor($atk_team, 'CB');
            $def_cb_data = $this->getActor($def_team, 'CB');

            $this->match_logs[] = "✈️ {$atk_cb_data['player']['name']} a urcat in careu si sare la cap cu {$def_cb_data['player']['name']}!";

            $duel = $this->calculateDuel($atk_cb_data, $def_cb_data, ['heading', 'strength'], ['heading', 'strength']);

            if ($duel['attacker_won']) {
                $this->match_logs[] = "🔥 {$atk_cb_data['player']['name']} castiga duelul aerian!";
                return $this->runStage4_Finishing($atk_team, $def_team, 'Aerian', $atk_cb_data);
            } else {
                $this->match_logs[] = "🛡️ {$def_cb_data['player']['name']} respinge mingea imperial. Pericolul a trecut.";
                $this->player_ratings[$def_cb_data['player']['player_id']] += 0.2;
                return true;
            }
        }
        elseif ($type == 'Direct Free Kick') {
            $this->match_logs[] = "🎯 Lovitura libera periculoasa de la 20 de metri! {$taker['name']} isi aseaza mingea.";

            $shot_quality = $taker['set_pieces'] + $taker['long_shots'] + rand(1, 20);

            if ($shot_quality < 25) {
                $this->match_logs[] = "🧱 Sutul se opreste direct in zidul advers.";
                return true;
            }

            $this->match_logs[] = "🚀 Sutul trece de zid si se indreapta spre poarta!";
            $this->player_ratings[$taker['player_id']] += 0.2;

            return $this->runStage4_Finishing($atk_team, $def_team, 'Positional', $taker_data);
        }

        return true;
    }
}
?>