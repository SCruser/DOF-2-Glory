<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');

include_once '../config/Database.php';

$database = new Database();
$db = $database->connect();

if($db == null) {
    die(json_encode(["error" => "Conexiune DB eșuată"]));
}

// Citim datele trimise (JSON)
$data = json_decode(file_get_contents("php://input"));

if(!isset($data->team_id) || !isset($data->manager_id)) {
    echo json_encode(["status" => "error", "message" => "Lipsesc datele: team_id sau manager_id."]);
    exit();
}

try {
    $db->beginTransaction();

    // 1. Verificăm dacă managerul este liber
    $queryMgr = "SELECT name, team_id FROM managers WHERE manager_id = :mid FOR UPDATE";
    $stmtMgr = $db->prepare($queryMgr);
    $stmtMgr->bindParam(':mid', $data->manager_id);
    $stmtMgr->execute();
    $manager = $stmtMgr->fetch(PDO::FETCH_ASSOC);

    if(!$manager) {
        throw new Exception("Managerul nu a fost găsit.");
    }
    if($manager['team_id'] !== null) {
        throw new Exception("Managerul " . $manager['name'] . " are deja un contract!");
    }

    // 2. Concediem antrenorul actual al echipei (dacă există)
    $fireQuery = "UPDATE managers SET team_id = NULL, contract_months_left = 0 WHERE team_id = :tid";
    $stmtFire = $db->prepare($fireQuery);
    $stmtFire->bindParam(':tid', $data->team_id);
    $stmtFire->execute();

    // 3. Angajăm noul manager (punem un contract standard de 24 luni)
    $hireQuery = "UPDATE managers 
                  SET team_id = :tid, contract_months_left = 24 
                  WHERE manager_id = :mid";
    $stmtHire = $db->prepare($hireQuery);
    $stmtHire->bindParam(':tid', $data->team_id);
    $stmtHire->bindParam(':mid', $data->manager_id);
    $stmtHire->execute();

    $db->commit();

    echo json_encode([
        "status" => "success",
        "message" => "L-ai angajat cu succes pe " . $manager['name'] . "!",
        "details" => [
            "manager_id" => $data->manager_id,
            "team_id" => $data->team_id,
            "contract_duration" => "24 luni"
        ]
    ]);

} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>