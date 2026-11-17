# BMIS employee feed

IMIS serves the list of current employees to BMIS (`../new-bmis`) for its
Employee manager and organization chart. BMIS calls IMIS server-to-server;
IMIS never calls BMIS.

## Request

```
GET /api/v1/bmis/employees
Authorization: Bearer <token>
Accept: application/json
```

Limited to 60 requests a minute per client. Without a valid token the
answer is `401 {"message": "Unauthorized."}`.

## Response

```json
{
  "data": [
    {
      "id": 12,
      "name": "Juan M. Dela Cruz",
      "username": "jmdelacruz",
      "email": "juan@example.test",
      "position": "Human Resource Specialist II",
      "unit_name": "Human Resource Division",
      "office_name": "CSC Regional Office VIII",
      "office_code": "RO8",
      "photo_url": "https://…/storage/profile-photos/….jpg"
    }
  ]
}
```

- Active accounts only, ordered by last name then first name.
- `unit_name` is the person's own unit. `office_name` / `office_code` are
  the unit itself or its nearest ancestor that is a regional unit, field
  office or satellite office (`null` if none).
- `photo_url` is `null` when the person has no photo.
- Only directory details are served: no PDS, plantilla, leave or payroll
  data.

## Tokens

An administrator issues the token under **Admin → Integration Tokens**
(permission `api_tokens.manage`), choosing BMIS.

- The token is shown once, when it is issued; IMIS keeps only its SHA-256
  hash. Copy it into BMIS's environment right away.
- It expires after a year by default (the date can be changed or cleared).
  Tokens that expire within 14 days are flagged on the page. To rotate,
  issue a new token, switch BMIS to it, then revoke the old one.
- The page shows when and from where each token was last used, so an idle
  token is easy to spot and revoke.
- Issuing and revoking are audited (Admin → Audit Logs).
- The older single secret in `BMIS_API_TOKEN` (`.env`) is still accepted so
  an existing connection keeps working. Leave it empty once BMIS uses an
  issued token.
