<?php

namespace App\Arkon\Ai\Mcp;

use App\Arkon\Ai\Actions\ActionRegistry;
use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Errors\ArkonException;
use Throwable;

/**
 * Arkon's MCP server for Claude Code (VS Code), spoken over stdio as newline-delimited
 * JSON-RPC 2.0. The connection is one paired Arkon user on one site (its token, from the
 * ARKON_MCP_TOKEN environment variable, resolved again on every call so revocation is
 * immediate); no tool takes a user or site id.
 *
 * Tools are the registered AI actions (ActionRegistry, CoreActions): discovery (capabilities,
 * content, terms, media, SEO analysis), new drafts (a post or a page from public blocks) and
 * proposals (page and website changes, validated and reviewed in Arkon before anything is
 * applied). Nothing here changes existing content, applies or publishes; there is no SQL,
 * shell, file or delete access.
 */
final class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly ?string $token,
        private readonly AiConnections $connections,
        private readonly ActionRegistry $actions,
    ) {}

    /** Handles one incoming line; returns the response line, or null for notifications. */
    public function handleLine(string $line): ?string
    {
        $message = json_decode($line, true);
        if (! is_array($message)) {
            return self::encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        }
        $response = $this->handle($message);

        return $response === null ? null : self::encode($response);
    }

    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (! array_key_exists('id', $message)) {
            return null; // notifications (initialized, cancelled, …) need no answer
        }
        $result = match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => new \stdClass,
            'tools/list' => ['tools' => $this->tools()],
            'tools/call' => $this->call((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []),
            default => null,
        };
        if ($result === null) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: {$method}"]];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[1],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'arkon', 'version' => '1.0.0'],
            'instructions' => 'Arkon CMS: start with arkon_get_capabilities (what this site supports and the rules). For a blog post use arkon_create_post (a draft). For a complete website use arkon_get_website_context then arkon_submit_website_proposal and review it at /admin/website. For one page, flow: arkon_list_pages → arkon_get_page → arkon_get_proposal_format → write a proposal as JSON matching the schema → arkon_submit_proposal with the page\'s draftVersion as baseVersion. The proposal is validated and waits in Arkon\'s editor (AI tab) where the user previews it and applies or discards it; you cannot apply or publish. If validation fails, fix the listed problems and submit again with a new requestKey.',
        ];
    }

    /** @return list<array> the registered actions (ActionRegistry), nothing else */
    public function tools(): array
    {
        return $this->actions->mcpTools();
    }

    /** A tool call: domain errors become tool errors (isError) the model can read and act on. */
    private function call(string $name, array $arguments): array
    {
        try {
            $connection = $this->connections->resolve($this->token, 'mcp')
                ?? throw new AiException(AiException::UNAUTHENTICATED, 'This Arkon connection is not paired or was revoked. Pair again with: php artisan arkon:ai-pair you@example.com --mcp');
            $this->connections->touch($connection->id);
            if (! isset($this->actions->all()[$name])) {
                throw new AiException(AiException::INVALID_OUTPUT, "Unknown tool {$name}");
            }
            $result = $this->actions->run($name, $this->connections->context($connection, 'ai'), $arguments, $connection->id);

            return ['content' => [['type' => 'text', 'text' => self::encode($result)]], 'structuredContent' => $result, 'isError' => false];
        } catch (ArkonException $error) {
            $data = $error->toArray();

            return ['content' => [['type' => 'text', 'text' => self::encode($data)]], 'isError' => true];
        } catch (Throwable $error) {
            report($error);

            return ['content' => [['type' => 'text', 'text' => 'Arkon could not complete this call.']], 'isError' => true];
        }
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
