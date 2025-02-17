<?php
date_default_timezone_set("Europe/Moscow");
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/main.php';
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
            sendMessage($peer_id, $message, $event["event_id"]);
        } catch (Exception $e) {
            print_r($e);
        }
        echo('ok');
        break;
    default:
        echo('Unsupported event pls help: ' . $event['type']);
        break;
}

function checkAccess($id, $level) {
    $member = Sheets::getMember($id);
    return $member["access_level"] >= (ACCESS_LEVELS_TYPES[$level] ?? $level);
}

function getStickers($type) {
    // https://vk.com/sticker/1-АЙДИСТИКЕРА-128b
    $stickers = [
        "deny_access" => [83411],
        "confused" => [13633, 80909, 94649, 106235],
        "error" => [100364],
    ];
    $typed = $stickers[$type] ?? $stickers["confused"];
    return $typed[rand(0, count($typed) - 1)];
}

function sendMessage($peer_id, $object, $uqId) {
    if ($object['date'] + 10 < time()) {
        return;
    }
    if (!in_array($peer_id, [PEER_MOM, PEER_TEST, PEER_WORK])) {
        return;
    }
    $me = intval($object['from_id']);
    $disable_mentions = true;
    $attachment = [];
    $sticker_id = 0;
    $random_id = 0;
    $isMom = ($me == PEER_MOM || $peer_id == PEER_MOM);
    $bot_names = ["летяга", "бот"];
    $bot_name = "летяга";
    $text = trim($object['text']);
    $line = mb_strtolower(mb_ereg_replace('[^а-яА-ЯЁё\w\d\-\[\]\|]+', '', $text));
    if ($line == "отмена" && !empty(Fly::getReply($object))) {
        $message = Fly::cancelAct($object);
    } else {
        $message = Fly::checkMessage($object);
    }
    if ($message == ""
        && preg_match('/^' . "(" . join("|", $bot_names) . ")" . '/iu', $text)
        && in_array(getCommand($text), $bot_names)) {
        $random_id = stringToRandomId($uqId);
        $command = getCommand($text);
        if ($command == "помощь" && $text == "") {
//            $message = "Список команд типа ну здесь";
//            $random_id = intval(time() / 10);
        } elseif ($command == "помощь" && $text != "") {
//            $message = "Команды \"$text\" не существует. Для просмотра команд напишите \"$bot_name помощь\".";
//            if ($text == "добавь") {
//                $message = "Описание команды 'добавь' и примеры ну ";
//            }
//            $random_id = intval(time() / 10);
        } elseif ($command == "добавь") {
            if (!checkAccess($me, "Глава") && !$isMom) {
                $sticker_id = getStickers("deny_access");
            } else {
                $vkIdOrTag = getCommand($text);
                preg_match('/\[id(\d+)\|/iu', $vkIdOrTag, $data);
                $vkId = intval($data[1] ?? $vkIdOrTag);
                if ($vkId < 1) {
                    $sticker_id = getStickers("confused");
                } else {
                    $user = getUserInfo($vkId);
                    $a = $user["sex"] == 1 ? "а" : "";
                    $exists = Fly::getCats($vkId)[$vkId] ?? [];
                    $message = json_encode($exists);
                    if (!empty($exists)) {
                        $message = "[id{$vkId}|$user[first_name] $user[last_name]] уже существует - это $exists[name] [$exists[id]]";
                    } else {
                        $textSplit = explode(" ", trim($text));
                        $catId = intval(array_pop($textSplit));
                        $catName = formatCatName(trim(join(" ", $textSplit)));
                        if (!$catName || $catId < 1) {
                            $message = "Правильный формат - 'Имя 123', у вас '$text'???";
                        } else {
                            $invite_date = new DateTime();
                            $invite_date->setTime(0, 0, 0);
                            $trial_end_date = new DateTime();
                            $trial_end_date->modify('+14 day');
                            $trial_end_date->setTime(23, 59, 59);
                            $data = [
                                "vk_name" => "$user[first_name] $user[last_name]",
                                "vk_id" => $vkId,
                                "cat_name" => $catName,
                                "id" => $catId,
                                "prefers_nickname" => false,
                                "invite_date" => $invite_date,
                                "trial_end_date" => $trial_end_date,
                                "access_level" => 0,
                            ];
                            $result = Sheets::addMember($data);
                            if ($result > 0) {
                                $message = "[id$user[id]|$user[first_name] $user[last_name]] добавлен$a! Это $catName [$catId]";
                            } else {
                                $sticker_id = getStickers("error");
                            }
                        }
                    }
                }
            }
        } elseif ($command == "стат") {
            preg_match('/([А-ЯЁ][а-яё]+( [А-ЯЁ][а-яё]+)?)?\s*(|весь|за \d+\.\d+(\.\d+)?\s*-\s*\d+\.\d+(\.\d+)?)$/u', trim($text), $matches);
            $catName = $matches[1] ?? "";
            $catSelf = Fly::getCats($me)[$me] ?? [];
            $catSelfName = $catSelf["name"] ?? "";
            if (!$catName) {
                $cat = Fly::getCats($me)[$me] ?? [];
                $catName = $cat["name"] ?? "";
            } else {
                $catName = formatCatName($catName);
                $cat = Sheets::getMembersBy(["name" => $catName])[0];
                $catName = $cat["name"] ?? "";
            }
            if ($catSelfName != $catName && !checkAccess($me, "Глава")) {
                $sticker_id = getStickers("deny_access");
            }
            if (!$catName) {
                $message = !empty($matches[1]) ? (formatCatName($matches[1]) . " не найден") : "Ты кто такой вообще?";
            } elseif ($sticker_id < 1) {
                $period = $matches[3] ?? "";
                $message = "Активность скалолаза $catName";
                $invite_date = DateTime::createFromFormat('d.m.Y H:i:s', $cat["invite_date"] . " 00:00:00");
                if ($period == "весь") {
                    $from = DateTime::createFromFormat('d.m.Y H:i:s', "01.01.1970 00:00:00");
                    if ($from < $invite_date) $from = $invite_date;
                    $to = new DateTime();
                    $message .= " за всё время";
                } elseif ($period != "") {
                    preg_match('/за (\d+\.\d+(\.\d+)?)\s*-\s*(\d+\.\d+(\.\d+)?)/u', $period, $matches);
                    $interval = explode('-', $period);
                    foreach ($interval as $key => $date) {
                        $date = mb_ereg_replace('[^\d.]+', '', $date);
                        $date = explode('.', $date);
                        if (count($date) > 2 && $date[2]) {
                            $year = $date[2];
                            if (strlen($year) < 4) {
                                $year = '20' . $year;
                                $date[2] = $year;
                            }
                        } else {
                            $date[2] = date("Y");
                        }
                        $interval[$key] = join('.', $date);
                    }
                    $from = DateTime::createFromFormat('d.m.Y H:i:s', $interval[0] . " 00:00:00");
                    if ($from < $invite_date) $from = $invite_date;
                    $to = DateTime::createFromFormat('d.m.Y H:i:s', $interval[1] . " 23:59:59");
                    $now = new DateTime();
                    $format = $from->format('Y') == $now->format('Y') ? 'd.m' : 'd.m.y';
                    $message .= " за " . $from->format($format) . "-" . $to->format($format);
                } else {
                    $from = new DateTime('first day of this month');
                    $to = new DateTime('last day of this month');
                    if ($from < $invite_date) $from = $invite_date;
                    $message .= " за этот месяц";
                }
                if ($from > $to) {
                    $message = "Всё нормально у тебя с датами?";
                } else {
                    $message .= ":\n" . Fly::getActivity($cat, $from, $to);
                }
            }
        }
    }

    if ($sticker_id != 0) {
        try {
            api('messages.send', array(
                'peer_id' => $peer_id,
                'message' => $message,
                'sticker_id' => $sticker_id,
                'disable_mentions' => $disable_mentions,
                'random_id' => $random_id . ""
            ));
        } catch (Exception $e) {
            print_r($e);
        }
    }
    if ($message != "") {
        if (!is_array($message)) {
            $message = [$message];
        }
        foreach ($message as $instance) {
            try {
                api('messages.send', array(
                    'peer_id' => $peer_id,
                    'message' => $instance,
                    'attachment' => join(",", $attachment),
                    'sticker_id' => $sticker_id,
                    'disable_mentions' => $disable_mentions,
                    'random_id' => $random_id . ""
                ));
            } catch (Exception $e) {
                var_dump($instance);
                print_r($e);
            }
        }
    }
}