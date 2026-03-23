<?php
class Database {
    private $host = 'localhost';
    private $db_name = 'football_manager_db'; // Numele bazei tale de date din phpMyAdmin
    private $username = 'root';      // Default XAMPP
    private $password = '';          // Default XAMPP
    private $conn;

    public function connect() {
        $this->conn = null;
        try {
            $this->conn = new PDO('mysql:host=' . $this->host . ';dbname=' . $this->db_name, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Setăm setul de caractere pentru a suporta diacritice sau nume străine
            $this->conn->exec("set names utf8mb4");
        } catch(PDOException $e) {
            echo 'Connection Error: ' . $e->getMessage();
        }
        return $this->conn;
    }
}
?>