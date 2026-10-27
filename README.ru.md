# Laravel Auto Swagger: документация на русском

[English documentation](README.md)

Пакет строит OpenAPI 3.0 по зарегистрированным маршрутам Laravel, правилам FormRequest и коду ресурсов. В новой схеме поля Request и Resource не нужно повторно описывать атрибутами: генератор читает `rules()` и статически разбирает `toArray()`.

**На методе контроллера по-прежнему нужен `#[ApiSwagger]`: он включает маршрут в документацию.** Атрибуты в Request и Resource необязательны и служат для уточнения схемы.

## Содержание

- [Установка и требования](#установка-и-требования)
- [Быстрый старт без атрибутов в Request и Resource](#быстрый-старт-без-атрибутов-в-request-и-resource)
- [Настройка новой схемы](#настройка-новой-схемы)
- [Разбор Request](#разбор-request)
- [Разбор Resource и вложенных ресурсов](#разбор-resource-и-вложенных-ресурсов)
- [Коллекции, обёртки и пагинация](#коллекции-обёртки-и-пагинация)
- [Явные описания и переопределения](#явные-описания-и-переопределения)
- [Собственные правила валидации](#собственные-правила-валидации)
- [Маршруты и авторизация](#маршруты-и-авторизация)
- [Генерация и размещение файлов](#генерация-и-размещение-файлов)
- [Диагностика и ограничения](#диагностика-и-ограничения)
- [Совместимость и переход](#совместимость-и-переход)
- [Проверка изменений](#проверка-изменений)

## Установка и требования

Поддерживаются PHP 8.2+ и Laravel 12/13. Для Laravel 13 требуется PHP 8.3+. Требования к версиям смотрите в [composer.json](composer.json). Laravel 10/11 не входят в текущую поддерживаемую матрицу.

```bash
composer require auto-swagger/php-swagger-generator
php artisan vendor:publish --tag=auto-swagger-config
```

Laravel автоматически обнаруживает провайдер пакета. При необходимости можно опубликовать шаблон Swagger UI:

```bash
php artisan vendor:publish --tag=auto-swagger-views
```

Swagger UI загружает закреплённую версию CSS/JS с jsDelivr. Отдельной команды публикации ассетов нет.

## Быстрый старт без атрибутов в Request и Resource

Пример описывает обновление пользователя. Контроллер, запрос и ресурс — обычные классы приложения; специальный атрибут нужен только контроллеру.

### 1. FormRequest

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // В приложении здесь может быть проверка политики доступа.
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
        ];
    }
}
```

Генератор определит поля, их типы и обязательность. Для типизированного аргумента FormRequest также добавляется стандартный ответ 422, если он не описан явно.

### 2. Resource

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
```

`@mixin` — необязательная PHPDoc-подсказка о модели, а не PHP-атрибут. Типы полей модели можно описать в PHPDoc самой модели или в её casts. Например, PHPDoc модели может содержать `@property string $name` и `@property string $email`. **Подсказка о модели не означает автоматического чтения всех колонок БД:** если тип поля определить нельзя, генератор выдаст диагностику.

### 3. Контроллер

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use AutoSwagger\Attributes\ApiSwagger;

class UserController extends Controller
{
    #[ApiSwagger(summary: 'Обновить пользователя', tag: 'Пользователи')]
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->validated());

        return new UserResource($user);
    }
}
```

Тип аргумента связывает метод с FormRequest. Ресурс ответа определяется по возвращаемому типу и поддерживаемым конструкциям в коде метода. `ApiSwaggerRequest` и `ApiSwaggerResponse` для этого примера не нужны.

### 4. Маршрут и генерация

```php
<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// В routes/api.php; префикс /api зависит от настройки приложения.
Route::put('/users/{user}', [UserController::class, 'update']);
```

Включите настройки из следующего раздела, затем выполните:

```bash
php artisan swagger:generate
```

Swagger UI по умолчанию доступен по адресу `/api/documentation`. В режиме `response_wrapping=laravel` одиночный ресурс будет описан с обёрткой `data`, соответствующей стандартному ответу Laravel.

Автоматически выведенный успешный ответ получает статус **200**. Статус 201, дополнительные ошибки и другие особенности ответа следует описывать через `ApiSwaggerResponse` — генератор не определяет произвольные runtime-статусы.

## Настройка новой схемы

Для нового API можно настроить `config/auto-swagger.php` следующим образом. Замените или дополните секцию `analysis` в опубликованном конфигурационном файле:

```php
<?php

return [
    // Остальные настройки пакета остаются в этом же массиве.
    'analysis' => [
        'resource_inference' => 'fallback',
        'use_references' => true,
        'response_wrapping' => 'laravel',
        'unknown_types' => 'legacy',
        'request_mode' => 'runtime',
        'diagnostics' => true,
        'rule_extensions' => [],
    ],
];
```

Это пример секции, а не замена всего опубликованного конфига: сохраните настройки `title`, `route`, `output`, `directory`, `auth` и остальные используемые параметры.

| Настройка | По умолчанию | Поведение |
| --- | --- | --- |
| `resource_inference` | `fallback` | `fallback` сохраняет явные описания свойств и автоматически разбирает ресурсы без них; `merge` объединяет авторазбор с атрибутами; `disabled` отключает автоматический разбор ресурсов и вывод схем ответов из контроллера. |
| `use_references` | `false` | При `true` ресурсы переиспользуются через `$ref` и `components.schemas`; при `false` обычные схемы раскрываются inline. |
| `response_wrapping` | `legacy` | `legacy` сохраняет прежнее описание ответов; `laravel` учитывает обёртки, метаданные и стандартную пагинацию Laravel. |
| `unknown_types` | `legacy` | `legacy` использует прежний запасной тип `string`; `permissive` не ограничивает неизвестный тип; `strict` завершает генерацию ошибкой. |
| `request_mode` | `runtime` | `runtime` получает правила через контейнер Laravel; `static` разбирает поддерживаемые правила из PHP-кода. |
| `diagnostics` | `true` | Показывает предупреждения в Artisan. Отключение вывода не отключает строгий режим. |
| `rule_extensions` | `[]` | Классы адаптеров для сторонних правил валидации. |

Настройки меняют **документацию**, а не поведение ваших HTTP-ответов или валидации Laravel.

## Разбор Request

### Обычные и вложенные поля

Можно использовать строковые правила и массивы правил. Например, метод FormRequest может возвращать:

```php
<?php

// Пример массива, возвращаемого из rules().
return [
    'profile' => ['array:name,age'],
    'profile.name' => ['string', 'max:255'],
    'profile.age' => ['integer', 'min:0'],
    'orders' => ['required', 'list'],
    'orders.*.items' => ['required', 'list'],
    'orders.*.items.*.product_id' => ['required', 'integer'],
    'orders.*.items.*.quantity' => ['required', 'integer', 'min:1'],
    'tags' => ['array'],
    'tags.*' => ['string'],
    'comment' => ['sometimes', 'nullable', 'string'],
    'code' => ['string', 'regex:/^(foo|bar)$/'],
    'v1\.0' => ['string'],
];
```

- Точечные пути образуют вложенные объекты, а `*` описывает элементы массива.
- `array:name,age` описывает объект с разрешёнными ключами; `list` — список.
- `sometimes` не делает поле безусловно обязательным.
- `nullable` и возможность отсутствия поля учитываются отдельно.
- Экранированная точка в `v1\.0` означает имя поля `v1.0`.
- Regex с `|` удобнее задавать отдельным элементом массива правил, как в примере. Непереносимые PCRE-возможности не подменяются неверным OpenAPI-pattern: для них выдаётся диагностика.

Поддерживаются основные типы, числовые и размерные ограничения, форматы, значения `in`/`not_in`, backed enum и поддерживаемые объектные правила Laravel. Не каждый произвольный validator можно точно выразить средствами OpenAPI 3.0.

### Условные правила

Разбираются семейства `required_if`, `required_unless`, `required_with`, `required_without` и их варианты `_all`, а также поддерживаемые условные правила `exclude_*` и `prohibited_*`.

```php
<?php

return [
    'kind' => ['string'],
    'value' => ['required', 'integer', 'exclude_if:kind,draft'],
    'secret' => ['string', 'prohibited_if:kind,public'],
    'items' => ['array'],
    'items.*.type' => ['string'],
    'items.*.code' => ['string', 'required_if:items.*.type,digital'],
];
```

Условия преобразуются в композиции `allOf`, `anyOf` и `not`. Исходная информация об условиях сохраняется в `x-laravel-conditions`.

`exclude_if` означает, что Laravel может исключить отправленное поле из валидированных данных. Это **не запрет отправлять поле**; описание запроса учитывает это различие.

У динамического `Rule::when()` могут быть выведены альтернативные схемы веток. Callback условия не вызывается для выбора одной ветки. Если связь с данными запроса нельзя установить статически, выдаётся диагностика.

### Rule::forEach()

Для callback с поддерживаемым статическим результатом правила элементов можно прочитать без его выполнения:

```php
<?php

use Illuminate\Validation\Rule;

return [
    'ids' => ['array'],
    'ids.*' => Rule::forEach(fn ($value, $attribute) => ['integer', 'min:1']),
];
```

Также поддерживается статически читаемый результат с правилами вложенных полей. Если callback зависит от значения элемента, обращается к сервисам или имеет неподдерживаемую структуру, нужен адаптер или явное описание.

### Файлы

```php
<?php

return [
    'title' => ['required', 'string'],
    'image' => ['nullable', 'image', 'max:2048'],
    'attachments' => ['array'],
    'attachments.*' => ['file'],
];
```

Файловые поля получают `type: string`, `format: binary`. При их обнаружении тело запроса по умолчанию описывается как `multipart/form-data`. Явный `mediaType` в `ApiSwaggerRequest` имеет приоритет.

### Runtime и static

В режиме `runtime` контейнер создаёт FormRequest и вызывает `rules()` с разрешением зависимостей. Генератор не вызывает `authorize()` и не запускает валидацию запроса. **Конструктор и сам `rules()` при этом выполняются** — учитывайте их зависимости от пользователя, маршрута, БД или внешних сервисов.

В режиме `static` не создаётся экземпляр запроса и не вызывается `rules()`. Поддерживаются статически читаемые массивы, константы и предусмотренные парсером фабрики правил. Если извлечь правила нельзя, генерация завершится ошибкой с предложением перейти на runtime или задать схему явно.

## Разбор Resource и вложенных ресурсов

### Откуда берутся типы

Парсер читает PHP-код, а не выполняет `toArray()`. Для определения типов используются:

- явные описания `ApiProperty` и `ApiSwaggerResource`;
- поддерживаемые выражения и значения в `toArray()`;
- PHP-типы и PHPDoc, включая поддерживаемые generics и array shapes;
- casts и PHPDoc модели, указанной через `@mixin` или `ApiSwaggerResource(model: ...)`.

Модель не определяется автоматически только по совпадению имён `UserResource` и `User`. Для неочевидных полей добавьте подсказку о модели либо явный тип.

Подсказки модели читаются без соединения с БД. Если же передать **сам класс Eloquent-модели** в `ApiSwaggerResponse(resource: Model::class)`, используется прежний разбор таблицы через соединение модели; для него БД должна быть доступна.

### Вложенные объекты и коллекции без атрибутов

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'customer' => new CustomerResource($this->customer),
            'items' => ItemResource::collection($this->whenLoaded('items')),
            'note' => $this->whenNotNull($this->note),
            'nickname' => $this->when($request->user(), 'Покупатель', null),
            $this->mergeWhen($request->user()?->is_admin, [
                'internal_comment' => $this->internal_comment,
            ]),
        ];
    }
}
```

`CustomerResource` и `ItemResource` — ресурсы вашего приложения. Их `toArray()` разбираются рекурсивно.

В этом примере:

- `customer` — объект по схеме `CustomerResource`;
- `items` — массив элементов `ItemResource`, который может отсутствовать, если связь не загружена;
- `note` может отсутствовать, когда значение равно null;
- у `nickname` есть явное значение по умолчанию null, поэтому отсутствие и nullable не смешиваются;
- поля из условного `mergeWhen()` не объявляются безусловно обязательными.

Поддерживаются `new Resource`, `Resource::make()`, `Resource::collection()`, алиасы и групповые импорты, локальные присваивания, поддерживаемые ветки возврата, объединения/распаковки массивов, `when*`-помощники и поддерживаемые преобразования коллекций.

### Переиспользование и циклы

При `use_references=true` ресурсы регистрируются в `components.schemas`, а вложенные поля ссылаются на них через `$ref`. Без атрибута имя компонента строится из имени класса и стабильного хеша его полного имени.

Если нужно управлять именем компонента, атрибут остаётся доступен: `#[ApiSwaggerResource(name: 'Order')]`. В режиме ссылок явные имена должны быть уникальными. Пробелы и неподходящие символы нормализуются. В обычном inline-режиме прежние повторяющиеся отображаемые имена допускаются.

Рекурсивные структуры, например `CategoryResource → children → CategoryResource`, поддерживаются. Схема регистрируется до разбора вложенных полей. Даже при `use_references=false` на границе цикла появляется `$ref`, а необходимый компонент включается в документ.

## Коллекции, обёртки и пагинация

### Стандартные ответы Laravel

При `response_wrapping=laravel` учитываются:

- стандартная верхнеуровневая обёртка `data`;
- `JsonResource::withoutWrapping()` и пользовательский `$wrap`;
- `Resource::collection()`, `ResourceCollection`, `$collects` и поддерживаемое соглашение об имени коллекции;
- метаданные из статически читаемых `with()` и `additional()`;
- полная, простая и курсорная пагинация;
- статически читаемый `paginationInformation()`, включая использование `$default`.

Вложенный ресурс не получает дополнительную верхнеуровневую обёртку. У анонимной коллекции Laravel правила обёртки определяются коллекцией, а не `$wrap` отдельного элемента.

Для стандартной пагинации можно вернуть из контроллера:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use AutoSwagger\Attributes\ApiSwagger;

class UserListController extends Controller
{
    #[ApiSwagger(summary: 'Список пользователей', tag: 'Пользователи')]
    public function index()
    {
        return UserResource::collection(User::query()->paginate(20));
    }
}
```

При включённых Laravel-обёртках ответ описывается через `data`, `links` и `meta`. Для `simplePaginate()` и `cursorPaginate()` используются соответствующие наборы метаданных. Нестандартные цепочки вызовов, преобразующие ответ, могут потребовать явного уточнения.

### Прежняя пагинация пакета

Существующий `PaginatedResource` и `ApiSwaggerResponse(isPagination: true)` продолжают описывать прежнюю форму `data`/`pagination`, где `pagination` содержит `total`, `count`, `per_page`, `current_page`, `total_pages`.

Это отдельный формат. Не заменяйте его на стандартные `links`/`meta`, если реальные ответы вашего API используют прежнюю форму.

## Явные описания и переопределения

### Уточнить одно поле Resource

Чтобы не скрывать данные модели реальным свойством ресурса, можно использовать отдельный метод с `ApiProperty(name: ...)`:

```php
<?php

namespace App\Http\Resources;

use AutoSwagger\Attributes\ApiProperty;
use Illuminate\Http\Resources\Json\JsonResource;

class TotalResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['total' => $this->resource->calculateTotal()];
    }

    #[ApiProperty(type: 'number', name: 'total', required: true, example: 12.5)]
    public function documentationMetadata() {}
}
```

Включите `resource_inference=merge`, чтобы совместить переопределения с автоматически выведенными полями. Явное описание имеет приоритет. `required` и `nullable` в таком описании задавайте осознанно.

В FormRequest такой же `ApiProperty` на свойстве или методе уточняет поле запроса. Для вложенного поля можно использовать `name: 'items.*.price'`.

### Полностью описать запрос

Полный `schema` можно задать на классе FormRequest, аргументе запроса или методе контроллера. На классе это позволяет обойти выполнение зависимого от контекста `rules()`:

```php
<?php

namespace App\Http\Requests;

use AutoSwagger\Attributes\ApiSwaggerRequest;
use Illuminate\Foundation\Http\FormRequest;

#[ApiSwaggerRequest(schema: [
    'type' => 'object',
    'properties' => ['total' => ['type' => 'number']],
    'required' => ['total'],
])]
class StoreTotalRequest extends FormRequest
{
    public function rules(): array
    {
        return ['total' => ['required', 'numeric']];
    }
}
```

Схема влияет только на документацию. Laravel продолжит использовать правила запроса при обработке реальных HTTP-запросов.

### Явные ответы и метаданные маршрута

`ApiSwaggerResponse` сохраняет аргументы `status`, `resource`, `description`, `mediaType`, `isPagination`. Дополнительно доступны `isCollection`, `wrapping` (`legacy`/`laravel`) и `pagination` (`legacy`/`laravel`/`simple`/`cursor`).

Для создания сущности можно явно указать статус 201 и ресурс. Для метода, где заданы явные ответы, автоматический разбор не подменяет их. Следовательно, описывайте нужные успешные и ошибочные ответы явно, если используете этот способ.

`ApiSwaggerResource(properties: ...)` по-прежнему поддерживает описания полей массивом. Классы вложенных ресурсов можно указывать как значение свойства и внутри `items`. `ApiResponseException` также остаётся доступным для отдельных ответов.

## Собственные правила валидации

Если правило нельзя вывести автоматически, оно может реализовать `SchemaAwareRule` вместе с обычным контрактом Laravel:

```php
<?php

namespace App\Rules;

use AutoSwagger\Contracts\SchemaAwareRule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PercentageRule implements ValidationRule, SchemaAwareRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || $value < 0 || $value > 100) {
            $fail('Значение должно быть числом от 0 до 100.');
        }
    }

    public function toOpenApiSchema(): array
    {
        return ['type' => 'number', 'minimum' => 0, 'maximum' => 100];
    }
}
```

При генерации используется `toOpenApiSchema()`, а не `validate()`. Описанная схема должна соответствовать реальным ограничениям правила.

Для стороннего класса реализуйте адаптер [RuleSchemaExtension](src/Contracts/RuleSchemaExtension.php) с методами `supports(object $rule): bool` и `schema(object $rule): array`. Зарегистрируйте класс адаптера в `analysis.rule_extensions`. В опубликованном конфиге используйте имена классов, чтобы работал `config:cache`.

## Маршруты и авторизация

- Документируются зарегистрированные методы контроллеров с `ApiSwagger`, включая invokable-контроллеры. Closure-маршруты пропускаются.
- Поддерживаются обычные маршруты, `resource` и `apiResource`.
- Path-параметры определяются по маршруту и сигнатуре метода; в OpenAPI они всегда обязательны.
- Query-параметры можно описывать повторяемым `ApiSwaggerQuery`. По умолчанию они необязательны и имеют тип `string`.
- Явный `operationId` должен быть уникальным. Автоматический идентификатор учитывает метод и путь маршрута.
- Для нескольких HTTP-методов создаются отдельные операции; неявный HEAD при наличии GET пропускается.

Включённая схема в `auth` делает её доступной в Swagger UI, но не назначает её всем операциям:

| Middleware | Требование в документации |
| --- | --- |
| `auth`, `auth:*` | Включённые Bearer/OAuth2-схемы. |
| `api_key` | Включённая API key-схема. |
| Оба вида | Требуются оба вида учётных данных. |

Доступ к самим страницам документации настраивается отдельно в `route.middleware`. Документация не меняет правила доступа приложения.

## Генерация и размещение файлов

```bash
php artisan swagger:generate
php artisan swagger:generate --format=yaml
```

| Назначение | По умолчанию |
| --- | --- |
| Swagger UI | `/api/documentation` |
| JSON | `/api/documentation/json` → `public/swagger/openapi.json` |
| YAML | `/api/documentation/yaml` → `public/swagger/openapi.yaml` |

Относительный `directory` разрешается от `public_path()`. Абсолютный путь также поддерживается. Команда и HTTP-контроллер читают одну и ту же директорию. URL сервера по умолчанию берётся из `app.url`; не добавляйте второй `/api`, если он уже входит в пути маршрутов.

Доступны следующие параметры:

```bash
php artisan swagger:generate \
  --title="API приложения" \
  --description="Описание методов" \
  --api-version="2.0.0" \
  --base-url="https://example.com" \
  --output="storage/api/openapi.json" \
  --view-docs
