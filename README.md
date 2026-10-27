# Laravel Auto Swagger

[Документация на русском: новая схема разбора Request и Resource](README.ru.md)

Generate OpenAPI 3.0 documentation from registered Laravel routes and PHP attributes. Requests are inferred from FormRequest validation rules; responses use arrays, annotated resources, or Eloquent models.

Requires PHP 8.2 or newer and Laravel 12/13 (Laravel 13 requires PHP 8.3+). Laravel 10/11 are no longer supported; applications using those versions must upgrade Laravel before updating this package. Composer selects compatible stable dependencies for the application's PHP and Laravel versions. The checked-in lock file targets PHP 8.2 with Laravel 12, Symfony YAML 7.4 LTS, and PHPUnit 11; newer Laravel and PHPUnit versions are allowed on compatible PHP versions.

## Installation

```bash
composer require auto-swagger/php-swagger-generator
php artisan vendor:publish --tag=auto-swagger-config
# Optional: override the Swagger UI template.
php artisan vendor:publish --tag=auto-swagger-views
```

Laravel discovers the service provider automatically. Swagger UI loads its pinned assets from jsDelivr; no asset-publishing step is required.

## Generate documentation

```bash
php artisan swagger:generate
php artisan swagger:generate --format=yaml
```

The defaults are:

- UI: `/api/documentation`
- JSON: `/api/documentation/json`, stored at `public/swagger/openapi.json`
- YAML: `/api/documentation/yaml`, stored at `public/swagger/openapi.yaml`

`directory` is relative to `public_path()` unless it is an absolute path. The generator and documentation routes use the same location. The server URL defaults to `app.url`; route paths already include prefixes such as `/api`.

Supported command options:

```bash
php artisan swagger:generate \
  --title="Example API" \
  --description="Public API" \
  --api-version="2.0.0" \
  --base-url="https://example.com" \
  --output="storage/api/openapi.json" \
  --view-docs
```

`--output` accepts an absolute filename or a filename relative to the application root. It overrides the destination for that invocation; change `directory`/`output` in configuration if the HTTP routes should serve it. `--config=/path/to/config.php` loads a PHP file returning configuration overrides. `--bearer`, `--oauth2`, and `--api-key` enable their corresponding schemes.

Invalid routes or schemas cause generation to fail with a nonzero exit code and an explanation. The last successfully generated document is preserved.

## Document routes

Register routes in Laravel normally, including `apiResource`, `resource`, and invokable controllers. Only controller methods marked with `ApiSwagger` are documented. Closure routes are ignored.

```php
use AutoSwagger\Attributes\ApiSwagger;
use AutoSwagger\Attributes\ApiSwaggerQuery;
use AutoSwagger\Attributes\ApiSwaggerResponse;

#[ApiSwagger(
    summary: 'List users',
    description: 'Returns users matching the search criteria.',
    tag: 'Users',
    operationId: 'listUsers',
)]
#[ApiSwaggerQuery(name: 'search', description: 'Search by name')]
#[ApiSwaggerQuery(name: 'page', type: 'integer')]
#[ApiSwaggerResponse(resource: UserResource::class, isPagination: true)]
public function index() { /* ... */ }
```

`summary`, `description`, `tag`, `operationId`, `parameters`, `responses`, and `deprecated` are honored. Tags fall back to the route prefix or controller name. Explicit operation IDs must be unique; generated IDs include the path and HTTP method. Routes registered for multiple HTTP methods produce an operation for each method; implicit HEAD is omitted when GET exists.

Path parameters are inferred automatically from the route and method signature. To override a path parameter's description or type:

```php
#[ApiSwaggerQuery(name: 'id', description: 'User ID', isId: true, type: 'integer')]
```

Path parameters are always required. Query parameters default to optional strings. Supported query types are `string`, `integer`, `number`, `boolean`, `array`, and `object`.

## Requests

Typed FormRequest arguments are discovered automatically. `ApiSwaggerRequest` can override the request class, description, required flag, and media type; it can also be placed on a method parameter.

```php
use AutoSwagger\Attributes\ApiSwaggerRequest;

#[ApiSwagger(summary: 'Create user', tag: 'Users')]
#[ApiSwaggerRequest(request: StoreUserRequest::class, description: 'User data')]
#[ApiSwaggerResponse(status: 201, resource: UserResource::class)]
public function store(StoreUserRequest $request) { /* ... */ }
```

The generator reads `rules()` without calling `authorize()` or validating a request. Constructor and rule-method dependencies are resolved through Laravel's container. Nested dotted fields and wildcard arrays, primitive types, nullable fields, required/sometimes, size bounds, backed enums, formats, and file rules are supported. File fields select `multipart/form-data` automatically, unless an explicit media type overrides it. Typed FormRequest arguments add a default 422 response.

