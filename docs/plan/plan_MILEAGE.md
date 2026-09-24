# plan_MILEAGE: пробег больше 400 000 км понижает решение до review

Добавляем в предварительную оценку заявки правило: пробег авто не больше 400 000 км — решение не меняется; пробег больше 400 000 км — решение `approve` понижается до `review` (`reject` остаётся `reject`). Решение сейчас считается в `backend/src/Domain/AssessmentService.php::assess()` (строки 28–42): валидация (`ApplicationValidator::validate()`) → LTV (`LtvCalculator::calculate()`) → решение (`DecisionEngine::decide()`, строки 30–41) → `approved_limit` (строка 39). Порог выносим в `backend/config/rules.php`, секция `vehicle` (строки 20–24). Существующая жёсткая валидация `max_mileage_km` = 500 000 (`backend/src/Domain/ApplicationValidator.php`, строки 43–46) НЕ заменяется новой проверкой: пробег свыше 500 000 км по-прежнему даёт 422, а не решение.

## 1. Файлы

- `backend/config/rules.php` — в секцию `vehicle` (строки 20–24) после `max_mileage_km` (строка 23) добавляем ключ `'review_max_mileage_km' => 400000` с комментарием о семантике; сам `max_mileage_km` не трогаем.
- `backend/src/Domain/AssessmentService.php` — в конструктор (строки 16–22) добавляем первый параметр `private readonly array $rules`; в `assess()` (строки 28–42) между строкой 33 и формированием возврата понижаем `approve` → `review` по пробегу.
- `backend/src/AppFactory.php` — в `new AssessmentService(...)` (строки 30–39) первым аргументом передаём уже прочитанный `$rules` (строка 27).
- `tests/Unit/AssessmentServiceTest.php` — `setUp()` (строки 19–30) передаёт `$rules` в конструктор сервиса; хелпер `payload()` (строки 33–43) получает массив оверрайдов; добавляем 5 кейсов по пробегу.
- `tests/Unit/ApplicationValidatorTest.php` — добавляем 2 кейса: отсутствующий и null-пробег → `ValidationException` с ошибкой по полю `mileage` (закрепляем существующее поведение).

Всё, чего нет в этом списке, при реализации трогать нельзя.

## 2. Шаги

1. **`backend/config/rules.php`** — в секцию `vehicle` после строки 23 добавляем `'review_max_mileage_km' => 400000` и комментарий вида «Выше этого пробега решение понижается с approve до review». Ключ `max_mileage_km` (строка 23) и его комментарий остаются как есть: это два независимых порога — валидационный (422) и решенческий (review).
2. **`backend/src/Domain/AssessmentService.php`** — новый конструктор (параметр `$rules` первым, по образцу `ApplicationValidator`, строки 13–18; PHPDoc `@param array<string,mixed> $rules`):

   ```php
   public function __construct(
       private readonly array $rules,
       private readonly ApplicationValidator $validator,
       private readonly LtvCalculator $ltvCalculator,
       private readonly DecisionEngine $decisionEngine,
       private readonly VehicleAge $vehicleAge,
   ) {
   }
   ```

   В `assess()` сразу после строки 33 (`$decision = $this->decisionEngine->decide($ltv);`) и до формирования массива возврата (строки 35–41): если `$decision === DecisionEngine::APPROVE` и `$input['mileage'] > $this->rules['vehicle']['review_max_mileage_km']` — присвоить `$decision = DecisionEngine::REVIEW`. Порядок вычислений важен: `approved_limit` (строка 39) считается из уже пониженного `$decision`, поэтому при пробеге свыше 400 000 км лимит автоматически станет 0 — отдельная правка лимита не нужна. Понижение только с `approve`: `review` и `reject` не меняются.
3. **`backend/src/AppFactory.php`** — в `new AssessmentService(...)` (строки 30–39) первым аргументом передаём `$rules`; остальные аргументы и порядок зависимостей без изменений.
4. **`tests/Unit/AssessmentServiceTest.php`** — (а) в `setUp()` (строки 19–30) первым аргументом в `new AssessmentService(...)` передаём `$rules`; (б) хелпер `payload()` получает сигнатуру `payload(int $amount, int $marketValue, array $overrides = []): array` — базовый `mileage` остаётся 96000, оверрайды мержатся поверх базовых значений; (в) добавляем кейсы 1–5 из раздела «Тесты». LTV-пары как в существующих кейсах: 50 — 450 000/900 000, 75 — 675 000/900 000, 95 — 855 000/900 000.
5. **`tests/Unit/ApplicationValidatorTest.php`** — добавляем кейсы 6–7 из раздела «Тесты». Payload без ключа `mileage` собираем удалением ключа из результата `validPayload()` (оверрайды через `array_merge` ключ удалить не могут).
6. **Проверка** — `make test` и `make lint` зелёные; существующие кейсы LTV 50/75/95 (строки 45–71) не изменяются.

## 3. Тесты

Все кейсы — AAA, имя описывает поведение, тест заканчивается assert'ом; assert'ы через `assertSame` на `decision`/`approved_limit` (`ltv` — где уместно).

