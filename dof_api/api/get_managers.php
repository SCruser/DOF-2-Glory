<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';

$database = new Database();
$db = $database->connect();

if($db == null) {
    die(json_encode(["error" => "Conexiune DB eșuată"]));
}

try {
    // Interogăm managerii liberi de contract și formția lor preferată
    $query = "SELECT 
                m.manager_id, 
                m.name, 
                m.salary, 
                f.name as preferred_formation, 
                m.mentality, 
                m.passing_style, 
                m.tempo, 
                m.defensive_line, 
                m.pressing_intensity
              FROM Managers m
              LEFT JOIN Formations f ON m.preferred_formation_id = f.formation_id
              WHERE m.team_id IS NULL
              ORDER BY m.salary DESC";

    $stmt = $db->prepare($query);
    $stmt->execute();

    $managers = [];
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Formatăm salariul pentru a arăta mai bine în JSON
        $row['salary'] = number_format($row['salary'], 0, '.', ',') . ' €/lună';
        $managers[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "market_size" => count($managers),
        "free_agents" => $managers
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>