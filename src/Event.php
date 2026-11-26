<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * One event of a model call, as the application needs it for a Chat Completions answer: a piece of text, the call of
 * a tool, or the end with its reason and the usage.
 */
final class Event {
    /**
     * @param array<string, mixed> $usage the usage as Chat Completions words it; only with an end
     */
    private function __construct(
        public readonly ?string $text = null,
        public readonly ?string $toolName = null,
        /** a JSON object; empty when `agy` gave arguments that do not parse */
        public readonly string $toolArguments = '',
        /** stop, tool_calls or length */
        public readonly ?string $finish = null,
        public readonly array $usage = [],
    ) {}

    public static function text(string $text): self {
        return new self(text: $text);
    }

    public static function tool(string $name, string $arguments): self {
        return new self(toolName: $name, toolArguments: $arguments);
    }

    /** @param array<string, mixed> $usage */
    public static function end(string $finish, array $usage): self {
        return new self(finish: $finish, usage: $usage);
    }
}
