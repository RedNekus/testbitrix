<?php
require_once __DIR__ . '/vendor/autoload.php';

if (!file_exists(__DIR__ . '/.env')) {
    http_response_code(500);
    exit('Ошибка: отсутствует файл .env. Скопируйте .env.example в .env и заполните параметры.');
}

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');
$twig = new \Twig\Environment($loader, [
    'cache' => false, // Отключите кэш на продакшене или укажите путь
]);

echo $twig->render('main.twig');