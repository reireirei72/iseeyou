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
    $id = DB::escape($id);
    return DB::getVal("SELECT access_level FROM cats_fly WHERE id=$id", -1) >= (ACCESS_LEVELS_TYPES[$level] ?? $level);
}

function getStickers($type) {
    // https://vk.com/sticker/1-АЙДИСТИКЕРА-128b
    $stickers = [
        "deny_access" => [83411],
        "confused" => [13633, 80909, 94649, 106235],
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
    $message = Fly::checkMessage($object);
    $text = trim($object["text"]);
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
                    $exists = DB::getRow("SELECT name, cat_id FROM cats_fly WHERE id=$vkId");
                    if (!is_null($exists)) {
                        $message = "[id{$vkId}|$user[first_name] $user[last_name]] уже существует - это $exists[name] [$exists[cat_id]]";
                    } else {
                        $textSplit = explode(" ", trim($text));
                        $catId = intval(array_pop($textSplit));
                        $catName = formatCatName(trim(join(" ", $textSplit)));
                        if (!$catName || $catId < 1) {
                            $message = "Правильный формат - 'Имя 123', у вас '$text'???";
                        } else {
                            $catName = DB::escape($catName);
                            $existingCatName = DB::getRow("SELECT id FROM cats_fly WHERE name='$catName'", 0);
                            if ($existingCatName > 0) {
                                $userExists = getUserInfo($existingCatName, "ins");
                                $message = "Это имя уже занято [id{$existingCatName}|$userExists[first_name] $userExists[last_name]]";
                            } else {
                                DB::q("INSERT INTO cats_fly SET id=$vkId, name='$catName'");
                                $message = "[id$user[id]|$user[first_name] $user[last_name]] добавлен$a! Это $catName [$catId]";
                            }
                        }
                    }
                }
            }
        } elseif ($command == "дай") {
            $command = getCommand($text);
            preg_match('/^\[id(\d+)\|/iu', $command, $data);
            $vkId = $data[1] ?? 0;
            $self = false;
            if ($vkId < 1) {
                $text = trim($command . " " . $text);
                $types = join("|", ["имя", "доступ"]);
                preg_match("/([А-ЯЁа-яё][а-яё]+( [А-ЯЁа-яё][а-яё]+)?|\d+)\s+(($types).*)/u", $text, $data);
                $catIdOrName = $data[1] ?? "";
                $text = $data[3] ?? "";
                if (is_numeric($catIdOrName)) {
                    $vkId = DB::getVal("SELECT id FROM cats_fly WHERE cat_id=" . intval($catIdOrName), -1);
                } else {
                    $catName = DB::escape(mb_strtolower($catIdOrName));
                    $vkId = DB::getVal("SELECT id FROM cats_fly WHERE LOWER(name)='$catName'", -1);
                }
            } else {
                $vkId = DB::getVal("SELECT id FROM cats_fly WHERE id=" . $vkId, -1);
            }

            if ($vkId < 1) {
                $self = true;
                $vkId = DB::getVal("SELECT id FROM cats_fly WHERE id=$me", -1);
            }

            $catInfo = DB::getRow("SELECT * FROM cats_fly WHERE id=$vkId");
            if ($vkId < 1 || !$catInfo) {
                $sticker_id = getStickers("confused");
            } else {
                $command = getCommand($text);
                if ($command == "имя") {
                    if (!$isMom && !$self && !checkAccess($me, "Глава")) {
                        $sticker_id = getStickers("deny_access");
                    } else {
                        $name = formatCatName($text);
                        if ($name == "") {
                            $message = "Какое???";
                        } elseif (!preg_match('/^[А-ЯЁ][а-яё]+( [А-ЯЁ][а-яё]+)?$/u', $name)) {
                            $message = "Что такое '$name'???";
                        } else {
                            if ($catInfo["name"] == $name) {
                                $message = "У него и так";
                                if ($self) {
                                    $message = "У вас и так";
                                }
                                $message .= " имя $name???";
                            } else {
                                DB::q("UPDATE cats SET name='$name' WHERE id=$vkId");
                                $message = "$catInfo[name] [$catInfo[cat_id]] теперь зовут $name!";
                                if ($self) {
                                    $message = "Теперь вас зовут $name!";
                                }
                            }
                        }
                    }
                } elseif ($command == "доступ") {
                    if (!$isMom && !checkAccess($me, "Глава")) {
                        $sticker_id = getStickers("deny_access");
                    } else {
                        $level = intval($text);
                        $level_str = array_flip(ACCESS_LEVELS_TYPES)[$level] ?? "???";
                        $leader_level = ACCESS_LEVELS_TYPES["Глава"];

                        $his_level = $catInfo["access_level"];
                        if ($self) { // Смена себе
                            if ($isMom || checkAccess($me, "Глава")) { // Автор глава (высший доступ)
                                if ($isMom || $level <= $leader_level) { // Автор ставит существующий доступ
                                    $leaderCount = DB::getVal("SELECT COUNT(id) FROM cats WHERE access_level=$leader_level");
                                    if ($leaderCount > 1 || $level >= $leader_level) {
                                        DB::q("UPDATE cats SET access_level=$level WHERE id=$vkId");
                                        $message = "Теперь ваш доступ - $level_str ($level)";
                                    } else {
                                        $message = "Снимать с себя доступ главы запрещено, если больше никого с таким доступом нет.";
                                    }
                                } else {
                                    $message = "Такого доступа не существует АЛО???";
                                }
                            } else {
                                $message = $self ? "Менять себе доступ" : "Менять доступ на главу отряда";
                                $message .= " может только глава отряда";
                            }
                        } elseif (!$isMom && (!checkAccess($me, $level) || !checkAccess($me, $his_level))) {
                            // Пытается выдать кому-то доступ выше собственного
                            $sticker_id = getStickers("deny_access");
                        } else {
                            if ($catInfo["access_level"] == $level) {
                                $message = "У " . ($self ? "вас" : "него") . " уже доступ $level_str ($level)...";
                            } else {
                                DB::q("UPDATE cats SET access_level=$level WHERE id=$vkId");
                                $message = "Теперь У " . ($self ? "вас" : $catInfo["name"]) . " доступ - $level_str ($level).";
                            }
                        }
                    }
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