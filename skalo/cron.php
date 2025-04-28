<?php
date_default_timezone_set("Europe/Moscow");

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/config.php';
(!empty($argc) && $argc == 2) or exit;
(VK_API_ACCESS_TOKEN == $argv[1]) or exit;

require_once __DIR__ . '/../includes/func.php';
require_once __DIR__ . '/includes/sheets.php';

$message = "Желающие на сбор со скал?\nИмя, ЛУ, хп, ущелье/уступы";
$cats = Sheets::getMembersBy(["is_responsible" => "TRUE"]);
$should_tag = [];
foreach ($cats as $cat) {
    $should_tag[] = "[id$cat[vk_id]|$cat[name]]";
}
if (!empty($should_tag)) {
    $message .= "\n" . join(", ", $should_tag);
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