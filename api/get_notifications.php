<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include_once '../config/Database.php';
include_once '../models/Notification.php';

$database = new Database();
$db = $database->connect();
$notif = new Notification($db);

$user_id = isset($_GET['user_id']) ? $_GET['user_id'] : die(json_encode(["message" => "user_id lipsa"]));

$result = $notif->getByUser($user_id);
$num = $result->rowCount();

if($num > 0) {
    $notif_arr = array();
    $notif_arr['data'] = array();

    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
        extract($row);
        $item = array(
            'id' => $notification_id,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'is_read' => (bool)$is_read,
            'date' => $created_at
        );
        array_push($notif_arr['data'], $item);
    }
    echo json_encode($notif_arr);
} else {
    echo json_encode(["message" => "Inbox gol."]);
}
?>