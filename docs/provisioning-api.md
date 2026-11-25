# Provisioning API

A stateless, machine-to-machine API through which an external system such as an ordering or
billing platform creates, reads, changes and removes members, their contacts and portal users,
and their connections to the exchange - instead of writing to IXP Manager's database.

It exists because the existing API v4 is a read and export surface: it publishes switch
configuration, route server configuration, Nagios targets and DNS data, but has no endpoint
which creates a member, a user or a port. Those live only in the web controllers.

> **Note on the existing endpoints.** The POST endpoints under `admin/api/v4` — including
> `switch/{s}/switch-port-prewired` — cannot be called from outside a browser. That route group
> carries the `web` middleware, and `VerifyCsrfToken` excepts only `login`. This API sits in its
> own route group without `web`, authenticated by API key alone.

## Off by default

These endpoints do not exist until an operator asks for them:

```dotenv
IXP_API_PROVISIONING_ENABLED=true
```

Without it the routes are never registered and every path answers `404`. They are the only
endpoints in IXP Manager which let an external caller create and change business objects, so an
upgrade must not be able to expose an installation to something it did not choose.

Further keys under `ixp_api.provisioning`: `require_api_key` (default true), `allowed_ips`
(comma-separated addresses and CIDR ranges, empty means unrestricted) and `rate_limit`
(default 60 a minute per user — the owner of the key, so several keys of one user share it —
zero disables).

`allowed_ips` is checked against the address Laravel reports for the request. IXP Manager
configures no trusted proxies, so behind a reverse proxy on another host every request appears
to come from the proxy: list the proxy only if it admits nobody but the calling system, or
configure trusted proxies first.

## Authentication

Every endpoint requires a **superuser** API key, passed in the header:

```
X-IXP-Manager-API-Key: ixpm_ident1234567_sec876543210...
```

A non-superuser key gets `403`; no key gets `401`. `GET /ping` confirms the key and returns the
resolved username — the way to verify a deployment without creating anything.

The key is the only credential these endpoints accept. A browser session is not accepted in
place of a key, and not alongside one either: the cookies of a request carrying a key are
discarded before the session starts, so a logged-in superuser's browser cannot vouch for a key
that is wrong.

Three operational points:

- **Keys expire after at most 12 months** (`ixp_fe.api_keys.max_expires_duration`). An
  unattended service will fail with `401` exactly once a year unless the key is rotated.
- **`allowed_ips` on an API key is enforced on these endpoints** — for modern and legacy keys
  alike — and nowhere else in IXP Manager. The same key used against other API v4 endpoints
  is not restricted by it, so it remains a full-access superuser credential there. Restrict
  `/admin` at the web server or firewall to the address of the calling system as well.
- **The key cannot lock itself out.** It authenticates as its owner. IXP Manager refuses the
  key of a disabled user or of a user whose default member is not active, and these endpoints
  refuse a user who is not a superuser on that member. A request which would bring one of those
  about is refused with `409` and changes nothing: closing or suspending the owner's own member
  (`PATCH /member/{cust}`), and disabling, renaming or deleting the owner or lowering its
  privilege on its default member (see *Users*). Such a change is made in the web UI, or with
  another superuser's key.

## Conventions

- Base path: `/admin/api/v4/provisioning`
- Anything which creates, changes or removes state uses `POST`, `PATCH` or `DELETE` — never
  `GET`. There is no `PUT`: nothing is replaced wholesale.
- **`PATCH` changes only what it is given.** A field left out keeps its value; a field sent as
  `null` is cleared, or refused where the web form requires it. The request is completed with
  the stored values and validated as the web edit form would validate the whole record, and the
  response carries `changed`: the fields which actually changed, empty when the PATCH matched
  what was stored.
- **Ids in a path are digits.** `member/2abc` matches no route and is a `404`; it is never looked
  up. (Route model binding used to hand such a value to MySQL, which casts `2abc` to `2`: a
  PATCH of `member/2abc` changed member 2.)
- **Ids come from the path, never from the body.** A `cust` or `id` key in a request body is
  ignored. Where the inherited web rules read the record being changed through such a name -
  members, billing details, new users, the sections of an onboarding order - it is removed
  before validation, because a body value would take the route's place: that ended in a `500`,
  or exempted somebody else's username from the uniqueness check. The user and member link
  endpoints remove every key named like one of their route parameters (`u`, `cust`) for the
  same reason. Any other field a request does not take is ignored as well.
- Responses are JSON, with one exception worth knowing: `401` and `403` raised by the
  upstream authentication middleware are plain text (`Unauthorized.`, `API key expired`,
  `Insufficient permissions`), not JSON. Only the guards added here answer with `{message}`.
  A client must not assume a JSON body on an authentication failure.
- Every representation is an explicit field list, built by the presenters in
  `IXP\Support\Provisioning\Presenters` rather than by the model's `toArray()`: a column added
  upstream does not appear on its own, and no secret ever does - no password hash, remember-me
  token, SNMP community or BGP MD5 secret (those are write-only, reported as `*_set`).
- Validation failures are `422` with `message` and `errors`, the latter keyed by field.
  Refusals raised by the code itself — an address outside the pool, an unusable switch port —
  are `422` with `message` only.
- `201` for something created, `200` for a read, a change, a delete or a no-op — and for a
  `POST` which found what it would have created (`POST /irrdb-source`) — `404` for an unknown
  id, `409` for a conflict which is not a validation problem: the state a record is in refuses
  the change, with a `message` saying why (a billing change for a member whose billing its
  reseller manages, the last port of a connection), or another request won a race. A refused
  request writes nothing.
- Booleans in a query string are `1` or `0`: Laravel's `boolean` rule refuses the words `true`
  and `false` there. `current` on `GET /member` takes both forms; `active` on `GET /switch` also
  takes `all`.
- Lists say when they are incomplete: `truncated` where a `limit` cut one short, `total` where
  one is paged with `limit` and `offset`.

## Members

