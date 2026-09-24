# Kilo hello

Готов.

1) Сервис: учебный проект предварительной оценки заявки на заём под ПТС — принимает заявку, считает LTV и возвращает решение `approve` / `review` / `reject` (README.md).
2) Makefile: `make up` (поднять сервис и базу), `make down`, `make ps`, `make logs`, `make install`, `make test` (PHPUnit), `make lint` (php -l), `make seed` (перезалить данные); docker-compose.yml — сервисы `backend` (PHP на порту 8080) и `db` (MySQL 8.0), команда запуска в compose — `php -S 0.0.0.0:8080 ... router.php`.
3) Решение approve / review / reject считается в `backend/src/Domain/` — `DecisionEngine.php` (пороги LTV) и `AssessmentService.php`.

модель: training-2026-09-glm-5.3
