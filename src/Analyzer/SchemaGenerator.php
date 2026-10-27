<?php

namespace AutoSwagger\Analyzer;

use AutoSwagger\Analysis\Ast\SourceReader;
use AutoSwagger\Analysis\Diagnostics;
use AutoSwagger\Analysis\Options;
use AutoSwagger\Analysis\Rules\ConditionalSchemaBuilder;
use AutoSwagger\Analysis\Rules\FieldPath;
use AutoSwagger\Analysis\Rules\RuleNormalizer;
use AutoSwagger\Analysis\Rules\StaticRuleExtractor;
use AutoSwagger\Analysis\Schema;
use AutoSwagger\Attributes\ApiProperty;
use AutoSwagger\Attributes\ApiSwaggerRequest;
use AutoSwagger\Contracts\RuleSchemaExtension;
use AutoSwagger\Contracts\SchemaAwareRule;
use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use ReflectionProperty;

class SchemaGenerator
{
    private Options $options;

    private Diagnostics $diagnostics;

    private SourceReader $source;

    private RuleNormalizer $normalizer;

    private array $extensions = [];

    public function __construct(array|Options $options = [], ?Diagnostics $diagnostics = null, ?SourceReader $source = null)
    {
        $this->options = $options instanceof Options ? $options : new Options($options);
        $this->diagnostics = $diagnostics ?? new Diagnostics($this->options);
        $this->source = $source ?? new SourceReader;
        $this->normalizer = new RuleNormalizer($this->diagnostics, $this->source);
        foreach ($this->options->get('rule_extensions') as $extension) {
            $extension = is_string($extension) ? Container::getInstance()->make($extension) : $extension;
            if (! $extension instanceof RuleSchemaExtension) {
                throw new \InvalidArgumentException('Rule extensions must implement RuleSchemaExtension.');
            }
            $this->extensions[] = $extension;
        }
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics->all();
    }

    public function generateFromResource(string $resourceClass, bool $isCollection = false): array
    {
        if (! is_subclass_of($resourceClass, JsonResource::class)) {
            return [];
        }
        $schema = (new ResourceSchemaGenerator($this->options, $this->diagnostics, $this->source))->generate($resourceClass, false);

        return $isCollection ? ['type' => 'array', 'items' => $schema] : $schema;
    }

    public function generateFromFormRequest(string $requestClass): array
    {
        $reflection = new \ReflectionClass($requestClass);
        $metadata = ($reflection->getAttributes(ApiSwaggerRequest::class)[0] ?? null)?->newInstance();
        if ($metadata?->schema !== null) {
            return $metadata->schema;
        }
        if (! $reflection->hasMethod('rules')) {
            throw new \InvalidArgumentException("{$requestClass} does not define rules().");
        }
        if ($this->options->get('request_mode') === 'static') {
            $rules = (new StaticRuleExtractor($this->source))->method($reflection->getMethod('rules'));
            if ($rules === null) {
                throw new \RuntimeException("{$requestClass}::rules() is data-dependent; use runtime mode or an explicit ApiSwaggerRequest schema.");
            }
        } else {
            $container = Container::getInstance();
            // Bypass FormRequest's after-resolving validation/authorization hooks.
            $instance = $container->build($requestClass);
            if ($instance instanceof FormRequest) {
                if ($container->bound('request')) {
                    FormRequest::createFrom($container->make('request'), $instance);
                }
                $instance->setContainer($container);
                if ($container->bound('redirect')) {
                    $instance->setRedirector($container->make('redirect'));
                }
            }
            $rules = $container->call([$instance, 'rules']);
        }
        $overrides = [];
        foreach ([...$reflection->getProperties(), ...$reflection->getMethods()] as $member) {
            $property = ($member->getAttributes(ApiProperty::class)[0] ?? null)?->newInstance();
            if ($property === null) {
                continue;
            }
            $name = $property->name ?? $member->getName();
            $definition = ['type' => match ($property->type) {
                'int' => 'integer', 'float', 'double' => 'number', 'bool' => 'boolean', default => $property->type
            }];
            foreach (['description', 'format', 'example', 'enum', 'items'] as $key) {
                if ($property->$key !== null) {
                    $definition[$key] = $property->$key;
                }
            }
            if ($property->ref !== null) {
                $definition = ['$ref' => $property->ref];
            }
            if ($property->isCollection || $property->type === 'array') {
                $definition = ['type' => 'array', 'items' => $property->items ?? new \stdClass] + $definition;
            }
            if ($property->nullable) {
                $definition = Schema::nullable($definition);
            }
            $overrides[$name] = ['schema' => $definition, 'required' => $property->required];
        }

        return $this->generateSchemaFromRules($rules, $overrides);
    }

