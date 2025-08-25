<?

require_once __DIR__ . '/func.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/config.php';

function formatNames($array) {
    $last = array_pop($array);
    if (count($array) < 1) {
        return $last;
    }
    return join(", ", $array) . " и " . $last;
}
/*
 * ?: is "x ? x : y"
 * ?? is "isset(x) ? x : y"
 * запомни псина
 * */
class Peck {
    private static $doz_min = 60; // Минут длится дозор в ПЦ
    private static $gb_doz_min = 15; // Минут длится дозор на ГБ
    private static function getMaxExtraMin() { // Максимум минут, на которые можно продлить дозор
        return intval(DB::getVal("SELECT max_extra_min FROM settings", 0));
    }

    private static function mapCatStats($data, $names, $isProbation) {
        $distinct = [ // Считать максимум 1 шт за 1 день
            "Сбор с ОТ" => [],
            "Сбор с МЗ" => [],
        ];
        $hasAny = false;
        $exclude = [];
        $result = [];
        $total_points = 0;
        $extra_points = 0;
        $herb_doz_count = 0;
        foreach ($data as $row) {
            $type = $row["type"];
            if (in_array($type, ["Аннулирование дозора (Дозор в ПЦ)", "Аннулирование дозора (Дозор на ГБ)", "Аннулирование дозора (Дозор на травах)"]) && $row["extra"]) {
                $exclude[] = $row["extra"];
                continue;
            }
            if (in_array($row["msg_id"], $exclude)) {
                continue;
            }
            $points = $row["points"];
            $total_points += $points;
            $key = $type;
            if (!isset($names[$key])) {
                $key = "";
            }
            if (!isset($result[$key])) {
                $result[$key] = [
                    "name" => $names[$key] ?? "",
                    "points" => 0,
                    "extra" => 0,
                ];
            }
            $result[$key]["points"] += $points;
            if (in_array($type, ["Чистка ботов КсД", "Перебор камней"])) {
                $result[$key]["extra"] += intval($row["extra"]);
            } elseif (in_array($type, array_keys($distinct))) {
                if (!in_array($row["date"], $distinct[$type])) {
                    $distinct[$type][] = $row["date"];
                    $result[$key]["extra"]++;
                }
            } else {
                $result[$key]["extra"]++;
            }
            if (!in_array($type, ["Дозор в ПЦ", "Дозор на ГБ", "дозор на гб старое", "Перенос ресурсов", "Перенос с мели"])) {
                $hasAny = true;
            }
            $extra_points += $points;
        }
        if (isset($result["дозор на гб старое"])) {
            if (!isset($result["Дозор на ГБ"])) {
                $result["Дозор на ГБ"] = [
                    "name" => $names["Дозор на ГБ"] ?? "",
                    "points" => 0,
                    "extra" => 0,
                ];
            }
            $result["Дозор на ГБ"]["points"] += $result["дозор на гб старое"]["points"] * 2;
            $result["Дозор на ГБ"]["extra"]  += $result["дозор на гб старое"]["extra"]  * 2;
            unset($result["дозор на гб старое"]);
        }
        $result["Переносы"]["points"] = ($result["Перенос ресурсов"]["points"] ?? 0) + ($result["Перенос с мели"]["points"] ?? 0);
        $result["Переносы"]["extra"]  = ($result["Перенос ресурсов"]["extra"] ?? 0)  + ($result["Перенос с мели"]["extra"] ?? 0);
        return [$result, $total_points, $extra_points, $hasAny];
    }

