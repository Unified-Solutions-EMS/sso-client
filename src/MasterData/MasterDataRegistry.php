<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

use Illuminate\Contracts\Container\Container;
use Unified\SsoClient\MasterData\Contracts\EntityMirror;
use Unified\SsoClient\MasterData\Divisions\DivisionMirror;
use Unified\SsoClient\MasterData\Exceptions\MasterDataSyncException;
use Unified\SsoClient\MasterData\Locations\LocationMirror;
use Unified\SsoClient\MasterData\Qualifications\QualificationMirror;

/**
 * The master-data entities the package knows how to mirror, and which of them
 * this app has opted into via `sso.master_data.<entity>`.
 *
 * Mirrors are resolved through the container so an app whose table shape
 * differs can bind its own subclass.
 */
class MasterDataRegistry
{
    /**
     * @var array<string, class-string<EntityMirror>>
     */
    private const ENTITIES = [
        'qualifications' => QualificationMirror::class,
        'divisions' => DivisionMirror::class,
        'locations' => LocationMirror::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<string>
     */
    public function entities(): array
    {
        return array_keys(self::ENTITIES);
    }

    public function knows(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    public function enabled(string $entity): bool
    {
        return $this->knows($entity) && (bool) config("sso.master_data.{$entity}", false);
    }

    public function mirror(string $entity): EntityMirror
    {
        if (! $this->knows($entity)) {
            throw new MasterDataSyncException("Unknown master-data entity [{$entity}].");
        }

        return $this->container->make(self::ENTITIES[$entity]);
    }

    public function entityForEvent(string $event): ?string
    {
        foreach (self::ENTITIES as $entity => $class) {
            if (in_array($event, $class::events(), true)) {
                return $entity;
            }
        }

        return null;
    }
}
