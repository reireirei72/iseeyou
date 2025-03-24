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
    return ($member["access_level"] ?? -1) >= (ACCESS_LEVELS_TYPES[$level] ?? $level);
}

function getStickers($type) {
    // https://vk.com/sticker/1-АЙДИСТИКЕРА-256b
    $stickers = [
        "deny_access" => [83411],
        "confused" => [13633, 80909, 94649, 106235],
        "error" => [100364],
        "silence" => [86521, 97485],
        "cute" => [72790, 72798, 72801, 72805, 72813, 72830],
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
    $reply = Fly::getReply($object);
    if ($line == "отмена" && !empty($reply)) {
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
        } elseif ($command == "инфо") {
            $catName = formatCatName($text);
            $cat = Sheets::getMembersBy(["name" => $catName])[0] ?? [];
            if (!empty($cat)) {
                $message = "$catName [$cat[id]]\n";
                $user = getUserInfo($cat["vk_id"]);
                $message .= "ВК: [id$cat[vk_id]|$user[first_name] $user[last_name]]\n";
                $access_name = array_flip(ACCESS_LEVELS_TYPES)[$cat["access_level"]] ?? "???";
                $message .= "Доступ: $access_name ($cat[access_level])\n";
                $message .= "Вступил в отряд: $cat[invite_date]";
            } else {
                $message = "Я таких не знаю.";
            }
        } elseif ($command == "кто") {
            $text = trim($text);
            if ($text == "я") {
                $message = generateFunniDescription();
            } elseif ($text == "это" && !empty($reply)) {
                $cat = Fly::getCats($reply["from_id"])[$reply["from_id"]] ?? [];
                if (!empty($cat)) {
                    $message = "Это $cat[name]" . (!empty($cat["nickname"]) ? " ($cat[nickname])" : "") . ".";
                } else {
                    $message = "Какой-то ноунейм.";
                }
            }
        } elseif ($command == "дай") {
            $whom = getCommand($text);
            if ($whom == "мне") {
                $vkId = $me;
            } else {
                preg_match('/\[id(\d+)\|/iu', $whom, $data);
                $vkId = intval($data[1] ?? $whom);
            }
            if ($vkId < 1) {
                $sticker_id = getStickers("confused");
            } elseif ($vkId != $me && !checkAccess($me, "Глава")) {
                $sticker_id = getStickers("deny_access");
            } else {
                $cat = Fly::getCats($vkId)[$vkId] ?? [];
                if (empty($cat)) {
                    $message = "Я таких не знаю.";
                } else {
                    $self = $vkId == $me;
                    $type = getCommand($text);
                    $user = getUserInfo($cat["vk_id"]);
                    $cat["vk_name"] = "$user[first_name] $user[last_name]";
                    if ($type == "имя") {
                        $name = formatCatName($text);
                        if ($name == "") {
                            $sticker_id = getStickers("confused");
                        } elseif (!preg_match('/^[А-ЯЁ][а-яё]+( [А-ЯЁ][а-яё]+)?$/u', $name)) {
                            $message = "'$name'? Это что ещё за имя?";
                        } else {
                            if ($cat["name"] == $name) {
                                $message = (($self) ? "Тебя" : "Его") . " уже зовут '$name'???";
                            } else {
                                $result = Sheets::editMember($cat, ["name" => $name]);
                                $message = ($result > 0) ? "Выдано имя $name игроку $cat[id]." : "Упс ошибонька $result вышла...";
                            }
                        }
                    } elseif ($type == "кличку") {
                        $nickname = trim($text);
                        if ($nickname == "") {
                            $sticker_id = getStickers("confused");
                        } elseif (!preg_match('/^[А-Яа-яЁё]{1,25}$/ui', $nickname)) {
                            $message = "Кличка должна состоять только из кириллицы и не быть больше 25 символов.";
                        } else {
                            if ($cat["nickname"] == $nickname) {
                                $message = "У " . (($self) ? "тебя" : "него") . " уже кличка '$nickname'???";
                            } else {
                                $result = Sheets::editMember($cat, ["nickname" => $nickname]);
                                $message = ($result > 0) ? "Установлена кличка $nickname игроку $cat[id]." : "Упс ошибонька $result вышла...";
                            }
                        }
                    } elseif ($type == "доступ") {
                        if (!checkAccess($me, "Глава")) {
                            $sticker_id = getStickers("deny_access");
                        } else {
                            $level = mb_ucfirst(trim($text));
                            if ($level == "Иc") {
                                $level = "ИС";
                            }
                            $levelNum = ACCESS_LEVELS_TYPES[$level] ?? false;
                            if ($levelNum === false || $levelNum > 10) {
                                $sticker_id = getStickers("confused");
                            } else {
                                if ($cat["access_level"] == $levelNum) {
                                    $message = "У " . (($self) ? "тебя" : "его") . " и так этот доступ???";
                                } else {
                                    $result = Sheets::editMember($cat, ["access_level" => $levelNum]);
                                    $message = ($result > 0) ? "Теперь у игрока $cat[id] доступ $level." : "Упс ошибонька $result вышла...";
                                }
                            }
                        }
                    } elseif ($type == "предпочтение") {
                        $prefers = mb_strtolower(trim($text));
                        if ($prefers != "имя" && $prefers != "кличка") {
                            $sticker_id = getStickers("confused");
                        } else {
                            $prefersNickname = $prefers == "кличка";
                            if ($cat["prefers_nickname"] == $prefersNickname) {
                                $message = "Я и так " . (($self) ? "тебя" : "его") . " называю по " . ($prefersNickname ? "кличке" : "имени") . "???";
                            } else {
                                $result = Sheets::editMember($cat, ["prefers_nickname" => $prefersNickname]);
                                $message = ($result > 0) ? "Теперь называю игрока $cat[id] по " . ($prefersNickname ? "кличке" : "имени") . "." : "Упс ошибонька $result вышла...";
                            }
                        }
                    } else {
                        $sticker_id = getStickers("confused");
                    }
                }
            }
        } else {
            $wholeText = "$command $text";
            if (mb_strpos($wholeText, "умница") !== false || mb_strpos($wholeText, "умнич") !== false
                || mb_strpos($wholeText, "молодец") !== false || mb_strpos($wholeText, "хорош") !== false) {
                $sticker_id = getStickers("cute");
                $random_id = intval(time() / 10);
            }
        }
    }
    if ($sticker_id == 0 && $message == "" && !shouldBeSilent() && rand(1, 100) <= 2) {
        $message = generateRandomMessage();
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

function shouldBeSilent() {
    $hour = intval(date('H'));
    $minute = intval(date('i'));
    return $hour == 15 && $minute >= 25
        || $hour == 16 && ($minute <= 10 || $minute >= 30)
        || $hour == 17 && $minute <= 5;
}

function generateFunniDescription() {
    $firstOptions = ["уставший", "невыспавшийся", "единственный", "радостный", "запутавшийся", "познавший жизнь",
        "загулявший", "накосячивший", "задолбавшийся", "отдохнувший", "игривый", "пониженный", "зловредный",
        "киннящий Скарамуччу", "играющий в геншин", "кринжовый", "знаменитый", "пошлый", "душный", "умный",
        "подающий надежды", "не вызывающий доверие", "вызывающий вопросы", "набивший медаль", "смешной",
        "харизматичный", "бессовестный", "аморальный", "флиртующий", "ржачный", "заподстиленный", "гигантский",
        "смачный", "ломающий стереотипы", "доводящий до психолога", "заблокированный", "вселяющий страх",
        "офигевающий", "прифигевший", "самовлюблённый", "красивый", "грустный", "амбициозный", "целеустремлённый",
        "толстый", "ехидный", "чёрный", "голодный", "сочный", "сумасшедший", "безумный", "бешеный", "главный",
        "лучший", " привлекательный"];
    $randomFirstOption = array_sample($firstOptions);

    $secondOptions = ["исник", "глава", "зам",  "предводитель", "глашатай", "советник", "целитель",
        "ученик целителя", "дозорный", "активист", "ноунейм", "медалист", "шутник", "душнила",
        "фанат Картошки", "подсос", "попущ", "патрульный", "варовец", "шпион Теней", "активист недели",
        "неактив", "единственный куратор", "колдун","анимешник", "МТРшник", "раб системы",  "Дазай кинни",
        "гей", "милун", "хоббихорсер", "тиктокер", "чистильщик", "экзаменатор", "боец", "флудер",
        "майнкрафтер", "тугосерик", "веган", "модератор", "администратор", "мультовод", "нарушитель ОПИ",
        "эмпат", "псих", "личинус", "борец за справедливость", "туннелер", "приколист", "абобус", "гомосексуал",
        "гетеросексуал", "любитель повыпендриваться", "боец основной группы", "тихохвостик", "маргинал", "негр",
        "наблюдатель", "скалолаз", "чушпан", "квадробер", "творец", "покоритель", "дарующий", "котёнок",
        "оруженосец", "ребёнок", "геймер", "расист", "боец высшей лиги"];
    $roles = ["муж", "личинус", "ребёнок", "сын", "фанат", "подсос", "питомец", "троюродный дед", "брат",
        "любовник", "сталкер", "хейтер", "каблук", "заместитель", "подписчик", "кинн", "рипер", "поклонник",
        "симп", "поклонник"];
    $names = ["Жыжи", "Мрачи", "Фэла", "Мары", "Нула", "Роя", "Дрека", "Хоры", "Саза", "Меш", "Банки", "Дрю", "Лего",
        "Сап", "Нори", "Паши",
        "Картошки", "Тав", "Скуши", "Сияния", "Тиса", "Малика", "выдры", "АМС"];
    $randomNumber = rand(1, 100);
    if ($randomNumber < 70) {
        $randomSecondOption = array_sample($secondOptions);
    } else {
        $randomSecondOption = array_sample($roles) . " " . array_sample($names);
    }

    $randomThirdOption = "";
    if (rand(0, 1)) {
        $thirdOptions = ["который упал с уступов", "который не выполнил норму", "который смешно шутит",
            "который словил угря", "который выловил жабу", "который всех достал (//безнега).",
            "который отвоевал Дубраву", "у которого обновилась карта", "который пропустил рейд",
            "который хочет удалиться", "который списал тест", "у которого хепи хаус",
            "который втайне любит скибиди туалеты", "которого скоро понизят", "который съел камень на три места",
            "который не посмотрел магичку", "который залип на тиктоки и утонул", "который флудит во время сборов",
            "который задротит в кетвар", "которого забанили в гугле", "у которого все карты в опасках",
            "с отрицательным айкью", "который случайно наложил на себя паутину", "которого облизали",
            "которого рипнет Жыжа", "который дофлудился", "который дошутился", "который взял заморозку",
            "который скучает по прошлому и вообще раньше было лучше", "которого забанили за ОПИ1.4",
            "которому расширили территорию", "без трусов", "который не понимает где он", "у которого много вопросов",
            "который прихуел", "который умер в туалете", "который устал", "которому нужна помощь",
            "который не может добить девятку"];
        $randomThirdOption = " " . array_sample($thirdOptions);
    }
    return "Ты — " . $randomFirstOption . " " . $randomSecondOption . $randomThirdOption . ".";
}

function generateRandomMessage() {
    $phrases = ["АХАХАХАХАХАХХАХАХАХХА", "хех.....) речная смекалочка... не все поймут)", "хепи хаус",
        "у него хепи хаус", "глубоко", "НЕ МОЖЕТ БЫТЬ", "так не бывает", "ручное племя вперёд",
        "но.......как.............", "средний айкью участников этой конференции только что упал в два раза",
        "не стыдись того, кто ты есть, пусть это делают твои родители", "чё", "О_О", "хех", "Я НА АТОМЫ", "сверху гей",
        "снизу лесбиянка", "кто я такая?....", "это фотошоп или реальность", "это фотошоп, я программист",
        "картошке не показывайте", "SKIBIDI SIGMA RIZZ OHIO GYATT XDDDDDD", "а ну-ка повтори", "выдра была?", "выдра",
        "ВСЕМ В ПАЛАТКИ ВЫДРА В ЛАГЕРЕ", "ловко ты это придумал, я даже сначала не поняла",
        "Ты такого, пожалуйста, не говори больше.", "Извинись", "интересно он реально умер или хайпит на своей смерти",
        "испугались?", "страшно", "кринж", "рёики тенкай", "ДАТТЕБАЙО ХДДДДД", "я полюбила красивого сумасшедшего",
        "расширение территории", "я не могу разорвать его и выкинуть в окно", "Я такая тупая блин класс",
        "Простите, Я \"НЕ ЗНАЛ\" что я ПСИХ..", "Давайте общаться без мата пожалуйста.", "//безнега", ":(", ":)", ":0",
        "ХАЙП ХАЙП КРИНЖ ХАЙП ХАЙП КРИНЖ", "Норму все закрыли?", "Норму закрыли? Часики тикают....", "попу ставлю",
        "боже упаси", "что было то прошло", "ВСЕМ СМОТРЕТЬ МОБ ПСИХО 100!", "айкью стремительно падает",
        "мой айкью падает с каждым сообщением", "извините конечно, но это не ваше дело оО",
        "Как тебе такое Созвездие Земли?", "Слэээээй", "Нет???", "Я нейроотличная??? Извинись?",
        "С ним все хорошо, он умер.", "Жека цитата", "Ну сколько можно", "Ребят завязывайте",
        "Однажды каждому поступит звонок который невозможно отменить.", "Я сейчас скушу позову",
        "Я увольняюсь, все отчёты теперь кидаем главам. Пока.", "Тунец.", "Лосось?..", "Икра.", "Горчица...",
        "Тунец хДДДДД", "Чёрная икра.", "Тунец с майонезом.", "Всем смотреть магичку", "Обезьяны", "Жить жить",
        "жить жить пожить", "Мои 5 300 000 айкью оперативной памяти приходят лишь к одному выводу...", "кинню",
        "жаль этого добряка", "Не забывайте пить воду! :)", "деточка ты что там вд натираешь?... хах..) шучу, ты очень громко бегаешь",
        "72... хех... транзакция", "гребанные амс", "грёбанные амс", "ауф", "понабирают",
        "ВСЕМ РАБОТАТЬ", "не все геи речные, но все речные геи.",
        "Папа пришёл поздно сказал собирать вещи только самое главное уникалки паутину ребят грядёт..",
        "Пользуясь случаем хочу прорекламировать отяд туннелеров ;) У нас есть ежемесячные призы за активность, аж ДВА вида деятельности, а ещё Лука Лебедев в беседе, приходите ;)", ];
    return array_sample($phrases);
}