```http
GET   /admin/api/v4/provisioning/member
POST  /admin/api/v4/provisioning/member
GET   /admin/api/v4/provisioning/member/{cust}
PATCH /admin/api/v4/provisioning/member/{cust}
GET   /admin/api/v4/provisioning/member/{cust}/billing-registration
PATCH /admin/api/v4/provisioning/member/{cust}/billing-registration
POST  /admin/api/v4/provisioning/member/{cust}/offboard
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

A member's portal users are created with `POST /member/{cust}/user` and its connections with
`POST /member/{cust}/connection`; both are described under *Users* and *Connections*.

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

The response to `POST /member` is the member as stored. Earlier versions of this API echoed the
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

**The member of the key's own user cannot be deactivated here.** IXP Manager refuses the key of
a user whose default member is suspended or has left, so a PATCH of that member which sets
`status` to *Suspended*, or a leave date which has passed, would lock the caller out with its own
request. It is refused with `409` and nothing changes. The check is the query `ApiAuthenticate`
runs, asked inside the transaction, so it judges the change exactly as the next request would.

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

### Offboarding a member" belongs at the end of "## Members" (after "### Billing and
    registration details" from the members fragment), and its endpoint joins the list at the top
    of that section:
        POST  /admin/api/v4/provisioning/member/{cust}/offboard
  - The bullet under "What is deliberately absent" replaces both "No suspend or offboard
    endpoints yet" (main document) and "No offboarding and no deletion of members" (members
    fragment).
  - The paragraph under "Concurrency" is an addition to that section.
  - "Machine-readable specification": 409 also arises from POST /member/{cust}/offboard - a
    connection of the member in a core bundle, or the member being the API key owner's default
    member.
  - DELETE /connection/{vi} is unchanged in behaviour and response; its teardown is now shared
    with the offboarding (TearsDownConnections).
-->

### Offboarding a member

```http
POST /admin/api/v4/provisioning/member/{cust}/offboard
```

Takes a member off the exchange, in one transaction:

- **Every connection is torn down**, each exactly as `DELETE /connection/{vi}` removes one - it is
  the same code: the physical interfaces through the inherited, fanout-aware `deletePi()`, the
  VLAN interfaces with their configured MACs, the discovered MACs, the sflow receivers and the
  virtual interface. Switch ports, IP addresses and MAC addresses are free for the next order;
  the address records stay in their VLAN's pool.
- **The member is closed**: a leave date (the one given, or today) and the status `2`,
  *Not Connected*.
- **The member record, its portal users and its contacts stay**, as history. Nothing else is
  changed. What the offboarding leaves behind is reported under `warnings`.

IXP Manager itself has no such action: the web closes a member by setting the leave date in the
edit form, which releases nothing. `PATCH /member/{cust}` and one `DELETE /connection/{vi}` per
connection reach the same end, but not atomically - a caller failing halfway is left with a
member half gone. Here all of it happens or none of it, and a dry run shows which.

Every field is optional:

```json
{ "dateleave": "2026-11-30", "dry_run": true, "reason": "Contract 4711 terminated" }
```

| field | meaning |
| --- | --- |
| `dateleave` | `Y-m-d`, a real calendar date. Default: today - unless the member already has a leave date of today or earlier, which is then kept (below). Past and future dates are accepted, and so is one before `datejoin`: an order cancelled before the member joined. |
| `dry_run` | Boolean, default `false`. `true` answers what the request would do and does nothing. `1`/`0` and the strings `"true"`/`"false"` are accepted as well. |
| `reason` | Up to 255 characters. Written to the log line of the offboarding and nowhere else; line breaks and other control characters become spaces. |

**Any other key is a `422`.** On purpose: a misspelt `dry_run` - `dryrun`, `dry-run` - must not
turn a preview into the real thing. There is no `status` either: an offboarded member is
*Not Connected*.

**What the leave date means.** IXP Manager reads it on two clocks, which disagree about the day
itself:

- Logins and API keys: `Customer::scopeNotDeleted()` compares the leave date with the current
  date *and time*, so **a leave date of today already counts as left** - from the first second
  of the day, the member's users are refused (see the warnings below).
- The configuration exports - switch, route server, IRRDB, IX-F - use `Customer::SQL_CUST_CURRENT`
  and keep the member current **through** its leave date: `is_current` stays `true` until the
  day after. With the connections gone that changes little in the exports, but every other
  consumer of `is_current` sees a member still current on its leave date.

A leave date in the future does not postpone anything: the connections go now. To offboard on a
future date, call this endpoint on that date. *Not Connected* counts as active in IXP Manager, so
until a future leave date the member's users can still log in.

When no leave date is sent and the member already has one which has been reached - today or
earlier - it is kept: it records when the member left, and a retry on a later day must not move
it. One in the future is brought forward to today, since the member is leaving now.

#### The answer

`200`, with what changed, what was removed and what stays behind:

```json
{
  "member": { "id": 2, "shortname": "heanet", "status": 2, "dateleave": "2026-11-24",
              "is_current": true, "has_left": true, "...": "as GET /member/{cust}" },
  "changed": [ "dateleave", "status" ],
  "removed": {
    "connections": [
      { "id": 1, "custid": 2, "name": "Port-Channel", "trunk": false, "lag_framing": true,
        "channelgroup": 1,
        "physical_interfaces": [
          { "id": 1, "status": 1, "speed": 1000, "duplex": "full", "rate_limit": null, "autoneg": true,
            "switchport": 3, "switchport_name": "GigabitEthernet3", "switch": 1, "switch_name": "switch1" },
          { "id": 2, "...": "the second port of the LAG" }
        ],
        "vlan_interfaces": [
          { "id": 1, "vlan": 1, "ipv4enabled": true, "ipv4address": "10.1.0.10", "ipv6enabled": true,
            "ipv6address": "2001:db8:1::10", "...": "as GET /member/{cust}/connection" }
        ] },
      { "id": 2, "...": "the second connection" }
    ],
    "physical_interfaces": 3,
    "vlan_interfaces": 2,
    "mac_addresses": 0,
    "discovered_mac_addresses": 0,
    "sflow_receivers": 0,
    "ip_addresses": [
      { "id": 10,  "vlan": 1, "protocol": 4, "address": "10.1.0.10" },
      { "id": 16,  "vlan": 1, "protocol": 6, "address": "2001:db8:1::10" },
      { "id": 137, "vlan": 2, "protocol": 4, "address": "10.2.0.11" },
      { "id": 417, "vlan": 2, "protocol": 6, "address": "2001:db8:2::11" }
    ]
  },
  "warnings": [
    "This member has 6 staged and 6 production route server filter rule(s). They stay: with no route server session left they have no effect, and IXP Manager removes them only with the member itself.",
    "1 portal user(s) have no active member besides this one: hecustadmin (id 5). They are neither disabled nor deleted, but from 2026-11-24 on IXP Manager refuses their login (\"Your user account is not associated with any active members.\"), ends a session of theirs at its next request and refuses their API keys. DELETE user/{u} removes a user for good."
  ],
  "dry_run": false
}
```

- `member` - as `GET /member/{cust}` returns it, after the change.
- `changed` - the member attributes which changed, `dateleave` and `status`, as
  `PATCH /member/{cust}` reports them; empty when they already held what was asked for.
- `removed.connections` - one entry per connection torn down, in the shape
  `GET /member/{cust}/connection` returns, as it was just before.
- `removed.physical_interfaces`, `vlan_interfaces`, `mac_addresses` (configured, `l2address`),
  `discovered_mac_addresses` (`macaddress`, filled by the layer 2 address tooling) and
  `sflow_receivers` - how many of each went with them.
- `removed.ip_addresses` - every address released to its pool: the pool entry's id (the `id` of
  `GET /vlan/{vlan}/address`), its VLAN, its family and the address.
- `warnings` - what stays behind; see below. Not errors: the request succeeded.
- `dry_run` - whether this was only a preview.

**Idempotent.** A member without connections whose leave date and status already are what the
request asks for is not written at all: `200`, nothing removed, `changed: []`. A retry of an
offboarding which went through answers as the first call did, except that nothing is removed -
unlike `DELETE /connection/{vi}`, which answers `404` the second time.

**Dry run.** With `dry_run: true` the request goes through every step - the checks, the member
change, the teardown, the warnings - inside its transaction, and then rolls all of it back. The
answer is therefore what the real request would answer, not a second implementation's guess: the
same body, short of `dry_run` and the member's `updated_at`, and the same `409`. Nothing is
written, logged or taken out of the cache. While it runs, a dry run holds the same row locks as
the real request.

**Refused with `409`, and nothing changed:**

- **A connection of the member belongs to a core bundle** - the exchange's own backbone, which
  `DELETE /connection/{vi}` refuses as well. Remove the core bundle in the web interface first.
- **The member is the default member of the API key's own user**, and the offboarding would
  deactivate it - refused exactly as `PATCH /member/{cust}` refuses it: IXP Manager would refuse
  the key afterwards, and nothing could undo that through the API. A leave date of today or
  earlier does this. A later one passes the check, as it does for `PATCH`, with a warning that
  the key stops working on that day.

`404` for an unknown member, `403` for a key which is not a superuser's, `422` for a malformed
body.

#### What stays behind

The `warnings`, in the order they appear. Each is somebody's decision, so the offboarding reports
it and changes nothing:

| warning | why it matters |
| --- | --- |
| A **reseller fanout port** linked to one of the member's ports | Handled as `DELETE /connection/{vi}` handles it: the link goes, the fanout interface stays on the reseller's connection, and its switch port becomes a peering port. When a reseller is offboarded, its fanout ports are unlinked from the resold members' interfaces. |
| A **patch panel port** still allocated to the member - one warning per port, with its circuit reference (`PPP-00012`), state and switch port | An offboarding does not cease cross-connects. If the switch port it is patched to was one of the member's, that port is now free and can be sold again while the cross-connect is still in place. IXP Manager refuses to delete the member until the port is ceased. |
| **Colocated equipment** recorded for the member; **console server connections** assigned to it | Things in the exchange's racks. Nothing removes the records. |
| The member's own **route server filters**, staged and production | Without a route server session they have no effect. IXP Manager removes them only when the member is deleted. |
| **Route server filters of other members naming this one as their peer** | They stay, without effect - and while they exist IXP Manager cannot delete this member: the foreign key has no cascade. |
| **Resold members** of a reseller which have not left | Offboarding the reseller does not offboard them. IXP Manager refuses to delete a reseller while they remain. |
| **Portal users with no active member besides this one** | See below. |
| **Portal users whose default member this is, but who belong to another active member** | See below. |
| The member is the **default member of the API key's own user**, and the leave date lies ahead | IXP Manager accepts the key until that day and refuses it from then on, unless the user has another default member by then. |
| The member was **Suspended**, and the leave date lies ahead | *Not Connected* counts as active: until the leave date its users can log in again. |

**What IXP Manager does with the member's users**, from the leave date on - today included:

- **A user with no active member besides this one cannot log in** - this was their only
  member, or their others have left or are suspended. `LoginController` finds no active member
  and logs them straight out again: "Your user account is not associated with any active
  members." A session already open ends at its next request (`Authenticate`), and their API keys
  are refused with `401` (`ApiAuthenticate`). The accounts are neither disabled nor deleted;
  `DELETE /user/{u}` removes one for good.
- **A user with another active member whose default member this was** has their session ended
  at its next request; at their next login IXP Manager makes an active member the default, with
  a notice. Until then their API keys are refused - `ApiAuthenticate` looks at the default member
  only.
- "Active" is judged when the offboarding runs: a member of theirs which leaves later counts as
  active.
- Users for whom this was neither the only nor the default member notice nothing, except that
  they can no longer switch to it.

#### What it does not do

- **No switch or router is touched.** As everywhere in this API, the switch configuration, the
  route servers and reverse DNS follow when they next pull their configuration.
- **The MACs go without a notification each.** As `DELETE /connection/{vi}` does, the configured
  MACs are deleted in bulk: no `Layer2Address\Deleted` event per MAC, unlike
  `DELETE /vlan-interface/{vli}`.
- **Nothing stops a new connection afterwards.** `POST /member/{cust}/connection`, onboarding and
  the web wizard all accept a member which has left.
- **Users, contacts, cross-connects and filters stay** - see the warnings.

Each connection removed is logged as `DELETE /connection/{vi}` logs it, marked "(offboarding)",
followed by one line for the member naming what changed, how many connections went and the
`reason`. A request which changed nothing logs nothing.

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

## Users

```http
GET    /admin/api/v4/provisioning/user
GET    /admin/api/v4/provisioning/user/{u}
PATCH  /admin/api/v4/provisioning/user/{u}
DELETE /admin/api/v4/provisioning/user/{u}
POST   /admin/api/v4/provisioning/user/{u}/welcome-email

