<?
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/config.php';

class Sheets {
    private static $spreadsheet_id = SPREADSHEET_ID;
    private static $spreadsheet_stats_name = SPREADSHEET_STATS_NAME;

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
            3  => ['type' => 'Транспортировка перьев',          'points' => 3 + ($hidden ? 3 : 0)],
            4  => ['type' => 'Транспортировка соплеменника',    'points' => 2 + round(floatval($hidden))],
        ];
        return $legend[$num] ?? 0;
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
}