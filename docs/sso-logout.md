# Single sign-out for connected systems (version 1)

When someone signs out of IMIS, every Laravel system they opened from that
IMIS session ends its own session too. Signing out of a system does **not**
sign the person out of IMIS or of other systems: the system ends its own
session and sends them back to the IMIS portal.

Legacy PHP systems are not covered yet (see the end).

## How it works

1. The person launches the system from IMIS. IMIS adds a one-time
   `?sso_token=` to the URL, as before, and remembers which IMIS session
   issued it.
2. The system redeems the token at `POST /api/v1/sso/validate` (unchanged).
   The response's `user` now also carries **`sid`**: a random 64-character
   reference, or `null` when the token wasn't issued from an IMIS session.
3. The system saves `sid` with the session it starts for that person.
4. When the person signs out of IMIS, IMIS calls the system's logout
   address once with that `sid`. The system ends the matching session.

IMIS never waits long for this: each call has a 3-second limit, and a
system that is down or answers with an error doesn't stop the person from
signing out of IMIS. That system's session then ends by its own idle limit.

## What the system provides

One `POST` address on its own origin, `/sso/logout` by default (IMIS builds
it from the launch URL's scheme, host and port plus `SSO_LOGOUT_PATH`):

- **Request:** JSON `{ "sid": "<the 64-character reference>" }`. No cookies,
  no CSRF token: IMIS calls it server-to-server, so exempt the route from
  CSRF and keep it out of the `web` session middleware's auth group.
- **Authentication:** the `sid` is the only credential. It is secret, random
  and known only to IMIS and the system, so treat it like a password: never
  log it or put it in a URL.
- **Action:** destroy every session stored with that `sid`. An unknown or
  already-ended `sid` is not an error.
- **Response:** `200` `{ "success": true }` within 3 seconds, no redirect
  (IMIS doesn't follow redirects). Answer `404` while the feature is off.

## Reference implementation (Laravel, database sessions)

Add a nullable `imis_sid` column to the system's `sessions` table (or to
whatever table holds its sessions), and keep the `sid` from the redeem
response in the session at sign-in:

```php
// where the system signs the person in after redeeming the token
$request->session()->put('imis_sid', $response['user']['sid'] ?? null);
```

```php
// routes/web.php
Route::post('/sso/logout', SsoLogoutController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:60,1');
```

```php
class SsoLogoutController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $sid = $request->input('sid');

        if (is_string($sid) && strlen($sid) === 64) {
            // Sessions store their payload serialized, so look it up by the
            // value kept in the dedicated column.
            DB::table('sessions')->where('imis_sid', $sid)->delete();
        }

        return response()->json(['success' => true]);
    }
}
```

To fill the column, set it when the session row is written (for example, in
a small middleware after sign-in:
`DB::table('sessions')->where('id', $request->session()->getId())->update(['imis_sid' => $request->session()->get('imis_sid')])`).
A system on file or Redis sessions can instead keep a `sid → session id`
map and delete that session through its store.

Signing out of the system itself ends only its own session and redirects to
the IMIS portal:

```php
Auth::logout();
$request->session()->invalidate();

return redirect()->away(config('services.imis.portal_url'));
```

## Connecting a system

1. The system adds the logout endpoint, stores `sid` at sign-in, and deploys.
2. Its host is already in IMIS `SSO_HUB_HOSTS`; nothing else to set unless
   the path isn't `/sso/logout` (then set `SSO_LOGOUT_PATH` in IMIS).
3. Test: sign in to IMIS, open the system, sign out of IMIS, and reload the
   system. It should ask to sign in.

Systems that don't have the endpoint yet are simply called and fail; nothing
breaks, and they keep their idle-limit behaviour.

## When systems are told

IMIS calls the logout address whenever the IMIS session ends, however it ends:

| Ending | When the system is told |
|---|---|
| The person signs out | At once |
| Absolute 8-hour cap | At once, on the next request |
| An administrator force-ends the session | At once |
| Account deactivated (by an administrator, separation or dormancy) | At once |
| Idle timeout (2 hours) | Within about 5 minutes (`imis:expire-sessions`) |

"Remember me" sign-ins that the cookie silently restores start a new IMIS
session; the system's old session was already ended when the first one was.

## Not covered yet

- **Legacy PHP systems** (`LEGACY_SSO_HOSTS`): they have no `sid` or logout
  endpoint. Until each gets one, rely on a short idle limit in its
  `includes/session.php`.
