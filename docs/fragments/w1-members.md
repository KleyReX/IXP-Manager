<!--
Fragment of docs/provisioning-api.md from the wave 1 "members" work package.

Merge notes:
  - "## Members" below replaces the section of that name, including "### Changing a member".
  - "### Billing and registration details" is new; it belongs at the end of "## Members".
  - The two bullets under "Conventions" replace the first bullet and extend the 409 bullet of
    the existing "## Conventions" list - the first one is stale since PATCH member/{cust}.
  - The paragraphs under "Validation" are additions to "## Validation".
  - The bullet under "What is deliberately absent" replaces "No suspend or offboard endpoints yet".
-->

## Conventions

- Anything which creates or changes state uses `POST`, `PATCH` or `DELETE` — never `GET`.
  `PATCH` changes only the fields it is given and answers with what actually changed.
- `409` also when a change is refused because of the state the record is in, with a `message`
  saying why — for example a billing change for a member whose billing its reseller manages.

## Members

```http
GET   /admin/api/v4/provisioning/member
POST  /admin/api/v4/provisioning/member
GET   /admin/api/v4/provisioning/member/{cust}
PATCH /admin/api/v4/provisioning/member/{cust}
POST  /admin/api/v4/provisioning/member/{cust}/user
GET   /admin/api/v4/provisioning/member/{cust}/billing-registration
PATCH /admin/api/v4/provisioning/member/{cust}/billing-registration
```

The member payload is validated with the same rules as the web form, plus a small addition —
see *Validation* below.

```json
{
  "name": "Example Networks Ltd",
  "shortname": "example",
  "abbreviatedName": "EXAMPLE",
  "type": 1,
  "status": 1,
  "datejoin": "2026-08-01",
  "autsys": 65550,
  "maxprefixes": 500,
  "maxprefixesv6": 500,
  "peeringemail": "peering@example.com",
  "peeringmacro": "AS-EXAMPLE",
  "nocemail": "noc@example.com",
  "peeringdb_oauth": true
}
```

`peeringdb_oauth` — whether the member's users may log in with their PeeringDB account — is
accepted on creation as well as on change. Left out, it takes the database default, which is
`true`, as in the web form, where the box is ticked for a new member. The `member` section of
`POST /onboarding` accepts it too, since it is validated with the same rules.

`PeeringDB` lookup remains available at the existing endpoint
`GET /admin/api/v4/customer/query-peeringdb/asn/{asn}` and is a useful way to pre-fill this.

A `cust` or `id` key in the body of `POST /member` or `PATCH /member/{cust}` is ignored. It
used to end the request in a `500`: the inherited web rules read the member being edited as
`$this->cust`, and Laravel resolves that name from the input before the route.

### The member representation

Every member endpoint — create, read, change, list — returns the same field list, so a member
can be diffed against the caller's records whichever way it was fetched. The fields are the ones
`POST /member` takes, plus:

| field | meaning |
| --- | --- |
| `id` | `cust.id` |
| `is_current` | Joined on or before today and the leave date, if any, not yet passed — `Customer::SQL_CUST_CURRENT`, the definition the switch, route server, IRRDB and IX-F exports use. **True through the leave date itself.** |
| `has_left` | A leave date is set — any leave date, a future one included (`Customer::hasLeft()`). PeeringDB OAuth logins, for one, are refused on that basis. |
| `in_manrs`, `in_peeringdb` | Maintained by the scheduled MANRS and PeeringDB jobs. Read-only. |
| `creator` | Who created the member, as a string: the user id for members created through the web UI or this API, a username on older rows. |
| `lastupdatedby` | User id of the last change through the web edit form or `PATCH /member/{cust}`; `null` if there was none. |
| `created_at`, `updated_at` | ISO 8601 with offset. `datejoin` and `dateleave` are `Y-m-d`. |

`is_current` and `has_left` disagree for a member whose leave date is today or in the future:
it has left in the sense of `has_left` and is still current. Ask the question you mean.
`is_current` is evaluated by MySQL with `CURRENT_DATE()`, exactly as the exports evaluate it — so
around midnight it follows the database's clock, which need not be in `app.timezone`.