GET    /admin/api/v4/provisioning/member/{cust}/user
POST   /admin/api/v4/provisioning/member/{cust}/user
GET    /admin/api/v4/provisioning/member/{cust}/user/{u}
POST   /admin/api/v4/provisioning/member/{cust}/user/{u}
PATCH  /admin/api/v4/provisioning/member/{cust}/user/{u}
DELETE /admin/api/v4/provisioning/member/{cust}/user/{u}
```

Portal users, and the links which give them access to members. A user has one privilege **per
member** — a link (`customer_to_users`) carries it — so the endpoints under `/user` treat the
person, and those under `/member/{cust}/user` one member's users.

### Creating a user

`POST /member/{cust}/user` creates a user for the member and sends the welcome email carrying the
password reset link — without it the account cannot be used. The password is random and never
returned. The customer comes from the route, so a `custid` in the body is ignored. The API takes
`enabled` (boolean, default true); the web form's inverted `disabled` field is not reproduced.

The answer is `201` with the member's view of the user, as `GET /member/{cust}/user/{u}` returns
it — a superset of the fields this endpoint has always returned.

### Finding a user before creating one

IXP Manager does not require a unique email address, and `POST /member/{cust}/user` does not look
for an existing account. A person who already works for another member should get a second
member link, not a second user — so ask first, as the web UI's create wizard does in its
"check email" step:

```http
GET /user?email=noc@example.net
```

```json
{
  "users": [
    { "id": 314, "username": "examplenet-noc", "name": "Erika Mustermann",
      "email": "noc@example.net", "authorisedMobile": null, "enabled": true,
      "custid": 97, "peeringdb_id": null, "creator": "provisioning",
      "created_at": "2026-08-01T10:00:00+02:00", "updated_at": "2026-08-01T10:00:00+02:00",
      "links": [
        { "id": 1201, "custid": 97, "user_id": 314, "privs": 2,
          "created_by": { "type": "api", "user_id": 1 },
          "last_login_date": "2026-11-20T08:14:03+01:00", "last_login_from": "192.0.2.10",
          "last_login_via": "Login",
          "created_at": "2026-08-01T10:00:00+02:00", "updated_at": "2026-11-20T08:14:03+01:00" }
      ] }
  ],
  "count": 1,
  "truncated": false
}
```

The email match is exact and case-insensitive. Further filters, all optional and combined with
AND: `username` (exact), `q` (substring of username, name or email, at least two characters;
`%` and `_` are literal), `member` (linked to this member), `privs` (has a link with this
privilege — on `member` when that is given, so `?member=97&privs=2` means "custadmins of member
97"), `enabled` (`1` or `0`) and `limit` (default 100, max 1000; `truncated` says whether there was
more).

A filter which is present but empty is a `422`, not ignored. `?email=` must not answer a duplicate
check with a list of everybody.

### Two shapes of a user

A user has one privilege per member, not one overall, so the API shows a user in two ways:

- **The person** — `GET /user`, `GET /user/{u}`, `PATCH /user/{u}`: who they are, plus `links`,
  one per member they can act for, each with its `privs`, who made it (`created_by`) and the
  user's last login to that member (`last_login_date`, `last_login_from`, `last_login_via`).
- **A member's user** — everything under `/member/{cust}/user`, creation included: the same
  person fields, plus `privs` and `link` for *that* member only. Other members do not appear.

`custid` is the user's default member: the one they act for after logging in, and the link IXP
Manager reads their privilege from. `enabled` is the inverse of the `disabled` column; a legacy
`NULL` there counts as enabled, as it does at login.

Neither shape contains the password hash, a remember-me token or anything about 2FA, and both are
explicit field lists, so a column added to the schema does not appear on its own.

### Changing a user

`PATCH /user/{u}` takes any subset of the web edit form's fields: `name`, `username`, `email`,
`authorisedMobile` and `enabled`. As with members, a field left out keeps its value and a field
sent as `null` is cleared — or refused, where the web form requires it (`name`, `username`,
`email`). The response is the user plus `changed`, the fields which actually changed, under the
API's names (`enabled`, not `disabled`). A PATCH which matches what is stored writes nothing, not
even `lastupdatedby`.

```json
{ "enabled": false }
```

The request is completed with the user's current values and validated with the web edit form's
rules, plus `enabled` — the same delta as creation. Things worth knowing:

- **Disabling ends the user's remember-me sessions**, as the web edit does. Their API keys and app
  passwords stay; IXP Manager refuses the keys of a disabled user, and enabling the user again
  restores them.
- **A field which is empty today and not sent is not part of the change.** Many legacy users have
  no `name`, which the web form requires; disabling one of them must not fail on that. Its rules
  are dropped, so it is neither validated nor written.
- **A stored value is validated in full.** A username from before the current rule — upper case,
  an `@` — makes every PATCH of that user a `422` until the same PATCH sends a valid `username`.
  The web edit form behaves the same way.
- **A sent `email` is stored lowercased**, as on creation. The web edit stores it as typed; an
  address which is merely carried along is not rewritten.
- **`authorisedMobile` follows the phone number rule**, as on creation: the inherited
  `IXP\Rules\IsPhone`, 7 to 25 characters of digits, spaces and `+ - ( ) .`.
- Fields outside the form (`custid`, `creator`, `password`, the web's `disabled`, `privs`, ...)
  are ignored. A privilege lives on a link: `PATCH /member/{cust}/user/{u}`.

### Deleting a user

`DELETE /user/{u}` removes the person everywhere, in one transaction: their member links and the
login history of each, API keys, app passwords (their login records cascade), remember-me tokens
and 2FA enrolment. Log rows the user wrote would block the delete (`log.user_id` has a foreign key
without `ON DELETE`), so they are copied into the application log and removed, exactly as the web
does it. Unlike the web, nothing is deleted by the wrong column: the web's own attempt at the app
password login records can hit somebody else's.

To end one member's access only, remove the link instead.

### Resending the welcome email

`POST /user/{u}/welcome-email` sends the welcome email again — the mail user creation sends, in its
"resent" wording, carrying a fresh link to set a password. Welcome links expire after 7 days, which
makes this the everyday remedy for "I never set my password". Building the mail issues a new token,
so the link of any earlier welcome email stops working.

```json
{ "sent": true, "email": "noc@example.net", "remaining": 4 }
```

Every call mails a third party, so it is limited to **5 sends per user per hour**. The sixth is a
`429` with `Retry-After`; `remaining` counts down to it. Only mails which actually went out count.
A disabled user (whose link would lead to an account that cannot log in) and a user without an
address get `409`. A mail server which refuses the mail is a `503` — nothing was sent, but the
earlier link is gone all the same, so retry.

### A member's users

`GET /member/{cust}/user` lists the member's users, ordered by username, each with its privilege
on and link to this member — the list a calling system reconciles against.
`GET /member/{cust}/user/{u}` reads one; it is `404` when the user exists but is not linked to the
member.

### Linking an existing user

`POST /member/{cust}/user/{u}` gives a user who already exists access to another member:

```json
{ "privs": 1 }
```

The answer is `201` with the member's view of the user. The user is told by email that they can
now act for the member — the web sends the same mail — and the link records the key owner as its
creator (`created_by: {type: api, user_id}`). The user's default member stays what it was.

Validated with the web request's rules, with one narrowing: `privs` must be 1 to 3. The web accepts
0 (PUBLIC) here, which creates a link that grants nothing. `3` (superuser) is only accepted on an
internal member (`type: 3`), as everywhere. A link which already exists is a `409` — use PATCH to
change its privilege.

### Changing the privilege

`PATCH /member/{cust}/user/{u}` with `{"privs": 2}`. The response carries `changed`: `["privs"]`, or
`[]` when the privilege was already that. A body without `privs` keeps it. `404` when the user is not
linked to the member.

The web changes privileges through an AJAX endpoint which answers HTTP 200 with `success: false`
when it refuses; the API reproduces its checks and answers `422` instead.

### Removing a link

`DELETE /member/{cust}/user/{u}` removes the link and its login history. If the member was the
user's default, the default moves to the user's oldest remaining link.

**A user's last link is not removed.** A user without a member cannot log in, has no privilege and
serves no purpose; the web UI never offers this, and crashes when it is done anyway — after deleting
the link, leaving the user pointing at a member they no longer belong to. The API answers `409` and
names the alternative: to remove the person, `DELETE /user/{u}`. Through this API a user therefore
always has at least one member.

### The key owner is protected

The API key authenticates as its owner, so these endpoints refuse with `409` anything which would
lock the API out:

- disabling the owner (every key of a disabled user is refused with `401`);
- changing the owner's username (every change made through this API is logged, and recorded in
  `user.creator`, under it);
- deleting the owner (the key would go with it);
- lowering or removing the owner's link to its default member (IXP Manager reads the owner's
  privilege from it; anything below superuser is a `403` on every further request);
- the owner demoting itself on another member while it is the last active user with that
  privilege there — the guard the web applies to everybody's own account.

Everything else about the owner may change. A second superuser's key, or the web UI, can do the
rest. The owner's own member is protected in the same way — see *Changing a member*.

## Connections

```http
POST   /admin/api/v4/provisioning/member/{cust}/connection
GET    /admin/api/v4/provisioning/member/{cust}/connection
GET    /admin/api/v4/provisioning/connection
GET    /admin/api/v4/provisioning/connection/{vi}
PATCH  /admin/api/v4/provisioning/connection/{vi}
DELETE /admin/api/v4/provisioning/connection/{vi}
POST   /admin/api/v4/provisioning/connection/{vi}/physical-interface
GET    /admin/api/v4/provisioning/physical-interface/{pi}
PATCH  /admin/api/v4/provisioning/physical-interface/{pi}
POST   /admin/api/v4/provisioning/physical-interface/{pi}/move
DELETE /admin/api/v4/provisioning/physical-interface/{pi}
```

A connection is a member's virtual interface: its physical interfaces on switch ports, and its
VLAN interfaces with their addresses. `{vi}` is the connection's virtual interface id - the `id`
every connection representation carries - and `{pi}` a physical interface id from its
`physical_interfaces`. The VLAN interfaces have endpoints of their own, described under
*VLAN interfaces and MAC addresses*.

### Creating a connection

`POST /member/{cust}/connection` creates the virtual interface, the physical interface on a
switch port, the VLAN interface and its addresses — the equivalent of the web UI's "add a port"
wizard.

```json
{
  "vlanid": 1,
  "switch": 7,
  "switchportid": 412,
  "speed": 10000,
  "rate_limit": 1000,
  "ipv4enabled": true,
  "ipv4address": "auto",
  "ipv4hostname": "example.peering.example.net",
  "ipv6enabled": true,
  "ipv6address": "auto",
  "ipv6hostname": "example.peering.example.net",
  "rsclient": true,
  "irrdbfilter": true
}
```

**`"auto"` allocates the next free address** in the VLAN. The web wizard requires an explicit
address because an operator picks one from a list rendered in the browser; an unattended caller
has no list.

An explicit address must already exist in the VLAN's pool. This is stricter than the web path,
which creates the address record on demand — for an unattended caller that turns a typo into a
stray address that nothing will ever clean up.

`status` defaults to *connected* and `duplex` to *full*; both can be set explicitly.

The switch port is checked before anything is written: it must exist, belong to `switch`, have
no physical interface, sit on an active switch and be of type *unset* or *peering* - otherwise
`422`. The response is `201` with the connection.

### Reading connections

`GET /member/{cust}/connection` lists the member's connections in the shape `POST` returns. That
shape is kept byte for byte - callers parse it.

`GET /connection/{vi}` returns a connection with everything `PATCH /connection/{vi}` can change:

```json
{
  "connection": {
    "id": 501, "custid": 97, "name": "ae", "description": "Example Networks primary",
    "mtu": null, "trunk": false, "lag_framing": true, "fastlacp": true, "channelgroup": 4,
    "physical_interfaces": [
      { "id": 733, "status": 1, "speed": 10000, "duplex": "full", "rate_limit": null,
        "autoneg": true, "switchport": 412, "switchport_name": "xe-0/0/9", "switch": 13,
        "switch_name": "vc513", "connection": 501, "switchport_type": 1, "notes": null }
    ],
    "vlan_interfaces": [ { "...": "as in the member-scoped list" } ]
  }
}
```

It is a superset of what `POST` and `GET /member/{cust}/connection` return: every field those
carry is here with the same value, plus `description`, `mtu` and `fastlacp` on the connection
and `connection`, `switchport_type` and `notes` on each physical interface. The member-scoped
endpoints keep their shape, so the fuller one is only returned by the connection and physical
interface endpoints. The VLAN interfaces are the same in both shapes; `GET /vlan-interface/{vli}`
reports every field of one.

`switchport_type` is the switch port's type (`1` peering, `3` core, `6` fanout, `7` reseller,
...). It explains the refusals below: interfaces on core and fanout ports are not changed here.

`GET /connection` lists connections across members, a page at a time, in id order:

```http
GET /connection?member=97
GET /connection?switch=13&status=4&limit=50&offset=0
```

```json
{ "connections": [ { "...": "as GET /connection/{vi}" } ],
  "count": 1, "total": 1, "limit": 50, "offset": 0 }