Rules are evaluated in the documentation-generation context. Rules that require a specific user, route binding, or live request data must account for that context. Conditional validation rules and arbitrary custom rules cannot always be represented exactly in OpenAPI.

## Responses and resources

Use an array of property definitions:

```php
#[ApiSwaggerResponse(status: 200, resource: [
    'id' => 'integer',
    'price' => 'number',
    'settings' => ['active' => 'boolean'],
    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
])]
#[ApiSwaggerResponse(status: 404, description: 'User not found')]
```

Or annotate a resource class:

```php
use AutoSwagger\Attributes\ApiSwaggerResource;
use Illuminate\Http\Resources\Json\JsonResource;

#[ApiSwaggerResource(name: 'User', properties: [
    'id' => 'integer',
    'name' => 'string',
    'email' => 'email',
])]
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email];
    }
}
```

`ApiProperty` on properties or methods adds descriptions, formats, examples, enum values, required/nullable flags, and collection item definitions. Avoid declaring resource properties that hide the wrapped model's fields; class-level `properties` is usually sufficient.

An Eloquent model class can also be passed as `resource`. Its schema is inferred from the model's own database connection, with supported scalar casts applied. This requires an available database; it describes the table/casts, not arbitrary transformations performed by an API resource. Schemas are emitted inline.

`ApiResponseException` adds a separate response in the same manner. Methods without explicit responses receive a default 200 response.

## Pagination

For the documented `data`/`pagination` shape, extend `PaginatedResource`:

```php
use AutoSwagger\Laravel\Resources\PaginatedResource;

class UserPaginatedResource extends PaginatedResource
{
    public function initCollection()
    {
        return $this->collection->map(fn ($user) => new UserResource($user));
    }
}
```

Pass a length-aware paginator to this resource and set `isPagination: true` on `ApiSwaggerResponse`. Pagination fields are `total`, `count`, `per_page`, `current_page`, and `total_pages`.

## Authentication and UI

Configure available schemes under `auth`. Enabled schemes appear in Swagger UI's **Authorize** dialog; they do not automatically secure every operation.

- `auth` and `auth:*` middleware add enabled Bearer/OAuth2 requirements.
- `api_key` middleware adds the enabled API key requirement.
- When both middleware types are present, both credentials are required.

The UI uses Laravel's configured title and the configured light/dark theme. Documentation route middleware is set independently under `route.middleware`.

To regenerate whenever the UI fetches the specification, or a JSON/YAML endpoint is requested:

```dotenv
SWAGGER_GENERATE_ALWAYS=true
```

## Development

```bash
composer install
composer test
composer check
composer audit
```

Tests cover route metadata, request/response schemas, model introspection, authentication, CLI options, atomic output, HTTP delivery, config caching, and OpenAPI validity. See `examples/` for Laravel controller/request/resource classes. `example/generate.php` demonstrates direct generator use after bootstrapping a Laravel application.

## Resource and request inference

Existing attributes and command options remain supported. With the defaults below, explicitly annotated resource schemas retain their previous shape. Resources without explicit property descriptions are inferred from PHP source. Resource constructors and `toArray()` are never invoked during inference.

```php
// config/auto-swagger.php
'analysis' => [
    'resource_inference' => 'fallback', // fallback | merge | disabled
    'use_references' => false,
    'response_wrapping' => 'legacy',   // legacy | laravel
    'unknown_types' => 'legacy',       // legacy | permissive | strict
    'request_mode' => 'runtime',       // runtime | static
    'diagnostics' => true,
    'rule_extensions' => [],
],
```

- `fallback` keeps explicitly described resources unchanged and infers resources without explicit properties. `merge` combines inferred fields with annotations; explicit annotations win. `disabled` switches automatic resource/response inference off.
- `use_references` registers reusable schemas under `components.schemas`. When reference mode is enabled, explicit `ApiSwaggerResource.name` values must be unique; inferred names include a stable class hash. Display names with spaces or punctuation are normalized into valid component names. Duplicate display names remain supported in the default inline mode. Inline output stays the default. Recursive resources use a reference at the cycle boundary even in inline mode, and their components are included automatically.
- `response_wrapping=laravel` models the top-level resource wrapper, `withoutWrapping()`, custom `$wrap`, resource collections, native full/simple/cursor pagination, `with()`, `additional()`, and statically readable `paginationInformation()`. Nested resources remain unwrapped. The default `legacy` preserves existing response envelopes. The existing `isPagination: true` attribute continues to describe the package's `data`/`pagination` shape.
- Unknown inferred values produce diagnostics. `legacy` retains the previous string fallback; `permissive` leaves the value's type unconstrained; `strict` fails generation. Diagnostics are available through `getDiagnostics()` and printed by the Artisan command. Turning off diagnostic display does not disable strict mode.
- `runtime` reads FormRequest rules through Laravel's container, as before. `static` reads supported literal rules/factories from PHP source without constructing the request or executing `rules()`. Data-dependent static requests need an explicit schema or runtime mode.

