<?php

namespace Unified\SsoClient\Assistant;

/**
 * One thing the assistant can do or look up in this app.
 *
 * Descriptions are written for the model: what the tool does, what it needs,
 * what it returns. Put fixed option lists in the schema as `enum`. Every WRITE
 * tool's schema accepts an optional boolean `confirmed`: the tool returns
 * AssistantToolResult::confirm() for a change the app's own UI treats as
 * needing extra care, the model asks the admin, and calls again with
 * confirmed: true.
 */
interface AssistantTool
{
    public const READ = 'read';

    public const WRITE = 'write';

    /** snake_case, unique within the app. */
    public function name(): string;

    /**
     * Same shape as ai-core Tool::definition(): a description and a JSON
     * Schema object for the arguments.
     *
     * @return array{description: string, parameters: array<string, mixed>}
     */
    public function definition(): array;

    /** self::READ or self::WRITE */
    public function kind(): string;

    /**
     * Act now, authorizing with $actor->user->can('...') as the UI does. Not
     * authorized means failed('You do not have permission to do that here.').
     *
     * @param  array<string, mixed>  $args
     */
    public function execute(array $args, AssistantActor $actor): AssistantToolResult;
}
