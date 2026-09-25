<?php

namespace App\Agent\Tools;

/**
 * Minimal JSON schema validator (plan T06 §3): no schema package existed
 * in composer.json, and adding one needs the owner's approval, so this
 * supports only what the tool contracts (§6) actually use: type, properties,
 * required, enum, minimum/maximum, minItems/maxItems, maxLength.
 */
class JsonSchemaValidator
{
    /**
     * @return string[] Empty when valid; otherwise "path: message" entries.
     */
    public function validate(array $schema, mixed $data, string $path = '$'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;

        if ($type !== null && ! $this->matchesType($data, $type)) {
            $errors[] = "{$path}: expected type ".(is_array($type) ? implode('|', $type) : $type);

            return $errors;
        }

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = "{$path}: must be one of ".implode(', ', array_map('strval', $schema['enum']));
        }

        if ($type === 'object' || (is_array($data) && $this->isAssoc($data) && isset($schema['properties']))) {
            $data = is_array($data) ? $data : [];

            foreach ($schema['required'] ?? [] as $requiredKey) {
                if (! array_key_exists($requiredKey, $data)) {
                    $errors[] = "{$path}.{$requiredKey}: required";
                }
            }

            foreach ($schema['properties'] ?? [] as $key => $propertySchema) {
                if (array_key_exists($key, $data)) {
                    $errors = array_merge($errors, $this->validate($propertySchema, $data[$key], "{$path}.{$key}"));
                }
            }
        }

        if ($type === 'array' && is_array($data)) {
            if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
                $errors[] = "{$path}: must have at least {$schema['minItems']} item(s)";
            }

            if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
                $errors[] = "{$path}: must have at most {$schema['maxItems']} item(s)";
            }

            if (isset($schema['items'])) {
                foreach ($data as $index => $item) {
                    $errors = array_merge($errors, $this->validate($schema['items'], $item, "{$path}[{$index}]"));
                }
            }
        }

        if (is_string($data) && isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
            $errors[] = "{$path}: must be at most {$schema['maxLength']} characters";
        }

        if (is_numeric($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = "{$path}: must be >= {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = "{$path}: must be <= {$schema['maximum']}";
            }
        }

        return $errors;
    }

    /** JSON Schema allows `type` to be a single string or a list of allowed types. */
    private function matchesType(mixed $data, string|array $type): bool
    {
        if (is_array($type)) {
            foreach ($type as $option) {
                if ($this->matchesType($data, $option)) {
                    return true;
                }
            }

            return false;
        }

        return match ($type) {
            'object' => is_array($data) && $this->isAssoc($data) || $data === [],
            'array' => is_array($data) && ! $this->isAssoc($data) || $data === [],
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            default => true,
        };
    }

    private function isAssoc(array $data): bool
    {
        return $data !== [] && array_keys($data) !== range(0, count($data) - 1);
    }
}
