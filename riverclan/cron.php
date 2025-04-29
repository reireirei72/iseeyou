<?php
date_default_timezone_set("Europe/Moscow");

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/config.php';
(!empty($argc) && $argc == 2) or exit;
(VK_API_ACCESS_TOKEN == $argv[1]) or exit;

$hour = intval(date('G'));
$day = intval(date('j'));

if (in_array($hour + 1, [9, 11, 15, 18, 21, 23])) {
    $message = "Близится сбор патруля, пора вставать на охрану границ! ";
    $field = "patr";
} elseif (in_array($hour + 1, [13, 19])) {
    $message = "Пора пополнить кучу с добычей! Не пропустите охотничий патруль и да благословит Звёздное племя вашу охоту!";
    $field = "hunt";
} elseif (in_array($hour + 1, [12, 16, 17])) {
    $message = "Целителям нужна ваша помощь, разминайте лапы и побежали собирать травы!";
    $field = "herb";
}
if (empty($message)) {
    exit;
}
require_once __DIR__ . '/../includes/func.php';
require_once __DIR__ . '/../includes/db.php';

$users = DB::getValArray("SELECT id FROM users_river WHERE $field = 1");
$tags = [];
$users = getUserInfo($users);
foreach ($users as $user) {
    $tags[] = "[id$user[id]|$user[first_name]]";
}
if (empty($tags)) {
    exit;
}
$message .= "\n" . join(", ", $tags);

try {
    api('messages.send', array(
        'peer_id' => PEER_NOTIFY,
        'message' => $message,
        'disable_mentions' => false,
        'random_id' => time() . ""
    ));
} catch (Exception $e) {
    print_r($e);
}