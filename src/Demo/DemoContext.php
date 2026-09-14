<?php
namespace App\Demo;

class DemoContext
{
    private array $maps = [];

    public function set(string $type, string|int $sourceId, object $target): void
    {
        $this->maps[$type][(string) $sourceId] = $target;
    }

    public function get(string $type, string|int $sourceId): object
    {
        return $this->maps[$type][(string) $sourceId]
            ?? throw new RuntimeException(
                sprintf('Aucun mapping Demo pour %s #%s', $type, $sourceId)
            );
    }
}