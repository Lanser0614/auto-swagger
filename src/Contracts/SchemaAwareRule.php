<?php

namespace AutoSwagger\Contracts;

/** Implement on a custom validation rule to describe it without executing validation. */
interface SchemaAwareRule
{
    public function toOpenApiSchema(): array;
}
