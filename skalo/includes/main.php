<?

require_once __DIR__ . '/../../includes/func.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/config.php';

class Fly {
    private static $missingCatMsg = "тi кто?";
    public static function checkMessage($object) {
        $text = trim($object['text']);
//        $hasReplies = (!empty($object['fwd_messages']) || !empty($object['reply_message']));
        if (preg_match('/^(сбор с (ущелья|уступов)|отдельный сбор)/iu', $text)) {
            return Fly::sbor($object) ?: "";
        }
        return "";
    }
    private static function getDate($object) {
        $date = new DateTime();
        $date->setTimestamp($object['date']);
        return $date;
    }
    private static function getCats($user_ids) {
        if (!is_array($user_ids)) {
            $user_ids = [$user_ids];
        }
        if (empty($user_ids)) {
            return [];
        }
        $user_ids = join(", ", $user_ids);
        $result = DB::q("SELECT id, cat_id, name, access_level FROM cats_fly WHERE id IN ($user_ids)");
        $data = [];
        while ($row = DB::fetch($result)) {
            $data[$row["id"]] = ["id" => $row["cat_id"], "name" => $row["name"], "access_level" => $row["access_level"]];
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
        if ($count < 1) return "Сколько???";
        if (count($object["attachments"]) < 1) {
            return "Задание не засчитано! Необходимо прикрепить скриншот истории и рта";
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
        return "$type засчитан, $cat[name].\n+" . declination($points, ['балл', 'балла', 'баллов']);
    }
}