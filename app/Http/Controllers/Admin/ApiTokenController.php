<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiIntegration;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\Integrations\ApiTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Integration Tokens: the bearer tokens connected systems use to call
 * IMIS's server-to-server APIs. A token is shown once, when it is issued;
 * only its hash is stored.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request, ApiTokens $tokens): Response
    {
        $issued = ApiToken::query()->with('creator:id,name')->latest('id')->get();

        return Inertia::render('Admin/ApiTokens/Index', [
            'integrations' => collect(ApiIntegration::cases())->map(fn (ApiIntegration $integration) => [
                'value' => $integration->value,
                'label' => $integration->label(),
                'legacy_secret' => $tokens->hasLegacySecret($integration),
                'tokens' => $issued->where('integration', $integration)->values()->map(fn (ApiToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'hint' => $token->token_hint,
                    'status' => $token->statusLabel(),
                    'created_by' => $token->creator?->name,
                    'created_at' => $token->created_at->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'last_used_ip' => $token->last_used_ip,
                ]),
            ]),
            // Set only on the redirect right after issuing: the one time the token is visible.
            'issued' => $request->session()->get('issued_api_token'),
        ]);
    }

    public function store(Request $request, ApiTokens $tokens): RedirectResponse
    {
        $data = $request->validate([
            'integration' => ['required', Rule::enum(ApiIntegration::class)],
            'name' => ['required', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $integration = ApiIntegration::from($data['integration']);

        [$token, $plain] = $tokens->issue(
            $integration,
            $data['name'],
            $request->user(),
            isset($data['expires_at']) ? now()->parse($data['expires_at'])->endOfDay() : null,
        );

        $this->audit()->record(
            AuditEvent::ApiTokenCreated,
            "Issued an API token \"{$token->name}\" for {$integration->label()}",
            $token,
            ['integration' => $integration->value, 'expires_at' => $token->expires_at?->toIso8601String()],
        );

        return back()->with('issued_api_token', [
            'integration' => $integration->label(),
            'name' => $token->name,
            'token' => $plain,
        ]);
    }

    public function destroy(ApiToken $apiToken, ApiTokens $tokens): RedirectResponse
    {
        $tokens->revoke($apiToken, request()->user());

        $this->audit()->record(
            AuditEvent::ApiTokenRevoked,
            "Revoked the API token \"{$apiToken->name}\" for {$apiToken->integration->label()}",
            $apiToken,
            ['integration' => $apiToken->integration->value],
        );

        return back()->with('status', "Token \"{$apiToken->name}\" was revoked.");
    }
}
