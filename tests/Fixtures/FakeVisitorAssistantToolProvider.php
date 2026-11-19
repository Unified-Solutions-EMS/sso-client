<?php

namespace Unified\SsoClient\Tests\Fixtures;

use Unified\SsoClient\Assistant\AssistantActor;
use Unified\SsoClient\Assistant\AssistantTool;
use Unified\SsoClient\Assistant\AssistantToolResult;
use Unified\SsoClient\Assistant\PreparesAssistantActor;
use Unified\SsoClient\Assistant\VisitorAssistantTool;
use Unified\SsoClient\Contracts\AssistantToolProvider;

/**
 * A website's tools: one a visitor may use, one that needs a signed-in user
 * (it reads $actor->user, which is null for a visitor).
 */
class FakeVisitorAssistantToolProvider implements AssistantToolProvider, PreparesAssistantActor
{
    public ?AssistantActor $lastActor = null;

    public ?AssistantActor $preparedActor = null;

    public bool $memberToolRan = false;

    public function prepare(AssistantActor $actor): void
    {
        $this->preparedActor = $actor;
    }

    public function tools(AssistantActor $actor): array
    {
        $this->lastActor = $actor;
        $provider = $this;

        return [
            new class implements VisitorAssistantTool
            {
                public function name(): string
                {
                    return 'find_product_info';
                }

                public function definition(): array
                {
                    return ['description' => 'Looks up a product.', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]];
                }

                public function kind(): string
                {
                    return self::READ;
                }

                public function execute(array $args, AssistantActor $actor): AssistantToolResult
                {
                    return AssistantToolResult::ok(['visitor' => $actor->visitorId, 'query' => $args['query'] ?? null]);
                }
            },
            new class($provider) implements AssistantTool
            {
                public function __construct(private FakeVisitorAssistantToolProvider $provider) {}

                public function name(): string
                {
                    return 'edit_page';
                }

                public function definition(): array
                {
                    return ['description' => 'Edits a page.', 'parameters' => ['type' => 'object', 'properties' => []]];
                }

                public function kind(): string
                {
                    return self::WRITE;
                }

                public function execute(array $args, AssistantActor $actor): AssistantToolResult
                {
                    $this->provider->memberToolRan = true;

                    return AssistantToolResult::ok(['by' => $actor->user?->getKey()], 'Edited the page');
                }
            },
        ];
    }
}
