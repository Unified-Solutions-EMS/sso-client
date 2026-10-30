<?php

namespace Unified\SsoClient\Tests\Fixtures;

use Unified\SsoClient\Assistant\AssistantActor;
use Unified\SsoClient\Assistant\AssistantTool;
use Unified\SsoClient\Assistant\AssistantToolResult;
use Unified\SsoClient\Assistant\HiddenAssistantTool;
use Unified\SsoClient\Assistant\PreparesAssistantActor;
use Unified\SsoClient\Contracts\AssistantToolProvider;

class FakeAssistantToolProvider implements AssistantToolProvider, PreparesAssistantActor
{
    public ?AssistantActor $lastActor = null;

    public ?AssistantActor $preparedActor = null;

    /** @var array<string, mixed>|null */
    public ?array $lastArgs = null;

    public bool $provisioned = true;

    public function prepare(AssistantActor $actor): void
    {
        $this->preparedActor = $actor;
    }

    public function tools(AssistantActor $actor): array
    {
        $this->lastActor = $actor;

        if (! $this->provisioned) {
            return [];
        }

        $provider = $this;

        return [
            new class($provider) implements AssistantTool
            {
                public function __construct(private FakeAssistantToolProvider $provider) {}

                public function name(): string
                {
                    return 'find_shifts';
                }

                public function definition(): array
                {
                    return [
                        'description' => 'Finds shifts on a date.',
                        'parameters' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string']], 'required' => ['date']],
                    ];
                }

                public function kind(): string
                {
                    return self::READ;
                }

                public function execute(array $args, AssistantActor $actor): AssistantToolResult
                {
                    $this->provider->lastArgs = $args;

                    return AssistantToolResult::ok(['shifts' => [['id' => 5, 'name' => 'C shift']]]);
                }
            },
            new class implements AssistantTool
            {
                public function name(): string
                {
                    return 'assign_shift';
                }

                public function definition(): array
                {
                    return [
                        'description' => 'Puts a person on a shift.',
                        'parameters' => ['type' => 'object', 'properties' => [
                            'shift' => ['type' => 'integer'],
                            'person' => ['type' => 'integer'],
                            'confirmed' => ['type' => 'boolean'],
                        ], 'required' => ['shift', 'person']],
                    ];
                }

                public function kind(): string
                {
                    return self::WRITE;
                }

                public function execute(array $args, AssistantActor $actor): AssistantToolResult
                {
                    if (($args['person'] ?? null) === 0) {
                        return AssistantToolResult::failed('You do not have permission to do that here.');
                    }

                    if (($args['bulk'] ?? false) && ! ($args['confirmed'] ?? false)) {
                        return AssistantToolResult::confirm('This changes 12 shifts. Shall I go ahead?', ['count' => 12]);
                    }

                    if (($args['explode'] ?? false) === true) {
                        throw new \RuntimeException('database exploded');
                    }

                    return AssistantToolResult::ok(
                        ['assignment_id' => 77],
                        'Assigned Dana Admin to C shift (Paramedic), Sat Nov 1',
                        ['tool' => 'unassign_by_assignment', 'args' => ['assignment' => 77]],
                    );
                }
            },
            new class implements HiddenAssistantTool
            {
                public function name(): string
                {
                    return 'unassign_by_assignment';
                }

                public function definition(): array
                {
                    return ['description' => 'Reverses assign_shift.', 'parameters' => ['type' => 'object', 'properties' => ['assignment' => ['type' => 'integer']]]];
                }

                public function kind(): string
                {
                    return self::WRITE;
                }

                public function execute(array $args, AssistantActor $actor): AssistantToolResult
                {
                    return AssistantToolResult::ok(['removed' => $args['assignment'] ?? null], 'Took Dana Admin off C shift');
                }
            },
        ];
    }
}
