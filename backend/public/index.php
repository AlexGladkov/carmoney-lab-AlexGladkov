<?php

declare(strict_types=1);

use CarMoneyLab\AppFactory;

// Подключаем автозагрузчик Composer из корня проекта (классы CarMoneyLab\ по PSR-4).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Точка входа: собирает приложение Slim и запускает обработку HTTP-запроса.
AppFactory::create()->run();
