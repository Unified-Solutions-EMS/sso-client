<?php

namespace Unified\SsoClient\Assistant;

/**
 * What a tool hands back. Build with the factories; never construct by hand.
 */
final readonly class AssistantToolResult
{
    /**
     * @param  array<string, mixed>  $content  what the model sees (no secrets; PHI only when the tool's job needs it)
     * @param  string|null  $summary  one customer-facing line for the action chip, e.g. "Assigned John Smith to C shift (Paramedic), Sat Nov 1"
     * @param  array{tool: string, args: array<string, mixed>}|null  $undo  a tool call that reverses this write, or null when it is not reversible
     * @param  bool  $needsConfirmation  the tool refused to act because the change is flagged as needing an explicit yes; $question says what to confirm
     */
    private function __construct(
        public bool $ok,
        public array $content = [],
        public ?string $summary = null,
        public ?array $undo = null,
        public bool $needsConfirmation = false,
        public ?string $question = null,
        public ?string $message = null,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     * @param  array{tool: string, args: array<string, mixed>}|null  $undo
     */
    public static function ok(array $content, ?string $summary = null, ?array $undo = null): self
    {
        return new self(ok: true, content: $content, summary: $summary, undo: $undo);
    }

    /**
     * The change needs an explicit yes. $question is what the admin is asked
     * ("This sends a page to all 34 people on the roster. Shall I go ahead?").
     *
     * @param  array<string, mixed>  $content
     */
    public static function confirm(string $question, array $content = []): self
    {
        return new self(ok: false, content: $content, needsConfirmation: true, question: $question);
    }

    /** $message is read by the admin: plain words, never exception text. */
    public static function failed(string $message): self
    {
        return new self(ok: false, message: $message);
    }

    /**
     * The HTTP body the execute route answers with.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->ok) {
            return [
                'ok' => true,
                'content' => $this->content,
                'summary' => $this->summary,
                'undo' => $this->undo,
            ];
        }

        if ($this->needsConfirmation) {
            return [
                'ok' => false,
                'needs_confirmation' => true,
                'question' => $this->question,
                'content' => $this->content,
            ];
        }

        return ['ok' => false, 'message' => $this->message];
    }
}