```

All filters are optional and combine: `member` (customer id), `switch` (connections with a port
on that switch), `vlan` (with a VLAN interface in it), `status` (with a physical interface in
that status - `4` lists everything awaiting a cross-connect). `limit` defaults to 100, at most
1000. A filter naming something which does not exist is a `422`, as on `port/free`, rather than
an empty page which looks like an answer. Core bundle connections are listed too; their ports
have `switchport_type` 3.

### Changing a connection

`PATCH /connection/{vi}` takes any subset of `name`, `description`, `mtu`, `trunk`,
`channelgroup`, `lag_framing` and `fastlacp`. A field that is left out keeps its value; a field
sent as `null` is cleared. Only the fields sent are written, and the response is the connection
plus `changed`, the attributes which actually changed:

```json
{ "lag_framing": true }
```

```json
{ "connection": { "...": "as GET /connection/{vi}", "lag_framing": true, "channelgroup": 4 },
  "changed": [ "name", "channelgroup", "lag_framing" ] }
```

The change is validated as the web edit form of a virtual interface validates it, and then the
rules that form's controller applies inline are applied in the same order:

- **LAG bookkeeping is the web's.** Switching LAG framing on assigns a channel group no other
  connection on the switch uses, and the switch vendor's bundle name if the connection has no
  name - which is why `changed` can list fields the request did not mention.
- **A channel group must be unique on the switch.** One another connection on the same switch
  uses is a `409`, and nothing is written.
- **A LAG with several ports keeps its channel group** - removing it is a `409`, as the web
  refuses it.
- **Surrounding double quotes are stripped from `name`.** The web form wraps a name with
  trailing whitespace in them so that it survives the trimming every request goes through, and
  this API trims the same way: `"\"ae0 \""` stores `ae0 `.
- A connection without a physical interface keeps no name, channel group or LAG settings - the
  web resets them, and so does this.

Stricter than the web, each a `409`:

- **LAG framing cannot be switched off while the connection has more than one port.** The switch
  configuration export renders only the first port of a connection without it; IXP Manager's own
  diagnostics flag that state as an error.
- **A connection of a core bundle is not changed here.** Both ends must match, and the core
  bundle's edit changes them together.

`custid` is ignored: moving a connection to another member changes who is billed for a port and
is not something a settings change should do by accident. Other fields are ignored as well.

### Adding a physical interface

`POST /connection/{vi}/physical-interface` adds a port to an existing connection - in practice a
further member of a LAG:

```json
{ "switch": 13, "switchportid": 413, "speed": 10000 }
```

`status` defaults to *connected* and `duplex` to *full*, as for `POST /member/{cust}/connection`;
`rate_limit`, `autoneg` and `notes` may be given. The answer is `201` with the new
`physical_interface`.

- **The port passes the gate every member port passes**: it exists, belongs to `switch`, has no
  physical interface, is on an active switch and is of type unset or peering - otherwise `422`.
  The web checks none of this.
- **A LAG stays on one switch.** A port on another switch than the connection's other ports is a
  `422`; the web only warns. IXP Manager assumes no multi-chassis LAGs, and the switch
  configuration export would name ports a switch does not have.
- **A second port needs LAG framing** - `409` otherwise, because the switch configuration of a
  connection without LAG framing carries only its first port and the new one would never be
  configured. Switch it on with `PATCH /connection/{vi}` first; that reconfigures the live port
  too, which is why it is not done implicitly.
- A connection of a core bundle is refused with `409`.

The LAG settings are worked out after the new port exists. The web does it before, which clears
them on a connection without a port and picks a missing channel group from the old set of ports.
The port's type becomes peering, or reseller if the connection's first port is a reseller port.

Reseller fanout links (`fanout`, `switch-fanout`, `switch-port-fanout`) are not supported and are
ignored; they are managed in the web UI.

### Changing a physical interface

`PATCH /physical-interface/{pi}` takes any subset of `status`, `speed`, `duplex`, `rate_limit`,
`autoneg` and `notes`, with the same semantics as `PATCH /connection/{vi}`. A `rate_limit` of `0`
is stored as none, as on the web. A physical interface has no `description` - its free text is
`notes`; the connection carries the description.

```json
{ "status": 2 }
```

Where the interface is - its switch, port and connection - is not changed by a PATCH, whatever
the body says; that is `/move`.

- **A reseller fanout link survives.** The web edit calls `processFanoutPhysicalInterface()` on
  every save, which deletes the related fanout interface - with the reseller's fanout connection,
  its VLAN interfaces and its MAC addresses, if that was its only port - whenever the request
  carries no `fanout` field. This API never calls it. The fanout port does follow a new speed,
  status or duplex, as on the web.
- Interfaces on fanout ports (changed through their peering interface) and in core links (changed
  through the core bundle) are refused with `409`, as the web refuses or special-cases them.

Setting `status` to *disabled* or *not connected* takes a port out of the generated
configuration at the next pull; nothing records what it was before.

### Moving a physical interface

```json
POST /physical-interface/733/move
{ "switch": 13, "switchportid": 414, "speed": 100000 }
```

`switch` and `switchportid` are required. Any field `PATCH` takes may be changed in the same
step - moving to a port of another speed is the usual reason to move at all, and doing both at
once means no configuration is ever generated for a 10G setting on a 100G port. `changed` lists
`switchportid` and whatever else changed.

On the web a move is a side effect of the edit form, which checks nothing about the target: a
taken port ends in a `500`. Here the target passes the same gate as a new port (`422`), a LAG
member stays on its LAG's switch (`422`), and the connection's channel group is validated again
on the new switch, which may already use it - `409`, and nothing moves. Asking for the port the
interface is already on moves nothing. The new port becomes a peering port, or a reseller port if
the old one was; the old one keeps its type, as on the web, and is free for the next order.
Interfaces on fanout ports and in core links are refused with `409`.

### Removing a physical interface

`DELETE /physical-interface/{pi}` removes one port of a connection and answers
`{"deleted": true}`. **Never the last one**: a connection without a physical interface keeps its
addresses but appears in no switch configuration. The web allows it and leaves exactly that
behind; here it is a `409`, and `DELETE /connection/{vi}` removes the whole connection.

The removal is the inherited `deletePi()`, fanout-aware and without the web's `related` option,
as for `DELETE /connection/{vi}`: a reseller's fanout interface is unlinked and its port returned
to peering, not deleted. `deletePi()` works out the LAG settings but never saves them; this API
saves them. A single remaining port stays a single-member LAG. An interface in a core link is
refused with `409`, as on the web. Removing the same interface twice gives `200`, then `404`.

### Removing a connection

`DELETE /connection/{vi}` removes the virtual interface with its physical interfaces, VLAN
interfaces and MAC addresses, in one transaction; its addresses return to the VLAN's pool. It
refuses with `409` if the virtual interface belongs to a core bundle. Removing the same
connection twice gives `200`, then `404`. The configured MACs go in bulk, without the
`Layer2Address\Deleted` event that `DELETE /vlan-interface/{vli}` raises for each of them.

## VLAN interfaces and MAC addresses

```http
POST   /admin/api/v4/provisioning/connection/{vi}/vlan-interface
GET    /admin/api/v4/provisioning/vlan-interface/{vli}
PATCH  /admin/api/v4/provisioning/vlan-interface/{vli}
DELETE /admin/api/v4/provisioning/vlan-interface/{vli}

