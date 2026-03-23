<?php
class Game {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Returnează data curentă din joc
    public function getCurrentDate() {
        $query = "SELECT current_game_date FROM Game_Settings WHERE setting_id = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    // Avansează o zi în calendar
    public function advanceOneDay() {
        $query = "UPDATE Game_Settings SET current_game_date = DATE_ADD(current_game_date, INTERVAL 1 DAY) WHERE setting_id = 1";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute();
    }
}
?>