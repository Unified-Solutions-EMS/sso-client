<?php

namespace Unified\SsoClient\Assistant\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Assistant\AssistantActor;
use Unified\SsoClient\Assistant\AssistantActorResolver;
use Unified\SsoClient\Assistant\AssistantTool;
use Unified\SsoClient\Assistant\AssistantToolResult;
use Unified\SsoClient\Assistant\HiddenAssistantTool;
use Unified\SsoClient\Assistant\NotAMemberException;
use Unified\SsoClient\Contracts\AssistantToolProvider;

/**
 * SSO's view of this app's assistant tools. Both routes run behind
 * CORE_APP_API_KEY with no session user; the actor comes from the sso ids
 * SSO sends, never from a cookie.
 */
class AssistantToolsController extends Controller
{
    public function __construct(private AssistantActorResolver $resolver) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sso_user_id' => ['required', 'integer'],
            'sso_company_id' => ['required', 'integer'],
            'impersonating' => ['sometimes', 'boolean'],
            'include_hidden' => ['sometimes', 'boolean'],
        ]);

        $provider = $this->provider();

        if ($provider === null) {
            return $this->catalog([]);
        }

        try {
            $actor = $this->resolver->resolve(
                (int) $validated['sso_user_id'],
                (int) $validated['sso_company_id'],
                (bool) ($validated['impersonating'] ?? false),
            );
        } catch (NotAMemberException) {
            return $this->notAMember();
        }

        if ($actor === null) {
            return $this->catalog([]);
        }

        $this->resolver->prepare($actor, $provider);

        $includeHidden = (bool) ($validated['include_hidden'] ?? false);

        $tools = [];

        foreach ($provider->tools($actor) as $tool) {
            if ($tool instanceof HiddenAssistantTool && ! $includeHidden) {
                continue;
            }

            $tools[] = [
                'name' => $tool->name(),
                'kind' => $tool->kind(),
                'definition' => $tool->definition(),
            ];
        }

        return $this->catalog($tools);
    }

    public function execute(string $name, ExecuteToolRequest $request): JsonResponse
    {
        $provider = $this->provider();

        if ($provider === null) {
            return $this->unknownTool();
        }

        try {
            $actor = $this->resolver->resolve(
                (int) $request->integer('sso_user_id'),
                (int) $request->integer('sso_company_id'),
                $request->boolean('impersonating'),
            );
        } catch (NotAMemberException) {
            return $this->notAMember();
        }

        if ($actor === null) {
            return $this->notAMember();
        }

        $this->resolver->prepare($actor, $provider);

        $tool = $this->findTool($provider, $actor, $name);

        if ($tool === null) {
            return $this->unknownTool();
        }

        try {
            $result = $tool->execute($request->args(), $actor);
        } catch (\Throwable $e) {
            Log::error('assistant tool failed', [
                'app' => config('sso.app_slug'),
                'tool' => $name,
                'sso_company_id' => $actor->ssoCompanyId,
                'error' => $e->getMessage(),
            ]);

            $result = AssistantToolResult::failed('That did not go through. Try again in a minute.');
        }

        return response()->json($result->toArray());
    }

    private function findTool(AssistantToolProvider $provider, AssistantActor $actor, string $name): ?AssistantTool
    {
        foreach ($provider->tools($actor) as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    private function provider(): ?AssistantToolProvider
    {
        return app()->bound(AssistantToolProvider::class) ? app(AssistantToolProvider::class) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function catalog(array $tools): JsonResponse
    {
        return response()->json(['app' => (string) config('sso.app_slug'), 'tools' => $tools]);
    }

    private function notAMember(): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => 'That person is not a member of this agency here.'], 404);
    }

    private function unknownTool(): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => 'Unknown tool.'], 404);
    }
}
