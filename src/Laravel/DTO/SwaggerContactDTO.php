<?php

declare(strict_types=1);

namespace AutoSwagger\Laravel\DTO;

class SwaggerContactDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $url,
    ) {}

    public static function fromArray(array $array): SwaggerContactDTO
    {
        return new self(
            name: $array['name'] ?? '',
            email: $array['email'] ?? '',
            url: $array['url'] ?? '',
        );
    }
}
