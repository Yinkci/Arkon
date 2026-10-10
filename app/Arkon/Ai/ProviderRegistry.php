<?php

namespace App\Arkon\Ai;

use App\Arkon\Errors\ValidationException;

final class ProviderRegistry
{
    public static function definitions(): array
    {
        return [
            'claude-code' => ['id' => 'claude-code', 'name' => 'Claude Code', 'description' => 'Use your own Claude subscription through the local CLI.', 'login' => 'claude auth login', 'install' => 'https://code.claude.com/docs/en/setup', 'capabilities' => ['structured_output', 'page_proposal', 'website_proposal', 'mcp']],
            'codex' => ['id' => 'codex', 'name' => 'Codex', 'description' => 'Use your own ChatGPT account through the local Codex CLI.', 'login' => 'codex login', 'install' => 'https://developers.openai.com/codex/cli', 'capabilities' => ['structured_output', 'page_proposal', 'website_proposal', 'mcp']],
        ];
    }

    public static function validate(string $id): string
    {
        if (! isset(self::definitions()[$id])) {
            throw new ValidationException('Choose a supported AI provider.');
        }

        return $id;
    }

    public static function requireCapability(string $id, string $capability): void
    {
        self::validate($id);
        if (! in_array($capability, self::definitions()[$id]['capabilities'], true)) {
            throw new AiException('TASK_UNSUPPORTED', 'This task is not supported by the selected Arkon provider connection.');
        }
    }

    public function runner(string $id): AiRunner
    {
        self::validate($id);

        return $id === 'claude-code' ? app(ClaudeRunner::class) : $this->resolve($id);
    }

    public function resolve(string $id): AiProvider
    {
        return match (self::validate($id)) {
            'claude-code' => ClaudeCodeCli::fromConfig(), 'codex' => CodexCli::fromConfig()
        };
    }

    public static function tokenFile(string $id): string
    {
        self::validate($id);

        return $id === 'claude-code' ? config('arkon.ai.helper_token_file') : config('arkon.ai.codex_helper_token_file');
    }
}
