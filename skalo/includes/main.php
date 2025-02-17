<?

require_once __DIR__ . '/../../includes/func.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/config.php';

class Fly {
    private static $missingCatMsg = "тi кто?";
    private static $hasLatin = "Удали эту чёртову клавиатуру с этой чёртовой латинской ё и поставь что-нибудь НОРМАЛЬНОЕ (GoogleSwift например)";
    public static function checkMessage($object) {
        $text = trim($object['text']);
//        $hasReplies = (!empty($object['fwd_messages']) || !empty($object['reply_message']));
        if (preg_match('/^(сбор с (ущелья|уступов)|отдельный сбор)/iu', $text)) {
            return Fly::sbor($object) ?: "";
        } elseif (preg_match('/^ун[еёë]с(ла)? (перья|соплеменник(а|ов))/iu', $text)) {
            if (mb_strpos($text, "ë") !== false) return Fly::$hasLatin;
            return Fly::transport($object) ?: "";
        }
        return "";
    }
    public static function getReply($object) {
        $reply = $object['reply_message'] ?? [];
        if (empty($reply)) {
            $reply = $object['fwd_messages'][0] ?? [];
        }
        return $reply;
    }
    public static function cancelAct($object) {
        $user_id = $object['from_id'];
        $reply = Fly::getReply($object);
        $report_user_id = $reply['from_id'];
        $unique = $object['peer_id'] . "_" . $reply['conversation_message_id'];
        if ($user_id == $report_user_id || checkAccess($user_id, "Глава")) {
            $response = Sheets::remove($unique);
            if ($response["status"] == "error") {
                return "Или это не отчёт, или его уже отменили";
            }
            return "Отчёт \"" . $response["data"][2] . "\" отменён";
        }
        return getStickers("deny_access");
    }
    private static function getDate($object) {
        $date = new DateTime();
        $date->setTimestamp($object['date']);
        return $date;
    }
    public static function getActivity($cat, $from, $to) {
        $data = Sheets::getStatistics($cat["id"], $from, $to);
        $total_points = 0;
        $result = [];
        foreach ($data as $row) {
            $type = $row["type"];
            if (!isset($result[$type])) {
                $result[$type] = [
                    "points" => 0,
                    "count" => 0,
                    "extra" => 0,
                ];
            }
            $points = $row["points"];
            $result[$type]["count"]++;
            $result[$type]["extra"] += $row["extra"];
            $result[$type]["points"] += $points;
            $total_points += $points;
        }

        $return = "Сборы с ущелья: " . ($result["Сбор с ущелья"]["count"] ?? 0) . "\n";
        $return .= "Сборы с уступов: " . ($result["Сбор с уступов"]["count"] ?? 0) . "\n";
        $tSeparate = $result["Сбор отдельных ресурсов"] ?? null;
        if (isset($tSeparate)) {
            $return .= "Отдельные сборы: " . $tSeparate["count"] . "\n";
        }
        $tFeathers = $result["Транспортировка перьев"] ?? null;
        $tCats = $result["Транспортировка соплеменника"] ?? null;
        if (isset($tFeathers) || isset($tCats)) {
            $return .= "\n";
        }
        if (isset($tFeathers)) {
            $return .= "Транспортировки перьев: " . $tFeathers["count"]
                . "(" . declination($tFeathers["points"] , ['балл', 'балла', 'баллов']) .")\n";
        }
        if (isset($tCats)) {
            $return .= "Транспортировки соплеменников: " . $tCats["count"]
                . "(" . declination($tCats["points"] , ['балл', 'балла', 'баллов']) .")\n";
        }
        $return .= "\n";
        $return .= "Паутины с ущелья: " . ($result["Сбор с ущелья"]["extra"] ?? 0) . "\n";
        $return .= "Мха с уступов: " . ($result["Сбор с ущелья"]["extra"] ?? 0) . "\n";
        if (isset($tSeparate)) {
            $return .= "Ресурсов с отдельных сборов: " . $tSeparate["extra"] . "\n";
        }
        $return .= "\n";
        $return .= "Всего баллов: $total_points\n";
        $return .= "В отряде с: $cat[invite_date]";
        return $return;
    }
    public static function getCats($user_ids) {
        if (!is_array($user_ids)) {
            $user_ids = [$user_ids];
        }
        if (empty($user_ids)) {
            return [];
        }
        $users = Sheets::getMembersBy(["vk_id" => $user_ids]);
        $data = [];
        foreach ($users as $user) {
            $preferredName = $user['prefers_nickname'] && $user['nickname'] ? $user['nickname'] : $user['name'];
            $data[$user["vk_id"]] = [
                "id" => $user["id"],
                "name" => $user["name"],
                "preferred_name" => $preferredName,
                "invite_date" => $user["invite_date"],
                "access_level" => $user["access_level"]
            ];
        }
        return $data;
    }
    private static function sbor($object) {
        $ex = explode('.', str_replace([',', "\n"], '.', trim($object['text'])));
        $ex_type = trim(array_shift($ex));
        $type = "";
        $num = 0;
        if (mb_strpos($ex_type, "сбор с ущелья") !== false) {
            $type = "сбор с ущелья";
        } elseif (mb_strpos($ex_type, "сбор с уступов") !== false) {
            $type = "сбор с уступов";
            $num = 1;
        } elseif (mb_strpos($ex_type, "отдельный сбор") !== false) {
            $type = "отдельный сбор";
            $num = 2;
        }
        if ($type == "") return "";
        $count = intval(mb_ereg_replace('\D+', '', array_pop($ex)));
        if ($count < 1) return "Количество ресурсов не указано! Перепиши отчёт";
        if (count($object["attachments"]) < 1) {
            return "Нет скриншота истории и рта! Перепиши отчёт";
        }
        $cat = Fly::getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return Fly::$missingCatMsg;
        $data = [[
            'num' => $num,
            'cat' => $cat['id'],
            'date' => Fly::getDate($object),
            'extra' => $count,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        $type = mb_ucfirst($type);
        return "$type засчитан, $cat[preferred_name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
    private static function transport($object) {
        $text = trim($object['text']);
        if (preg_match('/^ун[её]с(ла)? перья/iu', $text)) {
            $type = "перьев";
            $num = 3;
        } else {
            $type = "соплеменника";
            $num = 4;
        }
        if (count($object["attachments"]) < 1) {
            return "Нет скриншота истории! Перепиши отчёт";
        }
        preg_match('/перевес (\d+[.,]*\d*)/iu', $text, $matches);
        $overweight = floatval(str_replace(",", ".", ($matches[1] ?? "0")));
        $cat = Fly::getCats($object['from_id'])[$object['from_id']] ?? [];
        if (empty($cat)) return Fly::$missingCatMsg;
        $data = [[
            'num' => $num,
            'cat' => $cat['id'],
            'date' => Fly::getDate($object),
            'hidden' => $overweight,
            'msg_id' => $object['peer_id'] . "_" . $object['conversation_message_id']
        ]];
        $points = Sheets::write($data);
        return "Транспортировка $type засчитана, $cat[preferred_name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
}