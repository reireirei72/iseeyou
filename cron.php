<?php
date_default_timezone_set("Europe/Moscow");

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/config.php';

(!empty($argc) && $argc == 2) or exit;
(VK_API_ACCESS_TOKEN == $argv[1]) or exit;

require_once __DIR__ . '/www/u151626.test-handyhost.ru/includes/db.php';

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


function api($method, $params) {
    $params['access_token'] = VK_API_ACCESS_TOKEN;
    $params['v']            = VK_API_VERSION;
    $query                  = http_build_query($params);
    $url                    = VK_API_ENDPOINT . $method . '?' . $query;
    $curl                   = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $json  = curl_exec($curl);
    $error = curl_error($curl);
    if ($error) {
        var_dump($error);
        throw new Exception("Failed {$method} request");
    }
    curl_close($curl);
    $response = json_decode($json, true);
    if (!$response || !isset($response['response'])) {
        var_dump($json);
        throw new Exception("Invalid response for {$method} request");
    }
    return $response['response'];
}