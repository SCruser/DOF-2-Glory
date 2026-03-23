<?php
class Player {
    private $conn;
    private $table = 'Player_core';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Metoda pentru a vedea lotul unei echipe
    public function getTeamSquad($team_id) {
        $query = 'SELECT p.player_id, p.name, p.age, p.country_code, p.is_GK, 
                         a.finishing, a.pace, a.stamina_max, p.current_stamina, p.injury_days_left
                  FROM ' . $this->table . ' p
                  LEFT JOIN Attributes_Outfield a ON p.player_id = a.player_id
                  WHERE p.team_id = :team_id';

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':team_id', $team_id);
        $stmt->execute();

        return $stmt;
    }
}
?>