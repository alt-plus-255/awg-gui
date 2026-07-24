<?php

namespace App\Services\AmneziaWg\Versions;

use InvalidArgumentException;

class AwgVersionRegistry
{
    /** @var array<string, AwgVersionProfile> */
    private array $profiles = [];

    /** @var list<string> Ordered from oldest to newest (latest = last). */
    private array $order = [];

    public function __construct()
    {
        $this->register(new AwgVersion10Profile);
        $this->register(new AwgVersion15Profile);
        $this->register(new AwgVersion20Profile);
        // Future: $this->register(new AwgVersion30Profile);
    }

    public function register(AwgVersionProfile $profile): void
    {
        $id = $profile->id();
        if (! isset($this->profiles[$id])) {
            $this->order[] = $id;
        }
        $this->profiles[$id] = $profile;
    }

    /** @return list<AwgVersionProfile> */
    public function all(): array
    {
        return array_map(fn (string $id) => $this->profiles[$id], $this->order);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return $this->order;
    }

    public function has(string $id): bool
    {
        return isset($this->profiles[$id]);
    }

    public function get(string $id): AwgVersionProfile
    {
        if (! isset($this->profiles[$id])) {
            throw new InvalidArgumentException("Unknown AmneziaWG protocol version: {$id}");
        }

        return $this->profiles[$id];
    }

    /** Newest registered version id (becomes create-form default). */
    public function latest(): string
    {
        return $this->order[array_key_last($this->order)];
    }

    public function latestProfile(): AwgVersionProfile
    {
        return $this->get($this->latest());
    }

    public function profileForConfig(?string $protocolVersion): AwgVersionProfile
    {
        $id = $protocolVersion ?: $this->latest();
        if (! $this->has($id)) {
            return $this->latestProfile();
        }

        return $this->get($id);
    }
}