The response to `POST /member` is the member as stored. Until this release it echoed the
request, so a field left to its database default came back wrong — `peeringdb_oauth` as `false`
while the database held `1`.

### Listing members

```http
GET /member?current=1&autsys=65550
GET /member?updated_since=2026-11-01T00:00:00Z&limit=500
```

```json
{
  "members": [ { "id": 97, "shortname": "example", "...": "as GET /member/{cust}" } ],
  "total": 1
}
```

Ordered by `id`. `total` counts every match, not just the page.

| parameter | selects |
| --- | --- |
| `limit` | page size, default 100, at most 1000 |
| `offset` | members to skip, in `id` order |
| `status` | `1` Normal, `2` Not Connected, `3` Suspended |
| `type` | `1` Full, `2` Associate, `3` Internal, `4` Pro-bono |
| `current` | `1`/`true`: `is_current` members; `0`/`false`: all others, a member without a join date included. The two partition the members. |
| `autsys` | AS number |
| `shortname` | exact shortname (the column's collation compares case-insensitively) |
| `q` | substring of the name or the shortname; `%` and `_` are literal |
| `reseller` | members resold by this member id |
| `tag` | members carrying this tag (`cust_tag.id`) |
| `updated_since` | `updated_at` at or after this point in time — see below |

An unknown member id for `reseller`, an unknown tag, a value outside the sets above and **any
parameter not in this table** are `422`. The web member list ignores what it does not
recognise; for an unattended caller a misspelt `shortname` would return the first page of all
members, and the first entry would look like the match.

**`updated_since` is a convenience, not a change feed.** It compares with `cust.updated_at`,
inclusively, and an offset in the value is honoured. Feed back the greatest `updated_at` you have
seen and expect to see those members again. Where it misleads:

- Writes which bypass Eloquent leave `updated_at` alone. The daily MANRS and PeeringDB jobs
  (`ixp-manager:update-in-manrs`, `ixp-manager:update-in-peeringdb`) reset `in_manrs` /
  `in_peeringdb` for every member that way, so a member dropping out of MANRS or PeeringDB is
  never reported.
- Conversely those jobs then set the flag again through the model, which touches `updated_at`:
  every current member listed in MANRS or PeeringDB shows up as changed after every run.
- A member whose `updated_at` is NULL — one never changed through Eloquent — never matches.
- Changes to the billing and registration details are not changes to `cust` and do not touch
  its `updated_at` — except when a PATCH has to create a missing record and links it to the
  member.

### Changing a member

`PATCH /member/{cust}` takes any subset of the member's fields — the ones `POST /member` takes.
A field that is left out keeps its value; a field sent as `null` is cleared. Setting a leave date
is therefore just:

```json
{ "dateleave": "2026-11-01" }
```

The change is validated as the web edit form validates it: the request is completed with the
member's current values and the result runs through the same rules. One deliberate leniency:
a field which is empty today and not sent is not filled in, so a member whose `nocemail` or
`peeringpolicy` was never set — which the web form would insist on — can still be given a leave
date. A value that is sent, or already stored, is validated in full.

The response is the member as `GET /member/{cust}` returns it, plus `changed`: the attributes
which actually changed, empty if the PATCH matched what was stored. Fields outside the edit form
(`id`, `creator`, the company detail ids, ...) are ignored.

Creating a user sends the welcome email carrying the password reset link — without it the
account cannot be used. The customer comes from the route, so a `custid` in the body is
ignored. The API takes `enabled` (boolean, default true); the web form's inverted `disabled`
field is not reproduced.

### Billing and registration details

```http
GET   /member/{cust}/billing-registration
PATCH /member/{cust}/billing-registration
```

The member's registered company details and billing details — the web's "Billing /
Registration Details" form. `POST /member` creates both records empty; this is how they are
filled without writing to the database.

```json
{
  "billing_registration": {
    "member_id": 97,
    "billing_managed_by_reseller": false,
    "registration": {
      "registeredName": "Example Networks GmbH", "companyNumber": "HRB 12345",
      "jurisdiction": "Frankfurt am Main", "address1": "Hanauer Landstrasse 1",
      "address2": null, "address3": null, "townCity": "Frankfurt", "postcode": "60314",
      "country": "DE", "notes": null,
      "created_at": "2026-08-01T10:00:00+02:00", "updated_at": "2026-11-24T21:03:34+01:00"
    },
    "billing": {
      "billingContactName": "Accounts Payable", "billingFrequency": "QUARTERLY",
      "billingAddress1": "Postfach 100", "billingAddress2": null, "billingAddress3": null,
      "billingTownCity": "Offenbach", "billingPostcode": "63065", "billingCountry": "DE",
      "billingEmail": "billing@example.net", "billingTelephone": "+49 69 1234567",
      "purchaseOrderRequired": false, "purchaseOrderNumber": null,
      "invoiceMethod": "EMAIL", "invoiceEmail": "invoices@example.net",
      "vatRate": "19", "vatNumber": "DE123456789", "billingNotes": null,
      "created_at": "2026-08-01T10:00:00+02:00", "updated_at": "2026-11-24T21:03:34+01:00"
    }
  }
}
```

The field names are the web form's, and `PATCH` takes the same names, flat:

```json
{ "billingEmail": "billing@example.net", "vatNumber": "DE123456789" }
```

Both records have a `notes` column. `notes` is the registration record's; the billing record's
is `billingNotes` — the web form's mapping. `country` and `billingCountry` are ISO 3166 alpha-2
codes, `billingFrequency` one of `MONTHLY`, `2MONTHLY`, `QUARTERLY`, `HALFYEARLY`, `ANNUALLY`,
`NOBILLING`, `invoiceMethod` `EMAIL` or `POST`, `purchaseOrderRequired` a boolean.

PATCH semantics are those of `PATCH /member/{cust}`: left out keeps, `null` clears, the
response carries `changed` (under the field names above). Two refinements, both following the
web form:

- A stored `country` / `billingCountry` which is not an ISO code — written by something other
  than the web form — is neither validated nor cleared by a PATCH which does not send it. The
  web form shows such a value as empty. Any other stored value is validated in full, so a
  stored invalid `billingEmail` must be corrected (or cleared) in the same PATCH.
- A record the member lacks — possible for members written to the database directly — reads as
  `null`, and is created when a PATCH has something to store in it, as `POST /member` would
  have created it.

**Resold members in reseller mode.** With reseller mode on (`IXP_RESELLER_ENABLED`), a resold
member's billing is its reseller's business: the web form has no billing section for it and
leaves the record alone. `billing_managed_by_reseller` is then `true`, and a PATCH which would
change a billing field is refused with `409` — as a whole, registration fields included. Billing
fields sent with the values already stored are no change and are accepted. The billing record is
still reported, and its stored values are not validated on such a member's PATCH, since the web
form does not post them. (`IXP_RESELLER_RESOLD_BILLING` only hides links in the web UI; the web
controller does not consult it, and neither does the API.)

**Notification.** `IXP\Events\Customer\BillingDetailsChanged` fires when — and only when — the
billing record actually changed. The web fires it on every save of the form; for an unattended
caller retrying a PATCH that would mail the accounts team each time. Its listener mails
`ixp_fe.customer.billing_updates_notify` if set, and never for a resold member, in reseller
mode or not. The event fires after the change has been committed.

## Validation

`peeringdb_oauth` joined the customer delta: the web create and edit forms post it and the
column is fillable, but no web rule names it, so `validated()` dropped it. `UpdateCustomer` now
adds nothing of its own — its rules and delta are `StoreCustomer`'s.

`UpdateBillingRegistration` extends the web's `Customer\BillingInformation`; its delta is
`purchaseOrderRequired` (boolean), which the web form posts but no web rule validates — the
web writes `$r->all()`, this API only validated fields. `MemberRulesParityTest` holds it to the
web rules like the others, and also asserts that every validated field is written and every
written field is validated, fillable and readable back.

`GET /member` has no web request to extend: the admin list reads its filters unvalidated and
ignores what it does not recognise. `ListCustomers` validates them against the sets the web
uses.

## What is deliberately absent

- **No offboarding and no deletion of members.** A member leaves by setting `dateleave` (and,
  if wanted, `status`) through `PATCH /member/{cust}` — what the web UI does. Neither releases
  ports, addresses, users or contacts. A composite offboarding endpoint and hard deletion wait
  for a product decision.