GET    /admin/api/v4/provisioning/vlan-interface/{vli}/mac
POST   /admin/api/v4/provisioning/vlan-interface/{vli}/mac
GET    /admin/api/v4/provisioning/mac?mac=&member=&vlan=
DELETE /admin/api/v4/provisioning/mac/{l2a}
```

A VLAN interface is a connection's presence in one VLAN: its addresses and hostnames, the
route server, collector and AS112 sessions it takes part in, the prefix limits and MD5 secrets
of those sessions, and the MACs configured for it. These endpoints let a member join, change or
leave one VLAN while its port stays — moving from a quarantine VLAN to the peering LAN, taking
a second VLAN, renumbering, rotating an MD5 secret.

### Adding a VLAN to a connection

`{vi}` is the connection's `virtualinterface.id`, as `POST /member/{cust}/connection` returns
it. The body takes the VLAN interface fields of that endpoint:

```json
{
  "vlanid": 2,
  "ipv4enabled": true,
  "ipv4address": "auto",
  "ipv4hostname": "example.lan2.example.net",
  "ipv6enabled": true,
  "ipv6address": "auto",
  "ipv6hostname": "example.lan2.example.net",
  "ipv4bgpmd5secret": "s3cret-handed-to-the-member",
  "rsclient": true,
  "irrdbfilter": true
}
```

Addresses work exactly as for a new connection: `"auto"` takes the numerically next free
address of the VLAN's pool, an explicit address must already be in the pool and free, and an
enabled family must have an address — `"auto"` relaxes the type of the rule, never the
requirement. With `ixp_fe.vlaninterfaces.hostname_required` (the default) an enabled family needs
a hostname too, as in the web form.

Fields which are left out take what `POST /member/{cust}/connection` gives them: the column
defaults — IRRDB filtering on, the other flags off — and `busyhost` false.

**Max prefixes are not copied from the member.** The web form pre-fills `ipv4maxbgpprefix` /
`ipv6maxbgpprefix` with the member's `maxprefixes` / `maxprefixesv6`, which stores a copy on the
interface — frozen: a later change to the member's limits no longer reaches this interface's
route server sessions. Left out, they stay null, and the router configuration falls back to the
member's limits each time it is generated (then to `ixp.default_maxprefixes`). That is also what
`POST /member/{cust}/connection` does. A caller who wants the frozen copy sends the numbers.

**One VLAN interface per VLAN and connection.** Stricter than the web, which does not check:
nothing in the schema stops a second interface of the same connection in the same VLAN, which
then gets addresses of its own and appears twice in the port's switch configuration. The likely
way there for an unattended caller is a create retried after a timeout, so the `422` names the
interface that exists (`errors.vlanid`): a caller can carry on with it rather than guess.

A connection which belongs to a core bundle is refused with `409` — that is the exchange's
backbone, not a member's port. `DELETE /connection/{vi}` refuses it likewise.

The response is `201` with the interface, as `GET` returns it, and `warnings`:

```json
{
  "vlan_interface": { "id": 812, "connection": 501, "custid": 97, "vlan": 2, "...": "..." },
  "warnings": [
    "You have enabled IRRDB filtering for this VLAN interface's route server sessions. However, the customer does not have an IRRDB source set. ...",
    "802.1q framing is not set on connection 501 but it has more than one VLAN interface. Without it the port can carry one VLAN only."
  ]
}
```

Warnings do not stop the request. They are the web UI's own: the IRRDB one is raised by the
same helper the web uses, which keeps it in the session for a page view an API caller never has;
the 802.1q one is shown on the web's connection page. `warnings` is always present, empty when
there is nothing to say.

### Reading a VLAN interface

`GET /vlan-interface/{vli}` returns every field the web edit form can change, under the names
that form — and `PATCH` — use, plus `connection`, `custid`, `mac_addresses` (the configured MACs,
as `GET /vlan-interface/{vli}/mac` lists them), `created_at` and `updated_at`.

**MD5 secrets are write-only.** `ipv4bgpmd5secret_set` / `ipv6bgpmd5secret_set` say whether one
is configured; the secret itself is never returned, by any endpoint. It is the member's
credential for its route server sessions — once handed over at provisioning it has no business
in every read of the interface, nor in the logs of whatever reads it. Lost, it is replaced.

The representation is a strict superset of the VLAN interfaces `GET /member/{cust}/connection`
embeds: every field there is here, with the same name and type. That includes the
`maxbgpprefix` object `{ "ipv4": …, "ipv6": … }`; `ipv4maxbgpprefix` / `ipv6maxbgpprefix` carry
the same numbers under the names `PATCH` takes. The fields the embedded shape leaves out —
`mcastenabled`, `rsmorespecifics`, `busyhost`, the `canping` and `monitorrcbgp` flags, and whether
an MD5 secret is set — are all here.

### Changing a VLAN interface

`PATCH /vlan-interface/{vli}` takes any subset of the edit form's fields: `vlanid`,
`irrdbfilter`, `mcastenabled`, `rsclient`, `rsmorespecifics`, `as112client`, `busyhost`, and
per family `…enabled`, `…address`, `…hostname`, `…bgpmd5secret`, `…canping`, `…monitorrcbgp`,
`…maxbgpprefix`. A field left out keeps its value; a field sent as `null` is cleared (the flags
are true or false — null is refused). Rotating a secret is just:

```json
{ "ipv4bgpmd5secret": "n3w-s3cret", "ipv6bgpmd5secret": "n3w-s3cret" }
```

The response is the interface, `changed` — the fields which actually changed, under the request's
names, so `ipv4address` rather than the column `ipv4addressid`, and never with a value — and
`warnings`. A PATCH which repeats what is stored answers `200` with `changed: []`.

The change is validated as the web edit form validates it: the request is completed with the
interface's current values and the whole record runs through the rules. That matters because
the rules depend on each other. `{"ipv4address": null}` on an interface with IPv4 enabled is
refused (`422`), and so is `{"ipv6enabled": true}` without an address — the completed record
would break the invariant that an enabled family has an address. What is then *written* is only
the fields that were sent, on top of the interface as it stands under a row lock: the completion
is for validation, and writing it back would undo a change another request made to a different
field in the meantime. The web rules apply in full otherwise: an interface whose enabled family
has no hostname while `hostname_required` is on cannot be changed until it gets one — the edit
form would not save it either.

Addresses:

- **The current address, or `"auto"` for a family which has one, changes nothing.** `"auto"`
  asks for an address, not a different one, so a retried PATCH does not walk through the pool.
- **An explicit other address renumbers.** It must be in the VLAN's pool and free; the old
  address returns to the pool (the address record stays — it belongs to the VLAN).
- **Disabling a family keeps its address reserved**, as the web form does. Send
  `"ipv6address": null` as well to return it to the pool.

**Moving to another VLAN** (`vlanid`) is part of the edit form and is accepted, with what the
web does not check. The old VLAN's addresses cannot come along: for every family which holds an
address, send a new one, `"auto"` or `null`, or the `422` says so for that field. The MACs of the
interface must not be configured on another interface of the new VLAN, since a MAC is unique
within a VLAN (`errors.vlanid` names them). And a connection still has one interface per VLAN.

```json
{ "vlanid": 1, "ipv4address": "auto", "ipv6address": "auto" }
```

`virtualinterfaceid` cannot change — an interface does not move between connections — and is
ignored like any other field outside the form.

### Removing a VLAN interface

`DELETE /vlan-interface/{vli}` removes the interface and the MACs configured for it; its
addresses return to the pool. The answer is `{"deleted": true}`, then `404` on a repeat.

**Not the last one of a connection** — that is `409`. A connection without a VLAN interface is a
port in no VLAN: the switch configuration silently drops it while the port stays assigned. A
member leaving its last VLAN is leaving through this port; that is `DELETE /connection/{vi}`.
The web UI allows it.

Each MAC removed with the interface raises the same `Layer2Address\Deleted` event as
`DELETE /mac/{l2a}`, after the commit. The web's own delete removes them in bulk without one, so
whoever keeps switch MAC filters in step with the change notification e-mail would never hear of
them.

### Configured MAC addresses

The MAC addresses an operator registers for a VLAN interface (`Layer2Address`) — what switch
automation turns into port security or MAC filters. The MACs a switch has learned are a different
thing and not part of this API.

The web endpoints for this exist, but sit in the `web` route group and so cannot be called
without a browser's CSRF token; they also answer `200` for a refusal. These endpoints apply the
same checks — as validation rules, held to the web's verdicts by `MacAddressRulesParityTest` —
and raise the same events.

```json
{ "mac": "00:1A:2B:3C:4D:5E" }
```

- **Any usual notation**: case, colons, dashes, dots and spaces do not matter. The MAC is stored
  as the web stores it — twelve lowercase hex digits — and reported both ways:

  ```json
  { "mac_address": { "id": 4711, "mac": "001a2b3c4d5e", "mac_colon": "00:1a:2b:3c:4d:5e",
      "vlan_interface": 812, "connection": 501, "vlan": 2, "custid": 97,
      "member_name": "Example Networks GmbH", "created_at": "2026-11-24T20:03:34+00:00" } }
  ```

- **Unique within a VLAN**, on whichever of its interfaces — this one included. A duplicate is a
  `422` naming the interface which has the MAC, so a caller whose earlier attempt timed out can
  tell its own MAC from somebody else's. The same MAC in another VLAN is accepted.
- **The change notification**: adding and deleting raise `Layer2Address\Added` / `Deleted`, which
  send the e-mail of `ixp_fe.layer2-addresses.email_on_change_dest` when
  `email_on_superuser_change` is set — exactly as a superuser's change in the web UI does.
- **No customer limits.** `ixp_fe.layer2-addresses.customer_params` (minimum and maximum number of
  MACs) applies to members editing their own MACs, not to superusers — so not here. A caller
  acting on a member's behalf enforces them itself.

`GET /mac` answers which member owns a MAC: `?mac=` in any notation, `?member=` (a member id),
`?vlan=` (a VLAN id), combined with AND, each optional. Without any, every configured MAC is
listed — the whole set a reconciliation compares against, in one request. An unknown member or
VLAN is an empty list, not a `404`. A `mac` which is not twelve hex digits is a `422`.

`DELETE /mac/{l2a}` answers `{"deleted": true}`.

## Reference data

```http
GET  /admin/api/v4/provisioning/ping
GET  /admin/api/v4/provisioning/switch
GET  /admin/api/v4/provisioning/infrastructure
GET  /admin/api/v4/provisioning/port/free
GET  /admin/api/v4/provisioning/vlan
GET  /admin/api/v4/provisioning/vlan/{vlan}
GET  /admin/api/v4/provisioning/vlan/{vlan}/address
GET  /admin/api/v4/provisioning/irrdb-source
POST /admin/api/v4/provisioning/irrdb-source
GET  /admin/api/v4/provisioning/irrdb-source/{irrdbconfig}
GET  /admin/api/v4/provisioning/facility
GET  /admin/api/v4/provisioning/facility/{location}
GET  /admin/api/v4/provisioning/rack
GET  /admin/api/v4/provisioning/rack/{cabinet}
GET  /admin/api/v4/provisioning/vendor
```

What an ordering system needs to place an order and to keep its own records in step with
IXP Manager's. Everything here reads, except the IRRDB source find-or-create.

`switch`, `infrastructure` and `vlan/{vlan}/address` had callers before they were extended.
**Every such change is additive:** the existing fields keep their names and formats, a request
without the new parameters gets exactly what it got before, and new fields only appear next to
the old ones.

One representation per kind of object, used wherever that object appears: a VLAN looks the
same in `GET /vlan`, nested in `GET /infrastructure` and in the header of
`GET /vlan/{vlan}/address`.

`ping` confirms the key and returns the resolved username (see *Authentication*).

### Switches, infrastructures and free ports

`GET /switch` lists the active switches by default. Two filters:

- `?active=1` (the default), `?active=0` for switches out of service, `?active=all`. A `NULL`
  in the column counts as inactive, as it does for `port/free` and for orders, so every switch
  appears under exactly one of `1` and `0`. Without `active=0`, a switch somebody has just
  deactivated simply vanishes from the listing, which looks the same as a deletion.
- `?infrastructure=<id>`.

Each switch carries `active`, and `rack`, `facility` and `vendor` - ids for `GET /rack`,
`GET /facility` and `GET /vendor`. A switch reaches its facility only through its rack, so
without them an ordering system that sells ports per facility could not tell which switches
stand where. The SNMP community is never part of the response, nor are the SNMP-maintained
columns.

`GET /infrastructure` lists the infrastructures with their VLANs, and `country`, `ixf_ix_id`,
`peeringdb_ix_id` and `exclude_from_ixf_export`: the identifiers under which the exchange is
known to PeeringDB and the IX-F database. The nested VLANs carry the full VLAN representation.

`GET /port/free` walks every active switch and returns ports with no physical interface
attached. Only ports of type *unset* or *peering* are ever returned: core, management, monitor,
fanout and reseller ports are infrastructure, and offering them would let a caller reassign the
fabric.

```http
GET /port/free?infrastructure=1&limit=50
```

```json
{
  "ports": [
    { "switchport": 412, "name": "xe-0/0/9", "type": 0,
      "switch": 7, "switch_name": "swi1-fra", "infrastructure": 1 }
  ],
  "count": 1,
  "truncated": false
}
```

### VLANs

`GET /vlan` lists the VLANs with the flags an order depends on. A connection names its VLAN by
id, and before this endpoint nothing in the API told a public peering LAN from a private or a
quarantine VLAN - a caller could attach a member to the wrong one without noticing.

```json
{
  "vlans": [
    { "id": 1, "name": "Peering LAN", "number": 900, "infrastructure": 1,
      "private": false, "config_name": "peering", "export_to_ixf": true,
      "peering_matrix": true, "peering_manager": true },
    { "id": 3, "name": "Quarantine LAN", "number": 901, "infrastructure": 1,
      "private": false, "config_name": "quarantine", "export_to_ixf": false,
      "peering_matrix": false, "peering_manager": false }
  ]
}
```

Filters, which combine: `?infrastructure=<id>`, `?private=1|0`, `?export_to_ixf=1|0`. Ordered by
802.1Q tag, as the web list is.

`GET /vlan/{vlan}` is the same object plus `network_info`, the subnet per protocol:

```json
{
  "vlan": {
    "id": 1, "name": "Peering LAN", "number": 900, "infrastructure": 1, "...": "...",
    "network_info": {
      "ipv4": { "id": 1, "protocol": 4, "network": "193.189.82.0", "masklen": 23 },
      "ipv6": { "id": 2, "protocol": 6, "network": "2001:7f8:33::", "masklen": 64 }
    }
  }
}
```

Both keys are always there; a protocol without network information is `null`. The web UI
allows one row per VLAN and protocol, the schema does not enforce it; should there be two, the
newer is reported, which is also the one the member dashboard shows. The legacy columns
`rs1address`, `rs2address` and `dnsfile` are not reported - nothing maintains them any more.

### Address pools

`GET /vlan/{vlan}/address` lists a VLAN's address pool, ordered numerically, each address marked
free or in use. Addresses are added to a pool ahead of time, in the web UI; this API never
creates one, and none reserves one — a caller which wants the next free address asks for
`"auto"` when it creates the connection. `?free=1` restricts the listing to unassigned
addresses, `?protocol=4` or `6` to one family and `?network=` to one network; `?limit=` caps it
per family (default 200, at most 2000).

Every address which is not free names who holds it - the columns the web address list shows:

```json
{ "id": 342, "address": "193.189.82.102", "free": false,
  "assigned_to": { "custid": 97, "member_name": "Example Networks GmbH",
                   "connection": 501, "vlan_interface": 733,
                   "hostname": "example.peering.example.net" } }