    public static function getActivity($who, $from, $to, $period_type) {
        $period = "текущий месяц";
        if ($period_type == "total") {
            $period = "всё время";
        } elseif ($period_type == "custom" || $period_type == "probation") {
            $period = "период [ " . $from->format('d.m.y') . "-" . $to->format('d.m.y') . " ]";
        } elseif ($period_type == "singleday") {
            $period = $from->format('d.m.y');
        }
        $data = Sheets::getActivity($who, $from, $to);
        $who = intval($who);
        list($access_level, $cat_name) = DB::getRow("SELECT access_level, name FROM cats WHERE id=$who", [0, 0, "???"]);
        $norm_type = $access_level == 0 ? "ИС" : "обычная";
        $names = [
            "Дозор в ПЦ" => "Дозоры в ПЦ",
            "Дозор на ГБ" => "Дозоры на ГБ",
            "Дозор на локации с травами" => "Дозоры на локациях с травами",
            "Переносы" => "Участие в переносах в целом",
            "Сбор с ОТ" => "Сборы с ОТ",
            "Сбор с МЗ" => "Сборы с МЗ",
            "Чистка ботов КсД" => "Чистка ботов КсД",
            "Квест на ОС" => "Квест на ОС",
            "Ловля мышей в свободное время" => "Ловля мышей в свободное время",
            "Участие в травнике" => "Участие в травниках",
            "Помощь травнику" => "Помощь травнику",
            "Поимка мышей" => "Поручения по ловле мышей",
            "Наполнение мха" => "Наполнение мха",
            "Перебор камней" => "Перебор камней",
            "Перенос ресурсов" => "Участие в переносе в ПсТ",
            "Перенос с мели" => "Участие в переносе с ГБ",
            "Обход" => "Обходы номерных",
            "Поручение главы/целителя" => "Поручения",
            "Участие в ЧП" => "Участие в ЧП",
            "Выдача трав" => "Выдача трав",
            "Выдача костоправа" => "Выдача костоправов",
            "Обновление архива памяток" => "Обновление архива памяток",
        ];
        list($result, $total_points, $extra_points, $hasAny) = Peck::mapCatStats($data, $names, $access_level == 0);
        $return = "Активность котика $cat_name за $period"
            . "\nВариант нормы: $norm_type\n"
            . "\nВсего баллов: $total_points\n";
        $reverse = array_flip($names);
        $normStats = [];
        $endOfMonth = clone $to;
        $endOfMonth->modify('last day of this month');
        if ($from->format('j') === "1" && $to->format('Y-m-d') === $endOfMonth->format('Y-m-d') && $from->format('Y-m') === $to->format('Y-m') || $period_type == "probation") {
            $normStats = Peck::getNormStats($norm_type == "ИС" ? 0 : 1);
        }
        $isProbation = DB::getVal("SELECT access_level FROM cats WHERE name='" . DB::escape($cat_name) . "'") < 1;
        foreach ($reverse as $name => $key) {
            $now = $result[$key] ?? [
                    "name" => $name,
                    "points" => 0,
                    "extra" => 0,
                ];
            if ($now["points"] || in_array($key, ["Дозор в ПЦ", "Дозор на ГБ", "Переносы"]) || $isProbation && $key == "Дозор на локации с травами") {
                if (in_array($key, ["Дозор в ПЦ", "Дозор на ГБ", "Переносы", "Дозор на локации с травами", "Сбор с ОТ",
                    "Сбор с МЗ", "Квест на ОС", "Участие в травнике", "Чистка ботов КсД", "Перебор камней"])) {
                    $extra_extra = [
                            "Чистка ботов КсД" => " мусора",
                            "Перебор камней" => " камней",
                        ][$key] ?? "";
                    $return .= "\n$name: $now[extra]$extra_extra";
                    if (isset($normStats[$key])) {
                        $return .= "/" . $normStats[$key];
                    }
                    $return .= " (" . declination($now["points"], ['балл', 'балла', 'баллов']) . ")";
                } else {
                    $return .= "\n$name: " . declination($now["points"], ['балл', 'балла', 'баллов']);
                }
                if ($key == "Переносы") {
                    $return .= "\n\nДополнительные баллы: $extra_points";
                    if (isset($normStats["extra"])) {
                        $return .= "/" . $normStats["extra"];
                    }
                    $return .= " " . declination($extra_points, ['балл', 'балла', 'баллов'], true);
                    if ($hasAny) {
                        $return .= "\n————————";
                    }
                }
            }
        }
        return $return;
    }
    private static function getNormStats($norm) {
        return [
            0 => [
                "Дозор в ПЦ" => 2,
                "Дозор на ГБ" => 2,
                "Дозор на локации с травами" => 2,
                "Переносы" => 2,
                "extra" => 10,
                ],
            1 => [
                "Дозор в ПЦ" => 4,
                "Дозор на ГБ" => 4,
                "Переносы" => 4,
                "extra" => 72,
            ]
        ][$norm];
    }
    public static function getActivityStat($from, $to, $check) {
        // todo: я ебал перепиши это на две разные функции
        $isMonth = $from->format('j') == 1 && $to->format('j') == $to->format('t') && $to->format('Y-m') === $from->format('Y-m');
        $isWeek = $from->format('D') == "Sat" && $to->format('D') == "Fri" && $from->diff($to, true)->days == 6;
        if (!$isMonth && !$isWeek) {
            return "это не неделя и не месяц тебе норм?";
        }
        $names = [
            "Дозор в ПЦ" => "Дозоры в ПЦ",
            "Дозор на ГБ" => "Дозоры на ГБ",
            "Дозор на локации с травами" => "Дозоры на локациях с травами",
            "Чистка ботов КсД" => "Чистка ботов КсД",
            "Сбор с ОТ" => "Сбор с ОТ",
            "Наполнение мха" => "Наполнение мха",
            "Перенос ресурсов" => "Перенос ресурсов",
            "Перенос с мели" => "Перенос с мели",
            "Выдача трав" => "Выдача трав",
            "Выдача костоправа" => "Выдача костоправа",
        ];
        $return = "Стат активности за период " . $from->format('d.m') . "-" . $to->format('d.m') . "\n";
        $return_array = [];
        $result = DB::q("SELECT cats.id as 'id', users.id as 'vk_id', name, access_level, has_norm, has_medal FROM cats LEFT JOIN users ON cats.id=users.cat_id WHERE cats.access_level >= 1");
        $data_cats = [];
        while ($row = DB::fetch($result)) {
            if ($isMonth && !$row['has_norm']) continue;
            $data_cats[$row["id"]] = [
                'id' => $row['id'],
                'vk_id' => $row['vk_id'],
                'name' => $row['name'],
                'access_level' => $row['access_level'],
                'stats' => [],
            ];
        }
        $data_activ = Sheets::getActivity(0, $from, $to);
        $formatted_cats = [];
        $topHundredPoints = [];
        $topBartender = ""; $topBartenderAmt = 0;
        $topDozHerb = ""; $topDozHerbAmt = 0;
        $topMossFill = ""; $topMossFillAmt = 0;
        $topDozHeal = [];
        $topDozRiver = [];
        $topMisty = [];
        $topGiver = [];
        $topLogistics = [];
        $norm_stats = Peck::getNormStats(1);
        foreach ($data_cats as $id => $data) {
            if (!isset($data_activ[$data['id']])) {
                $data_activ[$data['id']] = [];
            }
            list($stats, $total_points, $extra_points) = Peck::mapCatStats($data_activ[$data['id']], $names, $data['access_level'] == 0);
            if ($isMonth) {
                $debt = 0;
                $debt_data = [];
                $string = "$data[name]";
                foreach ($norm_stats as $stat => $req) {
                    if ($stat == "extra") continue;
                    $actual = 0;
                    if (isset($stats[$stat])) {
                        $actual = $stats[$stat]['extra'];
                    }
                    if ($actual < $req) {
                        $debt += ($req - $actual) * 2.5; // 2.5 баллов для дозоров в ПЦ и на ГБ (todo: придумать, как их вписать отдельно, если разбалловка изменится)
                        $debt_data[] = mb_lcfirst($stat) . ": " . $actual . "/" . $req;
                    }
                }
                if ($extra_points < $norm_stats["extra"]) {
                    $debt += $norm_stats["extra"] - $extra_points;
                    $debt_data[] = "доп. баллы: " . $extra_points . "/" . $norm_stats["extra"];
                }
                if ($debt == 0) {
                    $string .= " - выполнена";
                } else {
                    $string .= " - не выполнена";
                }
                if (count($data_activ[$data['id']]) < 1) {
                    $debt_data = ["полностью"];
                }
                $formatted_cats[$id] = [
                    "string" => $string,
                    "debt" => min(10, $debt),
                    "debt_data" => join(", ", $debt_data),
                ];
            }

            if ($isWeek) {
                $catNameWithLink = "id$data[vk_id] ($data[name])";
                foreach ($stats as $stat => $req) {
                    $actual = $req['extra'];
                    if ($stat == "Чистка ботов КсД") {
                        if ($topBartenderAmt < $actual) {
                            $topBartenderAmt = $actual;
                            $topBartender = $catNameWithLink;
                        }
                        continue;
                    } elseif ($stat == "Дозор в ПЦ") {
                        if ($actual >= 5) {
                            $topDozHeal[] = $catNameWithLink;
                        }
                    } elseif ($stat == "Дозор на локации с травами") {
                        if ($topDozHerbAmt < $actual) {
                            $topDozHerbAmt = $actual;
                            $topDozHerb = $catNameWithLink;
                        }
                    } elseif ($stat == "Дозор на ГБ") {
                        if ($actual >= 5) {
                            $topDozRiver[] = $catNameWithLink;
                        }
                    } elseif ($stat == "Сбор с ОТ") {
                        if ($actual >= 5) {
                            $topMisty[] = $catNameWithLink;
                        }
                    } elseif ($stat == "Выдача трав" || $stat == "Выдача костоправа") {
                        if ($actual >= 5) {
                            $topGiver[] = $catNameWithLink;
                        }
                    } elseif ($stat == "Перенос ресурсов") {
                        $points = $req["points"] + ($stats["Перенос с мели"]["points"] ?? 0);
                        if ($points >= 15) {
                            $topLogistics[] = $catNameWithLink;
                        }
                    } elseif ($stat == "Наполнение мха") {
                        if ($topMossFillAmt < $req["points"]) {
                            $topMossFillAmt = $req["points"];
                            $topMossFill = $catNameWithLink;
                        }
                    }
                }
                if ($total_points >= 100) {
                    $topHundredPoints[] = $catNameWithLink;
                }
            }
        }

        if ($isMonth) {
            usort($formatted_cats, function($a, $b) { return $b["debt"] - $a["debt"];});
            foreach ($formatted_cats as $id => $data) {
                $return .= "\n" . $data["string"];
                if ($data["debt"] > 0) {
                    $return .= " ($data[debt_data]) - +$data[debt] баллов";
                }
            }
            // Formatting
            $return_split = explode("\n", $return);
            $return = "";
            foreach ($return_split as $line) {
                if (mb_strlen($return) > 2048 - 250) {
                    $return_array[] = $return;
                    $return = "";
                } else {
                    $return .= "\n" . $line;
                }
            }
            $return_array[] = $return;
        }
        if ($isWeek) {
            $return_array[] = "💂‍♀ Дозорный недели:"
                . "\nㅤㅤГБ: " . (join(", ", $topDozRiver) ?: "-")
                . "\nㅤㅤПЦ: " . (join(", ", $topDozHeal) ?: "-")
                . "\n💯 Стобалльник недели: " . (join(", ", $topHundredPoints) ?: "-")
                . "\n🍸 Бартендер недели: " . ($topBartender ?: "-")
                . "\n🌫︎ Туманный страж недели: " . (join(", ", $topMisty) ?: "-")
                . "\n🌿 Хранитель трав недели: " . ($topDozHerb ?: "-")
                . "\n🌾 Лучший друг Моховика: " . ($topMossFill ?: "-")
                . "\n💉 Выдаватель недели: " . (join(", ", $topGiver) ?: "-")
                . "\n📦 Логист недели: " . (join(", ", $topLogistics) ?: "-");
        }
        return $return_array;
    }
    public static function getReply($object) {
        $reply = $object['reply_message'] ?? [];
        if (empty($reply)) {
            $reply = $object['fwd_messages'][0] ?? [];
        }
        return $reply;
    }
    public static function getCurrentWatchers() {
        $w = Sheets::getCurrentWatchers(Peck::getMaxExtraMin());
        $cat_ids = [];
        $watchers = [];
        $now = new DateTime();
        foreach ($w as $watcher) {
            $type = ($watcher["type"] == "Дозор в ПЦ" ? "ПЦ" : "ГБ");
            $end = $watcher["date"];
            $end->modify("+"
                . ($type == "ПЦ" ? Peck::$doz_min : Peck::$gb_doz_min)
                . " minutes");
            $end->modify("+$watcher[extra_time] minutes");
            if ($end > $now) {
                $watchers[$watcher["who"]] = [
                    "type" => $type,
                ];
                $cat_ids[] = $watcher["who"];
            }
        }
        if (count($cat_ids) == 0) {
            return ["Никто не дозорит пичалька", "Никто, все тянут до вечера пятницы", 72826, 80972, 81769, 51128][rand(0, 5)];
        }

        $cat_ids = join(", ", $cat_ids);
        $result = DB::q("SELECT id, name, access_level FROM cats WHERE id IN ($cat_ids)");
        while ($row = DB::fetch($result)) {
            $id = $row["id"];
            $watchers[$id]["sort"] = 1;
            if ($row["access_level"] < 1) {
                $watchers[$id]["sort"] = 0;
                $row["name"] .= " (ИС)";
            }
            $watchers[$id]["name"] = $row["name"];
        }
        $sort = array_column($watchers, 'sort');
        array_multisort($sort, SORT_DESC, $watchers);

        $return_watchers = [
            "ГБ" => [],
            "ПЦ" => [],
        ];
        foreach ($watchers as $cat) {
            $return_watchers[$cat["type"]][] = $cat["name"];
        }

        return "Дозорящие на данный момент\n" .
            (count($return_watchers["ГБ"]) > 0 ? "\nГБ: " . formatNames($return_watchers["ГБ"]) : "") .
            (count($return_watchers["ПЦ"]) > 0 ? "\nПЦ: " . formatNames($return_watchers["ПЦ"]) : "");
    }
    public static function addCurrentWatch($object) {
        $user_id = $object['from_id'];
        $reply = Peck::getReply($object);
        $report_user_id = $reply['from_id'];
        $unique = $object['peer_id'] . "_" . $reply['conversation_message_id'];
        $num = intval(mb_ereg_replace('[^\d]+', '', $object["text"]));
        if ($num > 0 && $user_id == $report_user_id) {
            $check = Sheets::check($unique);
            $cat = getCats($user_id)[$user_id] ?? [];
            $info = $check["data"];
            if (count($info) < 1) { // Активности в таблице от такого сообщения нет = это не дозор
                return "";
            }
            $type = $info[2];
            if (!in_array($type, ["Дозор в ПЦ", "Дозор на ГБ"])) {
                return "";
            }
            if (count($check["null_data"]) > 0) { // Активность есть, но это дозор, который был аннулирован
                return "Выбранный дозор был аннулирован";
            }
            $extra = intval($info[4]);
            $total = $num + $extra;
            if ($total > Peck::getMaxExtraMin()) {
                return "Время, отведённое на отлучку, истекло. Дозор необходимо отменить";
            }
            $endtime = DateTime::createFromFormat('d.m.Y H:i:s', $info[1] . ':59');
            $endtime->modify("+"
                . ($type == "Дозор в ПЦ" ? Peck::$doz_min : Peck::$gb_doz_min)
                . " minutes");
            $endtime->modify('+' . $total . ' minutes');
            $now = new DateTime();
            if ($endtime < $now) {
                return "";
            }

            $supposedstart = DateTime::createFromFormat('d.m.Y H:i:s', $info[1] . ':00');
            $supposedstart->modify('+' . $total . ' minutes');
            if ($supposedstart > $now) {
                return "$num минут назад этот дозор ещё не начался, $cat[name]";
            }

            $data = [
                $total . "",
            ];
            Sheets::modifyRow($check["act_cell"], $data, 4);
            // TODO: Раз уж записываем все дозоры в БД, то и проверять их наличие можно через БД...
            DB::q("UPDATE doz_active SET ends_at=ends_at + INTERVAL $num MINUTE WHERE peer_id=$object[peer_id] AND msg_id=$reply[conversation_message_id]");
            return "Отлучка засчитана, $cat[name].\n"
                . "Дозор закончится в " . $endtime->format('H:i');
        }
        return "";
    }
    public static function cancelAct($object) { // "отмена"
        $user_id = $object['from_id'];
        $reply = Peck::getReply($object);
        $report_user_id = $reply['from_id'];
        $unique = $object['peer_id'] . "_" . $reply['conversation_message_id'];
        if ($user_id == $report_user_id || checkAccess($user_id, "Доверенный")) {
            $response = Sheets::remove($unique);
            if ($response["status"] == "error") {
                switch ($response["data"]) {
                    case 1:
                        return "не найдено, unique = " . $unique;
                    case 2:
                        return 83444;
                }
            }
            $type = $response["data"][2] ?? "";
            DB::q("DELETE FROM doz_active WHERE peer_id=$object[peer_id] AND msg_id=$reply[conversation_message_id]");

            if ($type == "Чистка от грязи") {
                Sheets::remove($unique, 1);
            }
            return "[ " . $response["data"][2] . " ] отменено";
        }
        return "";
    }
    public static function getTemplates($type) {
        if ($type == "") {
            return "Возможные виды деятельности:\n"
                . "дозор\n"
                . "аннулирование дозора\n"
                . "дозор на травах\n"
                . "помощь травнику\n"
                . "участие в травнике\n"
                . "чистка ботов\n"
                . "сбор с ОТ / сбор с острова туманов\n"
                . "сбор с МЗ / сбор с мшистых земель\n"
                . "перенос\n"
                . "перенос с ГБ\n"
                . "наполнение мха\n"
                . "поручение на мышей\n"
                . "обход\n"
                . "мыши\n"
                . "выдача трав\n"
                . "выдача костоправов\n"
                . "чистка от грязи\n"
                . "квест\n"
                . "перебор камней"
                ;
        }
        if (preg_match('/^дозоры?$/iu', $type)) {
            return "> Дозор (в ПЦ / на ГБ)\n"
                . "> чч:мм\n"
                . "Отправлять отчёт о дозоре можно не позднее 5 минут после его начала\n"
                . "При необходимости приостановить дозор, можно сделать это на время до 20 минут, написав "
                . "\"отошел(-ла)\". После возвращения нужно ответом на свое сообщение с началом дозора написать"
                . " \"вернулся(-ась), +кол-во минут, на которое вы отходили\"\n";
        } elseif (preg_match('/^аннулирование( дозора)?$/iu', $type)) {
            return "> Дозор аннулирован\n"
                . "Аннулировать дозор можно только единожды."
                . " Доступно только проверяющим и выше";
        } elseif (preg_match('/^дозоры? на травах/iu', $type)) {
            return "> Забрали(, дальняя)\n"
                . "Необходимо отправлять ответом на сообщение со скриншотом локации."
                . " Скришот должен быть отправлен не позднее :05:59."
                . " Отчитываться о том, что травы забрали, нужно не позднее :59:59\n"
                . "Необходимо указывать (через запятую или на новой строке), что локация дальняя,"
                . " если дозор был на Нагретых камнях, Травянистом берегу или Илистой тропе";
        } elseif (preg_match('/^помощь травнику/iu', $type)) {
            return "> Помог(ла) травнику";
        } elseif (preg_match('/^участие в травнике/iu', $type)) {
            return "> Принял(а) участие в травнике";
        } elseif (preg_match('/^чистка ботов/iu', $type)) {
            return "> Боты чисты (число мусора)\n"
                . "К сообщению необходимо прикреплять скриншоты истории и количества мусора,"
                . " а в самом сообщении указать количество мусора (скобки не обязательны)";
        } elseif (preg_match('/^сбор с (от|острова туманов)/iu', $type)) {
            return "> Мох с ОТ в ПЦ (заход)\n"
                . "Номер захода в скобки закрывать не обязательно";
        } elseif (preg_match('/^сбор с (мз|мшистых земель)/iu', $type)) {
            return "> Мох с МЗ в ПЦ (заход)\n"
                . "Номер захода в скобки закрывать не обязательно";
        } elseif (preg_match('/^перенос$/iu', $type)) {
            return "> Перенос засчитан\n"
                . "К сообщению должны быть прикреплены сообщения всех участников переноса."
                . " Доступно только доверенным и выше";
        } elseif (preg_match('/^перенос с гб$/iu', $type)) {
            return "> Перенос с ГБ засчитан\n"
                . "К сообщению должны быть прикреплены сообщения всех участников переноса."
                . " Доступно только доверенным и выше";
        } elseif (preg_match('/^наполнение мха/iu', $type)) {
            return "> Наполнение мха (разбалловка)\n"
                . "К сообщению должны быть прикреплены сообщения всех участников,"
                . " а в самом сообщении должно быть указано количество мха на каждого из участников последовательно."
                . " Указывать количество мха можно через пробел или перенося на новую строку."
                . " Доступно только доверенным и выше";
        } elseif (preg_match('/^поручение на мышей/iu', $type)) {
            return "> Выполнил(а) поручение, (количество мышей)\n"
                . "Количество мышей в скобки закрывать не обязательно";
        } elseif (preg_match('/^обход/iu', $type)) {
            return "> Обошёл(ла) ПВ/ПО/Детскую/все спальные локации, чч:мм\n"
                . "Можно указывать две локации (например, 'ПВ и ПО' или 'ПО и Детскую')."
                . " К сообщению необходимо прикладывать скриншот истории и время начала обхода";
        } elseif (preg_match('/^мыши/iu', $type)) {
            return "> Поймал(а) мышей\n"
                . "К сообщению необходимо прикладывать скриншот, на котором видно пойманных мышей";
        } elseif (preg_match('/^(выдач[аи] (травы?|костоправов|трав ?\/ ?костоправов)|чистка от грязи)/iu', $type)) {
            return "> Выдал(а) траву/травы\n"
                . "> Имя подопечного (ID), поднял камень: Имя\n"
                . "ИЛИ\n"
                . "> Выдал(а) костоправ(ы)\n"
                . "> Имя подопечного (ID), количество костоправов\n"
                . "ИЛИ\n"
                . "> Почистил(а) от грязи\n"
                . "> Имя подопечного (ID), количество мха\n"
                . "Доступно только для тех, кто с разрешением. Перенос строки обязателен";
        } elseif (preg_match('/^квест/iu', $type)) {
            return "> Выполнил(а) квест на ОС\n"
                . "Недоступно тем, кто на ИС, и оруженосцам";
        } elseif (preg_match('/^перебор камней/iu', $type)) {
            return "> Перебрал(а) камни, (количество) единиц\n"
                . "К сообщению необходимо прикладывать скриншот";
        }
        return "Неизвестный шаблон '$type'";
    }
    public static function checkMessage($object) {
        $text = $object['text'];
        $hasReplies = (!empty($object['fwd_messages']) || !empty($object['reply_message']));
        if (preg_match('/^дозор (в пц|на гб)/iu', $text)) {
            return Peck::dozorReport($object) ?: "";
        } elseif (preg_match('/^дозор аннулирован/iu', $text) && $hasReplies) {
            return Peck::dozorNull($object) ?: "";
        } elseif (preg_match('/^забрали/iu', $text) && $hasReplies) {
            return Peck::dozorHerb($object) ?: "";
        } elseif (preg_match('/^(помог(ла)? травнику|приняла? участие в травнике)/iu', $text)) {
            return Peck::misc($object) ?: "";
        } elseif (preg_match('/^боты чисты,? \(?\d+\)?/iu', $text)) {
            return Peck::cleanBots($object) ?: "";
        } elseif (preg_match('/^мох (с (от|мз)) в пц ?\(?\d*\)?/iu', $text)) {
            return Peck::gatherMoss($object) ?: "";
        } elseif (preg_match('/^перенос засчитан/iu', $text) && $hasReplies) {
            return Peck::carryover($object) ?: "";
        } elseif (preg_match('/^перенос с (мели|гб) засчитан/iu', $text) && $hasReplies) {
            return Peck::carryover($object, 1) ?: "";
        } elseif (preg_match('/^выполнила? поручение/iu', $text)) {
            return Peck::taskMouse($object) ?: "";
        } elseif (preg_match('/^наполнение мха/iu', $text) && $hasReplies) {
            return Peck::mossFill($object) ?: "";
        } elseif (preg_match('/^обош(ел|ёл|ла) ((по|пв|детскую)( и (по|пв|детскую))?|все спальные локации)/iu', $text)) {
            return Peck::rounds($object) ?: "";
        } elseif (preg_match('/^(поручение|участие в чп)/iu', $text)) {
            return Peck::task($object) ?: "";
        } elseif (preg_match('/^поймала? мышей/iu', $text)) {
            return Peck::mice($object) ?: "";
        } elseif (preg_match('/^(выдала? (трав[ыу]|костоправы?)|почистила? от грязи)/iu', $text)) {
            return Peck::heal($object) ?: "";
        } elseif (preg_match('/^выполнила? квест на ос/iu', $text)) {
            return Peck::flowerQuest($object) ?: "";
        } elseif (preg_match('/^вернул(ся|ась),? *\+(\d+)/iu', $text) && $hasReplies) {
            return Peck::addCurrentWatch($object) ?: "";
        } elseif (preg_match('/^проверила? отч[её]ты/iu', $text)) {
            return Peck::taskCheckReports($object) ?: "";
        } elseif (preg_match('/^обновила? архив памяток/iu', $text)) {
            return Peck::taskUpdateArchive($object) ?: "";
        } elseif (preg_match('/^перебрала? камни/iu', $text)) {
            return Peck::stoneCheck($object) ?: "";
        } elseif (preg_match('/^[А-Яа-яЁё]+( [А-Яа-яЁё]+)? \[\d+],? с правилами поведения в пц ознакомлен/iu', $text)) {
            return Peck::giveMemo($object) ?: "";
        }
        return "";
    }
    private static function giveMemo($object) {
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";
        $text = explode("\n", $object["text"]);
        $data = [];
        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);
        foreach ($text as $line) {
            preg_match('/\[(\d+)],? с правилами поведения в пц ознакомлена?,(.*)/iu', $line, $matches);
            $whom = $matches[1] ?? "";
            $type = mb_strtolower(trim($matches[2] ?? ""));
            preg_match('/\((.*)\)/iu', $type, $matches);
            $type_extra = trim($matches[1] ?? "");
            $type_cleaned = trim(mb_ereg_replace('\(.*\)', '', $type));
            if (!in_array($type_cleaned, ["кашель", "ушибы", "травмы от утопления", "отравление", "боевые раны", "грязь", "ныряние"])) {
                $message = "Памятки по таким увечьям ($type_cleaned) не существует";
                $corrected = "";
                if (preg_match('/травл/iu', $type_cleaned)) {
                    $corrected = "отравление";
                } elseif (preg_match('/утоп/iu', $type_cleaned)) {
                    $corrected = "травмы от утопления";
                } elseif (preg_match('/раны/iu', $type_cleaned)) {
                    $corrected = "боевые раны";
                } elseif (preg_match('/ушиб/iu', $type_cleaned)) {
                    $corrected = "ушибы";
                } elseif (preg_match('/ныр/iu', $type_cleaned)) {
                    $corrected = "ныряние";
                }
                if ($corrected) {
                    $message .= ". Возможно, вы имели в виду \"$corrected\"?";
                }
                return $message;
            }
            if ($type_extra) {
                $types_extra = explode(",", $type_extra);
                $has = [];
                foreach ($types_extra as $word) {
                    $word = trim($word);
                    if (!in_array($word, ["травмы от утопления", "отравление", "раны"])) {
                        $message = "Таких увечий ($word) при нырянии не существует";
                        $corrected = "";
                        if (preg_match('/травл/iu', $word)) {
                            $corrected = "отравление";
                        } elseif (preg_match('/утоп/iu', $word)) {
                            $corrected = "травмы от утопления";
                        }
                        if ($corrected) {
                            $message .= ". Возможно, вы имели в виду \"$corrected\"?";
                        }
                        return $message;
                    }
                    if (in_array($word, $has)) {
                        return "У вас повторяется одно и то же увечье на ныряниях два раза подряд ($word)";
                    }
                    $has[] = $word;
                }
            }
            $data[] = [
                "cat" => $cat["id"],
                "type" => $type_cleaned,
                "type_extra" => $type_extra,
                "whom" => $whom,
                "date" => $report_date,
            ];
        }
        $response = Sheets::writeMemo($data);
        if ($response < 1) {
            return "Что-то не так зовите маму...";
        }
        return "Успешно выдано " . declination($response, ['памятка', 'памятки', 'памяток']) . ", " . $cat["name"] . "!";
    }
    private static function stoneCheck($object) {
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        if (count($object["attachments"]) < 1) {
            return "Ошибка: к сообщению должен быть прикреплён скриншот";
        }

        $count = intval(mb_ereg_replace('\D+', '', $object['text']));

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => 25,
            'cat' => $cat["id"],
            'date' => $report_date,
            'extra' => $count,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Перебор камней засчитан, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function taskCheckReports($object) {
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => 22,
            'cat' => $cat["id"],
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Проверка отчётов засчитана, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }

    private static function taskUpdateArchive($object) {
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => 23,
            'cat' => $cat["id"],
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Обновление архива памяток засчитано, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }

    private static function flowerQuest($object) {
        if (!checkAccess($object['from_id'], "Наблюдатель")) {
            return "";
        }
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => 17,
            'cat' => $cat["id"],
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Выполнение квеста засчитано, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function heal($object) {
        $hasPermit = DB::getVal("SELECT has_permit FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE users.id=$object[from_id]", 0);
        if (!$hasPermit) {
            return "";
        }
        $ex = explode("\n", str_replace([","], "\n", $object['text']));
        $line = $ex[0] ?? "";
        $healee = $ex[1] ?? "";
        $count = $ex[2] ?? "";
        $healee = trim(explode(",", $healee)[0]);
        preg_match('/^([А-Яа-яЁё ]+)[(\[\s]*\d/iu', $healee, $matches);
        $healee = $matches[1] ?? "";
        $healee = trim(mb_ereg_replace('[^А-Яа-яЁё ]+', '', $healee));
        $healee = formatCatName($healee);
        if ($healee == "") return "";

        preg_match('/^(выдала? (трав[ыу]|костоправы?)|почистила? от грязи) *,? *(.*)/iu', $line, $matches);
        $num = 16;
        $type_s = "Выдача трав";
        if (preg_match('/^выдала? костоправы?/iu', $line)) {
            $num = 15;
            $type_s = "Выдача костоправа";
        } elseif (preg_match('/^почистила? от грязи/iu', $line)) {
            $num = 24;
            $type_s = "Чистка от грязи";
            $count = (intval(preg_replace('/[^\d]/', '', $count)) * -1);
        }

        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";
        $hidden = ($healee != $cat["name"]);

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => $num,
            'cat' => $cat['id'],
            'hidden' => $hidden,
            'extra' => $healee,
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id'],
        ]];
        $points = Sheets::write($data);
        if ($num == 24 && $count) {
            $report_date->setTime(0, 0, 0);
            $data = [
                'date' => $report_date,
                'count' => $count,
                'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id'],
            ];
            Sheets::writeFlowerStat($data); // TODO: Дописать отмену
        }
        return "$type_s засчитана, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function mice($object) {
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        $attachments = $object["attachments"];
        if (count($attachments) < 1) {
            return "Задание не засчитано, $cat[name]. Необходимо прикрепить скрин, на котором видно пойманных мышей";
        }

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => 14,
            'cat' => $cat['id'],
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Задание по ловле мышей засчитано, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function task($object) {
        $user_id = intval($object['from_id']);
        if (!checkAccess($user_id, "Целитель")) {
            return "";
        }
        $userData = getCats($user_id)[$user_id] ?? [];
        $points = 0;
        if (preg_match('/\d+([.,]\d+)?/', $object['text'], $matches)) {
            $points = floatval(str_replace(",", ".", $matches[0]));
        }
        $ex = explode(',', str_replace(["\n"], ',', $object['text']));
        $type = array_shift($ex);
        $num = 12;
        $type_s = "Участие в ЧП";
        if (preg_match('/^поручение/iu', $type)) {
            $type_s = "Поручение";
            $num = 13;
        }
        $cats = [];
        foreach ($ex as $key => $entry) {
            $n = trim($entry);
            if ($n == "") continue;
            if (!preg_match('/\d+(\.\d+)?/', $n)) {
                $cat = formatCatName($n);
                if (!in_array($cat, $cats)) {
                    $cats[] = $cat;
                }
            }
        }
        $cats_str = join("', '", $cats);
        $catData = DB::getValArray("SELECT id FROM cats WHERE name IN ('$cats_str')");
        if (count($cats) < 1 || count($cats) != count($catData)) return "";

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);
        $data = [];
        foreach ($catData as $cat) {
            $data[] = [
                'num' => $num,
                'cat' => $cat,
                'hidden' => $points,
                'date' => $report_date,
                'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
            ];
        }
        $points = Sheets::write($data, true);
        $names = $cats[0];
        $pl = count($cats) > 1;
        if (count($cats) > 1) {
            $last = array_pop($cats);
            $names = join(", ", $cats) . " и " . $last;
        }
        return "$type_s для котик"
            . ($pl ? "ов" : "а по имени")
            . " $names засчитано, $userData[name].\n+"
            . declination($points[0], ['балл', 'балла', 'баллов']);
    }
    private static function rounds($object) {
        $text = $object['text'];
        $text = mb_strtolower($text);
        preg_match_all('/^обош(ел|ёл|ла) (?P<full>(?P<first>по|пв|детскую)( и (?P<second>по|пв|детскую))?|все спальные локации)/iu',
            $text, $matches);
        $first = $matches["first"][0] ?: $matches["full"][0];
        $second = $matches["second"][0];
        if ($first == $second) return 72483;
        $list = [
            "по" => 1,
            "детскую" => 1,
            "пв" => 0.5,
            "все спальные локации" => 2.5,
        ];
        $points = ($list[$first] ?? 0) + ($list[$second] ?? 0);
        if ($points > 2.5) return 72483;
        if ($points == 0) return "";

        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        $attachments = $object["attachments"];
        if (count($attachments) < 1) {
            return "Обход не засчитан, $cat[name]. Необходимо прикрепить скриншот истории";
        }
        $report_date = 0;
        if (preg_match('/(\d+):(\d+)/', $text, $matches)) {
            $time = ['h' => intval($matches[1]), 'm' => intval($matches[2])];
            $report_date = new DateTime();
            $report_date->setTimestamp($object['date']);
            $report_date->setTime($time['h'], $time['m']);
        }
        if (!$report_date) {
            return "Обход не засчитан, $cat[name]. Необходимо прописать время начала обхода";
        }

        $data = [[
            'num' => 11,
            'cat' => $cat['id'],
            'hidden' => $points,
            'extra' => $first . ($second ? (", " . $second) : ""),
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Обход засчитан, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function mossFill($object) {
        if (!checkAccess($object['from_id'], "Доверенный")) {
            return "";
        }
        $fwd = $object["fwd_messages"];
        if (count($fwd) < 1 && count($object["reply_message"]) > 0) {
            $fwd = [$object["reply_message"]];
        }
        if (count($fwd) < 1) return "no fwd";
        $users = [];
        foreach ($fwd as $val) {
            if (!in_array($val['from_id'], $users)) {
                $users[] = $val['from_id'];
            }
        }
        $cats = getCats($users);
        if (empty($cats)) return 79400;
        $point_distribution = [];
        $ex = explode('.', str_replace([',', "\n", " "], '.', trim($object['text'])));
        foreach ($ex as $key => $entry) {
            $entry = trim($entry);
            if (preg_match('/\d+/', $entry, $matches)) {
                $point_distribution[] = intval($matches[0]);

            }
        }
        if (count($users) != count($point_distribution)) return 72471;
        $data_cats = [];
        foreach ($users as $key => $user_id) {
            if (!isset($cats[$user_id])) {
                $user = getUserInfo($user_id, "gen");
                return "$user[first_name] $user[last_name] нет в отряде...";
            }
            $data_cats[] = [
                "user_id" => $user_id,
                "cat_id" => $cats[$user_id]["id"],
                "cat_name" => $cats[$user_id]["name"],
                "points" => $point_distribution[$key],
            ];
        }
        $data = [];
        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);
        foreach ($data_cats as $data_cat) {
            $data[] = [
                'num' => 10,
                'cat' => $data_cat['cat_id'],
                'extra' => $data_cat['points'],
                'date' => $report_date,
                'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
            ];
        }
        $points_total = Sheets::write($data, true);
        $output = [];
        $i = 0;
        foreach ($data_cats as $key => $data_cat) {
            $points = $points_total[$key];
            $output[] = "$data_cat[cat_name] - " . declination($points, ['балл', 'балла', 'баллов']);
            $i++;
        }
        return "Наполнение мха засчитано.\n" . join(", ", $output);
    }
    private static function taskMouse($object) {
        $ex = explode('.', str_replace([',', "\n"], '.', $object['text']));
        $count = 0;
        foreach ($ex as $key => $val) {
            $n = trim($val);
            if ($n == "") continue;
            if (preg_match('/\d+/iu', $n, $matches)) {
                $count = intval($matches[0]);
            }
        }
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => 9,
            'cat' => $cat['id'],
            'extra' => $count,
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Поручение засчитано, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function carryover($object, $type = 0) {
        if (!checkAccess($object['from_id'], "Доверенный")) {
            return "";
        }
        $fwd = $object["fwd_messages"];
        if (count($fwd) < 1 && count($object["reply_message"]) > 0) {
            $fwd = [$object["reply_message"]];
        }
        if (!isset($fwd)) return "";
        $users = [];
        foreach ($fwd as $val) {
            if (!in_array($val['from_id'], $users)) {
                $users[] = $val['from_id'];
            }
        }
        $cats = getCats($users);
        $filteredCats = array_filter($cats, function($el) { return $el["access_level"] > 0; });
        if (empty($cats)) return "";
        if (empty($filteredCats)) { // Все участники - ИСники
            $filteredCats = $cats;
        }
        $data = [];

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);
        // type = 0 - обычный перенос
        // type = 1 - с гб
        $num = [8, 20][$type] ?? 0;
        if (!$num) return "";
        //1 2 3 4 5 6 cats
        //6 5 4 3 3 3 points
        $points = 7 - min(4, count($filteredCats));
        if ($type == 1) {
            //1 2 3 4 5 6 7 8 cats
            //8 6 5 5 4 4 4 4 points
            $points = 7 - min(3, ceil(count($filteredCats) / 2));
            if (count($filteredCats) == 1) {
                $points = 8;
            }
        }
        $str = ["в ПсТ", "с ГБ"][$type];
        foreach ($cats as $cat) {
            $data[] = [
                'num' => $num,
                'cat' => $cat['id'],
                'extra' => $points,
                'date' => $report_date,
                'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
            ];
        }
        $points = Sheets::write($data, true);
        return "Перенос ресурсов $str засчитан.\n+" . declination($points[0], ['балл', 'балла', 'баллов']);
    }
    private static function gatherMoss($object) {
        $text = $object['text'];
        $type = "ОТ";
        if (preg_match('/ с мз /iu', $text)) {
            $type = "МЗ";
        }
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => ($type == "ОТ" ? 7 : 19),
            'cat' => $cat['id'],
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Поход на $type засчитан, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function cleanBots($object) {
        $count = 0;
        if (preg_match('/боты чисты,? \(?(\d+)\)?/iu', $object['text'], $matches)) {
            $count = intval($matches[1]);
        }
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";
        $attachments = $object["attachments"];
        if (count($attachments) < 1 || $count < 1) {
            return "Чистка не засчитана, $cat[name].\nНеобходимо добавить скриншоты истории и количества мусора";
        }
        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $data = [[
            'num' => 6,
            'cat' => $cat['id'],
            'date' => $report_date,
            'extra' => $count,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Чистка ботов засчитана, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);

    }
    private static function misc($object) {
        $ex = explode('.', str_replace([',', "\n"], '.', trim($object['text'])));
        $type = mb_strtolower(trim(array_shift($ex)));
        $type_s = "Помощь засчитана";
        $num = 4;
        if (preg_match('/^принял(а)? участие в травнике/iu', $type)) {
            $type_s = "Участие в травнике засчитано";
            $num = 5;
        }
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";
        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);
        $data = [[
            'num' => $num,
            'cat' => $cat['id'],
            'date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "$type_s, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function dozorNull($object) {
        if (!checkAccess($object['from_id'], "Проверяющий")) {
            return "";
        }
        $reply = Peck::getReply($object);
        $unique = $object['peer_id'] . "_" . $reply['conversation_message_id'];
        $check = Sheets::check($unique);
        $info = $check["data"];
        if (count($info) < 1) {
            return "";
        }
        if (count($check["null_data"]) > 0) {
            return "";
        }
        $type = $info[2];
        $num = ["Дозор в ПЦ" => 2,
            "Дозор на ГБ" => 18,
            "Дозор на локации с травами" => 21
        ][$type] ?? 0;
        if (!$num) {
            return "";
        }

        $dozdate = DateTime::createFromFormat('d.m.Y H:i:s', $info[1] . ':00');

        $reportdate = new DateTime();
        $reportdate->setTimestamp($object['date']);

        $data = [[
            'num' => $num,
            'cat' => $info[0],
            'date' => $dozdate,
            'real_date' => $reportdate,
            'extra' => $unique,
            'hidden' => str_replace(",", ".", $info[3]),
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        Sheets::write($data);
        return "Дозор успешно аннулирован";
    }
    private static function dozorReport($object) {
        $ex = explode('.', str_replace([',', "\n"], '.', trim($object['text'])));
        $type = "";
        $num = 0;
        $ex_type = array_shift($ex);
        switch (mb_strtolower(trim($ex_type))) {
            case "дозор в пц": $type = "в ПЦ"; break;
            case "дозор на гб": $type = "на ГБ"; $num = 1; break;
        }
        if ($type == "") return "";
        $hasCustomTime = false;

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);

        $time = ['h' => $report_date->format('H'), 'm' => $report_date->format('i')];
        foreach ($ex as $key => $entry) {
            $entry = trim($entry);
            if (preg_match('/(\d+):(\d+)/', $entry, $matches)) {
                $time = ['h' => intval($matches[1]), 'm' => intval($matches[2])];
                $hasCustomTime = true;
            }
        }
        $cat = getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return "";

        $act_date = new DateTime();
        $act_date->setTimestamp($object['date']);
        $act_date->setTime($time['h'], $time['m']);

        $dozhour = intval($act_date->format('H'));
        $reporthour = intval($report_date->format('H'));

        if ($dozhour > $reporthour) {
            $act_date->setTimestamp($act_date->getTimestamp() - 24 * 60 * 60);
        }

        if ($report_date->getTimestamp() - $act_date->getTimestamp() > 6 * 60) { // 6 min
            $current = $report_date->format('H:i');
            $report_date->modify('-5 minutes');
            $max = $report_date->format('H:i');
            return "Слишком поздно, текущее время: $current. Начать можно не ранее $max.";
        }

        $past_doz = Sheets::getInfoBy([
            0 => $cat["id"],
            2 => [
                "Дозор в ПЦ",
                "Дозор на ГБ",
                "Аннулирование дозора (Дозор в ПЦ)",
                "Аннулирование дозора (Дозор на ГБ)",
            ],
        ], 2);
        $past_ignore = [];
        foreach ($past_doz as $item) {
            if (strpos($item["type"], "Аннулирование дозора") !== false) {
                $past_ignore[] = $item["extra"];
                continue;
            }
            if (in_array($item["msg_id"], $past_ignore)) {
                continue;
            }
            $past_date = DateTime::createFromFormat('d.m.Y H:i:s', $item["date"] . ':00');
            $doz_time = ($item["type"] == "Дозор на ГБ") ? Peck::$gb_doz_min : Peck::$doz_min;
            $past_date->modify("+$doz_time minutes");
            if ($item["extra"]) {
                $past_date->modify("+$item[extra] minutes");
            }
            if ($act_date->getTimestamp() < $past_date->getTimestamp() ) {
                return "Вы уже дозорите до " . $past_date->format('H:i') . " АЛО";
            }
        }

        $data = [[
            'num' => $num,
            'cat' => $cat["id"],
            'date' => $act_date,
            'real_date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        $ends_at = $act_date->format("Y-m-d H:i:s");
        $doz_time = ($type == "на ГБ") ? Peck::$gb_doz_min : Peck::$doz_min;
        $db_type = ($type == "на ГБ") ? "gb" : "main";
        DB::q("INSERT INTO doz_active SET user_id=$object[from_id], peer_id=$object[peer_id], msg_id=$object[conversation_message_id], ends_at='$ends_at' + INTERVAL $doz_time MINUTE, type='$db_type'");
        return "Дозор $type засчитан, $cat[name]. \n"
            . ($hasCustomTime ? "" : ("Начало дозора: " . $act_date->format('H:i') . "\n"))
            . "+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function dozorHerb($object) {
        $ex = explode('.', str_replace([',', "\n"], '.', trim($object['text'])));
        array_shift($ex);
        $ex = join("", $ex);
        $points = 2;
        if (preg_match('/дальняя/iu', $ex)) {
            $points = 3;
        }

        $reply = Peck::getReply($object);
        $attachments = $reply["attachments"];
        if (count($attachments) != 1) {
            return "Ошибка: прикреплённых к сообщению файлов должно быть 2";
        }
        $photo = $attachments[0]["photo"];
        if (!isset($photo)) {
            return "";
        }

        $act_date = new DateTime();
        $act_date->setTimestamp($photo['date']);
        $hour = intval($act_date->format('H'));
        $minute = intval($act_date->format('i'));
        $act_day = $act_date->format('d.m.Y H');
        if (!in_array($hour, [12, 16, 17]) || $minute > 5) {
            return "";
        }
        $cat = getCats($photo["owner_id"])[$photo["owner_id"]] ?? [];
        if (empty($cat)) return "";

        $report_date = new DateTime();
        $report_date->setTimestamp($object['date']);
        $report_day = $report_date->format('d.m.Y H');
        if ($report_day != $act_day) {
            return "Слишком поздно, $cat[name]. Отписаться о том, что травы забрали, необходимо по факту. Дозор не засчитан";
        }
        $unique = $reply['peer_id'] . "_" . $reply['conversation_message_id'];
        $check = Sheets::check($unique);
        if (count($check["null_data"]) > 0) {
            return "";
        }
        $data = [[
            'num' => 3,
            'cat' => $cat['id'],
            'date' => $act_date,
            'hidden' => $points,
            'real_date' => $report_date,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Дозор на травах засчитан, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
}