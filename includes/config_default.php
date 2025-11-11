<?
// В этой же подпапке должен быть создан config.php со всеми данными ниже и всеми глобальными переменными которые есть или будут
// Ключ доступа сообщества
if (!defined('VK_API_ACCESS_TOKEN')) define('VK_API_ACCESS_TOKEN', 'vk1.a...');
if (!defined('CALLBACK_API_CONFIRMATION_TOKEN')) define('CALLBACK_API_CONFIRMATION_TOKEN', 'test');  // Строка, которую должен вернуть сервер

// Адрес обращения к API
if (!defined('VK_API_ENDPOINT')) define('VK_API_ENDPOINT', 'https://api.vk.com/method/');
// Используемая версия API
if (!defined('VK_API_VERSION')) define('VK_API_VERSION', '5.199');

// Айди бесед и пользователей
if (!defined('PEER_MOM')) define('PEER_MOM', 1); // ID админа
if (!defined('PEER_TEST')) define('PEER_TEST', 2000000001);
// Можно добавлять здесь остальные PEER_ по желанию

// Пароли бд
if (!defined('DB_CONFIG')) define('DB_CONFIG', [
    "user" => "root",
    "pass" => "",
    "db" => "dbname",
    "host" => "localhost"
]);