```

`assigned_to` is `null` for a free address, so `free` and `assigned_to` always agree.
`connection` is the virtual interface id `DELETE /connection/{vi}` takes; `hostname` is the
VLAN interface's hostname for that address family.

`?network=` takes `address/length` or a bare address:

- A network belongs to one family, so it **implies the protocol**: the other family is left
  out of the response, exactly as `protocol` leaves it out. A `protocol` which contradicts the
  network is a `422`, not an empty list.
- The network has to **start at its first address**: `10.0.0.5/24` is refused, and the message
  names `10.0.0.0/24`. The web UI's delete-by-network form insists on the same, because the
  other reading is far more often a typo than an intention.
- A bare address names that one address - "who holds 193.189.82.102?" in one call.
- Unlike the web form, which only takes a /24 or longer (IPv6: /120), any prefix length is
  accepted. A filter enumerates nothing; it compares numerically, so a /16 costs no more than
  a /28. The comparison is on the numeric form, so the notation of a stored IPv6 address does
  not matter.

`free`, `limit` and `network` combine; `total` counts what the filters matched, and `truncated`
says whether `limit` cut the list short.

### IRRDB sources

A member refers to its IRRDB source by id - `irrdb` in the member payload - while PeeringDB
names it in its `irr_as_set`, as in `RIPE::AS-EXAMPLE`. The web customer form turns one into
the other in the browser, creating the source when it is missing, through an endpoint that
needs a CSRF token. These are the same operations for a machine:

```http
GET  /irrdb-source
GET  /irrdb-source/{irrdbconfig}
POST /irrdb-source
```

```json
{ "host": "whois.radb.net", "source": "ALTDB", "notes": "for AS-EXAMPLE" }
```

**`POST /irrdb-source` is a find-or-create.** When a source with the same host and the same
source list exists already, the response is `200` with that source and `"created": false` - it
is returned unchanged, and `notes` in the request is not applied to it. Otherwise the source is
created: `201` and `"created": true`. A caller can send this whenever it needs a source id,
without looking first, and retry it freely:

```json
{ "irrdb_source": { "id": 14, "host": "whois.radb.net", "source": "ALTDB",
                    "notes": "for AS-EXAMPLE", "member_count": 0,
                    "created_at": "2026-11-24T10:00:00+00:00",
                    "updated_at": "2026-11-24T10:00:00+00:00" },
  "created": true }
