## Contacts

```http
GET    /admin/api/v4/provisioning/contact-group
GET    /admin/api/v4/provisioning/member/{cust}/contact
POST   /admin/api/v4/provisioning/member/{cust}/contact
GET    /admin/api/v4/provisioning/contact/{contact}
PATCH  /admin/api/v4/provisioning/contact/{contact}
DELETE /admin/api/v4/provisioning/contact/{contact}
```

A member's contact persons — the NOC engineer, the person who receives the invoices — and their
roles. The equivalent of the web UI's contact form (`Contact\ContactController`).

```json
{
  "name": "Erika Mustermann",
  "position": "Head of Network Operations",
  "email": "erika@example.net",
  "phone": "+49 69 1234567",
  "mobile": "+49 170 1234567",
  "notes": "Prefers email.",
  "roles": [ 1, 2 ]
}
```

Only `name` is required. `POST /member/{cust}/contact` answers `201` with the contact:

```json
{
  "contact": {
    "id": 42, "custid": 97, "name": "Erika Mustermann",
    "position": "Head of Network Operations", "email": "erika@example.net",
    "phone": "+49 69 1234567", "mobile": "+49 170 1234567", "notes": "Prefers email.",
    "roles": [ { "id": 1, "name": "Billing", "type": "ROLE" },
               { "id": 2, "name": "Technical", "type": "ROLE" } ],
    "created_at": "2026-11-24T20:15:02+00:00", "updated_at": "2026-11-24T20:15:02+00:00"
  }
}
```

The member comes from the route; a `custid` in the body of a `POST` is ignored. `creator` and
`lastupdatedby` are set as the web sets them — the username and the id of the key's owner — and
are not part of the representation.

Two things the web UI does for custadmins do not happen here, because the key is a superuser's:
nothing keeps a contact to one member, and `notes` — which the web shows to superusers only,
never to the member's own users — is returned in full. A calling system with a member portal
has to apply both itself.

### Roles

`roles` is the complete list of the contact's contact groups, as ids from `GET /contact-group`.
The name follows the web form, which posts every group as `roles[]` — the ROLE type and any
other configured type alike — and each entry of a response carries its `type`.

Contact groups are optional. They are configured in `config/contact_group.php`, a file a stock
installation does not have (only `config/contact_group.php.dist`), and — as on the web contact
pages — they are on only when that file defines the `ROLE` type:

- `GET /contact-group` lists the groups of the configured types with `"enabled": true`, or
  answers `"enabled": false` and an empty list. `?type=ROLE` and `?active=1` (or `0`) filter.
- A contact can be given an active group of a configured type: what the web form offers.
  Anything else — an inactive group, a group of another type, any group while groups are off —
  is `422` under `roles`. The web controller drops an unknown id silently; a caller would
  believe the role had been set.

```json
{
  "enabled": true,
  "contact_groups": [
    { "id": 1, "name": "Billing", "description": "Contact role for billing matters",
      "type": "ROLE", "active": true, "limited_to": 0,
      "created_at": "2014-01-06T12:54:22+00:00", "updated_at": null }
  ]
}
```

**Group limits are checked before anything is written.** A group with `limited_to` n holds at
most n contacts per member; `0` means unlimited. A full group is `422` under `roles` and nothing
is saved. The web saves the contact first and finds the group full afterwards, which leaves a
contact without its roles behind an error message. The check runs inside the transaction that
writes the contact, under a row lock on the member, so two requests cannot both take the last
place.

### Changing a contact

`PATCH /contact/{contact}` takes any subset of the fields above, plus `custid`. A field that is
left out keeps its value; a field sent as `null` is cleared — except `name`, which the web rules
require. The change is validated as the web edit form validates it: the request is completed
with the contact's current values and runs through the same rules. Only the fields actually sent
are written.

- **`roles` is only touched when it is sent.** The web edit form always posts the complete form
  and detaches every active group missing from `roles[]`; a PATCH of a phone number therefore
  must not, and does not, strip the contact of its roles. Sent, `roles` is the complete set, and
  `[]` or `null` removes every group the API can give.
- **Groups the API cannot give are never taken away** — inactive ones, or of a type that is not
  configured. They can be sent back as they are, so what `GET` returns can be PATCHed back
  unchanged. (The web keeps inactive groups too, but detaches active groups of an unconfigured
  type, which its form never shows.)
- **`custid` moves the contact to another member**, as the web edit form does for superusers.
  The contact takes its groups along and needs room in each limited one at the new member,
  otherwise the move is `422` under `custid`. The web checks only the groups an edit adds.
- `creator` is kept, and the editor goes to `lastupdatedby`. The web overwrites `creator` with
  the editor's username on every edit.
- A change of roles alone is a change of the contact: it moves `updated_at` and sets
  `lastupdatedby`. The web leaves the contact row alone then, so its `updated_at` does not show
  role changes. A PATCH that matches what is stored writes nothing.

The response is the contact as `GET /contact/{contact}` returns it, plus `changed`: the columns
which actually changed, in table order, and `roles` when the groups changed — empty when nothing
did.

### Listing and deleting

`GET /member/{cust}/contact` lists the member's contacts, ordered by name. `?role={id}`
restricts it to one contact group; an id which names no group is `422`. The web list ignores a
filter it does not recognise and shows every contact, which here would hand a caller asking for
its billing contacts all of them. The filter reads the stored group links, configured or not.

`DELETE /contact/{contact}` removes the contact's group links and the contact in one transaction
— `contact_to_group` references the contact without `ON DELETE CASCADE` — and answers
`{"deleted": true}`. A second delete is `404`.

### Pitfalls

- **`POST` is not idempotent.** A caller which times out and retries creates a second contact.
  List the member's contacts before retrying.
- **`position` is cut to 50 characters.** The web rule allows 255, but the column is
  `varchar(50)` and the database connection runs with strict mode off, so on v7.4 the excess is
  silently dropped rather than refused. In the same way `email` is validated without a length
  limit for a `varchar(255)` column, and `creator` keeps the first 32 characters of a username.
- **`409`** comes only from a race with a change made in the web UI at the same moment: the API
  serialises its own changes of one contact on a row lock, the web takes none. Nothing was
  written; read the contact again and retry.
- The web edit form still strips the groups it does not show. That is why the API gives a
  contact only groups the form can offer — anything else would vanish with the next edit made
  in the web UI.

### Validation

The web validates contacts inline, in `ContactController::checkForm()`, so there is no web form
request to extend. `StoreContact` reproduces those rules — the superuser branch, which requires
`custid` — and adds `roles` as its delta; `UpdateContact` completes a PATCH with the current
values before the same rules run. `ContactParityTest` holds the copy to the original: it
captures the rules `checkForm()` hands to the validator and compares them with the copy, so an
upstream change fails a test at merge time; it sends the same invalid payloads to the web form
and to the API and asserts that the same fields fail; and it compares the rows and group links
both entry points leave behind.
