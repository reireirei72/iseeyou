<?php
date_default_timezone_set("Europe/Moscow");

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/config.php';

(!empty($argc) && $argc == 2) or exit;
(VK_API_ACCESS_TOKEN == $argv[1]) or exit;

require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/db.php';
require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/func.php';

$hour = intval(date('G'));
$day = intval(date('j'));
$isDayEven = !($day % 2);

$column = "message_" . ($hour + 1) . "_" . ($isDayEven ? "" : "un") . "even";
$message = DB::getVal("SELECT `$column` FROM settings", "");
($message != "") or exit("Неизвестный текст настройки $column!");

$users = [];
$result = DB::q("SELECT users.id AS 'id', name FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE access_level = 6");
while (list($id, $name) = DB::fetch($result)) {
    $users[] = "[id$id|$name]";
}
if (!empty($users)) {
    $message .= "\n" . join(", ", $users) . ", работать!";
}
try {
    api('messages.send', array(
        'peer_id' => PEER_WORK,
        'message' => $message,
        'disable_mentions' => false,
        'random_id' => time() . ""
    ));
} catch (Exception $e) {
    print_r($e);
}