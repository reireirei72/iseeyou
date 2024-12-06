<?php
date_default_timezone_set("Europe/Moscow");

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/config.php';

(!empty($argc) && $argc == 2) or exit;
(VK_API_ACCESS_TOKEN == $argv[1]) or exit;

require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/db.php';
require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/func.php';

$ended = DB::q("SELECT id, user_id, type FROM doz_active WHERE ends_at <= NOW()");
$to_clean = [];
$to_notify = [];
$users_info = [];
foreach ($ended as $doz) {
    $to_clean[] = $doz["id"];
    $to_notify[] = $doz["user_id"];
    $users_info[$doz["user_id"]] = $doz["type"];
}
if (empty($to_clean)) {
    exit;
}
$ids = join(", ", $to_clean);
DB::q("DELETE FROM doz_active WHERE id IN ($ids)");

$ids = join(", ", $to_notify);
$users = DB::q("SELECT users.id AS 'id', name, maindoz_tag_enabled AS 'main', gbdoz_tag_enabled AS 'gb' FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE users.id IN ($ids)");
$users_names = [];
foreach ($users as $user) {
    if ($users_info[$user["id"]] == "main" && $user["main"] ||
        $users_info[$user["id"]] == "gb" && $user["gb"]) {
        $users_names[] = "[id$user[id]|$user[name]]";
    }
}

if (!empty($users_names)) {
    $message = join(", ", $users_names) . ", дозор всё, можно спать";
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
}