The original `AutoSwagger\Generator\OpenApiGenerator` and `AutoSwagger\Generator\SchemaGenerator` imports remain available as deprecated compatibility wrappers. `generateFromResource($class, $isCollection)` remains available.

### Nested resources

```php
#[ApiSwaggerResource(name: 'Order', model: Order::class)]
class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'customer' => new CustomerResource($this->customer),
            'items' => ItemResource::collection($this->whenLoaded('items')),
            'note' => $this->whenNotNull($this->note),
            $this->mergeWhen($request->user()?->is_admin, [
                'internal_comment' => $this->internal_comment,
            ]),
        ];
    }
}
```

The parser recognizes `new Resource`, `Resource::make()`, `Resource::collection()`, aliases/group imports, arrays, local assignments, conditional return branches, array spreads/merges, conditional resource helpers, and collection mappings. Conditional fields are optional; explicit null defaults are modeled separately from omission. Scalar types can come from PHP types, PHPDoc, backed enums, model casts, or a resource's model hint. `@mixin Order` is also supported. Model hints are read without opening a database connection; directly passing an Eloquent model as a response resource retains database introspection.

For an ambiguous value, annotate a named field without declaring a property that would hide the wrapped model's data:

```php
#[ApiProperty(type: 'number', name: 'total', example: 12.5)]
public function documentationMetadata() {}
```

Use `resource_inference=merge` to combine that override with the resource's inferred fields. Existing annotations also support resource class strings inside collection item definitions:

```php
#[ApiSwaggerResource(name: 'Order', properties: [
    'customer' => CustomerResource::class,
    'items' => ['type' => 'array', 'items' => ItemResource::class],
])]
```

Controller resource returns and resource collection returns can supply response schemas when an explicit response is absent. Explicit response descriptions/schemas retain priority. Per-response additions are optional:

```php
#[ApiSwaggerResponse(
    resource: ItemResource::class,
    isCollection: true,
    wrapping: 'laravel',
    pagination: 'laravel', // laravel | simple | cursor | legacy
)]
```

### Advanced validation rules

Request parsing normalizes rules before building the field tree. It supports allowed object keys (`array:name,age`), `list`, escaped dots, deep wildcard paths, regex patterns containing pipes, conditional required/excluded/prohibited fields, and statically readable `Rule::forEach()` callbacks.

Conditions use OpenAPI 3.0 composition (`allOf`/`anyOf`/`not`), with `x-laravel-conditions` retaining Laravel condition metadata. Exclusion means that Laravel may ignore a submitted value; an excluded field is not incorrectly documented as forbidden input. Dynamic `Rule::when()` branches are represented as alternatives; dynamic condition callbacks are not executed. PCRE constructs without a portable OpenAPI equivalent produce a diagnostic instead of an incorrect pattern.

A full schema override can bypass data-dependent request evaluation:

```php
#[ApiSwaggerRequest(schema: [
    'type' => 'object',
    'properties' => ['total' => ['type' => 'number']],
    'required' => ['total'],
])]
class StoreOrderRequest extends FormRequest
{
    // ...
}
```

The same `schema` argument can be placed on a controller method or request parameter. `ApiProperty(name: 'nested.field', ...)` on a request property/method overrides one field while keeping the remaining inferred request fields.

Custom rules can implement a small contract:

```php
use AutoSwagger\Contracts\SchemaAwareRule;

class MoneyRule implements SchemaAwareRule
{
    public function toOpenApiSchema(): array
    {
        return ['type' => 'number', 'minimum' => 0.01, 'multipleOf' => 0.01];
    }

    // Implement the appropriate Laravel validation rule contract as usual.
}
```

For third-party rules, implement `AutoSwagger\Contracts\RuleSchemaExtension` (`supports(object $rule)` and `schema(object $rule)`) and register the adapter class under `analysis.rule_extensions`. Use class names in published configuration so Laravel can cache it.

Static inference cannot precisely describe arbitrary PHP, database/user-dependent conditions, runtime-added macros, or custom serialization. It never executes resource methods or dynamic rule-condition/forEach callbacks to guess a schema. Use diagnostics and explicit overrides for those cases. PHPDoc and casts supply serialization hints, not proof of every possible runtime value.
