<?php

declare(strict_types=1);

namespace AutoSwagger\Laravel\DTO;

class SwaggerConfigDTO
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $version,
        public readonly SwaggerContactDTO $contact,
        public readonly bool $securityBearer,
        public readonly bool $apiKey,
        public readonly bool $oauth2,
    ) {}

    public static function fromArray(array $array): SwaggerConfigDTO
    {
        return new self(
            title: $array['title'],
            description: $array['description'],
            version: $array['version'],
            contact: SwaggerContactDTO::fromArray($array['contact'] ?? []),
            securityBearer: $array['security']['bearer']['enabled'] ?? false,
            apiKey: $array['security']['apiKey']['enabled'] ?? false,
            oauth2: $array['security']['oauth2']['enabled'] ?? false,
        );
    }
}
