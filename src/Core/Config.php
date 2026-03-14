<?php

declare(strict_types=1);

namespace EasyPay\Payment\Core;

use EasyPay\Payment\Exception\InvalidConfigException;

final class Config
{
    public function __construct(
        private array $items
    ) {
    }

    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function requireString(string $key): string
    {
        $value = $this->get($key);

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidConfigException(sprintf('Missing config key "%s".', $key));
        }

        return $value;
    }

    public function only(string ...$keys): array
    {
        $selected = [];

        foreach ($keys as $key) {
            if ($this->has($key)) {
                $selected[$key] = $this->get($key);
            }
        }

        return $selected;
    }
}


