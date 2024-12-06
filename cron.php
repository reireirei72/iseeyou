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
$should_tag = [];
$result = DB::q("SELECT users.id AS 'id', name, access_level = 6 AS 'is_gatherer' FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE access_level = 6 OR herbdoz_tag_enabled");
while (list($id, $name, $isGatherer) = DB::fetch($result)) {
    if (!!$isGatherer) {
        $users[] = "[id$id|$name]";
    } else {
        $should_tag[] = "[id$id|$name]";
    }
}
if (!empty($users)) {
    $message .= "\n" . join(", ", $users) . ", работать!";
}
if (!empty($should_tag)) {
    $message .= "\n" . join(", ", $should_tag) . ", просыпаемся!";
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