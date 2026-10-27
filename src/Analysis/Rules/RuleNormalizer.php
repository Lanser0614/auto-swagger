<?php

namespace AutoSwagger\Analysis\Rules;

use AutoSwagger\Analysis\Ast\SourceReader;
use AutoSwagger\Analysis\Diagnostics;
use AutoSwagger\Contracts\SchemaAwareRule;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\RequiredIf;

final class RuleNormalizer
{
    public function __construct(private readonly Diagnostics $diagnostics, private readonly SourceReader $source = new SourceReader) {}

    public function normalize(array $rules): array
    {
        $fields = [];
        $conditions = [];
        $alternatives = [];
        $excluded = [];
        foreach ($rules as $field => $definition) {
            $fields[$field] = [];
            $pending = $this->list($definition);
            while ($pending !== []) {
                $rule = array_shift($pending);
                if ($rule instanceof RequiredIf || $rule instanceof ExcludeIf || $rule instanceof ProhibitedIf) {
                    $kind = $rule instanceof RequiredIf ? 'required' : ($rule instanceof ExcludeIf ? 'exclude' : 'prohibited');
                    if (is_bool($rule->condition)) {
                        if ($rule->condition) {
                            array_unshift($pending, $kind);
                        }
                    } else {
                        $conditions[] = ['field' => $field, 'rule' => $kind.'_if', 'parameters' => [], 'dynamic' => true];
                        $this->diagnostics->report('A dynamic rule condition cannot be evaluated statically; the field remains optional.', (string) $field);
                    }

                    continue;
                }
                if ($rule instanceof ConditionalRules) {
                    $condition = (new \ReflectionProperty($rule, 'condition'))->getValue($rule);
                    $positive = (new \ReflectionProperty($rule, 'rules'))->getValue($rule);
                    $negative = (new \ReflectionProperty($rule, 'defaultRules'))->getValue($rule);
                    if (is_bool($condition)) {
                        $branch = $condition ? $positive : $negative;
                        if ($branch instanceof \Closure) {
                            $branch = (new StaticRuleExtractor($this->source))->closure($branch);
                        }
                        if ($branch !== null) {
                            $pending = [...$this->list($branch), ...$pending];
                        }
                    } else {
                        $branches = [];
                        foreach ([$positive, $negative] as $branch) {
                            if ($branch instanceof \Closure) {
                                $branch = (new StaticRuleExtractor($this->source))->closure($branch);
                            }
                            $branches[] = $branch === null ? [] : $this->list($branch);
                        }
                        $alternatives[$field] = $branches;
                        $conditions[] = ['field' => $field, 'rule' => 'when', 'parameters' => [], 'dynamic' => true];
                        $this->diagnostics->report('Dynamic Rule::when() is represented by alternative schemas; its condition needs an explicit override for an exact contract.', (string) $field);
                    }

                    continue;
                }
                if ($rule instanceof NestedRules) {
                    $callback = (new \ReflectionProperty($rule, 'callback'))->getValue($rule);
                    $nested = $callback instanceof \Closure ? (new StaticRuleExtractor($this->source))->closure($callback) : null;
                    if ($nested === null) {
                        $this->diagnostics->report('Rule::forEach() callback is data-dependent or unavailable; provide a SchemaAwareRule or explicit schema.', (string) $field);
                    } elseif (array_is_list($nested)) {
                        $pending = [...$nested, ...$pending];
                    } else {
                        foreach ($nested as $name => $value) {
                            $expanded = $this->normalize([$field.'.'.$name => $value]);
                            $fields = array_replace($fields, $expanded['fields']);
                            $conditions = [...$conditions, ...$expanded['conditions']];
                        }
                        $fields[$field][] = 'array';
                    }

                    continue;
                }
                // Only known framework string rules may be serialized. Custom __toString
                // implementations and conditional rule callbacks must never be executed.
                if (is_object($rule) && ! $rule instanceof SchemaAwareRule && in_array(get_class($rule), [
                    'Illuminate\\Validation\\Rules\\In', 'Illuminate\\Validation\\Rules\\NotIn',
                    'Illuminate\\Validation\\Rules\\Exists', 'Illuminate\\Validation\\Rules\\Unique',
                    'Illuminate\\Validation\\Rules\\Date', 'Illuminate\\Validation\\Rules\\Numeric',
                ], true) && $rule instanceof \Stringable) {
                    $rule = (string) $rule;
                }
                if (is_string($rule)) {
                    [$name, $arguments] = array_pad(explode(':', $rule, 2), 2, '');
                    if ($name === 'exclude') {
                        $excluded[] = $field;

                        continue;
                    }
                    if (preg_match('/^(required|exclude|prohibited)_(if|unless|with|with_all|without|without_all)$/', $name)) {
                        $conditions[] = ['field' => $field, 'rule' => $name, 'parameters' => str_getcsv($arguments, ',', '"', '\\'), 'dynamic' => false];

                        continue;
                    }
                    if ($name === 'prohibited') {
                        $conditions[] = ['field' => $field, 'rule' => 'prohibited', 'parameters' => [], 'dynamic' => false];

                        continue;
                    }
                }
                $fields[$field][] = $rule;
            }
        }
        foreach ($excluded as $field) {
            foreach (array_keys($fields) as $candidate) {
                if ($candidate === $field || str_starts_with($candidate, $field.'.')) {
                    unset($fields[$candidate]);
                }
            }
            $conditions = array_values(array_filter($conditions, fn ($condition) => $condition['field'] !== $field && ! str_starts_with($condition['field'], $field.'.')));
        }

        return compact('fields', 'conditions', 'alternatives');
    }

    public function list(mixed $rules): array
    {
        if (is_array($rules)) {
            return array_values($rules);
        }
        if (! is_string($rules)) {
            return [$rules];
        }
        $result = [];
        $rule = '';
        $delimiter = null;
        $closing = null;
        $escaped = false;
        $depth = 0;
        for ($i = 0; $i < strlen($rules); $i++) {
            $character = $rules[$i];
            if ($delimiter !== null) {
                $rule .= $character;
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($character === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($delimiter !== $closing && $character === $delimiter) {
                    $depth++;
                }
                if ($character === $closing) {
                    $depth--;
                    if ($depth <= 0) {
                        $delimiter = null;
                    }
                }

                continue;
            }
            if (in_array($rule, ['regex:', 'not_regex:'], true)) {
                $delimiter = $character;
                $closing = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$delimiter] ?? $delimiter;
                $depth = 1;
                $rule .= $character;

                continue;
            }
            if ($character === '|' && $rule !== '') {
                $result[] = $rule;
                $rule = '';

                continue;
            }
            $rule .= $character;
        }
        if ($rule !== '') {
            $result[] = $rule;
        }

        return $result;
    }
}
