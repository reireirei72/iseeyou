<?php
date_default_timezone_set("Europe/Moscow");
header('Content-Type: text/html; charset=utf-8');

// Типы доступов
if (!defined('ACCESS_LEVELS_TYPES')) define('ACCESS_LEVELS_TYPES', [
    "ИС" => 0,
    "Наблюдатель" => 1,
    "Разрешение" => 2,
    "Проверяющий" => 3,
    "Куратор" => 4,
    "Целитель" => 5,
    "Доверенный" => 6,
    "Глава" => 7,
    "Мама" => 100,
]);

function getCommand(&$text, $allowCommas = false, $reverse = false) {
    $text = trim($text);
    if ($reverse) {
        $space = mb_strrpos($text, ' ') ?: null;
        $command = mb_substr(mb_strtolower($text), $space ?? -1 + 1);
        $text = (($space === null) ? "" : mb_substr($text, 0, $space ?? -1 + 1));
    } else {
        $space = mb_strpos($text, ' ') ?: null;
        $command = mb_substr(mb_strtolower($text), 0, $space);
        $text = (($space === null) ? "" : mb_substr($text, $space + 1));
    }
    return mb_ereg_replace('[^а-яА-ЯЁё\w' . ($allowCommas ? '\.' : '') . '\d\-\[\]\|]+', '', trim($command));
}

function mapUsers($user_id_array, $case = "nom") {
    $user_array = getUserInfo($user_id_array, $case);
    $mapped = array_map(function($u) {return "[id$u[id]|$u[first_name] $u[last_name]]"; }, $user_array);
    if (count($mapped) < 2) {
        return join(", ", $mapped);
    }
    $last = array_pop($mapped);
    return join(", ", $mapped) . " и " . $last;
}

function checkAccess($id, $level) {
    return DB::getVal("SELECT access_level FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE users.id=$id", -1) >= (ACCESS_LEVELS_TYPES[$level] ?? $level);
}

function getTimePeriodFromString($string, &$from, &$to) {
    if (preg_match('/(\d+\.\d+(\.\d*)?)?-(\d+\.\d+(\.\d*)?)?/iu', $string)) {
        $interval = explode('-', $string);
        foreach ($interval as $key => $date) {
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
        $to = DateTime::createFromFormat('d.m.Y H:i:s', $interval[1] . " 23:59:59");
    } elseif (preg_match('/\d+\.\d+(\.\d+)?/iu', $string)) {
        $date = explode('.', $string);
        if (count($date) > 2 && $date[2]) {
            $year = $date[2];
            if (strlen($year) < 4 && $date[2]) {
                $year = '20' . $year;
                $date[2] = $year;
            }
        } else {
            $date[2] = date("Y");
        }
        $date = join('.', $date);
        $from = DateTime::createFromFormat('d.m.Y H:i:s', $date . " 00:00:00");
        $to = DateTime::createFromFormat('d.m.Y H:i:s', $date . " 23:59:59");
    }
}

require_once __DIR__ . '/includes/main.php';

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
    default:
        echo('Unsupported event pls help: ' . $event['type']);
        break;
}

