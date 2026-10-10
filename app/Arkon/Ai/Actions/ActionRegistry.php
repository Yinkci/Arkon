<?php

namespace App\Arkon\Ai\Actions;

use App\Arkon\Errors\NotFoundException;
use App\Arkon\Sites\Membership;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;

/**
 * The allowlist of what AI can do in Arkon. The MCP server offers exactly these actions as
 * tools; there is no SQL, shell, file, apply, publish or delete action. Core actions come from
 * CoreActions; an extension adds its own with register(), so nothing is a central switch.
 */
final class ActionRegistry
{
    /** @var array<string, Action> */
    private array $actions = [];

    public function register(Action $action): void
    {
        if (isset($this->actions[$action->name])) {
            throw new \LogicException("The AI action {$action->name} is already registered.");
        }
        $this->actions[$action->name] = $action;
    }

    /** @return array<string, Action> */
    public function all(): array
    {
        return $this->actions;
    }

    /** Checks the arguments, then runs the action as the connection's member (via "ai"). */
    public function run(string $name, SiteContext $ctx, array $arguments, string $connectionId): array
    {
        $action = $this->actions[$name] ?? throw new NotFoundException("Action {$name}");
        InputSchema::check($action->input, $arguments);

        return ($action->handler)(new SiteContext($ctx->siteId, $ctx->userId, 'ai'), $arguments, $connectionId);
    }

    /** MCP tool descriptors (tools/list). */
    public function mcpTools(): array
    {
        return array_values(array_map(fn (Action $a) => [
            'name' => $a->name,
            'description' => $a->description,
            'inputSchema' => $a->input,
            ...($a->readOnly ? ['annotations' => ['readOnlyHint' => true]] : ['annotations' => ['readOnlyHint' => false, 'destructiveHint' => false]]),
        ], $this->actions));
    }

    /** @return list<array{name: string, area: string, readOnly: bool, allowed: bool}> what this member's role lets AI do */
    public function forMember(SiteContext $ctx): array
    {
        $role = app(Membership::class)->roleOf($ctx->siteId, $ctx->userId);

        return array_values(array_map(fn (Action $a) => [
            'name' => $a->name, 'area' => $a->area, 'readOnly' => $a->readOnly,
            'allowed' => $a->permission === null ? $role !== null : Permissions::allows($role, $a->permission),
        ], $this->actions));
    }
}
