<?php
require_once __DIR__ . '/config.php';

function mb_ucfirst($string) {
    $firstChar = mb_substr($string, 0, 1);
    $then = mb_substr($string, 1);
    return mb_strtoupper($firstChar) . $then;
}
function mb_lcfirst($string) {
    $firstChar = mb_substr($string, 0, 1);
    $then = mb_substr($string, 1);
    return mb_strtolower($firstChar) . $then;
}
function formatNames($array) {
    $last = array_pop($array);
    if (count($array) < 1) {
        return $last;
    }
    return join(", ", $array) . " и " . $last;
}
function formatCatName($string) {
    $name = "";
    $string = mb_strtolower(trim($string));
    $string = mb_ereg_replace('[^а-яА-ЯЁё\s]+', '', $string);
    $arr = explode(' ', $string);
    foreach($arr as $key => $word) {
        $arr[$key] = mb_ucfirst(trim($word));
    }
    if (count($arr) <= 2) {
        $name = trim(join(' ', $arr));
    }
    return $name;
}

function getCats($user_ids) {
    if (!is_array($user_ids)) {
        $user_ids = [$user_ids];
    }
    if (empty($user_ids)) {
        return [];
    }
    $user_ids = join(", ", $user_ids);
    $result = DB::q("SELECT users.id as 'id', cat_id, name, access_level FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE users.id IN ($user_ids)");
    $data = [];
    while ($row = DB::fetch($result)) {
        $data[$row['id']] = ["id" => $row["cat_id"], "name" => $row["name"], "access_level" => $row["access_level"]];
    }
    return $data;
}

function checkAccess($id, $level) {
    return DB::getVal("SELECT access_level FROM users LEFT JOIN cats ON cats.id=users.cat_id WHERE users.id=$id", -1) >= (ACCESS_LEVELS_TYPES[$level] ?? $level);
}
function declination($number, $titles, $onlyWords = false) {
    $start = ($onlyWords ? '' : "$number ");
    if (strpos($number, '.') !== false) {
        return $start . $titles[1];
    } else {
        $cases = [2, 0, 1, 1, 1, 2];
        $number = abs($number);
        return $start . $titles[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
    }
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