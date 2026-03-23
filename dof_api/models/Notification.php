<?php
class Notification {
    private $conn;
    private $table = 'Notification';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Extrage toate mesajele unui utilizator, cele noi primele
    public function getByUser($user_id) {
        $query = 'SELECT notification_id, type, title, content, is_read, created_at 
                  FROM ' . $this->table . ' 
                  WHERE user_id = :user_id 
                  ORDER BY created_at DESC';

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        return $stmt;
    }

    // Marchează un mesaj ca citit
    public function markAsRead($notification_id) {
        $query = 'UPDATE ' . $this->table . ' SET is_read = 1 WHERE notification_id = :id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $notification_id);

        return $stmt->execute();
    }
}
?>