1. `AssessmentServiceTest::testApprovesWhenMileageBelowReviewThreshold` — пробег 399 999 км, LTV 50 → `approve`, `approved_limit` 450 000 (пробег в допуске, решение не меняется).
2. `AssessmentServiceTest::testApprovesWhenMileageAtReviewThreshold` — пробег 400 000 км, LTV 50 → `approve`, `approved_limit` 450 000 (граница «не больше 400 000» включительно).
3. `AssessmentServiceTest::testSendsHighMileageToReviewWithZeroLimit` — пробег 400 001 км, LTV 50 → `review`, `approved_limit` 0 (понижение approve → review; лимит от пониженного решения).
4. `AssessmentServiceTest::testKeepsRejectWhenMileageAboveReviewThreshold` — пробег 400 001 км, LTV 95 → `reject`, `approved_limit` 0 (пробег не «повышает» решение: reject остаётся reject).
5. `AssessmentServiceTest::testKeepsReviewWhenMileageAboveReviewThreshold` — пробег 400 001 км, LTV 75 → `review`, `approved_limit` 0 (review остаётся review).
6. `ApplicationValidatorTest::testRejectsMissingMileage` — ключ `mileage` отсутствует → `ValidationException`, в `errors()` есть ключ `mileage` с текстом «Пробег от 0 до 500000 км» (закрепляем существующее: `(int)($payload['mileage'] ?? -1)` → −1 → ошибка).
7. `ApplicationValidatorTest::testRejectsNullMileage` — `'mileage' => null` → `ValidationException`, в `errors()` тот же ключ `mileage` с тем же текстом (тот же путь кода: `null ?? -1` → −1).

Стиль кейсов 6–7 — try/catch `ValidationException` + assert на `errors()` как в `testRejectsAmountBelowMinimum` (строки 56–64), для текста ошибки — `assertSame`. LTV ровно 60.0 в новых тестах не используем (см. риски).

## 4. Риски

- **Интервал 400 001–500 000 км** — теперь даёт `review`, а свыше 500 000 км — по-прежнему 422. Категорически запрещено «упрощать» и заменять `max_mileage_km` на 400 000: это меняет семантику (422 вместо review), ломает текст ошибки `sprintf('Пробег от 0 до %d км', ...)` и существующее поведение API. `max_mileage_km` и `review_max_mileage_km` — независимые пороги, оба остаются в конфиге.
- **Изменение конструктора `AssessmentService`** ломает `AppFactory::create()` и `AssessmentServiceTest::setUp()`: без первого аргумента PHP бросает `ArgumentCountError`, приложение и тесты не стартуют. Поэтому шаги 2–4 выполняются одним согласованным изменением (один коммит) — не оставлять промежуточное состояние, где конструктор уже новый, а вызовы ещё старые.
- **`approved_limit` считается от уже пониженного решения** — понижение должно произойти до формирования массива возврата (до строки 39); иначе при пробеге > 400 000 км лимит останется равным запрошенной сумме. Контролируется кейсом 3 (400 001 → `review` + limit 0).
- **Существующие нюансы, которые НЕ чиним**: (а) строгое `<` в `DecisionEngine::decide()` (строка 32): LTV ровно 60.0 даёт `review`, хотя докблок обещает `<=` — новые тесты на этом значении не строятся (используем LTV 50/75/95); (б) каст `(int)""` → 0 в валидаторе (строка 43): присланная напрямую пустая строка проходит как валидный пробег 0, фронтенд шлёт пустое поле как `Number('')` = 0 (`frontend/app.js:8-14`) — поведение сохраняем, новых тестов на пустой строке не строим.
- **Seed/БД/фронтенд не затрагиваются**: максимальный пробег в `db/seed.sql` — 296 000, все сид-заявки ниже порога и своих решений не меняют; `db/schema.sql:22` (`mileage_km INT UNSIGNED NOT NULL`) — без изменений; форматы ответов API не меняются, поэтому `tests/Feature/` остаётся пустым.
- Отклонённая альтернатива (одной строкой): вынос проверки в отдельный класс-правило или в `DecisionEngine::decide()` — отклонён как избыточный для одного условия; достаточно конфиг-ключа и одной проверки в `AssessmentService`.

### Что не входит

- `max_mileage_km` (500 000) и тексты ошибок валидации — без изменений.
- Фронтенд (`frontend/`) — не трогаем.
- `db/schema.sql`, `db/seed.sql` — не трогаем.
- Форматы ответов API (никаких новых полей вроде `reason: high_mileage` — см. вопрос 5).
- Feature-тесты HTTP (`tests/Feature/`).
- Фикс `<` → `<=` в `DecisionEngine` — отдельная задача, не здесь.
- Лимит суммы по `rules['ltv_by_age']` — задача LOAN-12.

## Вопросы заказчику

1. Граница 400 000 — включительно: `approve` при ровно 400 000? (мы предположили «не больше» = `<=` 400 000 → approve).
2. Пробег > 400 000 при LTV-решении `reject` — оставлять `reject` или понижать до `review`? (мы предположили: reject остаётся reject).
3. Пустой/отсутствующий пробег — оставить текущее поведение (422, ошибка валидации) или трактовать как «нет данных → review»?
4. Остаётся ли валидационный потолок 500 000 км без изменений (т.е. > 500 000 — по-прежнему 422, а не решение)?
5. Нужно ли показывать причину понижения (например, `reason: high_mileage`) в ответе API или в БД, или решение меняется без объяснений?