<?php

namespace Demo;

final readonly class SubscriptionLine
{
    public function __construct(
        public string $feedUrl,
        public int $position,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromLine(array $line): self
    {
        return new self(
            feedUrl: LineField::string($line, 'feedUrl'),
            position: LineField::int($line, 'position'),
            createdAt: LineField::date($line, 'createdAt'),
        );
    }
}

final class LineField
{
    public static function string(array $line, string $key): string
    {
        $value = $line[$key] ?? null;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException($key);
        }

        return $value;
    }

    public static function int(array $line, string $key): int
    {
        $value = $line[$key] ?? null;
        if (!\is_int($value)) {
            throw new \InvalidArgumentException($key);
        }

        return $value;
    }

    public static function date(array $line, string $key): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::string($line, $key));
    }
}

final readonly class BackupReader
{
    private function toDto(string $kind, array $decoded): object
    {
        return match ($kind) {
            'subscription' => SubscriptionLine::fromLine($decoded),
            default => throw new \LogicException($kind),
        };
    }
}
