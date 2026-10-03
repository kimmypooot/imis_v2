<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacySso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a legacy subsystem redeems the one-time `sso_token` IMIS put on
 * its launch URL (legacy includes/session.php). Called server-to-server,
 * so it is stateless: no session, cookies or CSRF. The token is the only
 * credential, and it works once.
 *
 * Request:  POST token=<token>[&path=<the subsystem page's path>]
 * Response: 200 {success: true, user: {id, username, fname, …}}
 *           401 {success: false, message}
 */
class SsoValidateController extends Controller
{
    public function __invoke(Request $request, LegacySso $sso): JsonResponse
    {
        $token = $request->input('token');
        $path = $request->input('path');

        $user = is_string($token) && $token !== ''
            ? $sso->redeem($token, is_string($path) ? $path : null)
            : null;

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired sign-in token.'], 401);
        }

        return response()->json(['success' => true, 'user' => $sso->payload($user)]);
    }
}