```

`--output` принимает абсолютное имя файла или путь от корня приложения. Это разовое изменение места записи; для выдачи файла HTTP-маршрутами измените также постоянные настройки `directory`/`output`.

`--config=/path/to/config.php` загружает PHP-файл, возвращающий массив переопределений. Флаги `--bearer`, `--oauth2`, `--api-key` включают соответствующие схемы.

Для генерации при получении спецификации интерфейсом либо прямом запросе JSON/YAML можно включить:

```dotenv
SWAGGER_GENERATE_ALWAYS=true
```

## Диагностика и ограничения

Если тип определить нельзя, диагностика содержит контекст поля и, когда доступно, файл/строку. Она выводится командой и доступна через `OpenApiGenerator::getDiagnostics()`.

| Режим | Результат для неизвестного значения |
| --- | --- |
| `legacy` | Сохраняется запасной тип `string`, выдаётся диагностика. |
| `permissive` | Неизвестный тип не ограничивается, выдаётся диагностика. |
| `strict` | Генерация завершается ошибкой. |

После уточнения известных неоднозначностей можно включить `unknown_types=strict` в проверках проекта. В случае ошибки команда возвращает ненулевой код и сохраняет последний успешно созданный документ.

Границы автоматического разбора:

- Конструкторы ресурсов, `toArray()`, `with()` и другие ресурсные методы не выполняются для угадывания ответа.
- Динамические callback условий и `Rule::forEach()` не запускаются для выбора результата.
- Runtime-режим запроса выполняет его конструктор и `rules()`; static-режим имеет ограниченный набор читаемых конструкций.
- Произвольные вызовы сервисов, runtime-макросы, сериализация и условия, зависящие от БД или пользователя, не всегда выводятся автоматически.
- PHPDoc и casts помогают определить тип, но не доказывают все возможные значения ответа.
- Описание таблицы модели не заменяет анализ преобразований Resource. В частности, enum-тип или формат поля может нуждаться в уточнении.

Для таких случаев используйте точечные атрибуты, полный `schema`, `SchemaAwareRule` или адаптер. Не отключайте диагностику только ради получения внешне полной схемы.

## Совместимость и переход

Для существующего приложения:

1. Сначала оставьте `resource_inference=fallback`, `use_references=false`, `response_wrapping=legacy`.
2. Сгенерируйте документ и проверьте диагностику. Явно описанные свойства ресурсов сохранят прежний формат.
3. Для новых ресурсов используйте обычный `toArray()` и типизированные FormRequest без обязательного дублирования атрибутами.
4. Если нужны авторазбор и точечные атрибуты в одном ресурсе, включите `resource_inference=merge`.
5. Отдельно включите `$ref` и Laravel-обёртки, сравнив схему с реальными ответами вашего API.
6. Для прежнего `PaginatedResource` сохраните `isPagination: true` и соответствующую форму ответа.

Старые аргументы атрибутов и команды сохранены. Новые аргументы добавлены в конец конструкторов. Доступны устаревающие совместимые импорты `AutoSwagger\Generator\OpenApiGenerator` и `AutoSwagger\Generator\SchemaGenerator`, включая `generateFromResource($class, $isCollection)`.

Совместимость формата не означает поддержку Laravel 10/11: текущие зависимости ориентированы на Laravel 12/13.

## Проверка изменений

```bash
composer install
composer test
composer check
composer audit
```

Тесты проверяют прежние схемы, новые варианты разбора, совпадение реальных ответов Laravel со схемами, циклы, JSON/YAML и валидность OpenAPI. Матрица PHP/Laravel задана в [CI](.github/workflows/tests.yml). Установка без dev-зависимостей поддерживается: AST-парсер входит в runtime-зависимости.

Реализация: [разбор ресурсов](src/Analyzer/ResourceSchemaGenerator.php), [разбор запросов](src/Analyzer/SchemaGenerator.php), [настройки анализа](src/Analysis/Options.php).