```

- The match **ignores case** - host names are case-insensitive, and so are IRR source names to
  bgpq3/bgpq4 - but is otherwise literal: `RADB,RIPE` and `RIPE,RADB` are different sources,
  since the order of a source list is the order of preference.
- There is no `protocol`. Sources used to have one; the column was dropped in 2021
  (`2021_09_17_144421_modernise_irrdb_conf_table`), so host and source are all there is.
- Neither the web UI nor the schema keeps sources unique. Two simultaneous requests for the
  same new source do not both create it (see *Concurrency*). A source created in the web UI at
  the same instant can still slip past - the web UI permits duplicates anyway - and where
  duplicates exist already, the oldest is the one returned.

`member_count` is the number of members referring to the source, departed members included -
the same count the web UI checks before it lets a source be deleted.

### Facilities, racks and vendors

```http
GET /facility
GET /facility/{location}
GET /rack?facility=<id>
GET /rack/{cabinet}
GET /vendor
```

The schema calls a facility a *location* and a rack a *cabinet*; the API uses the words of the
web UI, as its users do, and the route parameters keep the model names. A rack names its
facility in `facility`; a switch names both, so `facility -> rack -> switch` can be followed in
either direction. The fields are those of the web views: for a facility its names, address,
PeeringDB facility id and NOC and office contacts; for a rack its colocation reference, height,
type and which way its units count (`u_counts_from`: `1` from the top, `2` from the bottom).
`GET /vendor` has no show endpoint - the list carries every field.

An unknown `?facility=` is a `422`, as an unknown `?infrastructure=` is everywhere else.

## Onboarding

```http
POST /admin/api/v4/provisioning/onboarding
```

Creates a member, optionally a user, and optionally a connection **in one transaction**.

`POST /member`, `POST /member/{cust}/user` and `POST /member/{cust}/connection` can be called in
sequence, but that sequence is not atomic: a caller whose second request fails is left with a
half-provisioned member. Here the whole order lands or none of it does.

```json
{
  "reference": "ORD-2026-04711",
  "member":     { "...": "as POST /member" },
  "user":       { "...": "as POST /member/{cust}/user, without custid" },
  "connection": { "...": "as POST /member/{cust}/connection" }
}
```

Sections are nested because `name` means something different for a member, a user and a virtual
interface. `user` and `connection` are optional. Each section is validated with the rules of its
own endpoint, and, as there, a `cust` or `id` key in a section is ignored.

**`reference` makes retries safe.** It is the caller's own order identifier. A caller which
times out and retries with the same reference gets the original result back:

```json
{ "created": false, "reference": "ORD-2026-04711",
  "member": { "id": 97, "shortname": "example" },
  "user": { "id": 314, "username": "example-noc" },
  "connection": { "id": 501 } }