function send_message($peer_id, $object) {
    // TODO: peer_id check
    if ($object['date'] + 10 < time()) {
        return;
    }
    $me = intval($object['from_id']);
    $who = $me;
    $disable_mentions = true;
    $message = "";
    $attachment = [];
    $forward = "{}";
    $sticker_id = 0;
    $random_id = 0;
    $isMom = ($me == PEER_MOM || $peer_id == PEER_MOM);
    if (!in_array($peer_id, [PEER_MOM, PEER_TEST]) && !in_array($peer_id,PEERS_WORK)) {
        return;
    }

    $text = trim($object['text']);
    $reply = Peck::getReply($object);
    $line = mb_strtolower(mb_ereg_replace('[^а-яА-ЯЁё\w\d\-\[\]\|]+', '', trim($text)));
    if ($line == "отмена" && !empty($reply)) {
        $message = Peck::cancelAct($object);
    } else {
        $message = Peck::checkMessage($object);
    }

    if (is_int($message)) {
        $sticker_id = $message;
        $message = "";
    }

    $bot_name = "дятлушка";
    $bot_names = ["дятлушка", "бот"];
    if ($message == ""
        && preg_match('/^' . "(" . join("|", $bot_names) . ")" . '/iu', $text)
        && in_array(getCommand($text), $bot_names)) {
        $command = getCommand($text);
        if ($command == "помощь" && $text == "") {
            $message = "Список команд (напишите \"$bot_name помощь (команда)\" для подробностей):\n"
                . "> дай (мне / ВК VK_ID / @таг / ID / Имя) (имя / доступ / норму / медаль / разрешение / айди) (текст / число)\n"
                . "> инфо (Имя / ID / ВК VK_ID / @таг)\n"
                . "> шаблон(ы)\n"
                . "> активность (VK_ID / @таг / Имя) (за [период])\n"
                . "> отмена\n"
                . "> добавь (VK_ID / @таг) Имя ID\n"
                . "> удали (ВК VK_ID / @таг / ID / Имя)\n"
                . "> дозорные\n"
                . "> норма (1 / 2 / ИС)\n"
                . "> тагни на перенос/не тагай на перенос\n"
                . "> тагни выдавателей\n"
                . "> уведомление (вид деятельности) (включить/выключить)\n"
                . "> настройка (ключ настройки)"
            ;
            $random_id = intval(time() / 10);
        } elseif ($command == "помощь" && $text != "") {
            $message = "Команды \"$text\" не существует. Для просмотра команд напишите \"$bot_name помощь\".";
            if ($text == "дай") {
                $message = "> дай (кому) (что) (на что заменить)\n"
                    . "Каждая из команд пишется по одному и тому же принципу. В поле \"кому\" можно указывать одно из"
                    . " следующих: ВК (ID ВК), @тег, ID на варе, имя на варе. Можно писать \"мне\", чтобы"
                    . " изменить параметр вам.\n"
                    ."Примеры:\n"
                    . "> $bot_name дай мне имя Наблюдатель\n"
                    . "> $bot_name дай ВК 320045059 имя Омуль\n"
                    . "Меняет имя.\n\n"
                    . "> $bot_name дай 1454689 доступ 7\n"
                    . "Меняет доступ. Могут это делать только доверенные и выше и только для тех, у кого доступ ниже,"
                    ." чем у них. Менять себе доступ нельзя.\n\n"
                    . "> $bot_name дай Омуль норму 2\n"
                    . "Меняет тип нормы. О типах нормы можно узнать, введя команду \"$bot_name норма (тип)\"\n\n"
                    . "> $bot_name дай @podlazhishi медаль\n"
                    . "Выдаёт медаль. Если написать \"-медаль\", медаль снимется.\n\n"
                    . "> $bot_name дай @podlazhishi разрешение\n"
                    . "Выдаёт разрешение на выдачу трав/костоправов. Если написать \"-разрешение\", разрешение уберётся.\n\n"
                    . "> $bot_name дай @podlazhishi айди 1454689\n"
                    . "Меняет варовский ID для персонажа и всех ВК, которые к нему привязаны. Нельзя сменить ID через ВК-тег или ВК ID на такой, который ни за кем не закреплён.";
            } elseif ($text == "инфо") {
                $message = "> инфо (Имя / ID / ВК ID / ВК @таг)\n"
                    . "Пример: $bot_name инфо Тис\n"
                    . "Пример: $bot_name инфо 406811\n"
                    . "Пример: $bot_name инфо ВК 320045059\n"
                    . "Показывает имя и уровень доступа пользователя с этим варовским именем, варовским ID, ВК-шным ID или ВК-шным @тагом.";
            } elseif ($text == "шаблон" || $text == "шаблоны") {
                $message = "> шаблоны\n"
                    . "Показывает список шаблонов активностей.\n"
                    . "> шаблон (активность)\n"
                    . "Пример: $bot_name шаблон дозор в пц\n"
                    . "Показывает шаблон активности.";
            } elseif ($text == "активность") {
                $message = "> активность (ID / @таг / Имя) (за [период])\n"
                    . "Пример: $bot_name активность\n"
                    . "Пример: $bot_name активность 320045059 за 12.05-19.05\n"
                    . "Пример: $bot_name активность Омуль за 15.05\n"
                    . "Пример: $bot_name активность за всё время\n"
                    . "Показывает вашу активность за период. Если период не указан, берётся текущая неделя"
                    . " (между последней субботой и ближайшей пятницей)."
                    . " Можно указывать 'за всё время', тогда будет считаться абсолютно вся активность."
                    . " Смотреть чужую активность можно только с доступом доверенного или выше";
            } elseif ($text == "отмена") {
                $message = "> отмена\n"
                    . "Отменяет отчёт с активностью. Отмена таким образом удаляет вашу запись из таблицы."
                    . " Удалять чужие записи можно только с доступом доверенного или выше";
            } elseif ($text == "удали") {
                $message = "> удали (ВК VK_ID / @таг / ID / Имя)\n"
                    . "Пример: $bot_name удали 320045059\n"
                    . "Пример: $bot_name удали Тис\n"
                    . "Удаляет пользователя с этим ВК ID, @тагом, варовским ID или именем. В гугловской таблице все записи с ним останутся. Если удалять по варовскому ID или имени, удалятся все пользователи, привязанные к этому персонажу";
            } elseif ($text == "добавь") {
                $message = "> добавь (ВК ID / @таг) Имя ID\n"
                    . "Пример: $bot_name добавь 320045059 Тис 406811\n"
                    . "Добавляет пользователя с указанными варовским именем и ID";
            } elseif ($text == "дозорные") {
                $message = "> дозорные (или 'кто дозорит')\n"
                    . "Пример: $bot_name кто дозорит\n"
                    . "Показывает текущих дозорных в Палатке целителей и на Галечном берегу";
            } elseif ($text == "норма") {
                $message = "> норма (1 / 2 / ИС)\n"
                    . "Пример: $bot_name норма 1\n"
                    . "Показывает норму под выбранным вариантом";
            } elseif ($text == "тагни на перенос" || $text == "не тагай") {
                $message = "> тагни на перенос\n"
                    . "Бот запоминает вас, чтобы тагнуть, когда собирающий перенос отдаст ему эту команду."
                    . " Память бота сбрасывается в полночь по МСК.\n"
                    . "Чтобы бот вас забыл, используйте команду 'не тагай'";
            } elseif ($text == "тагни выдавателей") {
                $message = "> тагни выдавателей (выдавателя)\n"
                    . "Пример: $bot_name тагни выдавателей\n"
                    . "Пример: $bot_name тагни выдавателя\n"
                    . "Тагает всех (или одного случайного) выдавателя (кого-то с разрешением), который сейчас в сети.";
            } elseif ($text == "уведомление") {
                $message = "> уведомление (вид деятельности) включить/выключить\n"
                    . "Пример: $bot_name уведомление перенос включить\n"
                    . "Пример: $bot_name уведомление дозор в ПЦ выключить\n"
                    . "Включает или выключает постоянные уведомления для какой-то деятельности (достаточно включить"
                    . " один раз, и бот будет всегда вас тагать на эту деятельность). Слово 'включить' можно не писать"
                    . " - тогда опция просто переключится (если было выключено, включится, и наоборот).\n"
                    . " Чтобы увидеть список всех доступных уведомлений, просто напишите '$bot_name уведомление'.";
            } elseif ($text == "настройка") {
                $message = "> настройка (ключ настройки) ('установить')\n"
                    . "Пример: $bot_name настройка message_12_even\n"
                    . "Пример: $bot_name настройка message_15_uneven установить Собирайтесь, ало!\n"
                    . "Просматривает или устанавливает (меняет) одну из настроек бота."
                    . " Чтобы увидеть список всех доступных настроек (их ключи и описания), просто напишите '$bot_name настройка'.\n"
                    . "Доступно только главе отряда.";
            }
        } elseif ($command == "норма") {
            $text = trim($text);
            if ($text == "1") {
                $message = "1 вариант нормы для участника без медали:\n"
                    . "• 4 часа в ПЦ;\n"
                    . "• 4 дозора на Гб;\n"
                    . "• 6 баллов за доп. задания\n\n"
                    . "1 вариант нормы для участника с медалью:\n"
                    . "• 3 часа в ПЦ;\n"
                    . "• 4 дозора на Гб;\n"
                    . "• 4 балла за доп. задания\n\n"
                    . "Доп.задания в списке заданий блога с 3 по 16";
            } elseif ($text == "2") {
                $message = "2 вариант нормы для участника без медали:\n"
                    . "• 3 часа в ПЦ;\n"
                    . "• 2 дозора на Гб;\n"
                    . "• 15 баллов за доп. задания\n\n"
                    . "2 вариант нормы для участника с медалью:\n"
                    . "• 2 часа в ПЦ;\n"
                    . "• 2 дозора на Гб;\n"
                    . "• 12 балла за доп. задания\n\n"
                    . "Доп.задания в списке заданий блога с 3 по 16";
            } elseif (mb_strtolower($text) == "ис") {
                $message = "• 4 часа в ПЦ;\n"
                    . "• 2 дозора на Гб;\n"
                    . "• 3 дозора на локациях с травами;\n"
                    . "• 10 баллов за доп. задания\n\n"
                    . "Доп.задания в списке заданий блога с 3 по 14, 16\n"
                    . "3 дозора на локациях с травами НЕ входят в эту часть. От 4 и более — входят.";
            }
        } elseif ($command == "уведомление") {
            if (empty(trim($text))) {
                $message = "Доступные виды уведомлений:\n"
                    . "• Дозор в ПЦ (таг по окончании дозора);\n"
                    . "• Дозор на ГБ (таг по окончании дозора);\n"
                    . "• Дозор на травах (таг при начале сбора);\n"
                    . "• Перенос (таг в начале каждого переноса).";
            } else {
                $optionStr = getCommand($text, false, true);
                $newValue = $optionStr == "включить";
                if (!$newValue && $optionStr != "выключить") { // это не "включить" и не "выключить"
                    $text = trim($text) . " " . trim($optionStr);
                    $newValue = null;
                }
                $type = mb_strtolower(trim($text));
                $field = "";
                $fieldName = "";
                if ($type == "дозор в пц") {
                    $field = "maindoz";
                    $fieldName = "по окончании дозора в ПЦ";
                } elseif ($type == "дозор на гб") {
                    $field = "gbdoz";
                    $fieldName = "по окончании дозора на ГБ";
                } elseif ($type == "дозор на травах") {
                    $field = "herbdoz";
                    $fieldName = "на начало сбора дозора на травах";
                } elseif ($type == "перенос") {
                    $field = "carryover";
                    $fieldName = "на начало переноса ресурсов";
                } else {
                    $message = "Чё-т у вас странное уведомление...";
                }
                if ($field) {
                    if (is_null($newValue)) {
                        $newValue = !(DB::getVal("SELECT {$field}_tag_enabled FROM users WHERE id=$me", 0));
                    }
                    $newValue = $newValue ? 1 : 0;
                    DB::q("UPDATE users SET {$field}_tag_enabled=$newValue WHERE id=$me");
                    $message = "Уведомление $fieldName успешно " . ($newValue ? "включено" : "выключено") . "!";
                }
            }
        } elseif ($command . " " . $text == "тагни на перенос") {
            $date = date('Y-m-d H:i:s');
            DB::q("UPDATE users SET asked_to_tag_at = '$date' WHERE id=$me");
            $affected = DB::affectedRows();
            if ($affected < 1) {
//                $sticker_id = 83436;
            } else {
                sendReaction($peer_id, $object["conversation_message_id"], 10);
                return;
            }
        } elseif ($command . " " . $text == "не тагай") {
            DB::q("UPDATE users SET asked_to_tag_at = '0000-00-00 00:00:00' WHERE id=$me");
            $affected = DB::affectedRows();
            if ($affected < 1) {
//                $sticker_id = 83436;
            } else {
                sendReaction($peer_id, $object["conversation_message_id"], 10);
                return;
            }
        } elseif ($command . " " . $text == "тагни желающих в перенос") {
            if (!checkAccess($me, "Доверенный")) {
                $sticker_id = 83411;
            } else {
                $date = date('Y-m-d 00:00:00');
                $result = DB::q("SELECT users.id as 'id', name FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE (asked_to_tag_at >= '$date' OR carryover_tag_enabled > 0)");
                $users = [];
                while (list($id, $name) = DB::fetch($result)) {
                    $users[] = "[id$id|$name]";
                }
                if (count($users) < 1) {
                    $sticker_id = 80969;
                } else {
                    $message = join(', ', $users) . ", партия зовёт!";
                    $disable_mentions = false;
                }
            }
        } elseif (preg_match('/^тагни выдавател(ей|я)$/iu', $command . " " . $text)) {
            $isRandom = (($command . " " . $text) == "тагни выдавателя");
            $result = DB::q("SELECT users.id AS 'id', name FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE has_permit ORDER BY RAND()");
            $users = [];
            $check = [];
            $hasMe = false;
            while (list($id, $name) = DB::fetch($result)) {
                if ($id != $me) {
                    $users[$id] = "[id$id|$name]";
                    $check[] = $id;
                } else {
                    $hasMe = true;
                }
            }
            if (count($users) < 1) {
                $sticker_id = 80969;
            } else {
                $check = getUserInfo($check);
                foreach ($check as $user) {
                    if ($user["online"] < 1) {
                        unset($users[$user["id"]]);
                    }
                }
                if (count($users) < 1) {
                    $sticker_id = $hasMe ? 86536 : [72826, 80972, 81769, 51128][rand(0, 3)];
                } else {
                    if ($isRandom) {
                        $single = array_rand($users);
                        $users = [$users[$single]];
                    }
                    $message = join(', ', $users) . ", время потрогать траву!";
                    $disable_mentions = false;
                }
            }
        } elseif ($command == "шаблон" || $command == "шаблоны") {
            $message = Peck::getTemplates($text);
        } elseif ($command == "дозорные" || $command . " " . $text == "кто дозорит") {
            $message = Peck::getCurrentWatchers();
        } elseif ($command == "активность") {
            $command = getCommand($text);
            $who = $me;
            $hour = intval(date('H'));
            $minute = intval(date('i'));
            if (in_array($hour, [11, 15, 16]) && $minute >= 40 && $minute < 55 || $isMom) {
                $sticker_id = 86521;
//                sendReaction($peer_id, $object["conversation_message_id"], 8);
                // вк говнина и не любит отправку реакций, либо я говнина и не умею делать апи запросы. короче удачи
            }
            if ($sticker_id == 0 && $command != "" && $command != "за") {
                $i = intval(((preg_match('/^\[id(\d+)\|/ui', $command, $matches)) ? $matches[1] : 0));
                if ($i > 0) {
                    $who = $i;
                    $command = getCommand($text);
                } elseif (preg_match('/^\d+$/ui', $command)) {
                    $who = $command;
                } else {
                    $name = $command;
                    $command = getCommand($text);
                    if ($command != "" && $command != "за") {
                        $name .= " $command";
                    }
                    $name = formatCatName($name);
                    $name = DB::escape($name);
                    $i = DB::getVal("SELECT users.id FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE name='$name'", 0);
                    if ($i > 0) {
                        $who = $i;
                    } else {
                        $sticker_id = 83436;
                    }
                    if ($command != "за") {
                        $command = getCommand($text);
                    }
                }
            }
            if ($sticker_id == 0 && $message == "") {
                if ($me != $who && !checkAccess($me, "Доверенный") &&
                    (!checkAccess($me, "Куратор") || checkAccess($who, "Младший"))
                ) {
                    $sticker_id = 83411;
                } else {
                    $cat_id = DB::getVal("SELECT cat_id FROM users WHERE id=$who", 0);
                    if ($cat_id <= 0) {
                        $sticker_id = 83455;
                    } else {
                        $period_type = "weekly";
                        $time_from = new DateTime('last Saturday');
                        if ($time_from->format('w') == date('w')) {
                            $time_from->modify('+7 day');
                        }
                        $time_to = clone $time_from;
                        $time_to->modify('+7 day');
                        $time_to->modify('-1 second');
                        if ($command == "за") {
                            $command = getCommand($text, true);
                            if (preg_match('/(\d+\.\d+(\.\d*)?)?-(\d+\.\d+(\.\d*)?)?/iu', $command)) {
                                $period_type = "custom";
                                getTimePeriodFromString($command, $time_from, $time_to);
                            } elseif (preg_match('/\d+\.\d+(\.\d+)?/iu', $command)) {
                                $period_type = "singleday";
                                getTimePeriodFromString($command, $time_from, $time_to);
                            } else {
                                $check = $command . " " . $text;
                                if (preg_match('/^вс[её] время$/iu', $check)) {
                                    $period_type = "total";
                                    $time_from = DateTime::createFromFormat('d.m.Y H:i:s', "01.01.1970 00:00:00");
                                    $time_to = new DateTime();
                                }
                            }
                        }
                        $message = Peck::getActivity($cat_id, $time_from, $time_to, $period_type);
                    }
                }
            }
        } elseif ($command == "активисты") {
            if (!checkAccess($me, "Доверенный")) {
                $sticker_id = 83411;
            } else {
                $time_from = new DateTime('last Saturday');
                $time_from->modify('-7 day');
                if ($time_from->format('w') == date('w')) {
                    $time_from->modify('+7 day');
                }
                $time_to = clone $time_from;
                $time_to->modify('+7 day');
                $time_to->modify('-1 second');

                $command = getCommand($text);
                if ($command == "за") {
                    $command = getCommand($text, true);
                    getTimePeriodFromString($command, $time_from, $time_to);
                }

                $message = Peck::getActivityStat($time_from, $time_to);
            }
        } elseif ($command == "инфо") {
            $command = getCommand($text);
            $info = null;
            if ($command == "вк") {
                $i = intval(((preg_match('/^\[id(\d+)\|/ui', $text, $matches)) ? $matches[1] : $text));
                $who = (($i > 0) ? $i : $who);
                $info = DB::getRow("SELECT users.id AS 'user_id', cat_id, name, norm, access_level, has_medal, has_permit FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE users.id=$who");
                if (is_null($info)) {
                    $sticker_id = 79400;
                } else {
                    $user = getUserInfo($who);
                    $message = "[id{$who}|$user[first_name] $user[last_name]]"
                                . "\nИмя: $info[name]";
                }
            } else {
                $text = trim($command . " " . $text);
                $is_cat = true;
                if (empty($text)) {
                    $cond = "users.id=$who";
                    $is_cat = false;
                } elseif (is_numeric($text)) {
                    $cond = "cat_id=" . intval($text);
                } else {
                    $cat_id = DB::getVal("SELECT id FROM cats WHERE LOWER(name)='" . DB::escape(mb_strtolower($text)) . "'", -1);
                    $cond = "cat_id=$cat_id";
                }
                $info_all = DB::q("SELECT users.id AS 'user_id', bonk_count, cat_id, name, norm, access_level, has_medal, has_permit, maindoz_tag_enabled, gbdoz_tag_enabled, herbdoz_tag_enabled, carryover_tag_enabled FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE $cond");
                if (DB::numRows($info_all) < 1) {
                    $sticker_id = 79400;
                    $info = null;
                } else {
                    $vk_ids_array = [];
                    if ($is_cat) {
                        $info = DB::fetch($info_all);
                        $vk_ids_array[] = $info["user_id"];
                        $message = "$info[name] [$info[cat_id]]";
                        while ($row = DB::fetch($info_all)) {
                            $vk_ids_array[] = $row["user_id"];
                        }
                        $message .= "\nВК: " . mapUsers($vk_ids_array);
                    } else {
                        $info = DB::fetch($info_all);
                        $user = getUserInfo($info["user_id"]);
                        $message = "[id$user[id]|$user[first_name] $user[last_name]]"
                            . "\nИмя: $info[name] ($info[cat_id])";
                    }
                }
            }
            if (!is_null($info)) {
                $level = $info['access_level'];
                $level_str = array_flip(ACCESS_LEVELS_TYPES)[$level] ?? "???";
                $norm_type = ["ИС", "1", "2"][$info['norm']] ?? "не выбрана";
                $bonking_flavor = ["Ударили по голове", "Уронили на пол", "Избили за гаражами", "Забуллили"];
                $bonking_flavor = $bonking_flavor[rand(0, count($bonking_flavor) - 1)];
                $message .= "\nДоступ: $info[access_level] ($level_str)"
                    . "\nВариант нормы: $norm_type"
                    . "\nМедаль: " . ($info['has_medal'] ? "есть" : "нет")
                    . "\nРазрешение: " . ($info['has_permit'] ? "есть" : "нет")
                    . "\nТагать в конце дозора в ПЦ: " . ($info['maindoz_tag_enabled'] ? "да" : "нет")
                    . "\nТагать в конце дозора на ГБ: " . ($info['gbdoz_tag_enabled'] ? "да" : "нет")
                    . "\nТагать перед дозорами на травах: " . ($info['herbdoz_tag_enabled'] ? "да" : "нет")
                    . "\nТагать всегда на перенос: " . ($info['carryover_tag_enabled'] ? "да" : "нет")
                    . ($info["bonk_count"] > 0 ? ("\n$bonking_flavor: " . declination($info["bonk_count"], ['раз', 'раза', 'раз'])) : "");
            }
        } elseif ($command == "список" && in_array(trim($text), ["отряда", "нормы"])) {
            $text = trim($text);
            $order_by = ($text == "отряда" ? "access_level" : "norm");
            $result = DB::q("SELECT users.id AS 'id', cat_id, name, access_level, norm FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE norm >= 0 ORDER BY $order_by DESC, name");
            $list = [];
            $order_by_str = ($text == "отряда" ? "уровень доступа" : "вариант нормы");
            $message = "Список $text (ВК — имя — $order_by_str):\n";
            while ($row = DB::fetch($result)) {
                $list[$row["id"]] = [
                    "name" => $row["name"],
                    "access_level" => $row["access_level"],
                    "norm" => $row["norm"],
                ];
            }
            $ids = array_keys($list);
            $users = getUserInfo($ids);
            foreach ($users as $user) {
                $list[$user["id"]]["first_name"] = $user["first_name"];
            }
            foreach ($list as $id => $item) {
                $s = $item[$order_by];
                if ($text == "нормы") {
                    if ($s == 0) {
                        $s = "не выбрано";
                    } else {
                        $s .= " вариант";
                    }
                }
                $message .= "[id{$id}|$item[first_name]] — $item[name] — $s\n";
            }
            $count = DB::getVal("SELECT COUNT(id) FROM cats");
            $message .= "Всего персонажей: $count";
        } elseif ($command == "удали") {
            if (!checkAccess($me, "Глава") && !$isMom) {
                $sticker_id = 83411;
            } else {
                $command = getCommand($text);
                $info = null;
                $is_vk = false;
                if (preg_match('/^\[id(\d+)\|/ui', $command)) { // для команд "бот удали @тег" вместо "бот удали вк @тег"
                    $is_vk = true;
                }
                if ($command == "вк") {
                    $command = getCommand($text);
                    $is_vk = true;
                }
                if ($is_vk) {
                    $i = intval(((preg_match('/^\[id(\d+)\|/ui', $command, $matches)) ? $matches[1] : $command));
                    $who = (($i > 0) ? $i : $who);
                    if ($who == $me && !$isMom) {
                        $message = "суицид запрещён";
                    } else {
                        $user = getUserInfo($who);
                        $cat_id = DB::getVal("SELECT cat_id FROM users WHERE id=$who", -1);
                        DB::q("DELETE FROM users WHERE id=$who");
                        if (DB::affectedRows() < 1) {
                            $sticker_id = 79400;
                        } else {
                            $en = $user["sex"] == 1 ? "ена" : "ён";
                            $exists = DB::getValArray("SELECT id FROM users WHERE cat_id=$cat_id");
                            if (count($exists) < 1) {
                                DB::q("DELETE FROM cats WHERE id=$cat_id");
                            }
                            $message = "[id{$who}|$user[first_name] $user[last_name]] удал$en";
                            if (count($exists) > 0) {
                                $his = $user["sex"] == 1 ? "её" : "его";
                                $message .= "\nК $his варовскому ID=$cat_id привязан" . (count($exists) > 1 ? "ы" : "")
                                . " " . mapUsers($exists);
                            }
                        }
                    }
                } else {
                    $text = trim($command . " " . $text);
                    if (is_numeric($text)) {
                        $cat_id = intval($text);
                    } else {
                        $text = DB::escape(mb_strtolower($text));
                        $cat_id = DB::getVal("SELECT id FROM cats WHERE LOWER(name)='$text'", -1);
                    }
                    $cat_name = DB::getVal("SELECT name FROM cats WHERE id=$cat_id", "???");
                    $roscomnadzor = DB::getVal("SELECT COUNT(id) FROM users WHERE cat_id=$cat_id AND id=$me");
                    if ($roscomnadzor && !$isMom) {
                        $message = "суицид запрещён";
                    } else {
                        $vk_ids = DB::getValArray("SELECT id FROM users WHERE cat_id=$cat_id");
                        DB::q("DELETE FROM cats WHERE id=$cat_id");
                        if (count($vk_ids) > 0) {
                            DB::q("DELETE FROM users WHERE cat_id=$cat_id");
                            $message = "Персонаж $cat_name ($cat_id) удалён (ВК "
                                . mapUsers($vk_ids) . ")";
                        } elseif (DB::affectedRows() > 0) {
                            $message = "Персонаж $cat_name ($cat_id) удалён (не было привязано ни одного ВК)";
                        } else {
                            $sticker_id = 79400;
                        }
                    }
                }
            }
        } elseif ($command == "добавь") {
            if (!checkAccess($me, "Глава") && !$isMom) {
                $sticker_id = 83411;
            } else {
                $command = getCommand($text);
                $i = intval(((preg_match('/^\[id(\d+)\|/ui', $command, $matches)) ? $matches[1] : $command));
                $who = (($i > 0) ? $i : $who);
                $user = getUserInfo($who);
                $exists = DB::getRow("SELECT name, cat_id FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE users.id=$who");
                if (!is_null($exists)) {
                    $message = "[id{$who}|$user[first_name] $user[last_name]] уже существует ($exists[name], ID=$exists[cat_id])";
                } else {
                    preg_match('/([А-ЯЁа-яё][а-яё]+( [А-ЯЁа-яё][а-яё]+)?)\s+(\d+)/u', $text, $data);
                    $name = formatCatName($data[1]);
                    $cat_id = intval($data[3]);
                    $user = getUserInfo($who);
                    $a = $user["sex"] == 1 ? "а" : "";
                    if (!$name || $cat_id < 1) {
                        $message = "'$text' как-то не похоже на имя и айди...";
                    } else {
                        $old_data = DB::getRow("SELECT cats.id AS 'cat_id', cats.name AS 'name', users.id AS 'id' 
                                        FROM cats LEFT JOIN users ON cats.id=users.cat_id 
                                        WHERE cats.name='$name' OR cats.id=$cat_id");
                        if (!is_null($old_data)) {
                            $old_user = "";
                            if (!is_null($old_data["id"])) {
                                $old_user = getUserInfo($old_data["id"]);
                                $old_user = " (это [id$old_user[id]|$old_user[first_name] $old_user[last_name]])";
                            }
                            if ($old_data["cat_id"] != $cat_id) {
                                $message = "Игрок $name уже существует{$old_user}, но с другим варовским ID ($old_data[cat_id]). Переименовывайте, а потом уже добавляйте новый аккаунт";
                            } elseif ($old_data["name"] != $name) {
                                $message = "Игрок с варовским ID=$cat_id уже существует{$old_user}, но с другим именем ($old_data[name]). Переименовывайте, а потом уже добавляйте новый аккаунт";
                            } else {
                                DB::q("INSERT INTO users SET 
                                            id=$who, 
                                            cat_id=$cat_id");
                                $message = "[id$user[id]|$user[first_name] $user[last_name]] добавлен$a! Имя: $name, ID: $cat_id"
                                    . "\nИгрок $name уже существует{$old_user}"
                                    . ", новый аккаунт будет использовать поля с его данными";
                            }
                        } else {
                            DB::q("INSERT INTO cats SET id=$cat_id, name='$name'");
                            DB::q("INSERT INTO users SET id=$who, cat_id=$cat_id");
                            $message = "[id$user[id]|$user[first_name] $user[last_name]] добавлен$a! Имя: $name, ID: $cat_id";
                        }
                    }
                }
            }
        } elseif ($command == "дай") {
            $command = getCommand($text);
            $self = false;
            $cat_info = [];
            $is_vk = false;
            if (preg_match('/^\[id(\d+)\|/ui', $command)) { // для команд "бот дай @тег" вместо "бот дай вк @тег"
                $is_vk = true;
            }
            if ($command == "вк") {
                $command = getCommand($text);
                $is_vk = true;
            }
            if ($is_vk) { // ВК айди
                $i = intval(((preg_match('/^\[id(\d+)\|/ui', $command, $matches)) ? $matches[1] : $command));
                $who = (($i > 0) ? $i : $who);
                $cat_id = DB::getVal("SELECT cat_id FROM users WHERE id=$who", -1);
                $self = $who == $me;
            } elseif ($command != "мне") { // Варовские айди или имя
                $text = trim($command . " " . $text);
                $types = join("|", ["имя", "айди", "норму", "доступ", "медаль", "-медаль", "разрешение", "-разрешение"]);
                preg_match("/([А-ЯЁа-яё][а-яё]+( [А-ЯЁа-яё][а-яё]+)?|\d+)\s+(($types).*)/u", $text, $data);
                $cat_id = $data[1] ?? "";
                $text = $data[3] ?? "";
                if (is_numeric($cat_id)) {
                    $cat_id = DB::getVal("SELECT id FROM cats WHERE id=" . intval($cat_id), -1);
                } else {
                    $cat_name = DB::escape(mb_strtolower($cat_id));
                    $cat_id = DB::getVal("SELECT id FROM cats WHERE LOWER(name)='$cat_name'", -1);
                }
                $who = 0;
            } else {
                $self = true;
                $cat_id = DB::getVal("SELECT cat_id FROM users WHERE id=$me", -1);
            }
            if ($cat_id <= 0) {
                $sticker_id = 79400;
            } else {
                $cat_info = DB::getRow("SELECT * FROM cats WHERE id=$cat_id");
            }
            $command = (($message == "" && $sticker_id == 0) ? getCommand($text) : "");
            if ($command == "имя") {
                if (!$isMom && !$self && !checkAccess($me, "Глава")) {
                    $sticker_id = 83411;
                } else {
                    $name = formatCatName($text);
                    if ($name == "") {
                        $message = "Какое?";
                    } elseif (!preg_match('/^[А-ЯЁ][а-яё]+( [А-ЯЁ][а-яё]+)?$/u', $name)) {
                        $message = "'$name' какое-то странное имя.....";
                    } else {
                        if ($cat_info["name"] == $name) {
                            $message = "Этого котика";
                            if ($self) {
                                $message = "Вас";
                            }
                            $message .= " уже зовут $name...";
                        } else {
                            DB::q("UPDATE cats SET name='$name' WHERE id=$cat_id");
                            $message = "$cat_info[name] ($cat_id) теперь зовут $name!";
                            if ($self) {
                                $message = "Теперь вас зовут $name!";
                            }
                        }
                    }
                }
            } elseif ($command == "норму") {
                if (!$isMom && !$self && !checkAccess($me, "Глава")) {
                    $sticker_id = 83411;
                } else {
                    $type = intval($text);
                    if ($cat_info["norm"] == $type) {
                        $message = "У этого котика";
                        if ($self) {
                            $message = "У вас";
                        }
                        $message .= " уже норма $type...";
                    } elseif ($type > 2 || ($type < 1 && !checkAccess($me, "Доверенный"))) {
                        $message = "Нормы типа '$text' не существует...";
                    } else {
                        DB::q("UPDATE cats SET norm=$type WHERE id=$cat_id");
                        if ($type < 1) {
                            if ($type == 0) {
                                $type = "ИС";
                            } else {
                                $type = "привилегированная";
                            }
                        }
                        if ($self) {
                            $message = "Теперь ваш";
                        } else {
                            $message = "Теперь у котика $cat_info[name] ($cat_id)";
                        }
                        $message .= " вариант нормы — $type!";
                    }
                }
            } elseif ($command == "доступ") {
                if (!$isMom && !checkAccess($me, "Доверенный")) {
                    $sticker_id = 83411;
                } else {
                    $level = intval($text);
                    $leader_level = ACCESS_LEVELS_TYPES["Глава"];

                    $his_level = DB::getVal("SELECT access_level FROM cats WHERE id=$cat_id", -1);
                    if ($level >= $leader_level || $self) {
                        if ($isMom || checkAccess($me, "Глава")) {
                            if ($isMom || $level <= $leader_level) {
                                if ($self) {
                                    $leaderCount = DB::getVal("SELECT COUNT(id) FROM cats WHERE access_level=$leader_level");
                                    if ($leaderCount > 1) {
                                        DB::q("UPDATE cats SET access_level=$level WHERE id=$cat_id");
                                        $level_str = array_flip(ACCESS_LEVELS_TYPES)[$level] ?? "???";
                                        $message = "Теперь у вас доступ $level ($level_str).";
                                    } else {
                                        $message = "Снимать с себя доступ главы запрещено, если больше никого с таким доступом нет.";
                                    }
                                } else {
                                    DB::q("UPDATE cats SET access_level=$level WHERE id=$cat_id");
                                    $level_str = array_flip(ACCESS_LEVELS_TYPES)[$level] ?? "???";
                                    $message = "Теперь у котика $cat_info[name] доступ $level ($level_str).";
                                }
                            } else {
                                $message = "Доступа выше главы для смертных не существует.";
                            }
                        } else {
                            $message = $self ? "Менять себе доступ" : "Менять доступ на главу отряда";
                            $message .= " может только глава отряда.";
                        }
                    } elseif (!$isMom && (!checkAccess($me, $level) || !checkAccess($me, $his_level))) {
                        $sticker_id = 83411;
                    } else {
                        if ($cat_info["access_level"] == $level) {
                            $message = "У этого котика";
                            if ($self) {
                                $message = "У вас";
                            }
                            $message .= " уже доступ уровня $level...";
                        } else {
                            DB::q("UPDATE cats SET access_level=$level WHERE id=$cat_id");
                            $level_str = array_flip(ACCESS_LEVELS_TYPES)[$level] ?? "???";
                            $message = "Теперь у котика $cat_info[name]";
                            if ($self) {
                                $message = "Теперь у вас";
                            }
                            $message .= " доступ $level ($level_str).";
                        }
                    }
                }
            } elseif ($command == "медаль" || $command == "-медаль") {
                if (!$isMom && !checkAccess($me, "Глава")) {
                    $sticker_id = 83411;
                } else {
                    $medal = 1;
                    if ($command == "-медаль") {
                        $medal = 0;
                    }
                    if ($cat_info["has_medal"] == $medal) {
                        $message = "У этого котика";
                        if ($self) {
                            $message = "У вас";
                        }
                        $message .= ($medal == 1) ? " уже есть медаль..." : " и так нет медали...";
                    } else {
                        DB::q("UPDATE cats SET has_medal=$medal WHERE id=$cat_id");
                        $message = "Котику $cat_info[name] ($cat_id) " . ($medal == 1 ? "выдана" : "снята") . " медаль.";
                    }
                }
            } elseif ($command == "разрешение" || $command == "-разрешение") {
                if (!$isMom && !checkAccess($me, "Глава")) {
                    $sticker_id = 83411;
                } else {
                    $permit = 1;
                    if ($command == "-разрешение") {
                        $permit = 0;
                    }
                    if ($cat_info["has_permit"] == $permit) {
                        $message = "У этого котика";
                        if ($self) {
                            $message = "У вас";
                        }
                        $message .= ($permit == 1) ? " уже есть разрешение..." : " и так нет разрешения...";
                    } else {
                        DB::q("UPDATE cats SET has_permit=$permit WHERE id=$cat_id");
                        $message = "Котику $cat_info[name] ($cat_id) " . ($permit == 1 ? "выдано" : "снято") . " разрешение.";
                    }
                }
            } elseif ($command == "айди") {
                if (!$isMom && !checkAccess($me, "Глава")) {
                    $sticker_id = 83411;
                } else {
                    $new_cat_id = intval($text);
                    if ($new_cat_id <= 0) {
                        $message = "'$text' какое-то странное айди...";
                    } elseif ($new_cat_id > 0) {
                        if ($cat_id == $new_cat_id) {
                            $message = "У этого котика";
                            if ($self) {
                                $message = "У вас";
                            }
                            $message .= " уже айди $new_cat_id...";
                        } else {
                            $cat_exists = DB::getVal("SELECT COUNT(*) FROM cats WHERE id=$new_cat_id");
                            if ($who > 0) { // Простая смена привязки персонажа к вк
                                if ($cat_exists < 1) {
                                    $message = "Персонаж с ID=$new_cat_id нигде не записан. Выдавать такой ID через привязку к ВК запрещено (используйте вместо этого команду '$bot_name дай (ID / Имя) айди ...')";
                                } else {
                                    DB::q("UPDATE users SET cat_id=$new_cat_id WHERE id=$who");
                                    if ($self) {
                                        $message = "У вас";
                                    } else {
                                        $user = getUserInfo($who, "gen");
                                        $message = "У [id$user[id]|$user[first_name] $user[last_name]]";
                                    }
                                    $message .= " теперь айди $new_cat_id!";
                                    $old_cat_exists = DB::getVal("SELECT COUNT(*) FROM users WHERE cat_id=$cat_id");
                                    if ($old_cat_exists < 1) {
                                        DB::q("DELETE FROM cats WHERE id=$cat_id");
                                        $message .= "\nБольше нет пользователей с варовским ID=$cat_id. Данные об этом персонаже были удалены";
                                    }
                                }
                            } else { // Массовая смена айди самому персонажу и всем привязанным к нему ВК
                                $user_ids = DB::getValArray("SELECT id FROM users WHERE cat_id=$cat_id");
                                DB::q("UPDATE users SET cat_id=$new_cat_id WHERE id IN (" . join(", ", $user_ids) . ")");
                                $message = "У " . mapUsers($user_ids, "abl") . " теперь варовский ID $new_cat_id!";
                                if ($cat_exists < 1) {
                                    DB::q("UPDATE cats SET id=$new_cat_id WHERE id=$cat_id");
                                    $message .= "\nПерсонажу $cat_info[name] также был изменён ID на $new_cat_id";
                                } else {
                                    DB::q("DELETE FROM cats WHERE id=$cat_id");
                                    $message .= "\nУже есть минимум один персонаж с ID=$new_cat_id. Данные о персонаже ID=$cat_id были удалены, пользователю(-ям) были выданы данные ID=$new_cat_id";
                                }
                            }
                            $message .= "\nНе забудьте поменять ID в таблице на листе 'Статистика'!";
                        }
                    }
                }
            }
        } elseif ($command == "боньк") {
            if (rand(0, 100)) {
                $sticker_id = [84225, 83455, 71883, 80930, 79405, 83338][rand(0, 5)];
            } else {
                $message = "Советую тебе не закрывать глаза, пока спишь.";
            }
            DB::q("UPDATE users SET bonk_count = bonk_count + 1 WHERE id = $me");
        } elseif ($command == "настройка") {
            if (!$isMom && !checkAccess($me, "Глава")) {
                $sticker_id = 83411;
            } else {
                $settings = [];
                $result = DB::q("SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_NAME='settings' AND TABLE_SCHEMA='u151626_bot' AND COLUMN_NAME!='id'");
                while (list($column, $comment) = DB::fetch($result)) {
                    $settings[$column] = $comment;
                }
                $command = getCommand($text);
                if (!$command) {
                    $message = "Настройка чего? Список доступных настроек (название - описание):";
                    foreach ($settings as $column => $comment) {
                        $message .= "\n$column - $comment";
                    }
                } elseif (isset($settings[$command])) {
                    $comment = $settings[$command];
                    $column = DB::escape($command);
                    $command = getCommand($text);
                    if ($command == "установить") {
                        $text = DB::escape(trim($text));
                        DB::q("UPDATE settings SET `$column` = '$text'");
                        $message = "Настройка [$comment] изменена!";
                    } elseif (!$command) {
                        $message = "Настройка [$comment]:\n";
                        $message .= DB::getVal("SELECT $column FROM settings", "[ ОШИБКА - ОТСУТСТВУЮТ ДАННЫЕ В ТАБЛИЦЕ settings ]");
                    } else {
                        $sticker_id = 13633;
                    }
                }
            }
        } elseif ($command == "объект" && $isMom) {
            $explode = explode(">", $text);
            $value = $object;
            $return = [];
            foreach ($explode as $key) {
                $value = $value[$key];
                $return[] = "['$key']";
            }
            $return = join("", $return);
            $message = "\$object$return = " . json_encode($value);
        }
    }

    if (is_int($message)) {
        $sticker_id = $message;
        $message = "";
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
                    'forward' => $forward,
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