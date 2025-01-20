<?php
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

function getUserInfo($user_id, $case = "nom") { // Возвращает объект пользователя
    try {
        $data = api('users.get', array(
            'user_id' => $user_id,
            'fields' => "sex, online",
            'name_case' => $case,
        ));
        if (is_array($user_id)) {
            return $data;
        }
        return $data[0];
    } catch (Exception $e) {
        return [];
    }
}

function sendReaction($peer_id, $cmid, $reaction_id) {
    try {
        api('messages.sendReaction', array(
            'peer_id' => $peer_id,
            'cmid' => $cmid,
            'reaction_id' => $reaction_id,
        ));
    } catch (Exception $e) {
        var_dump($e);
    }
}