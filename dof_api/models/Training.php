<?php
class Training {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Execută antrenamentul săptămânal
    public function runWeekly($team_id) {
        try {
            // Apelăm procedura stocată CALL Run_Weekly_Training(id)
            $query = "CALL Run_Weekly_Training(:team_id)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':team_id', $team_id, PDO::PARAM_INT);

            if($stmt->execute()) {
                return true;
            }
            return false;
        } catch (PDOException $e) {
            return $e->getMessage();
        }
    }

    // Actualizează setările de focus înainte de antrenament
    public function updateSettings($team_id, $focus, $intensity) {
        $query = "INSERT INTO Training_Settings (team_id, focus_area, intensity) 
                  VALUES (:team_id, :focus, :intensity)
                  ON DUPLICATE KEY UPDATE focus_area = :focus, intensity = :intensity";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':team_id', $team_id);
        $stmt->bindParam(':focus', $focus);
        $stmt->bindParam(':intensity', $intensity);

        return $stmt->execute();
    }
}
?>