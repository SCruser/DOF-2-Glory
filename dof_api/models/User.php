<?php
class User {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function login($mail, $password) {
        // Luăm user-ul și team_id-ul asociat dintr-un JOIN
        $query = 'SELECT u.user_id, u.password, t.team_id 
                  FROM User u 
                  LEFT JOIN Team t ON u.user_id = t.user_id 
                  WHERE u.mail = :mail LIMIT 1';

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':mail', $mail);
        $stmt->execute();

        if($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Verificăm parola criptată
            if(password_verify($password, $row['password'])) {
                return $row; // Returnăm datele user-ului
            }
        }
        return false;
    }
}
?>