```

with status `200` rather than `201`. `user` and `connection` are present when the first call
created them and they still exist — a caller which timed out never saw the first response, and
the replay is the quickest way to learn those ids (`GET /member/{cust}/user` and
`GET /member/{cust}/connection` list them too). References recorded by earlier versions carry
the member only. The payload of a repeat is not compared with the original. Without a reference,
a retry creates a second member.

This endpoint does not orchestrate anything beyond the database. Rolling configuration out to
switches, regenerating route server configuration and updating reverse DNS remain the caller's
responsibility; it only makes the IXP Manager side of an order indivisible.

## Validation

The API form requests **extend the web ones** rather than restating their rules, so a rule
changed upstream applies here automatically. Each adds a declared delta, and `RulesParityTest`
subtracts that delta and asserts the remainder is identical — divergence fails a test rather
than surfacing as a behavioural difference. Where the web validates inline, in a controller,
there is nothing to extend: the API restates those rules, and a parity test sends the same
payloads to the web and to the API and asserts that the same fields fail.

PATCH requests are completed with the record's current values before validation, so a change is
validated as the web edit form would validate the whole record. The completion is for
validation: the values it fills in are the stored ones, so only what the caller sent can change.
Contacts, billing details, connections, physical interfaces and VLAN interfaces go further and
write only the fields sent, so that a change another request made to a different field in the
meantime is not undone by values read before it.

### Members

The customer delta covers fillable attributes the web request does not validate. Three are
worth naming: the columns are `dateleave`, `MD5Support` and `isReseller`, while the web rules
refer to `dateleft` and `md5support`. Those two rules match nothing, so the values currently
reach `Customer::create()` unvalidated. The web rules are left alone; the API validates the
names the schema actually uses.

`peeringdb_oauth` joined the customer delta: the web create and edit forms post it and the
column is fillable, but no web rule names it, so `validated()` dropped it. `UpdateCustomer` adds
nothing of its own — its rules and delta are `StoreCustomer`'s.

`UpdateBillingRegistration` extends the web's `Customer\BillingInformation`; its delta is
`purchaseOrderRequired` (boolean), which the web form posts but no web rule validates — the
web writes `$r->all()`, this API only validated fields. `MemberRulesParityTest` holds it to the
web rules like the others, and also asserts that every validated field is written and every
written field is validated, fillable and readable back.

`GET /member` has no web request to extend: the admin list reads its filters unvalidated and
ignores what it does not recognise. `ListCustomers` validates them against the sets the web
uses.

### Contacts

The web validates contacts inline, in `ContactController::checkForm()`, so there is no web form
request to extend. `StoreContact` reproduces those rules — the superuser branch, which requires
`custid` — and adds `roles` as its delta; `UpdateContact` completes a PATCH with the current
values before the same rules run. `ContactParityTest` holds the copy to the original: it
captures the rules `checkForm()` hands to the validator and compares them with the copy, so an
upstream change fails a test at merge time; it sends the same invalid payloads to the web form
and to the API and asserts that the same fields fail; and it compares the rows and group links
both entry points leave behind.

### Users

`POST /member/{cust}/user` extends the web's `User\Store` and adds `enabled`. `PATCH /user/{u}`
extends the web edit request (`User\Update`) and adds `enabled` as well. `DELETE /user/{u}`
extends the web delete request unchanged. The web request for linking a user
(`User\CustomerToUser\Store`) is `final`, so the API delegates to it rather than extending it:
its rules are evaluated against the API request, with `privs` declared as an override. The web
validates privilege changes inline in the controller, so the API reproduces those checks and
`UserRulesParityTest` sends the same values to the web route and to the API and compares what each
refuses. The same test compares the rules of the edit, delete and link requests with the web's,
as `RulesParityTest` does for user creation, members and connections.

### Connections and physical interfaces

For a new connection, `ipv4address` / `ipv6address` are replaced rather than added to, since they
must also accept `"auto"`. Only the address *type* is relaxed - the wizard's conditional
requirement stays, so an enabled address family still demands an address. `ipv4hostname` /
`ipv6hostname` are the wizard's rule with `bail` in front: without it a hostname which is not a
string reached `IdnValidate`, whose `idn_to_ascii()` throws a `TypeError` - a `500` instead of a
`422`, for `POST /member/{cust}/connection` and the connection section of `POST /onboarding`
alike. `RulesParityTest` holds the override to exactly the wizard rule plus `bail`. The delta adds
the fields the wizard has no form control for: `name`, `description`, `mtu`, `lag_framing`,
`fastlacp` and `busyhost`.

(`rate_limit` and `autoneg` were in that delta until this branch was rebased from v7.3.1 onto
`main`, where upstream had added them to the wizard request itself. RulesParityTest failed on
exactly that, which is what it is for.)

`PATCH /connection/{vi}` uses `UpdateConnection`, which extends the request of the web edit form
of a virtual interface (`StoreVirtualInterface`). Its delta is `trunk`, `lag_framing` and
`fastlacp`: the form posts them, but its request never validates them, so on the web they reach
the model unchecked. `custid`, which those rules require, is always the connection's own.

The physical interface endpoints use `StorePhysicalInterface`, which extends the web request of
the same name, with `UpdatePhysicalInterface` and `MovePhysicalInterface` extending it in turn,
so all three validate a port alike. The delta is `autoneg`. The fanout fields are removed before
validation, because the inherited rules switch on `fanout`.

`InterfaceParityTest` holds the requests to the web rules plus the declared delta, and sends the
same invalid payloads to the web routes and to the API to check that the same fields fail. It
also checks that the web and the API refuse the same connection changes - a channel group removed
from a LAG with several ports, a channel group in use on the switch - and the removal of an
interface in a core link.

### VLAN interfaces and MAC addresses

The VLAN interface requests extend the web's `StoreVlanInterface`, which serves the web's create
and edit forms alike. The delta is empty: the web rules cover every field, and
`virtualinterfaceid` — a hidden field there — comes from the route here. Four rules are replaced,
each checked by `VlanInterfaceRulesParityTest`:

- `ipv4address` / `ipv6address`, to accept `"auto"`, as for connections — the conditional
  `required` stays.
- `ipv4hostname` / `ipv6hostname`: the web rule with `bail` in front, and nothing else. Without
  it a hostname which is not a string reaches `IdnValidate`, whose `idn_to_ascii()` throws a
  `TypeError` — `"ipv4hostname": ["x"]` is a `500` on the web. `POST /member/{cust}/connection`
  takes the same `bail` (see above).

`VlanInterfaceRulesParityTest` also fails when upstream adds a field to the edit form, so that
whether `PATCH` takes it is decided rather than forgotten.

The MAC endpoint has no web form request to extend — the web controller checks inline
(`Api\V4\Layer2AddressController.php:90-108`). `StoreMacAddress` restates those checks as rules
and `MacAddressRulesParityTest` sends the same MACs to both and compares the verdicts. A `mac`
which is not a string is a `500` on the web; here it is a `422`.

Neither the web rules nor these range-check the max prefixes: a negative value is stored as `0`
by MySQL (IXP Manager does not run it in strict mode), which the router configuration reads as
"no interface limit, use the member's". Send `null` to say that.

### IRRDB sources

The payload is validated as the web form validates it: `host` required, a host name of at most
255 characters; `source` required, at most 255. The web UI validates these inline in its
controller, so there is no form request to extend; `IrrdbSourceTest` instead sends the same
invalid payloads to the web form and to the API and asserts that the same fields fail. Two
differences, both deliberate: `notes` is validated (`nullable|string`; the web form stores it
unchecked), and a `host` that is not a string is a `422` - the web form's host-name check hands
it to `idn_to_ascii()`, which turns an array into a 500.

## Concurrency

Address allocation takes a row lock inside the request's transaction. Switch port assignment is
checked before anything is written, and the write happens in the same transaction.

Neither check can see a competing order which has not committed yet, so two orders for the same
port or the same address can both pass them. The unique indexes on
`physicalinterface.switchportid` and `vlaninterface.ipv4addressid` / `ipv6addressid` then refuse
whichever loses. Every endpoint which takes a port or an address answers that with `409` and
rolls back everything — `/member/{cust}/connection` and `/onboarding` alike, and adding or moving
a physical interface. A retry will normally produce a `422` naming the resource that is now
taken.

**Connections and physical interfaces.** Channel groups must be unique per switch, and nothing in
the schema enforces that. Two requests for connections on one switch could otherwise pick or
check the same number, neither seeing the other's uncommitted write. Every write of a connection
or a physical interface therefore runs in one transaction which first locks the rows of the
switches involved, then the connection's row. The same lock means two removals cannot each take
one of a connection's last two ports.

**VLAN interfaces and MAC addresses.** Every write takes its locks in one order — the connection,
the interface, the VLAN, then the addresses — so that two of them queue rather than deadlock. The
checks made during validation (one interface per VLAN, a MAC unique within a VLAN) are repeated
under those locks; a request which lost a race to another one gets `409`, as does one whose change
another request made impossible in between (an address gone, say), and nothing is written. A MAC's
uniqueness within a VLAN exists only in that check — the schema enforces uniqueness per interface
— so every MAC write locks the VLAN row and checks again before it inserts.

**Contacts.** Group limits are checked under a row lock on the member — on a PATCH, the contact
and the member it ends up with — so two requests through this API cannot both take the last place
in a group. The web UI takes no lock: a group link it adds at the same moment can collide with
one this API adds, which is what a `409` from the contact endpoints means.

**Users.** Removing a link counts the user's links under a lock on them, so two requests cannot
each remove one of the last two. A username taken by another request between validation and write
is a `409` from `PATCH /user/{u}`, and a link made by another request in the meantime is a `409`
from `POST /member/{cust}/user/{u}`.

**IRRDB sources.** `POST /irrdb-source` looks the source up with a locking read in the same
transaction as the insert, so under MySQL's default isolation level (REPEATABLE READ) the second
of two simultaneous requests for the same new source waits for the first and then finds its row.

**Offboarding.** An offboarding locks the member's row first, then its connections in id order. A second
offboarding of the same member waits, and then finds nothing left to do. A connection ordered for
the member at the same moment waits for the offboarding as well - its insert takes a shared lock
on the member's row for the foreign key - and is created after it: it is not torn down, and the
member then has a connection again. A deadlock is retried, from a fresh read of everything.

## What is deliberately absent

- **No endpoint reserves an address on its own.** An address held by nothing but a promise
  leaks if the caller then fails. Allocation happens inside the connection transaction, where
  it either sticks or is rolled back with everything else.
- **No deletion of members, and no suspension with an exact restore.**
  `POST /member/{cust}/offboard` closes a member and releases its ports, addresses and MACs; it
  deletes neither the member nor its users. Hard deletion (`CustomerAggregator::deleteObject()`)
  is for members created by mistake and stays in the web UI. A suspension which can be undone
  exactly needs somewhere to record what each field held before; there is no such place in the
  schema today.
- **No `"auto"` MD5 secret.** The web form generates one in the browser; an API that generated one
  would have to return it, and secrets are never returned. The caller generates the secret it
  hands to the member.
- **No duplicate endpoint for VLAN interfaces.** The web's "duplicate VLAN interface" is
  `POST /connection/{vi}/vlan-interface` plus `POST /vlan-interface/{vli}/mac` here — or, to move
  rather than copy, `PATCH` with `vlanid`.
- **The VLAN's infrastructure is not checked against the connection's switch.** Neither the web
  nor `POST /member/{cust}/connection` checks it; doing it here alone would make the endpoints
  disagree.
- **Reference data is not changed here.** No endpoint changes a VLAN, a switch, a facility, a
  rack or a vendor, and an IRRDB source can be found or created but not changed or deleted;
  those remain web UI operations.

## Machine-readable specification

`docs/provisioning-api.openapi.yaml` — OpenAPI 3.1, hand written, no generator package and no
build step. 38 paths with 59 operations, 76 schemas, every field derived from the controllers,
the presenters and the merged validation rules rather than from this prose. Its tags follow the
sections above.

Worth knowing when reading it: a taken or unusable switch port, and an address outside the pool
or already assigned, answer `422`, not `409` — they are found before anything is written. `409`
arises from two kinds of cause, and the `409` response of each operation says which apply:

- **A race lost to another request**, refused by a unique index or by a check repeated under a
  lock: a port or an address at `/member/{cust}/connection`, `/onboarding`, adding or moving a
  physical interface and the VLAN interface endpoints; a duplicate VLAN interface or MAC; a
  contact group link written by the web UI at the same moment; a username or a member link taken
  in the meantime.
- **The state of a record refusing the change**: a reused onboarding reference whose member has
  since been deleted; a connection of a core bundle (`DELETE` and `PATCH /connection/{vi}`, adding
  a port or a VLAN interface); the LAG rules of a connection; the last physical interface or the
  last VLAN interface of a connection; an interface on a fanout port or in a core link; a user's
  last member link, or a link which exists already; billing managed by a reseller; a welcome email
  to a disabled user or one without an address; a connection of the member in a core bundle at `POST /member/{cust}/offboard`; and anything which would lock the API key out.
