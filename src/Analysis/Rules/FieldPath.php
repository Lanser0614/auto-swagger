<?php

namespace AutoSwagger\Analysis\Rules;

final class FieldPath
{
    public static function segments(string $field): array
    {
        $parts = [];
        $part = '';
        $escaped = false;
        for ($i = 0; $i < strlen($field); $i++) {
            if ($escaped) {
                $part .= $field[$i];
                $escaped = false;
            } elseif ($field[$i] === '\\') {
                $escaped = true;
            } elseif ($field[$i] === '.') {
                $parts[] = $part;
                $part = '';
            } else {
                $part .= $field[$i];
            }
        }
        if ($escaped) {
            $part .= '\\';
        }
        $parts[] = $part;

        return $parts;
    }
}
