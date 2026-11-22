# Executive Dashboard summary feed (version 1)

How a connected system sends its figures to the IMIS Executive Dashboard.
IMIS **pulls** the summary every 30 minutes (`imis:collect-metrics`). IMIS
never connects to the system's database, and the system never pushes to
IMIS.

## What the system provides

One `GET` address, for example `https://proctad.example/api/imis/summary`:

- **Authentication:** IMIS sends `Authorization: Bearer <key>`. The key is
  generated in IMIS (Executive Dashboard → Settings → *Generate key*). It is
  shown only once there, so copy it into the system's `.env` as
  `IMIS_FEED_KEY`. Compare it with `hash_equals`. When the key is unset,
  answer 404 (the feed is off); when it is wrong, answer 401.
- **Response:** `200` with JSON in the format below, within 10 seconds,
  and under 256 KB. IMIS doesn't follow redirects.
- **Content:** totals only. Never a person's name, ID number or record
  (RA 10173). A breakdown is by office, status or type, never by person.

```json
{
  "version": 1,
  "generated_at": "2026-10-07T08:00:00+08:00",
  "metrics": [
    {
      "key": "examinations.upcoming",
      "label": "Upcoming examinations",
      "value": 3,
      "unit": "count",
      "period": "October 2026",
      "note": "Next: CSE-PPT, Mar 1, 2027",
      "breakdown": [ { "label": "Leyte FO", "value": 1 } ]
    }
  ]
}
```

| Field | Rules |
|---|---|
| `version` | Must be `1`. |
| `metrics` | A list of up to 50 figures. |
| `key` | Required and stable: `targets` and history are stored under it. Lowercase letters, digits and `_`, in dot-separated parts (`assignments.confirmed_rate`), up to 100 characters, and unique within the feed. |
| `label` | Required. What the figure counts, up to 120 characters. |
| `value` | A number, or `null` when there's nothing to count. |
| `unit` | One of `count` (default), `percent`, `days`, `hours` or `peso`. |
| `period`, `note` | Optional short text, such as the period covered or "12 of 40". |
| `breakdown` | Optional, up to 50 rows of `{ "label", "value" }`. |

IMIS refuses the whole feed when any rule is broken: the dashboard then
shows the system as *Not answering*, with the reason. Text is stripped of
HTML and cut to length. A feed can't add links into IMIS.

**Changing a figure:** add new keys freely. Renaming a key starts a new
history and leaves its target orphaned; IMIS lists orphaned targets on its
Settings page so they can be removed.

## Connecting a system

1. The system adds the endpoint (example below) and deploys.
2. In IMIS, Executive Dashboard → Settings: enter the address and Save,
   then *Generate key*.
3. Put the key in the system's `.env` as `IMIS_FEED_KEY` (and clear its
   config cache).
4. In IMIS, click *Collect now*. The system's tile should read *Running*.

*Replace key* invalidates the old key at once: until the new one is in the
system's `.env`, its figures stop updating (and viewers are alerted, if
alerts are on).

## Reference implementation (Laravel)

`config/services.php`, under the existing `imis` entry:

```php
'feed_key' => env('IMIS_FEED_KEY'),
```

`routes/web.php` (a `GET` with no session needs nothing else):

```php
Route::get('/api/imis/summary', ImisSummaryController::class)->middleware('throttle:30,1');
```

### PROCTAD

Served at `/api/imis/summary` (`App\Http\Controllers\Api\ImisSummaryController`
in `../proctad`; it is the model for the others: key check, then totals).
Starter figures: upcoming examinations (`examinations.upcoming`, with the
next one), the share of assignments confirmed for them
(`assignments.confirmed_rate`, broken down by status), active test
administrators (`members.active`, broken down by **testing center**, since
most test administrators have no field office) and certificates awaiting
approval (`certificates.pending`). They are placeholders until management
agrees which figures to track.

PROCTAD's database uses a table prefix (`proctad_`), so its controller
avoids raw table names in `selectRaw`; do the same in any system that has
one.

### JPortal

Served at `/api/imis/summary` (`App\Http\Controllers\Api\ImisSummaryController`
in `../jportal`). Starter figures, each broken down by sector (NGA, LGU,
SUC, Water District): open vacancy bulletins (`bulletins.open`, with how
many close within 7 days), bulletins posted this month
(`bulletins.posted_month`), and agencies that posted this year
(`agencies.posting_year`, no breakdown). Archived bulletins are left out.

### TIMS

Served at `/api/imis/summary` (`App\Http\Controllers\Api\ImisSummaryController`
in `../csc-tims`). Starter figures: published trainings scheduled or under
way (`trainings.scheduled`, with the next one), registrations received this
month by status (`registrations.month`), participants registered this month
by field office (`participants.month_by_office`), certificates issued this
year (`certificates.issued_year`), payments awaiting verification
(`payments.pending`), fees verified this month (`payments.verified_month`,
in pesos) and agency training requests awaiting HRD
(`agency_requests.awaiting`).

### Online Recruitment System

The same shape. Likely starter figures: open vacancies, applications
received this month, and applications at each stage (as a breakdown).
These need the recruitment repository's own status values, so the
controller has to be written in that repository.

### Legacy PHP systems (Exec-4)

A plain PHP file can serve the same response: check
`$_SERVER['HTTP_AUTHORIZATION']` against the key with `hash_equals`, run
`COUNT(*)` queries, and `echo json_encode([...])` with
`Content-Type: application/json`. This is a stopgap until each system is
replaced.

Apache drops the `Authorization` header before PHP sees it unless told
otherwise. Laravel's `public/.htaccess` already passes it on; a plain PHP
folder needs the same rule:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```
