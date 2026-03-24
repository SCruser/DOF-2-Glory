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

    public function getActor($team_side, $target_role) {
        $lineup = ($team_side === 'home') ? $this->home_lineup : $this->away_lineup;

        if (isset($lineup[$target_role]) && !empty($lineup[$target_role])) {
            return ['player' => $lineup[$target_role][0], 'is_out_of_position' => false, 'played_as' => $target_role, 'malus' => 0.0];
        }

        if (isset($this->fallback_matrix[$target_role])) {
            $fallback_level = 1;
            foreach ($this->fallback_matrix[$target_role] as $backup_role) {
                if (isset($lineup[$backup_role]) && !empty($lineup[$backup_role])) {
                    $malus = ($fallback_level == 1) ? 0.15 : (($fallback_level == 2) ? 0.30 : 0.50);
                    return ['player' => $lineup[$backup_role][0], 'is_out_of_position' => true, 'played_as' => $backup_role, 'malus' => $malus];
                }
                $fallback_level++;
            }
        }

        foreach ($lineup as $pos => $players) {
            if ($pos !== 'GK' && !empty($players)) {
                return ['player' => $players[0], 'is_out_of_position' => true, 'played_as' => 'Desperation', 'malus' => 0.75];
            }
        }

        throw new Exception("Eroare critică MatchEngine: Nu s-a găsit niciun jucător pe teren pentru echipa $team_side care să preia rolul de $target_role!");
    }

    // --- NOU: SISTEMUL DE CARTONAȘE ȘI FAULTURI ---
    private function checkFoul($defender_id, $defender_name) {
        $roll = rand(1, 100);
        if ($roll <= 2) {
            // 2% șansă de Roșu direct
            $this->match_logs[] = "🟥 CARTONAȘ ROȘU! {$defender_name} are o intrare criminală și este eliminat!";
            $this->player_ratings[$defender_id] -= 1.5;
            return 'Red';
        } elseif ($roll <= 22) {
            // 20% șansă de cartonaș Galben
            $this->match_logs[] = "🟨 Cartonaș galben pentru {$defender_name} după această intervenție dură.";
            $this->player_ratings[$defender_id] -= 0.3;
            return 'Yellow';
        } else {
            // 78% din faulturi sunt doar fluierate de arbitru
            $this->match_logs[] = "🗣️ Arbitrul dictează doar fault, fără să acorde vreun cartonaș.";
            $this->player_ratings[$defender_id] -= 0.1;
            return 'None';
        }
    }

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
                    $this->match_logs[] = "Faza $phase: Posesia este pierdută de $attacking_team.";
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

        foreach ($this->player_ratings as $id => $rating) {
            $this->player_ratings[$id] = min(10.0, max(1.0, round($rating, 1)));
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

    private function runStage1_BuildUp($atk_team, $def_team) {
        $this->match_logs[] = "Stadiul 1: $atk_team începe construcția din propria jumătate.";
        $passing_style = $this->getTactic($atk_team, 'passing_style');

        $chance_short = 75;
        if ($passing_style == 'Shorter') $chance_short = 90;
        if ($passing_style == 'Direct') $chance_short = 50;

        if (rand(1, 100) <= $chance_short) {
            $this->match_logs[] = "-> Pasă scurtă reușită. Jocul avansează spre mijlocași.";
            return $this->runStage2_Midfield($atk_team, $def_team);
        }

        $is_flank_bypass = (rand(1, 100) <= 40);
        $target_role = $is_flank_bypass ? ((rand(1, 100) > 50) ? 'RW' : 'LW') : 'ST';
        $this->match_logs[] = "-> Se încearcă o minge lungă peste liniile adverse spre " . ($is_flank_bypass ? "flanc" : "centru") . " (Bypass)!";

        $passer_data = $this->getActor($atk_team, 'CB');
        $receiver_data = $this->getActor($atk_team, $target_role);
        $defender_data = $this->getActor($def_team, $is_flank_bypass ? ($target_role == 'RW' ? 'LB' : 'RB') : 'CB');

        $passer = $passer_data['player'];
        $receiver = $receiver_data['player'];
        $defender = $defender_data['player'];

        if (rand(1, 20) == 20) {
            $this->match_logs[] = "🌟 NAT 20! {$passer['name']} dă o pasă lungă absolut genială peste toată apărarea!";
            $this->player_ratings[$passer['player_id']] += 0.5;
            return $this->runStage4_Finishing($atk_team, $def_team, 'Clean Break', $receiver_data);
        }

        $pass_quality = $passer['passing'] + rand(1, 20);
        if ($pass_quality < 18) {
            $this->match_logs[] = "❌ Pasa lungă a lui {$passer['name']} este imprecisă și iese în aut / este recuperată ușor.";
            $this->player_ratings[$passer['player_id']] -= 0.1;
            return true;
        }

        $duel = $this->calculateDuel($receiver_data, $defender_data, ['pace', 'first_touch'], ['heading', 'marking']);

        if ($duel['def_score'] + 4 >= $duel['att_score']) {
            $this->match_logs[] = "🛡️ {$defender['name']} anticipează mingea lungă și respinge din fața lui {$receiver['name']}.";
            $this->player_ratings[$defender['player_id']] += 0.2;
            return true;
        }

        $this->match_logs[] = "✅ {$receiver['name']} reușește o preluare de senzație din mingea lungă! Intrăm în Stadiul 3.";

        if ($is_flank_bypass) {
            return $this->runStage3_FinalThird($atk_team, $def_team, 'Route_A', $receiver_data);
        } else {
            return $this->runStage3_FinalThird($atk_team, $def_team, 'Route_B_Positional', $receiver_data);
        }
    }

    private function runStage2_Midfield($atk_team, $def_team, $retry_count = 0) {
        $this->match_logs[] = "Stadiul 2: Bătălia la mijlocul terenului" . ($retry_count > 0 ? " (Se caută o nouă breșă)" : "") . ".";

        $atk_cm = $this->getActor($atk_team, 'CM');
        $def_cdm = $this->getActor($def_team, 'CDM');
        $atk_cam = $this->getActor($atk_team, 'CAM');

        if ($atk_cam && $def_cdm) {
            $cam_score = $atk_cam['player']['dribbling'] + rand(1, 20);
            $cdm_score = $def_cdm['player']['marking'] + $def_cdm['player']['determination'] + rand(1, 20);

            if ($cam_score > $cdm_score + 4) {
                $this->match_logs[] = "🔥 {$atk_cam['player']['name']} scapă din marcaj la 25 de metri! Are spațiu liber și trage!";
                $this->player_ratings[$atk_cam['player']['player_id']] += 0.2;
                return $this->runStage4_Finishing($atk_team, $def_team, 'CAM Long Shot', $atk_cam);
            }
        }

        $duel = $this->calculateDuel($atk_cm, $def_cdm, ['passing', 'dribbling'], ['tackling', 'marking']);

        if (!$duel['attacker_won']) {
            // AM CRESCUT LA 35%
            if (rand(1, 100) <= 25) {
                $this->match_logs[] = "哨 Intervenție întârziată a lui {$def_cdm['player']['name']} la mijlocul terenului!";
                $this->checkFoul($def_cdm['player']['player_id'], $def_cdm['player']['name']);
                return $this->runSetPiece($atk_team, $def_team, 'Midfield Free Kick');
            }

            if ($duel['def_score'] <= $duel['att_score'] + 7) {
                $this->match_logs[] = "🛡️ {$def_cdm['player']['name']} închide perfect zona centrală, așa că {$atk_cm['player']['name']} deschide jocul pe flanc.";
                $flank = (rand(1, 100) > 50) ? 'RW' : 'LW';
                $receiver = $this->getActor($atk_team, $flank);
                return $this->runStage3_FinalThird($atk_team, $def_team, 'Route_A', $receiver);
            }

            $this->match_logs[] = "❌ {$def_cdm['player']['name']} face un tackle curat și recuperează mingea (Turnover).";
            $this->player_ratings[$def_cdm['player']['player_id']] += 0.2;

            if ($def_cdm['is_out_of_position'] == false && rand(1, 100) <= 40) {
                $this->match_logs[] = "🔄 {$def_cdm['player']['name']} calmează jocul și pasează înapoi la fundași.";
            } else {
                $this->match_logs[] = "⚡ Contraatac declanșat!";
            }
            return true;
        }

        $this->player_ratings[$atk_cm['player']['player_id']] += 0.1;
        $direct_passing_bonus = ($this->getTactic($atk_team, 'passing_style') == 'Direct') ? 2 : 0;
        $pass_roll = rand(1, 20) + $direct_passing_bonus;

        if ($pass_roll >= 17) {
            $this->match_logs[] = "✨ Pasă filtrantă de geniu a lui {$atk_cm['player']['name']} printre fundași!";
            $receiver = $this->getActor($atk_team, 'ST');
            return $this->runStage4_Finishing($atk_team, $def_team, 'Clean Break', $receiver);
        } else {
            $this->match_logs[] = "-> {$atk_cm['player']['name']} câștigă duelul fizic și avansează pe centru.";
            $receiver = $this->getActor($atk_team, 'ST');
            return $this->runStage3_FinalThird($atk_team, $def_team, 'Route_B_Positional', $receiver);
        }
    }

    private function runStage3_FinalThird($atk_team, $def_team, $route, $attacker_data) {
        $attacker = $attacker_data['player'];

        if ($route == 'Route_A') {
            $this->match_logs[] = "Stadiul 3: {$attacker['name']} atacă pe flancul " . (strpos($attacker_data['played_as'], 'L') !== false ? 'Stâng' : 'Drept') . ".";

            $right_positions = ['RW', 'RM', 'RB', 'RCM', 'RCB', 'RST'];
            $def_role = in_array($attacker_data['played_as'], $right_positions) ? 'LB' : 'RB';
            $defender_data = $this->getActor($def_team, $def_role);
            $defender = $defender_data['player'];

            $is_target_man_on_wing = ($attacker_data['is_out_of_position'] && $attacker['finishing'] > $attacker['crossing']);

            if ($is_target_man_on_wing) {
                $this->match_logs[] = "🛡️ {$attacker['name']} nu are viteză de extremă. Protejează mingea (Hold-up play) în fața lui {$defender['name']}.";
                $hold_up_roll = $attacker['strength'] + rand(1, 20);
                $def_roll = $defender['strength'] + rand(1, 20);

                if ($hold_up_roll > $def_roll) {
                    $action = rand(1, 100);
                    if ($action <= 40) {
                        $this->match_logs[] = "-> Scoate un fault inteligent! (Lovitură liberă laterală)";
                        $this->player_ratings[$attacker['player_id']] += 0.1;
                        return $this->runSetPiece($atk_team, $def_team, 'Wide Free Kick');
                    } elseif ($action <= 80) {
                        $this->match_logs[] = "-> Câștigă un duel la margine și obține o lovitură de la colț!";
                        return $this->runSetPiece($atk_team, $def_team, 'Corner');
                    } else {
                        $this->match_logs[] = "🔄 Reciclează posesia înapoi la mijloc. Adversarul e dezechilibrat!";
                        return $this->runStage2_Midfield($atk_team, $def_team);
                    }
                } else {
                    $this->match_logs[] = "❌ {$defender['name']} îi fură mingea masivului atacant ieșit în bandă.";
                    return true;
                }
            }

            $action = (rand(1, 100) > 40) ? 'Cross' : 'Cut_Inside';

            if ($action == 'Cross') {
                $duel = $this->calculateDuel($attacker_data, $defender_data, ['pace', 'crossing'], ['pace', 'tackling']);

                if ($duel['attacker_won']) {
                    $this->match_logs[] = "✅ {$attacker['name']} reușește centrarea în careu!";
                    $target_man = $this->getActor($atk_team, 'ST');
                    return $this->runStage4_Finishing($atk_team, $def_team, 'Aerian', $target_man);
                } else {
                    // --- NOU: VERIFICARE FAULT PE FLANC (CROSS) ---
                    if (rand(1, 100) <= 30) {
                        $this->match_logs[] = "哨 Fault imprudent făcut de {$defender['name']} pe flanc!";
                        $this->checkFoul($defender['player_id'], $defender['name']);
                        return $this->runSetPiece($atk_team, $def_team, 'Wide Free Kick');
                    }

                    $this->match_logs[] = "❌ {$defender['name']} blochează centrarea lui {$attacker['name']}.";
                    $this->player_ratings[$defender['player_id']] += 0.2;

                    if (rand(1, 100) <= 50) {
                        $this->match_logs[] = "-> Mingea este deviată în afara terenului. Este Corner!";
                        return $this->runSetPiece($atk_team, $def_team, 'Corner');
                    }
                    return true;
                }
            } else {
                $duel = $this->calculateDuel($attacker_data, $defender_data, ['pace', 'dribbling'], ['determination', 'tackling']);
                if ($duel['attacker_won']) {
                    $this->match_logs[] = "⚡ {$attacker['name']} driblează spre interiorul careului!";
                    return $this->runStage4_Finishing($atk_team, $def_team, 'Cut-Inside', $attacker_data);
                } else {
                    // --- NOU: VERIFICARE FAULT LA INTRAREA ÎN CAREU (CUT-INSIDE) ---
                    if (rand(1, 100) <= 35) {
                        $this->match_logs[] = "哨 {$defender['name']} bagă o alunecare și își agață adversarul la marginea careului!";
                        $this->checkFoul($defender['player_id'], $defender['name']);
                        return $this->runSetPiece($atk_team, $def_team, 'Direct Free Kick');
                    }

                    $this->match_logs[] = "❌ {$defender['name']} blochează acțiunea pe flanc.";
                    $this->player_ratings[$defender['player_id']] += 0.2;
                    return true;
                }
            }

        } else {
            $this->match_logs[] = "Stadiul 3: Atac pozițional pe centru.";
            $defender_data = $this->getActor($def_team, 'CB');
            $defender = $defender_data['player'];

            $action_roll = rand(1, 100);

            if ($action_roll <= 25 && $attacker['long_shots'] > 10) {
                $this->match_logs[] = "🚀 {$attacker['name']} nu mai așteaptă și trage prin surprindere de la marginea careului!";
                return $this->runStage4_Finishing($atk_team, $def_team, 'CAM Long Shot', $attacker_data);
            }
            elseif ($attacker['dribbling'] > $attacker['strength']) {
                $this->match_logs[] = "-> {$attacker['name']} încearcă să treacă prin dribling de {$defender['name']}.";
                $duel = $this->calculateDuel($attacker_data, $defender_data, ['pace', 'dribbling'], ['determination', 'tackling']);
            } else {
                $this->match_logs[] = "-> {$attacker['name']} folosește forța pentru a se întoarce cu mingea la picior.";
                $duel = $this->calculateDuel($attacker_data, $defender_data, ['strength', 'workrate'], ['strength', 'marking']);
            }

            if ($duel['attacker_won']) {
                $this->match_logs[] = "✅ Trece superb! Are culoar de șut.";
                $this->player_ratings[$attacker['player_id']] += 0.2;
                return $this->runStage4_Finishing($atk_team, $def_team, 'Positional', $attacker_data);
            } else {
                // --- NOU: VERIFICARE FAULT ATAC CENTRAL ---
                if (rand(1, 100) <= 30) {
                    $this->match_logs[] = "哨 {$defender['name']} îl faultează pe {$attacker['name']} la 20 de metri de poartă!";
                    $this->checkFoul($defender['player_id'], $defender['name']);
                    return $this->runSetPiece($atk_team, $def_team, 'Direct Free Kick');
                }

                $this->match_logs[] = "❌ {$defender['name']} oprește atacul central curat.";
                $this->player_ratings[$defender['player_id']] += 0.2;
                return true;
            }
        }
    }

    private function runStage4_Finishing($atk_team, $def_team, $shot_type, $shooter_data) {
        $shooter = $shooter_data['player'];
        $gk_data = $this->getActor($def_team, 'GK');
        $gk = $gk_data['player'];

        $this->match_stats[$atk_team]['shots']++;
        $base_xg = 0.0;
        $shot_on_target_chance = 0;

        switch ($shot_type) {
            case 'Clean Break':
                $this->match_logs[] = "🚨 SINGUR CU PORTARUL! {$shooter['name']} avansează spre poartă!";
                $base_xg = 0.45;
                $shot_on_target_chance = $shooter['finishing'] + $shooter['composure'] + rand(1, 20);
                break;
            case 'CAM Long Shot':
                $this->match_logs[] = "🚀 Șut puternic de la distanță expediat de {$shooter['name']}!";
                $base_xg = 0.06;
                $shot_on_target_chance = $shooter['long_shots'] + $shooter['composure'] + rand(1, 10);
                break;
            case 'Cut-Inside':
                $base_xg = 0.12;
                $is_inverted = false;
                if ($shooter_data['played_as'] == 'RW' && $shooter['preferred_foot'] == 'Left') $is_inverted = true;
                if ($shooter_data['played_as'] == 'LW' && $shooter['preferred_foot'] == 'Right') $is_inverted = true;
                if ($is_inverted) {
                    $this->match_logs[] = "📐 {$shooter['name']} și-a făcut mingea perfect pe piciorul de bază ({$shooter['preferred_foot']})!";
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
                $this->match_logs[] = "✈️ Duel aerian între {$shooter['name']} și {$cb['name']}.";
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
                $this->match_logs[] = "🎯 Șut din interiorul careului printre apărători!";
                $base_xg = 0.25;
                $shot_on_target_chance = $shooter['finishing'] + $shooter['composure'] + rand(1, 20);
                break;
        }

        $this->match_stats[$atk_team]['xg'] += $base_xg;

        if ($shot_on_target_chance < 25) {
            $this->match_logs[] = "💨 Șutul se duce pe lângă poartă / peste bară.";
            $this->player_ratings[$shooter['player_id']] -= 0.2;
            return true;
        }

        $this->match_stats[$atk_team]['shots_on_target']++;
        $this->match_logs[] = "🥅 Șutul este pe cadrul porții!";

        $gk_save_chance = (($gk['reflexes'] + $gk['handling'])/1.8) + rand(1, 20);
        if ($gk_data['is_out_of_position']) {
            $this->match_logs[] = "🚨 {$gk['name']} este un jucător de câmp forțat în poartă!";
            $gk_save_chance = $gk_save_chance * 0.25;
        }

        $shot_power = ($shot_type == 'Aerian') ? $shooter['heading'] : $shooter['finishing'] + rand(1, 20);

        if ($shot_power > $gk_save_chance) {
            $this->match_logs[] = "⚽ GOOOOOL! {$shooter['name']} marchează pentru $atk_team!";
            $this->match_stats[$atk_team]['goals']++;
            $this->player_ratings[$shooter['player_id']] += 1.0;
            $this->player_ratings[$gk['player_id']] -= 0.3;
        } else {
            $this->match_logs[] = "🧤 PARADĂ EXTRAORDINARĂ! {$gk['name']} respinge mingea!";
            $this->player_ratings[$gk['player_id']] += 0.8;
            $this->player_ratings[$shooter['player_id']] -= 0.1;

            if (rand(1, 100) <= 75) {
                $this->match_logs[] = "-> Mingea este respinsă în corner de portar.";
                return $this->runSetPiece($atk_team, $def_team, 'Corner');
            } else {
                $this->match_logs[] = "-> {$gk['name']} reușește să rețină mingea în doi timpi, calmând jocul.";
            }
        }
        return true;
    }

    private function runSetPiece($atk_team, $def_team, $type) {
        $taker_data = $this->getSetPieceTaker($atk_team);
        $taker = $taker_data['player'];

        // --- NOU: Posesia reluată scurt (Midfield Free Kick) ---
        if ($type == 'Midfield Free Kick') {
            $this->match_logs[] = "🔄 {$atk_team} repune mingea rapid din lovitură liberă și continuă construcția.";
            return $this->runStage2_Midfield($atk_team, $def_team, 0);
        }

        if ($type == 'Corner' || $type == 'Wide Free Kick') {
            $this->match_logs[] = "🚩 {$taker['name']} execută " . ($type == 'Corner' ? "lovitura de la colț." : "lovitura liberă laterală.");
            $cross_quality = $taker['set_pieces'] + rand(1, 20);
            if ($cross_quality < 15) {
                $this->match_logs[] = "💨 Centrare slabă, mingea este respinsă ușor de prima linie a apărării.";
                $this->player_ratings[$taker['player_id']] -= 0.1;
                return true;
            }
            $this->match_logs[] = "✅ Centrare periculoasă în mijlocul careului!";
            $this->player_ratings[$taker['player_id']] += 0.2;
            $atk_cb_data = $this->getActor($atk_team, 'CB');
            $def_cb_data = $this->getActor($def_team, 'CB');
            $this->match_logs[] = "✈️ {$atk_cb_data['player']['name']} a urcat în careu și sare la cap cu {$def_cb_data['player']['name']}!";
            $duel = $this->calculateDuel($atk_cb_data, $def_cb_data, ['heading', 'strength'], ['heading', 'strength']);
            if ($duel['attacker_won']) {
                $this->match_logs[] = "🔥 {$atk_cb_data['player']['name']} câștigă duelul aerian!";
                return $this->runStage4_Finishing($atk_team, $def_team, 'Aerian', $atk_cb_data);
            } else {
                $this->match_logs[] = "🛡️ {$def_cb_data['player']['name']} respinge mingea imperial. Pericolul a trecut.";
                $this->player_ratings[$def_cb_data['player']['player_id']] += 0.2;
                return true;
            }
        } elseif ($type == 'Direct Free Kick') {
            $this->match_logs[] = "🎯 Lovitură liberă periculoasă de la 20 de metri! {$taker['name']} își așază mingea.";
            $shot_quality = $taker['set_pieces'] + $taker['long_shots'] + rand(1, 20);
            if ($shot_quality < 25) {
                $this->match_logs[] = "🧱 Șutul se oprește direct în zidul advers.";
                return true;
            }
            $this->match_logs[] = "🚀 Șutul trece de zid și se îndreaptă spre poartă!";
            $this->player_ratings[$taker['player_id']] += 0.2;
            return $this->runStage4_Finishing($atk_team, $def_team, 'Positional', $taker_data);
        }
        return true;
    }
}
?>