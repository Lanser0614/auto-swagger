<?php

namespace AutoSwagger\Contracts;

/** Adapts existing rule classes that cannot implement SchemaAwareRule. */
interface RuleSchemaExtension
{
    public function supports(object $rule): bool;

    public function schema(object $rule): array;
}
