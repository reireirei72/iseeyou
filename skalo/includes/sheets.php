<?
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/config.php';

class Sheets {
    private static $spreadsheet_id = SPREADSHEET_ID;
    private static $spreadsheet_stats_name = SPREADSHEET_STATS_NAME;
    private static $spreadsheet_members_name = SPREADSHEET_MEMBERS_NAME;

    private static function getService() {
        $client = new \Google_Client();
        $client->setApplicationName(SPREADSHEET_APPLICATION_NAME);
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setAuthConfig(__DIR__ . '/credentials.json');
        return new Google_Service_Sheets($client);
    }

    private static function getSheetId($table_id, $name, $service = null) {
        if (!isset($service)) {
            $service = Sheets::getService();
        }
        $response = $service->spreadsheets->get($table_id);
        $sheetId = -1;
        foreach ($response->sheets as $sheet) {
            $properties = $sheet->properties;
            if ($properties->title == $name) {
                $sheetId = $properties->sheetId;
                break;
            }
        }
        return $sheetId;
    }

    private static function dateToSerial($date) {
        return floatval(25569 + (($date->getTimestamp() + 3 * 60 * 60) / 86400));
    }

    private static function getInfo($num, $hidden, $extra) {
        $legend = [
            0  => ['type' => 'Сбор с ущелья',                   'points' => 6 + 3 * intval($extra)],
            1  => ['type' => 'Сбор с уступов',                  'points' => 6 + 2 * intval($extra)],
            2  => ['type' => 'Сбор отдельных ресурсов',         'points' => 3 + intval($extra)],
            3  => ['type' => 'Транспортировка перьев',          'points' => 3 + round(floatval($hidden))],
            4  => ['type' => 'Транспортировка соплеменника',    'points' => 2 + round(floatval($hidden))],
        ];
        return $legend[$num] ?? 0;
    }
    public static function getMembersBy($search = []) {
        $service = Sheets::getService();
        $sheetId = Sheets::getSheetId(self::$spreadsheet_id, self::$spreadsheet_members_name, $service);
        if ($sheetId == -1) {
            return -1;
        }
        $range = self::$spreadsheet_members_name . "!A2:J";
        $response = $service->spreadsheets_values->get(self::$spreadsheet_id, $range);
        $values = $response->getValues();

        $conditions = [];
        foreach ($search as $key => $searchValues) {
            $realKey = [
                    "vk_id" => 1,
                    "name" => 2,
                    "id" => 3,
                    "nickname" => 4,
                ][$key] ?? 0;
            $conditions[$realKey] = $searchValues;
        }

        $data = [];
        if (!empty($values)) {
            foreach ($values as $row) {
                $isOk = true;
                foreach ($conditions as $key => $cond) {
                    if (is_array($cond)) {
                        $isCondOk = in_array($row[$key], $cond);
                    } elseif (is_callable($cond)) {
                        $isCondOk = $cond($row[$key]);
                    } else {
                        $isCondOk = $row[$key] == $cond;
                    }
                    $isOk = $isOk && $isCondOk;
                }
                if ($isOk) {
                    $data[] = [
                        "vk_id" => $row[1],
                        "name" => $row[2],
                        "id" => $row[3],
                        "nickname" => $row[4],
                        "prefers_nickname" => $row[5] == "TRUE",
                        "invite_date" => $row[6],
                        "trial_end_date" => $row[7],
                        "access_level" => $row[8],
                    ];
                }
            }
        }
        return $data;
    }
    public static function getMember($vkId = 0, $catId = 0) {
        $service = Sheets::getService();
        $sheetId = Sheets::getSheetId(self::$spreadsheet_id, self::$spreadsheet_members_name, $service);
        if ($sheetId == -1) {
            return -1;
        }

        $range = self::$spreadsheet_members_name . "!A2:J";
        $response = $service->spreadsheets_values->get(self::$spreadsheet_id, $range);
        $values = $response->getValues();
        $data = [];
        $column = 1;
        $value = $vkId;
        if (!$value) {
            $column = 3;
            $value = $catId;
        }
        if (!empty($values)) {
            foreach ($values as $key => $row) {
                $id = $row[$column];
                if ($id == $value) {
                    $data = $row;
                    break;
                }
            }
        }
        if (empty($data)) return -2;
        return [
            "vk_id" => $data[1],
            "name" => $data[2],
            "id" => $data[3],
            "nickname" => $data[4],
            "prefers_nickname" => $data[5] == "TRUE",
            "invite_date" => $data[6],
            "trial_end_date" => $data[7],
            "access_level" => $data[8],
        ];
    }
    public static function addMember($info) {
        $service = Sheets::getService();
        $sheetId = Sheets::getSheetId(self::$spreadsheet_id, self::$spreadsheet_members_name, $service);
        if ($sheetId == -1) {
            return -1;
        }
        $range = self::$spreadsheet_members_name . "!A2:J";
        $conf = ["valueInputOption" => "USER_ENTERED"];
        $rows = [[
            $info["vk_name"],
            $info["vk_id"],
            $info["cat_name"],
            $info["id"],
            "",
            $info["prefers_nickname"],
            Sheets::dateToSerial($info["invite_date"]),
            Sheets::dateToSerial($info["trial_end_date"]),
            $info["access_level"],
        ]];
        try {
            $postBody = new Google_Service_Sheets_ValueRange([
                "values" => $rows
            ]);
            $service->spreadsheets_values->append(self::$spreadsheet_id, $range, $postBody, $conf);
            return 1;
        } catch (Exception $e) {
            print_r("WRITE ERROR ". $e->getMessage());
            return -1;
        }
    }
    public static function write($info, $force_return_array = false) {
        $sheetId = Sheets::getSheetId(self::$spreadsheet_id, self::$spreadsheet_stats_name);
        if ($sheetId == -1) {
            return -1;
        }
        $rows_raw = [];
        $return_points = [];
        foreach ($info as $item) {
            $data = $item;
            $actinfo = Sheets::getInfo($data['num'], $data['hidden'] ?? 0, $data['extra'] ?? 0);
            $points = $actinfo['points'];
            $return_points[] = $points;
            $type = $actinfo['type'];
            if (!is_numeric($points)) {
                return -1;
            }
            if (!isset($item['real_date'])) {
                $item['real_date'] = $item['date'];
            }
            $values = [
                intval($item['cat'] ?? 0),
                $item['date'],
                $type,
                $points,
                $item['extra'] ?? "",
                $item['msg_id'] ?? "",
            ];
            $rows_raw[] = $values;
        }
        $rows = [];
        foreach ($rows_raw as $row) {
            $cells = [];
            foreach ($row as $key => $cell) {
                $new_cell = [
                    "userEnteredValue" => [],
                    "userEnteredFormat" => []
                ];
                $value_type = (gettype($cell) == "integer" || gettype($cell) == "double") ? "numberValue" : "stringValue";
                if ($cell instanceof DateTime) {
                    $value_type = "numberValue";
                    $cell = Sheets::dateToSerial($cell);
                    $new_cell["userEnteredFormat"]["numberFormat"] = ["type" => "DATE", "pattern" => "dd.mm.yyyy hh:mm"];
                }
                $new_cell["userEnteredFormat"]["horizontalAlignment"] = (($value_type == "stringValue") ? "LEFT" : "CENTER");
                $new_cell["userEnteredValue"] = [$value_type => $cell];
                $cells[] = $new_cell;
            }
            $rows[] = ['values' => $cells];
        }

        $requests = [
            new Google_Service_Sheets_Request([
                'insertDimension' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'dimension' => "ROWS",
                        'startIndex' => '1',
                        'endIndex' => '' . (count($rows) + 1)
                    ],
                    'inheritFromBefore' => true
                ]
            ]),
            new Google_Service_Sheets_Request([
                'updateCells' => [
                    'rows' => $rows,
                    'fields' => '*',
                    'range' => [
                        'sheetId' => $sheetId,
                        'startRowIndex' => 1,
                        'endRowIndex' => (count($rows) + 1),
                        'startColumnIndex' => 0
                    ],
                ]
            ])
        ];

        $request = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => $requests
        ]);

        try {
            Sheets::getService()->spreadsheets->batchUpdate(self::$spreadsheet_id, $request);
        } catch (Exception $e) {
            exit($e->getMessage());
        }
        if (count($return_points) == 1 && !$force_return_array) {
            return $return_points[0];
        }
        return $return_points;
    }

    private static function findByUnique($unique, $service = null) {
        if (!isset($service)) {
            $service = Sheets::getService();
        }
        $range = self::$spreadsheet_stats_name . "!A:F";
        $response = $service->spreadsheets_values->get(self::$spreadsheet_id, $range);
        $values = $response->getValues();
        $cell_num = 0; // Ячейка, в которой лежит отчёт
        $data = [];
        if (!empty($values)) {
            foreach ($values as $key => $row) {
                $id = end($row);
                if ($id == $unique) {
                    $cell_num = $key;
                    $data = $row;
                    break;
                }
            }
        }
        return [
            "key" => $cell_num,
            "data" => $data,
        ];
    }
    public static function remove($unique) {
        $service = Sheets::getService();
        $data = Sheets::findByUnique($unique, $service);

        if ($data["key"] == 0) {
            return [
                "status" => "error",
                "data" => 1
            ];
        }
        $sheetId = Sheets::getSheetId(self::$spreadsheet_id, self::$spreadsheet_stats_name, $service);
        $request = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => [
                new Google_Service_Sheets_Request([
                    'deleteDimension' => [
                        'range' => [
                            'sheetId' => $sheetId,
                            'dimension' => "ROWS",
                            'startIndex' => '' . $data["key"],
                            'endIndex' => '' . ($data["key"] + 1)
                        ]
                    ]
                ]),
            ]
        ]);

        try {
            $service->spreadsheets->batchUpdate(self::$spreadsheet_id, $request);
        } catch (Exception $e) {
            exit($e->getMessage());
        }

        return [
            "status" => "success",
            "data" => $data["data"],
        ];
    }
    public static function getStatistics($who, $from, $to) {
        $service = Sheets::getService();

        $range = self::$spreadsheet_stats_name . "!A:F";
        $response = $service->spreadsheets_values->get(self::$spreadsheet_id, $range);
        $values = $response->getValues();
        $array = [];

        if (!empty($values)) {
            foreach ($values as $row) {
                $name = trim($row[0]);
                if ($name == $who || $who === 0) {
                    $date = DateTime::createFromFormat('d.m.Y H:i:s', $row[1] . ':00');
                    if ($date && $date->getTimestamp() >= $from->getTimestamp() && $date->getTimestamp() <= $to->getTimestamp()) {
                        if (!isset($array[$name])) {
                            $array[$name] = [];
                        }
                        $array[$name][] = [
                            "type" => trim($row[2]),
                            "date" => $row[1],
                            "points" => floatval(str_replace(',', '.', $row[3])),
                            "extra" => trim($row[4]),
                            "msg_id" => $row[5],
                        ];
                    }
                }
            }
        }
        if ($who === 0) {
            return $array;
        }
        return array_pop($array);
    }
}