<?php

namespace AutoSwagger\Laravel;

class DocumentationPath
{
    public static function resolve(string $directory, string $file): string
    {
        if ($directory === '') {
            return public_path($file);
        }
        $path = rtrim($directory, '/\\').'/'.$file;

        return self::isAbsolute($path) ? $path : public_path($path);
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[/\\\\]~', $path) === 1;
    }
}
