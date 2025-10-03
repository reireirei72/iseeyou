<?
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/config.php';

class Sheets {
    private static function getService() {
        $client = new \Google_Client();
        $client->setApplicationName(SPREADSHEET_APPLICATION_NAME);
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setAuthConfig(__DIR__ . '/credentials.json');
        return new Google_Service_Sheets($client);
    }

    private static function dateToSerial($date) {
        return floatval(25569 + (($date->getTimestamp() + 3 * 60 * 60) / 86400));
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
    public static function search($conditions) {
        $service = Sheets::getService();
        $range = SPREADSHEET_LIST_NAME . "!" . SPREADSHEET_LIST_RANGE;
        $response = $service->spreadsheets_values->get(SPREADSHEET_ID, $range);
        $values = $response->getValues();
        $num = 0;
        $data = [];
        if (!empty($values)) {
            foreach ($values as $rowNum => $row) {
                $isOk = false;
                foreach ($conditions as $key => $cond) {
                    if (is_array($cond)) {
                        $isCondOk = in_array($row[$key], $cond);
                    } elseif (is_callable($cond)) {
                        $isCondOk = $cond($row[$key]);
                    } else {
                        $isCondOk = $row[$key] == $cond;
                    }
                    $isOk = $isOk || $isCondOk;
                }
                if ($isOk) {
                    $num = $rowNum;
                    $data = $row;
                    break;
                }
            }
        }
        return [$num, $data];
    }
    public static function write($info) {
        $service = Sheets::getService();
        $range = SPREADSHEET_LIST_NAME . "!" . SPREADSHEET_LIST_RANGE;
        $conf = ["valueInputOption" => "USER_ENTERED"];
        $rows = [];

        foreach ($info as $item) {
            $cat_id = intval($item['cat_id'] ?? 0);
            $vk_id = intval($item['vk_id'] ?? 0);
            $values = [
                "=IFNA(VLOOKUP(INDEX(" . SPREADSHEET_LIST_RANGE . "; ROW(); 2); ". SPREADSHEET_LIST_MEER . "; 4; FALSE); \"?\")",
                $cat_id,
                "=HYPERLINK(\"https://catwar.net/cat\"&index(" . SPREADSHEET_LIST_RANGE . "; ROW(); 2); IFNA(VLOOKUP(INDEX(" . SPREADSHEET_LIST_RANGE . "; ROW(); 2); ". SPREADSHEET_LIST_MEER . "; 2; FALSE); \"\"))",
                $item['nickname'] ?? "",
                $vk_id,
                "=HYPERLINK(\"https://vk.com/id$vk_id\";\"$item[vk_name]\")",
                $item['msg_id'] ?? "",
            ];
            $rows[] = $values;
        }
        try {
            $postBody = new Google_Service_Sheets_ValueRange([
                "values" => $rows
            ]);
//            print_r('APPEND');
//            print_r($rows);
            $service->spreadsheets_values->append(SPREADSHEET_ID, $range, $postBody, $conf);
        } catch (Exception $e) {
            exit("WRITE ERROR ". $e->getMessage());
        }
    }

    public static function writeExams($info) {
        $rows_raw = [];
        foreach ($info as $item) {
            $values = [
                "",
                $item['full_string'],
                $item['name'],
                $item['id'],
                $item['date'],
                $item['type'],
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
                    $pattern = "dd.mm.yyyy";
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
        $sheetId = Sheets::getSheetId(SPREADSHEET_EXAM_ID, SPREADSHEET_EXAM_LIST_NAME);

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
            Sheets::getService()->spreadsheets->batchUpdate(SPREADSHEET_EXAM_ID, $request);
        } catch (Exception $e) {
            exit("WRITE ERROR ". $e->getMessage());
        }
        return true;
    }

    public static function modify($cell_num, $info) {
        $service = Sheets::getService();
        $cat_id = intval($info['cat_id'] ?? 0);
        $vk_id = intval($info['vk_id'] ?? 0);
        $row = [
            "=IFNA(VLOOKUP(INDEX(" . SPREADSHEET_LIST_RANGE . "; ROW(); 2); ". SPREADSHEET_LIST_MEER . "; 4; FALSE); \"?\")",
            $cat_id,
            "=HYPERLINK(\"https://catwar.net/cat\"&index(" . SPREADSHEET_LIST_RANGE . "; ROW(); 2); IFNA(VLOOKUP(INDEX(" . SPREADSHEET_LIST_RANGE . "; ROW(); 2); ". SPREADSHEET_LIST_MEER . "; 2; FALSE); \"\"))",
            $info['nickname'] ?? "",
            $vk_id,
            "=HYPERLINK(\"https://vk.com/id$vk_id\";\"$info[vk_name]\")",
            $info['msg_id'] ?? "",
        ];
        $conf = ["valueInputOption" => "USER_ENTERED"];

        $range = SPREADSHEET_LIST_NAME . "!C" . ($cell_num + 1) . ":I" . ($cell_num + 1);
        try {
            $postBody = new Google_Service_Sheets_ValueRange([
                "values" => [$row]
            ]);
//            print_r('MODIFY');
//            print_r($row);
            $service->spreadsheets_values->update(SPREADSHEET_ID, $range, $postBody, $conf);
        } catch (Exception $e) {
            exit("MODIFYROW ERROR ". $e->getMessage());
        }
    }
    public static function remove($cellNums) {
        if (!is_array($cellNums)) {
            $cellNums = [$cellNums];
        }
        if (count($cellNums) < 1) {
            return;
        }
        $service = Sheets::getService();
        $sheetId = Sheets::getSheetId(SPREADSHEET_ID, SPREADSHEET_LIST_NAME, $service);
        $requests = [];
        $i = 0;
        foreach ($cellNums as $cellNum) {
            $requests[] = new Google_Service_Sheets_Request([
                'deleteDimension' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'dimension' => "ROWS",
                        'startIndex' => ($cellNum - $i),
                        'endIndex' => ($cellNum + 1 - $i)
                    ]
                ]
            ]);
            $i++;
        }
        $request = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => $requests,
        ]);
        try {
            $service->spreadsheets->batchUpdate(SPREADSHEET_ID, $request);
        } catch (Exception $e) {
            exit($e->getMessage());
        }
    }
    public static function getArray($conditions = []) {
        $service = Sheets::getService();
        $range = SPREADSHEET_LIST_NAME . "!" . SPREADSHEET_LIST_RANGE;
        $response = $service->spreadsheets_values->get(SPREADSHEET_ID, $range);
        $values = $response->getValues();
        $array = [];

        if (!empty($values)) {
            foreach ($values as $rowNum => $row) {
                $isOk = empty($conditions);
                foreach ($conditions as $key => $cond) {
                    if (is_array($cond)) {
                        $isCondOk = in_array($row[$key], $cond);
                    } elseif (is_callable($cond)) {
                        $isCondOk = $cond($row[$key]);
                    } else {
                        $isCondOk = $row[$key] == $cond;
                    }
                    $isOk = $isOk || $isCondOk;
                }
                if ($isOk) {
                    $array[$rowNum] = $row;
                }
            }
        }
        return $array;
    }
}