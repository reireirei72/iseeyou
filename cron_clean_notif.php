<?php
date_default_timezone_set("Europe/Moscow");

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/config.php';

(!empty($argc) && $argc == 2) or exit;
(VK_API_ACCESS_TOKEN == $argv[1]) or exit;

require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/func.php';

$hour = intval(date('G'));
$day = intval(date('j'));
$isDayEven = !($day % 2);

$message = "Который час?\nВремя почистить КсД!";

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