    public function generateSchemaFromRules(array $rules, array $overrides = []): array
    {
        $normalized = $this->normalizer->normalize($rules);
        $fields = $normalized['fields'];
        $schema = ['type' => 'object', 'properties' => []];
        uksort($fields, fn ($a, $b) => count(FieldPath::segments((string) $a)) <=> count(FieldPath::segments((string) $b)));
        foreach ($fields as $field => $fieldRules) {
            $required = in_array('required', $fieldRules, true) && ! in_array('sometimes', $fieldRules, true);
            if (isset($overrides[$field])) {
                $definition = $overrides[$field]['schema'];
                $required = $overrides[$field]['required'];
            } elseif (isset($normalized['alternatives'][$field])) {
                $variants = [];
                $branchRequired = [];
                foreach ($normalized['alternatives'][$field] as $branch) {
                    $variantRules = $this->normalizer->normalize([$field => [...$fieldRules, ...$branch]])['fields'][$field] ?? [];
                    $branchRequired[] = in_array('required', $variantRules, true) && ! in_array('sometimes', $variantRules, true);
                    $variants[] = $variantRules === [] ? ['description' => 'Rules are inactive for this branch.'] : $this->processFieldRules($variantRules, (string) $field);
                }
                $required = $branchRequired !== [] && ! in_array(false, $branchRequired, true);
                $definition = Schema::union(...$variants);
            } else {
                $definition = $this->processFieldRules($fieldRules, (string) $field);
            }
            $this->addField($schema, FieldPath::segments((string) $field), $definition, $required);
        }
        foreach ($overrides as $field => $override) {
            if (! array_key_exists($field, $fields)) {
                $this->addField($schema, FieldPath::segments((string) $field), $override['schema'], $override['required']);
            }
        }
        $schema = (new ConditionalSchemaBuilder)->apply($schema, $normalized['conditions']);
        if ($schema['properties'] === []) {
            $schema['properties'] = new \stdClass;
        }

        return $schema;
    }

    private function addField(array &$node, array $segments, array $field, bool $required): void
    {
        $name = array_shift($segments);
        if ($name === '*') {
            $node['type'] = 'array';
            unset($node['properties'], $node['required']);
            $node['items'] = (array) ($node['items'] ?? []);
            if ($segments === []) {
                $node['items'] = array_replace_recursive($node['items'], $field);
            } else {
                $this->addField($node['items'], $segments, $field, $required);
            }

            return;
        }

        $node['type'] = 'object';
        unset($node['items']);
        foreach (['minItems' => 'minProperties', 'maxItems' => 'maxProperties'] as $from => $to) {
            if (isset($node[$from])) {
                $node[$to] = $node[$from];
                unset($node[$from]);
            }
        }
        $node['properties'] = (array) ($node['properties'] ?? []);
        $node['properties'][$name] = (array) ($node['properties'][$name] ?? []);
        if ($segments === []) {
            $node['properties'][$name] = array_replace_recursive($node['properties'][$name], $field);
        } else {
            $this->addField($node['properties'][$name], $segments, $field, $required);
        }
        // A required child does not force an optional parent to be present.
        if ($segments === [] && $required) {
            $node['required'] = array_values(array_unique([...($node['required'] ?? []), $name]));
        }
    }

