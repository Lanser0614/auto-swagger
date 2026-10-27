<?php

namespace AutoSwagger\Analysis\Ast;

final class InferredValue
{
    public function __construct(public readonly array $schema, public readonly bool $optional = false, public readonly bool $missing = false) {}
}
