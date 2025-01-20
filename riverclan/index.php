<?php
date_default_timezone_set("Europe/Moscow");
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/sheets.php';
require_once __DIR__ . '/../includes/func.php';

$event = json_decode(file_get_contents('php://input'), true);

switch ($event['type']) {
    case "confirmation":
        echo(CALLBACK_API_CONFIRMATION_TOKEN);
        break;
    case "message_new":
        $message = $event['object']['message'];
        $peer_id = $message['peer_id'];
        try {
            send_message($peer_id, $message);
        } catch (Exception $e) {
            print_r($e);
        }
        echo('ok');
        break;
    case "board_post_edit":
    case "board_post_new":
    case "board_post_delete":
    case "board_post_restore":
        $type = substr($event['type'], 11);
        $object = $event['object'];
        try {
            board_event($object, $type);
        } catch (Exception $e) {
            print_r($e);
        }
        echo('ok');
        break;
    default:
        echo('Unsupported event pls help: ' . $event['type']);
        break;
}

function board_event($object, $type) {
    if ($object["topic_id"] != PEOPLE_BOARD || $object["from_id"] < 0) {
        return;
    }
    if ($type == "new" || $type == "edit" || $type == "restore") {
        $user = getUserInfo($object["from_id"]);
        $text = $object["text"];
        $text = (str_replace(["\n"], ' ', $text));
        $text = trim(str_replace(['—', '–', '‐'], '-', $text));
        // Тис - 406811 - кличка
        preg_match('/^([А-яЁёë ]+)\s*-\s*(\d+)(\s*-?\s*([А-яЁёë]+)?)?/iu', $text, $matches);
        $name = formatCatName(trim($matches[1] ?? ""));
        $cat_id = intval($matches[2] ?? 0);
        $nickname = trim($matches[4] ?? "");
        $key = Sheets::search([
            6 => $object['id'],
        ]);
        $data = [[
            'name' => $name,
            'cat_id' => $cat_id,
            'nickname' => $nickname,
            'vk_id' => $object['from_id'],
            'vk_name' => "$user[first_name] $user[last_name]",
            'msg_id' => $object["id"],
        ]];
        if ($key < 1) {
            Sheets::write($data);
        } else { // То же самое сообщение
            Sheets::modify($key, $data[0]);
        }
    } elseif ($type == "delete") {
        $key = Sheets::search([
            6 => $object['id'],
        ]);
        if ($key > 0) {
            Sheets::remove($key);
        }
    }

}
/* EDIT
DELETE
    "object": {
        "topic_owner_id": -62974276,
        "id": 5083,
        "topic_id": 40293628
    }
 *  "object": {
        "id": 3490,
        "from_id": 320045059,
        "date": 1589471841,
        "text": "Тис - 406811 [https://catwar.su/cat406811]",
        "topic_owner_id": -62974276,
        "topic_id": 40293628
    }
 *
 * NEW
 *
    "object": {
        "id": 5083,
        "from_id": 320045059,
        "date": 1735905232,
        "text": "123",
        "topic_owner_id": -62974276,
        "topic_id": 40293628
    }
}
RESTORE
    "object": {
        "id": 5083,
        "from_id": 320045059,
        "date": 1735905232,
        "text": "123",
        "topic_owner_id": -62974276,
        "topic_id": 40293628
    }
 * */
function send_message($peer_id, $object) {
    if ($object['date'] + 10 < time()) {
        return;
    }
    $me = intval($object['from_id']);
    $isMom = ($me == PEER_MOM || $peer_id == PEER_MOM);
    $text = $object["text"];
    if ($text[0] != "/") {
        return;
    }
    $text = trim(mb_substr(mb_strtolower($text), 1));
    $commands = [
        "check" => "проверить участников",
        "clean" => "чистка",
    ];
    if (!in_array($text, $commands)) {
        return;
    }

    $managers = api('groups.getMembers', array(
        'group_id' => GROUP_ID,
        'filter' => 'managers',
    ));
    $managers = $managers["items"] ?? [];
    $isManager = false;
    foreach ($managers as $manager) {
        if ($manager["id"] == $me) {
            $isManager = true;
            break;
        }
    }
    if (!$isManager) {
//        print_r("error 3");
        return;
    }
    $messageArray = [];
    if ($text == $commands["check"]) {
        $members = api('groups.getMembers', array(
            'group_id' => GROUP_ID,
        ));
        $members = $members["items"];
        $tableIds = Sheets::getColumnArray(4);
        array_shift($tableIds); // Удаляет заголовок
        $notInTable = [];
        foreach ($members as $key => $member) {
            $index = array_search($member, $tableIds);
            if ($index === false) {
                $notInTable[] = $member;
                $all[] = $member;
            } else {
                unset($tableIds[$index]);
                unset($members[$key]);
            }
        }
        $notInGroup = $tableIds;
        $all = array_merge($notInTable, $notInGroup);
        if (empty($all)) {
            $messageArray[] = "Всё идеально засинхронено, все молодцы";
        } else {
            $users = getUserInfo($all);
            $list = [];
            foreach ($users as $user) {
                $list[$user["id"]] = $user;
            }
            $message = "Есть в группе, но нет в таблице (надо кикнуть):\n";
            if (!empty($notInTable)) {
                foreach ($notInTable as $user) {
                    $userInfo = $list[$user] ?? ["first_name" => "Пользователь", "last_name" => "удалён"];
                    $message .= "$user [id$user|$userInfo[first_name] $userInfo[last_name]]\n";
                }
            } else {
                $message .= "Здесь пусто!\n";
            }
            $message .= "\nЕсть в таблице, но нет в группе (надо почистить обсуждение с отписями):\n";
            if (!empty($notInGroup)) {
                foreach ($notInGroup as $user) {
                    $userInfo = $list[$user] ?? ["first_name" => "Пользователь", "last_name" => "удалён"];
                    $message .= "$user [id$user|$userInfo[first_name] $userInfo[last_name]]\n";
                }
            } else {
                $message .= "Здесь пусто!";
            }
            $message = explode("\n", $message);
            $displayMessage = "";
            foreach ($message as $line) {
                $displayMessage .= $line . "\n";
                if (mb_strlen($displayMessage) > 2048 - 250) {
                    $messageArray[] = trim($displayMessage);
                    $displayMessage = "";
                }
            }
            $messageArray[] = trim($displayMessage);
        }
    } elseif ($text == $commands["clean"]) {
        $missingIds = Sheets::getColumnArray(6, [
            0 => "?",
        ]);
        if (!empty($missingIds)) {
            $missingIds = array_slice($missingIds, 0, 5);
            foreach ($missingIds as $id) {
                try {
                    api('board.deleteComment', array(
                        'group_id' => GROUP_ID,
                        'topic_id' => PEOPLE_BOARD,
                        'comment_id' => $id,
                    ));
                } catch (Exception $e) {
                    print_r($e);
                }
            }
            $messageArray[] = "Удалено " . count($missingIds) . " записей!\n(Если остались ещё записи - повторите команду ещё раз)";

        } else {
            $messageArray[] = "Табличка уже чиста от удалённых :о";
        }
    }
    foreach ($messageArray as $instance) {
        try {
            api('messages.send', array(
                'peer_id' => $peer_id,
                'message' => $instance,
                'disable_mentions' => true,
                'random_id' => "0",
            ));
        } catch (Exception $e) {
            print_r($instance);
            print_r($e);
        }
    }
}