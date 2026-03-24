<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';
include_once '../models/Game.php';

$database = new Database();
$db = $database->connect();
$game = new Game($db);

$currentDate = $game->getCurrentDate();
$timestamp = strtotime($currentDate);
$month = (int)date('m', $timestamp);
$day = (int)date('d', $timestamp);

// Verificăm dacă suntem în fereastra de transferuri
$is_mercato = false;

// Fereastra de vară (Iulie - August)
if ($month == 7 || $month == 8) {
    $is_mercato = true;
}
// Fereastra de iarnă (Ianuarie)
if ($month == 1) {
    $is_mercato = true;
}

try {
    // 1. Procesăm transferurile DOAR dacă e Mercato
    if ($is_mercato) {
        $queryTransfers = "SELECT transfer_id FROM TransferList 
                           WHERE status = 'Active' AND DATE(expires_at) <= :currDate";
        $stmtT = $db->prepare($queryTransfers);
        $stmtT->bindParam(':currDate', $currentDate);
        $stmtT->execute();

        while($row = $stmtT->fetch(PDO::FETCH_ASSOC)) {
            $stmtF = $db->prepare("CALL Finalize_Auction(:t_id)");
            $stmtF->bindParam(':t_id', $row['transfer_id']);
            $stmtF->execute();
        }
    }

    // 2. Mentenanță Medicală (Asta merge tot anul)
    $db->query("CALL Decrement_Injuries()");

    // 3. Verificăm Finalul de Sezon (30 Iunie)
    if ($month == 6 && $day == 30) {
        // Presupunem că team_id = 1 pentru test, sau facem un loop pentru toate echipele
        $db->query("CALL End_Of_Season_Maintenance(1)");
    }

    // 4. Avansăm data
    $game->advanceOneDay();
    $newDate = $game->getCurrentDate();

    echo json_encode([
        "status" => "success",
        "current_date" => $newDate,
        "is_mercato_open" => $is_mercato,
        "message" => $is_mercato ? "Mercato este DESCHIS." : "Mercato este ÎNCHIS."
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>