    private function processFieldRules(array $rules, string $field = ''): array
    {
        $names = array_map(fn ($rule) => is_string($rule) ? explode(':', $rule, 2)[0] : null, $rules);
        $type = match (true) {
            in_array('integer', $names, true) => 'integer',
            in_array('numeric', $names, true), in_array('decimal', $names, true) => 'number',
            in_array('boolean', $names, true) => 'boolean',
            in_array('array', $names, true), in_array('list', $names, true) => 'array',
            default => 'string',
        };
        $schema = ['type' => $type];
        if ($type === 'array') {
            $schema['items'] = $this->options->get('unknown_types') === 'legacy' ? ['type' => 'string'] : new \stdClass;
        }
        $binary = (bool) array_intersect(['file', 'image', 'mimes', 'mimetypes'], $names);
        foreach ($rules as $rule) {
            if ($rule instanceof File || $rule instanceof Dimensions) {
                $binary = true;
            }
        }
        if ($binary) {
            $schema = ['type' => 'string', 'format' => 'binary'];
        }

        $overrides = [];
        foreach ($rules as $rule) {
            if ($rule instanceof SchemaAwareRule) {
                $overrides = array_replace($overrides, $rule->toOpenApiSchema());

                continue;
            }
            foreach ($this->extensions as $extension) {
                if (is_object($rule) && $extension->supports($rule)) {
                    $overrides = array_replace($overrides, $extension->schema($rule));

                    continue 2;
                }
            }
            if ($rule instanceof Enum) {
                $property = new ReflectionProperty($rule, 'type');
                $enumClass = $property->getValue($rule);
                if (is_subclass_of($enumClass, BackedEnum::class)) {
                    $only = (new ReflectionProperty($rule, 'only'))->getValue($rule);
                    $except = (new ReflectionProperty($rule, 'except'))->getValue($rule);
                    $cases = array_filter($enumClass::cases(), fn ($case) => ($only === [] || in_array($case, $only, true)) && ! in_array($case, $except, true));
                    $values = array_values(array_map(fn ($case) => $case->value, $cases));
                    if ($values === []) {
                        throw new \InvalidArgumentException('Enum validation rule has no permitted backed values.');
                    }
                    $schema['type'] = is_int($values[0] ?? null) ? 'integer' : 'string';
                    $schema['enum'] = $values;
                }

                continue;
            }
            if (! is_string($rule)) {
                if (! $rule instanceof File && ! $rule instanceof Dimensions) {
                    $this->diagnostics->report('Custom rule cannot be inferred; implement SchemaAwareRule or register a RuleSchemaExtension.', $field);
                }

                continue;
            }
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
            switch ($name) {
                case 'array':
                    if ($argument !== null && $argument !== '') {
                        $schema = ['type' => 'object', 'properties' => array_fill_keys(explode(',', $argument), new \stdClass), 'additionalProperties' => false] + array_diff_key($schema, ['type' => true, 'items' => true]);
                    }
                    break;
                case 'regex':
                case 'not_regex':
                    $pattern = $this->pattern($argument ?? '', $field);
                    if ($pattern !== null) {
                        if ($name === 'regex') {
                            $schema['pattern'] = $pattern;
                        } else {
                            $schema['not'] = ['pattern' => $pattern];
                        }
                    }
                    break;
                case 'not_in':
                    $values = str_getcsv($argument ?? '', ',', '"', '\\');
                    $schema['not'] = ['enum' => array_map(fn ($value) => match ($schema['type']) {
                        'integer' => (int) $value, 'number' => (float) $value, default => $value
                    }, $values)];
                    break;
                case 'multiple_of':
                    if (is_numeric($argument) && (float) $argument > 0) {
                        $schema['multipleOf'] = (float) $argument;
                    }
                    break;
                case 'nullable':
                    $schema['nullable'] = true;
                    break;
                case 'email':
                case 'url':
                case 'uuid':
                case 'ulid':
                case 'ipv4':
                case 'ipv6':
                case 'date':
                    $schema['format'] = match ($name) {
                        'url' => 'uri', 'date' => 'date', default => $name,
                    };
                    break;
                case 'date_format':
                    if ($argument === 'Y-m-d') {
                        $schema['format'] = 'date';
                    } elseif (in_array($argument, ['Y-m-d\\TH:i:sP', 'c', \DateTimeInterface::ATOM], true)) {
                        $schema['format'] = 'date-time';
                    }
                    break;
                case 'in':
                    $values = str_getcsv($argument ?? '', ',', '"', '\\');
                    $schema['enum'] = array_map(fn ($value) => match ($schema['type']) {
                        'integer' => (int) $value, 'number' => (float) $value, default => $value,
                    }, $values);
                    break;
                case 'min':
                case 'max':
                case 'between':
                case 'size':
                    if ($binary || $argument === null) {
                        break;
                    }
                    $bounds = explode(',', $argument);
                    [$min, $max] = match ($schema['type']) {
                        'array' => ['minItems', 'maxItems'],
                        'object' => ['minProperties', 'maxProperties'],
                        'integer', 'number' => ['minimum', 'maximum'],
                        default => ['minLength', 'maxLength'],
                    };
                    if (in_array($name, ['min', 'between', 'size'], true)) {
                        $schema[$min] = $schema['type'] === 'number' ? (float) $bounds[0] : (int) $bounds[0];
                    }
                    if (in_array($name, ['max', 'between', 'size'], true)) {
                        $value = $name === 'between' ? ($bounds[1] ?? $bounds[0]) : $bounds[0];
                        $schema[$max] = $schema['type'] === 'number' ? (float) $value : (int) $value;
                    }
                    break;
            }
        }

        if (! array_intersect(['integer', 'numeric', 'decimal', 'boolean', 'array', 'list', 'string', 'date', 'email', 'url', 'uuid', 'ulid', 'ipv4', 'ipv6', 'json'], $names) && ! $binary && $overrides === [] && ! isset($schema['enum'])) {
            $fallback = $this->diagnostics->unknown($field);
            if ($this->options->get('unknown_types') !== 'legacy') {
                unset($schema['type']);
            }
            $schema = array_replace($fallback, $schema);
        }
        if ($overrides !== [] && ! isset($overrides['type']) && (isset($overrides['oneOf']) || isset($overrides['anyOf']) || isset($overrides['allOf']) || isset($overrides['$ref']))) {
            unset($schema['type']);
        }

        return array_replace($schema, $overrides);
    }

    private function pattern(string $value, string $field): ?string
    {
        if (strlen($value) < 2) {
            return null;
        }
        $delimiter = $value[0];
        $closing = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$delimiter] ?? $delimiter;
        $end = strrpos($value, $closing);
        if ($end === false || $end === 0) {
            return null;
        }
        $flags = substr($value, $end + 1);
        $body = substr($value, 1, $end - 1);
        $unsupported = array_filter(['A', 'z', 'Z', 'K', 'p', 'P'], fn ($escape) => str_contains($body, chr(92).$escape));
        if (trim($flags, 'u') !== '' || str_contains($body, '(?') || $unsupported !== []) {
            $this->diagnostics->report('PCRE pattern cannot be represented portably in OpenAPI; use an explicit pattern override.', $field);

            return null;
        }

        return str_replace(chr(92).$delimiter, $delimiter, $body);
    }

    public function containsFile(array $schema): bool
    {
        if (($schema['format'] ?? null) === 'binary') {
            return true;
        }
        foreach ($schema['properties'] ?? [] as $property) {
            if ($this->containsFile((array) $property)) {
                return true;
            }
        }

        return isset($schema['items']) && $this->containsFile((array) $schema['items']);
    }
}
