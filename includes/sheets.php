<?
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

class Sheets {
    private static $spreadsheet_id = SPREADSHEET_ID;
    private static $flowers_spreadsheet_id = FLOWERS_SPREADSHEET_ID;
    private static $memo_spreadsheet_stats_name = MEMO_SPREADSHEET_STATS_NAME;
    private static $spreadsheet_stats_name = SPREADSHEET_STATS_NAME;
    private static $flowers_spreadsheet_stats_name = FLOWERS_SPREADSHEET_STATS_NAME;

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
            0  => ['type' => 'Дозор в ПЦ',                                  'points' => 2.5],
            1  => ['type' => 'Дозор на ГБ',                                 'points' => 2.5],
            2  => ['type' => 'Аннулирование дозора (Дозор в ПЦ)',           'points' => -floatval($hidden)],
            3  => ['type' => 'Дозор на локации с травами',                  'points' => floatval($hidden)],
            4  => ['type' => 'Помощь травнику',                             'points' => 1],
            5  => ['type' => 'Участие в травнике',                          'points' => 2],
            6  => ['type' => 'Чистка ботов КсД',                            'points' => floor((floatval($extra) + (floatval($extra) < 25 ? 15 : 0)) / 25)],
            7  => ['type' => 'Сбор с ОТ',                                   'points' => 3],
            8  => ['type' => 'Перенос ресурсов',                            'points' => floatval($extra)],
            9  => ['type' => 'Поимка мышей',                                'points' => floor((floatval($extra) - 1) / 5) + 1],
            10 => ['type' => 'Наполнение мха',                              'points' => floatval($extra) / 2],
            11 => ['type' => 'Обход',                                       'points' => floatval($hidden)],
            12 => ['type' => 'Участие в ЧП',                                'points' => floatval($hidden)],
            13 => ['type' => 'Поручение главы/целителя',                    'points' => floatval($hidden)],
            14 => ['type' => 'Ловля мышей в свободное время',               'points' => 1],
            15 => ['type' => 'Выдача костоправа',                           'points' => ($hidden ? 1 : 0)],
            16 => ['type' => 'Выдача трав',                                 'points' => ($hidden ? 1 : 0)],
            17 => ['type' => 'Квест на ОС',                                 'points' => 3],
            18 => ['type' => 'Аннулирование дозора (Дозор на ГБ)',          'points' => -floatval($hidden)],
            19 => ['type' => 'Сбор с МЗ',                                   'points' => 2],
            20 => ['type' => 'Перенос с мели',                              'points' => floatval($extra)],
            21 => ['type' => 'Аннулирование дозора (Дозор на травах)',      'points' => -floatval($hidden)],
            22 => ['type' => 'Проверка отчётов',                            'points' => 4],
            23 => ['type' => 'Обновление архива памяток',                   'points' => 2],
            24 => ['type' => 'Чистка от грязи',                             'points' => ($hidden ? 1 : 0)],
            25 => ['type' => 'Перебор камней',                              'points' => ceil(min(5, max(0, ($extra - 5) / 10)))],
        ];
        return $legend[$num] ?? 0;
    }
    public static function modifyRow($cell_num, $data, $start_index = 0) {
        $service = Sheets::getService();
        $sheetId = Sheets::getSheetId(self::$spreadsheet_id, self::$spreadsheet_stats_name, $service);
        $rows = [];
        $cells = [];
        $date_format = [];
        foreach ($data as $key => $cell) {
            $new_cell = [
                "userEnteredValue" => [],
                "userEnteredFormat" => []
            ];
            $value_type = (gettype($cell) == "integer" || gettype($cell) == "double") ? "numberValue" : "stringValue";
            if ($cell instanceof DateTime) {
                $pattern = $date_format[$key] ?? "dd.mm.yyyy hh:mm";
                $value_type = "numberValue";
                $cell = Sheets::dateToSerial($cell);
                $new_cell["userEnteredFormat"]["numberFormat"] = ["type" => "DATE", "pattern" => $pattern];
            }
            $new_cell["userEnteredFormat"]["horizontalAlignment"] = (($value_type == "stringValue") ? "LEFT" : "CENTER");
            $new_cell["userEnteredValue"] = [$value_type => $cell];
            $cells[] = $new_cell;
        }
        $rows[] = ['values' => $cells];
        $request = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => [
                new Google_Service_Sheets_Request([
                    'updateCells' => [
                        'rows' => $rows,
                        'fields' => '*',
                        'range' => [
                            'sheetId' => $sheetId,
                            'startRowIndex' => $cell_num,
                            'endRowIndex' => ($cell_num + 1),
                            'startColumnIndex' => $start_index,
                            'endColumnIndex' => $start_index + count($data),
                        ],
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
        ];
    }
    public static function getCurrentWatchers($extra_min = 0) {
        $service = Sheets::getService();

        $range = self::$spreadsheet_stats_name . "!A2:G";
        $response = $service->spreadsheets_values->get(self::$spreadsheet_id, $range);
        $values = $response->getValues();
        $array = [];

        $now = new DateTime();
        $now->modify('-1 hour');
        $now->modify("-$extra_min minutes");
        $now->setTime($now->format("H"), $now->format("i"), 0);
        $to_ignore = [];

        if (!empty($values)) {
            foreach ($values as $row) {
                $date = DateTime::createFromFormat('d.m.Y H:i:s', $row[1] . ':00');
                $type = $row[2];
                if ($type == "Аннулирование дозора (Дозор в ПЦ)"
                    || $type == "Аннулирование дозора (Дозор на ГБ)") {
                    $to_ignore[] = $row[4];
                } elseif ($date < $now) { // После аннулирования, потому что там дата берется старая
                    break;
                } elseif (($type == "Дозор в ПЦ" || $type == "Дозор на ГБ") && !in_array($row[6], $to_ignore)) {
                    $extra = intval($row[4]);
                    $array[] = [
                        "who" => $row[0],
                        "date" => $date,
                        "type" => $type,
                        "extra_time" => $extra,
                    ];
                }
            }
        }
        return $array;
    }

    public static function getInfoBy($conditions, $max_hours = 1) {
        $service = Sheets::getService();

        $range = self::$spreadsheet_stats_name . "!A:G";
        $response = $service->spreadsheets_values->get(self::$spreadsheet_id, $range);
        $values = $response->getValues();
        $array = [];

        $max = new DateTime();
        $max->modify("-$max_hours hour");

        if (!empty($values)) {
            foreach ($values as $row) {
                $date = DateTime::createFromFormat('d.m.Y H:i:s', $row[1] . ':00');
                if ($date) {
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
                        if ($date->getTimestamp() >= $max->getTimestamp()) {
                            $array[] = [
                                "type" => trim($row[2]),
                                "date" => $row[1],
                                "points" => floatval(str_replace(',', '.', $row[3])),
                                "extra" => trim($row[4]),
                                "msg_id" => $row[6],
                            ];
                        } else {
                            break;
                        }
                    }
                }
            }
        }
        return $array;
    }
    public static function getActivity($who, $from, $to) {
        $service = Sheets::getService();

        $range = self::$spreadsheet_stats_name . "!A:G";
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
                            "msg_id" => $row[6],
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

    private static function findByUnique($unique, $table = 0, $service = null) {
        if (!isset($service)) {
            $service = Sheets::getService();
        }
        if ($table === 0) {
            $range = self::$spreadsheet_stats_name . "!A:G";
            $response = $service->spreadsheets_values->get(self::$spreadsheet_id, $range);
        } else {
            $range = self::$flowers_spreadsheet_stats_name . "!A:C";
            $response = $service->spreadsheets_values->get(self::$flowers_spreadsheet_id, $range);
        }
        $values = $response->getValues();
        $act_null_cell = 0;
        $act_cell = 0;
        $act_last_cell = 0;
        $null_data = [];
        $data = [];
        if (!empty($values)) {
            foreach ($values as $key => $row) {
                $id = $row[4];
                if ($id == $unique) {
                    $act_null_cell = $key;
                    $null_data = $row;
                }

                $id = end($row);
                if ($id == $unique) {
                    if (!$act_cell) {
                        $act_cell = $key;
                    }
                    $act_last_cell = $key;
                    $data = $row;
                }
                if ($id != $unique && $act_cell) {
                    break;
                }
            }
        }
        return [
            "act_cell" => $act_cell,
            "act_last_cell" => $act_last_cell,
            "data" => $data,
            "act_null_cell" => $act_null_cell,
            "null_data" => $null_data,
        ];
    }

    // Проверяет, существует ли запись с таким ID, и не является ли она аннулированной
    public static function check($unique) {
        $service = Sheets::getService();
        $data = Sheets::findByUnique($unique, 0, $service);

        return [
            "data" => $data["data"],
            "act_cell" => $data["act_cell"],
            "null_data" => $data["null_data"],
        ];
    }

    public static function remove($unique, $table = 0) {
        $service = Sheets::getService();
        $data = Sheets::findByUnique($unique, $table, $service);

        if ($data["act_cell"] == 0) {
            return [
                "status" => "error",
                "data" => 1
            ];
        }
        if ($data["act_null_cell"] != 0) {
            return [
                "status" => "error",
                "data" => 2
            ];
        }
        if ($table === 0) {
            $sheetId = Sheets::getSheetId(self::$spreadsheet_id, self::$spreadsheet_stats_name, $service);
        } else {
            $sheetId = Sheets::getSheetId(self::$flowers_spreadsheet_id, self::$flowers_spreadsheet_stats_name, $service);
        }
        $request = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => [
                new Google_Service_Sheets_Request([
                    'deleteDimension' => [
                        'range' => [
                            'sheetId' => $sheetId,
                            'dimension' => "ROWS",
                            'startIndex' => '' . $data["act_cell"],
                            'endIndex' => '' . ($data["act_last_cell"] + 1)
                        ]
                    ]
                ]),
            ]
        ]);

        try {
            if ($table === 0) {
                $service->spreadsheets->batchUpdate(self::$spreadsheet_id, $request);
            } else {
                $service->spreadsheets->batchUpdate(self::$flowers_spreadsheet_id, $request);
            }
        } catch (Exception $e) {
            exit($e->getMessage());
        }

        return [
            "status" => "success",
            "data" => $data["data"],
        ];
    }
    public static function writeMemo($info) {
        $sheetId = Sheets::getSheetId(self::$spreadsheet_id, self::$memo_spreadsheet_stats_name);
        if ($sheetId == -1) {
            return -1;
        }
        $rows_raw = [];
        foreach ($info as $item) {
            if (!isset($item['real_date'])) {
                $item['real_date'] = $item['date'];
            }
            $values = [
                intval($item['cat']),
                $item['date'],
                $item['type'],
                $item['type_extra'],
                intval($item['whom']),
            ];
            $rows_raw[] = $values;
        }
        $date_format = [];
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
                    $pattern = $date_format[$key] ?? "dd.mm.yyyy hh:mm";
                    $value_type = "numberValue";
                    $cell = Sheets::dateToSerial($cell);
                    $new_cell["userEnteredFormat"]["numberFormat"] = ["type" => "DATE", "pattern" => $pattern];
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
        return count($rows);
    }
    public static function writeFlowerStat($info) {
        $sheetId = Sheets::getSheetId(self::$flowers_spreadsheet_id, self::$flowers_spreadsheet_stats_name);
        if ($sheetId == -1) {
            return;
        }
        $rows_raw = [[
            $info['date'],
            $info['count'],
            $info['msg_id'],
        ]];
        $rows = [];
        $date_format = ['date' => "dd.mm.yyyy"];
        foreach ($rows_raw as $row) {
            $cells = [];
            foreach ($row as $key => $cell) {
                $new_cell = [
                    "userEnteredValue" => [],
                    "userEnteredFormat" => []
                ];
                $value_type = (gettype($cell) == "integer" || gettype($cell) == "double") ? "numberValue" : "stringValue";
                if ($cell instanceof DateTime) {
                    $pattern = $date_format[$key] ?? "dd.mm.yyyy hh:mm";
                    $value_type = "numberValue";
                    $cell = Sheets::dateToSerial($cell);
                    $new_cell["userEnteredFormat"]["numberFormat"] = ["type" => "DATE", "pattern" => $pattern];
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
            Sheets::getService()->spreadsheets->batchUpdate(self::$flowers_spreadsheet_id, $request);
        } catch (Exception $e) {
            exit($e->getMessage());
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
                $item['real_date'],
                $item['msg_id'] ?? "",
            ];
            $rows_raw[] = $values;
        }
        $date_format = [];
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
                    $pattern = $date_format[$key] ?? "dd.mm.yyyy hh:mm";
                    $value_type = "numberValue";
                    $cell = Sheets::dateToSerial($cell);
                    $new_cell["userEnteredFormat"]["numberFormat"] = ["type" => "DATE", "pattern" => $pattern];
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
}