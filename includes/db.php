<?php
require_once __DIR__ . '/config.php';
class DB {
    private static $instance;
    private $db;
    private function __construct() {
        $this->db = new mysqli(DB_CONFIG["host"], DB_CONFIG["user"], DB_CONFIG["pass"], DB_CONFIG["db"]);
        (!$this->db->connect_errno) or exit('Ошибка подключения.');
        $this->db->set_charset('utf8') or exit('Ошибка кодировки.');
    }

    public function __destruct() {
        if (self::$instance->db) {
            self::$instance->db->close();
        }
    }

    private static function get() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance->db;
    }

    public static function escape($text) {
        return DB::get()->real_escape_string($text);
    }


    public static function q($sql) {
        return DB::get()->query($sql);
    }

    public static function getRow($sql, $default = null) {
        $result = DB::q($sql);
        if (!$result) {
            return false;
        }
        if (DB::numRows($result) < 1) {
            return $default;
        }
        return DB::fetch($result);
    }

    public static function getValArray($sql) {
        $array = [];
        $result = DB::q($sql);
        if (!$result) {
            return [];
        }
        while (list($value) = DB::fetch($result)) {
            $array[] = $value;
        }
        return $array;
    }

    public static function getVal($sql, $default = null) {
        $val = DB::getRow($sql, $default);
        if ($val === null || $val === $default) {
            return $val;
        }
        return $val[0];
    }

    public static function numRows($result) {
        return $result->num_rows;
    }

    public static function fetch($result) {
        return $result->fetch_array();
    }

    public static function affectedRows() {
        return DB::get()->affected_rows;
    }
}