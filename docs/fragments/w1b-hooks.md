<!--
Fragment from the wave 1b "hooks" work package: outgoing webhooks.

Merge notes:
  - Everything from "# Outgoing webhooks" down is new. It is long enough to be its own document
    (suggested: docs/webhooks.md, linked from docs/provisioning-api.md); as a chapter of
    provisioning-api.md, demote every heading by one level.
  - The provisioning API gains one optional request header on every endpoint, X-Correlation-Id
    (see "The loop-prevention contract"). Add to the "## Conventions" list of
    provisioning-api.md:
      - `X-Correlation-Id` (optional, every endpoint): 1 to 100 characters from `A-Z a-z 0-9 . _ : -`;
        anything else is `422` before anything is written. Echoed in the response and stamped on
        every webhook event the request causes. See the webhook documentation.
    docs/fragments/w1b-hooks.openapi.yaml has the matching OpenAPI pieces.
  - No route was added.
-->

# Outgoing webhooks

IXP Manager tells an external system - KleyReX's API5 - about changes to members, users,
contacts, connections, switch ports and member notes, **whoever made them**: IXP staff or a
member's own users in the web UI, the provisioning API, other API clients, artisan commands or
the scheduler. One HTTPS `POST` per event, signed per [Standard Webhooks][sw], delivered at least
once, retried for three days, in order.

Off by default. Nothing is recorded or sent until `IXP_WEBHOOKS_ENABLED=true`.

[sw]: https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md

## Setting up

1. `php artisan migrate` - creates `webhook_pending`, `webhook_events` and `webhook_deliveries`.
2. Create a secret and give it to both sides:

   ```sh
   php -r 'echo "whsec_" . base64_encode(random_bytes(32)), PHP_EOL;'
   ```

3. In `.env`:

   ```ini
   IXP_WEBHOOKS_ENABLED=true
   IXP_WEBHOOKS_URL=https://api5.example.net/ixp-manager/webhooks
   IXP_WEBHOOKS_SECRET=whsec_...
   ```

   then `php artisan config:cache` if the configuration is cached.
4. `php artisan webhooks:ping` - sends a signed `webhook.ping` and prints the response.

The scheduler cron (`* * * * * php artisan schedule:run`), which every IXP Manager installation
already has, runs `webhooks:deliver` every minute; nothing else needs to be set up. No queue
worker is needed.

## Configuration

All keys are read by `config/ixp_api.php` (`ixp_api.webhooks`).

| Key | Default | Meaning |
| --- | --- | --- |
| `IXP_WEBHOOKS_ENABLED` | `false` | Master switch. While false no change is recorded and nothing is sent; changes made meanwhile are never sent later. |
| `IXP_WEBHOOKS_URL` | *(empty)* | Where events are `POST`ed. Empty means there is no endpoint. Must be `https://` unless `IXP_WEBHOOKS_ALLOW_HTTP` is true. |
| `IXP_WEBHOOKS_SECRET` | *(empty)* | Signing secret, `whsec_` followed by the base64 of at least 24 random bytes (32 recommended). Without a usable secret nothing is sent - events queue up until it is fixed. |
| `IXP_WEBHOOKS_SECRET_PREVIOUS` | *(empty)* | The secret being rotated out. While set, every request carries two signatures, one per secret. |
| `IXP_WEBHOOKS_EVENTS` | `*` | Comma-separated globs of the event types to send, e.g. `member.*,connection.*,switch_port.*`. Empty means `*`. |
| `IXP_WEBHOOKS_EXCLUDE_EVENTS` | *(empty)* | Comma-separated globs of event types not to send, e.g. `note.*`. Wins over `IXP_WEBHOOKS_EVENTS`. |
| `IXP_WEBHOOKS_SKIP_API_KEYS` | *(empty)* | Comma-separated API keys - by `api_keys.id` or by token identifier (the part between `ixpm_` and the next `_`) - whose own changes are recorded but not sent to this endpoint. See the loop-prevention contract before using it. |
| `IXP_WEBHOOKS_MAX_ATTEMPTS` | `11` | Delivery attempts per event, the first included. |
| `IXP_WEBHOOKS_MAX_AGE_HOURS` | `72` | An event not delivered within this many hours of being queued is given up on, whatever the attempts. |
| `IXP_WEBHOOKS_TIMEOUT` | `10` | Seconds a request may take in total. |
| `IXP_WEBHOOKS_CONNECT_TIMEOUT` | `3` | Seconds to establish the connection. |
| `IXP_WEBHOOKS_RETENTION_DAYS` | `30` | Days an event is kept once all its deliveries are finished. |
| `IXP_WEBHOOKS_DELIVER_AFTER_RESPONSE` | `auto` | Whether the request or command that made a change also makes the first delivery attempt, after its response has been sent. `auto`: for commands, and for requests under PHP-FPM or LiteSpeed, which release the client before. Not under Apache's mod_php - the setup of IXP Manager's installer - where the browser would wait for the attempt; the scheduler delivers there, within a minute. `true` / `false` force either way. |
| `IXP_WEBHOOKS_INCLUDE_CONSOLE` | `true` | Also record changes made by artisan commands, the scheduler and queue workers (ports discovered by `switch:snmp-poll`, `user:create`, ...). |
| `IXP_WEBHOOKS_ALLOW_HTTP` | `false` | Allow a plain `http://` URL. For a development receiver only: payloads carry personal data. |

The endpoint built from these keys is called `default`. A second receiver is one more entry
under `ixp_api.webhooks.endpoints` in `config/ixp_api.php`, with its own `url`, secrets,
`events`, `exclude_events` and `skip_api_keys`; deliveries are tracked per endpoint.

## The request

```http
POST /ixp-manager/webhooks HTTP/1.1
Host: api5.example.net
Content-Type: application/json
User-Agent: IXP-Manager-Webhooks/7.4.1
webhook-id: 0193f7a2-5c3e-7b21-9a4f-6d0c2e8b1f37
webhook-timestamp: 1795528992
webhook-signature: v1,K5oZfzN95Z9UVu1EsfQmfVNQhnkZ2pj9o9NDN/H/pI4=
X-IXP-Manager-Event: connection.updated
X-IXP-Manager-Delivery: 10423
X-IXP-Manager-Attempt: 1

{"schema":"ixpm.webhook.v1","id":"0193f7a2-5c3e-7b21-9a4f-6d0c2e8b1f37","sequence":104233, ...}
```

| Header | |
| --- | --- |
| `webhook-id` | The event's id - the same as `id` in the body, and the same on every attempt. **Deduplicate on it.** |
| `webhook-timestamp` | Unix seconds of this attempt. |
| `webhook-signature` | `v1,` + base64 HMAC-SHA256, see below. During a rotation two such values, separated by a space. |
| `X-IXP-Manager-Event` | The event type, for routing without parsing the body. |
| `X-IXP-Manager-Delivery` | The delivery's id: one per event and endpoint, the same on every attempt. For support questions. |
| `X-IXP-Manager-Attempt` | 1 for the first attempt, 2 for the first retry, ... |

Redirects are not followed. TLS certificates are verified.

### Verifying the signature

The signed content is `{webhook-id}.{webhook-timestamp}.{raw body}`; the key is the
base64-decoded part of the secret after `whsec_`. A receiver must:

- verify **over the raw body**, before decoding the JSON;
- reject a timestamp more than five minutes from its own clock (replays);
- compare in constant time, and accept the request if **any** of the signatures matches;
- answer `2xx` within the timeout (10 seconds) and do the work afterwards.

```php
function verifyIxpManagerWebhook( string $secret, string $id, string $timestamp, string $signatures,
                                  string $body, int $tolerance = 300 ): bool
{
    if( !ctype_digit( $timestamp ) || abs( time() - (int)$timestamp ) > $tolerance ) {
        return false;
    }

    $key      = base64_decode( preg_replace( '/^whsec_/', '', $secret ), true );
    $expected = 'v1,' . base64_encode( hash_hmac( 'sha256', "{$id}.{$timestamp}.{$body}", $key, true ) );

    foreach( explode( ' ', $signatures ) as $candidate ) {
        if( hash_equals( $expected, $candidate ) ) {
            return true;
        }
    }

    return false;
}

// Laravel:
$ok = verifyIxpManagerWebhook( config( 'services.ixpm.webhook_secret' ),
    $request->header( 'webhook-id' ), $request->header( 'webhook-timestamp' ),
    $request->header( 'webhook-signature' ), $request->getContent() );
```

Any Standard Webhooks library does the same. To test an implementation, the specification's
example must verify (with the clock at `1614265330`):

| | |
| --- | --- |
| secret | `whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw` |
| webhook-id | `msg_p5jXN8AQM9LWM0D4loKWxJek` |
| webhook-timestamp | `1614265330` |
| body | `{"test": 2432232314}` |
| webhook-signature | `v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=` |

`IXP\Services\Webhooks\Signer::verify()` is the reference implementation on IXP Manager's side.

### What the response means

| Response | Outcome |
| --- | --- |
| any `2xx` | delivered |
| `400`, `405`, `410`, `413`, `415`, `422` | the receiver rejects the event itself: given up at once, not retried |
| anything else - `5xx`, `408`, `409`, `429`, `401`/`403`/`404` (typical while a receiver deploys), a `3xx`, a timeout, a connection or TLS error | retried |

## The envelope

Every event has the same top-level fields, in this order.

| Field | |
| --- | --- |
| `schema` | `ixpm.webhook.v1`. Changes only for an incompatible change of the envelope; fields may be added to it, and to `data`, at any time. |
| `id` | The event id, a UUIDv7. Also sent as `webhook-id`. |
| `sequence` | A number that grows with every event recorded. Later events have higher numbers; there can be gaps. `null` for `webhook.ping`. |
| `type` | `<resource>.<created\|updated\|deleted>`, or `webhook.ping`. |
| `occurred_at` | When the unit of work first changed the resource - UTC, microseconds. |
| `source` | `{app: "ixp-manager", url, version}`: which installation sent it. |
| `origin` | How the change was made, see below. |
| `actor` | Who made it, see below. |
| `resource` | `{type, id, member_id}`. `member_id` is the member the resource belongs to - `null` for a user, who can belong to several (see their `links`), and for a switch port no member has. |
| `data` | The resource **after** the change, as the provisioning API represents it. For a deletion: the last state before it. |
| `changes` | For `updated`: what changed, `[{path, old, new}]`, `path` a JSON pointer into `data`. Empty for `created` and `deleted`. |

`origin`:

| Field | |
| --- | --- |
| `channel` | `web` (a browser - staff or a member's users -, including the web UI's own calls to `admin/api/v4`), `api` (another API endpoint, called with an API key), `provisioning_api`, `artisan` (a command, including those the scheduler runs) or `queue` (a job on a queue worker). |
| `route` | The route name for `web`, `api` and `provisioning_api`, e.g. `customer@update`, `api-v4-provisioning@connection-store`. |
| `command` | The artisan command, e.g. `switch:snmp-poll`. |
| `job` | The queued job's class. |
| `api_key_id` | `api_keys.id` of the key an `api` or `provisioning_api` request authenticated with. |
| `correlation_id` | The `X-Correlation-Id` header of the provisioning request, unchanged. |
| `unit_id` | The unit of work: events with the same `unit_id` came from the same request, command or job. |

`actor`: `{type, user_id, username, privs, customer_id, impersonated_by}`. `type` is `user`, or
`system` when no user was logged in (commands, the scheduler). For a provisioning request the
user is the API key's owner. `privs` is the user's privilege on the member they were acting for -
`3` superuser (IXP staff), `2` member administrator, `1` member user - which tells staff from a
member's self-service. `customer_id` is that member. `impersonated_by` is the superuser who had
switched to this user, if any.

## Units of work and what counts as a change

A **unit of work** is one HTTP request, one artisan command, or one job on a queue worker (a
job on the default `sync` queue runs inside the request or command that dispatched it and
belongs to that). Per unit of work and resource there is **at most one event**:

- Several writes to one resource - the web wizard writes the virtual, physical and VLAN
  interfaces, a switch port and an address of a new connection - are one event.
- `changes` are relative to the state before the unit's first write to the resource; `data` is
  the state when the unit ended. Intermediate states are not reported.
- A transaction that rolls back produces nothing; a unit that rolls back part of its work reports
  the rest.
- A resource created and deleted within one unit produces nothing.
- A write that leaves every reported field as it was produces nothing.

Events are recorded when the unit ends, after its transactions have committed: at the end of
the request (after the response), of the command, or of the job. A long command reports at its
end; `occurred_at` still says when it first changed each resource.

Left out of `changes` (still in `data`, and never the only reason for an event):
`updated_at` everywhere; for members `lastupdatedby`, `in_manrs` and `in_peeringdb` (the daily
MANRS and PeeringDB jobs rewrite the latter two); for users `custid` (the member a user is
currently switched to, which moves on login) and `links/*/last_login_*`.

Never an event: logins, user preferences (e.g. which notes were read), passwords, remember-me
tokens, 2FA, API keys, and the switch port fields the SNMP poll maintains (operational and
administrative status, speed, MTU, MAU, ifIndex, ifAlias, last poll).

## Events

`data` is always the provisioning API's own representation of the resource, so a webhook can be
applied with the same code as a `GET`. Secrets are never part of it: no password hash, no BGP MD5
secret (only `ipv4bgpmd5secret_set` / `ipv6bgpmd5secret_set`), no SNMP community, no token.

| Resource | `data` is | Events |
| --- | --- | --- |
| `member` | `GET member/{cust}`, plus `billing_registration` as `GET member/{cust}/billing-registration` returns it | `member.created`, `member.updated`, `member.deleted` |
| `user` | `GET user/{u}`: the person and every member link (`links`) | `user.created`, `user.updated`, `user.deleted` |
| `contact` | `GET contact/{contact}`, roles included | `contact.created`, `contact.updated`, `contact.deleted` |
| `connection` | `GET connection/{vi}`, with each VLAN interface as `GET vlan-interface/{vli}` returns it (a superset of the connection's own VLAN interface shape, adding the configured MACs as `mac_addresses`) | `connection.created`, `connection.updated`, `connection.deleted` |
| `switch_port` | `{id, name, switch, switch_name, infrastructure, type, active, physical_interface, connection, custid, free}` | `switch_port.updated`; `switch_port.created` / `.deleted` when a port is discovered or removed |
| `note` | `{id, custid, title, note, private, created_at, updated_at}` | `note.created`, `note.updated`, `note.deleted` |
| - | `{message, endpoint}` | `webhook.ping`, from `webhooks:ping` only |

### member.*

From the web UI (member create, edit, billing and registration details, delete), the members'
own dashboard (NOC and billing details), `POST`/`PATCH member`, `PATCH member/{cust}/billing-registration`
and `POST onboarding`. A change of `status`, `type` or `dateleave` is how suspension and
offboarding show.

```json
{
  "schema": "ixpm.webhook.v1",
  "id": "0193f7a2-3a42-71ae-a43c-571968811e15",
  "sequence": 104230,
  "type": "member.updated",
  "occurred_at": "2026-11-24T14:03:12.059607Z",
  "source": { "app": "ixp-manager", "url": "https://ixp.example.net", "version": "7.4.1" },
  "origin": {
    "channel": "provisioning_api", "route": "api-v4-provisioning@member-update",
    "command": null, "job": null, "api_key_id": 7, "correlation_id": "api5-cmd-88f1",
    "unit_id": "0193f7a2-3a39-718d-951e-403a8a12b6cc"
  },
  "actor": { "type": "user", "user_id": 3, "username": "api5", "privs": 3, "customer_id": 1, "impersonated_by": null },
  "resource": { "type": "member", "id": 97, "member_id": 97 },
  "data": {
    "id": 97, "name": "Example Networks Ltd", "shortname": "example", "abbreviatedName": "EXAMPLE",
    "type": 1, "status": 2, "autsys": 65550, "maxprefixes": 500, "maxprefixesv6": null,
    "peeringemail": "peering@example.com", "peeringmacro": null, "peeringmacrov6": null,
    "peeringpolicy": null, "irrdb": null, "datejoin": "2026-08-01", "dateleave": "2026-12-31",
    "reseller": null, "isReseller": false, "corpwww": null, "nocphone": null, "noc24hphone": null,
    "nocemail": "noc@example.com", "nochours": null, "nocwww": null, "MD5Support": "UNKNOWN",
    "activepeeringmatrix": false, "peeringdb_oauth": true, "in_manrs": false, "in_peeringdb": false,
    "is_current": true, "has_left": true, "creator": "3", "lastupdatedby": 3,
    "created_at": "2026-08-01T09:12:40+00:00", "updated_at": "2026-11-24T14:03:12+00:00",
    "billing_registration": {
      "member_id": 97, "billing_managed_by_reseller": false,
      "registration": { "registeredName": "Example Networks Ltd", "companyNumber": null, "...": "..." },
      "billing": { "billingContactName": "Accounts Payable", "billingEmail": "billing@example.com", "...": "..." }
    }
  },
  "changes": [
    { "path": "/status",    "old": 1,     "new": 2 },
    { "path": "/dateleave", "old": null,  "new": "2026-12-31" },
    { "path": "/has_left",  "old": false, "new": true }
  ]
}
```

A billing change reads `{"path": "/billing_registration/billing/billingEmail", "old": null, "new": "billing@example.com"}`.

`member.deleted` comes with an event for everything the deletion takes along: `connection.deleted`
for each connection, `contact.deleted` for each contact, `note.deleted` for each note,
`user.deleted` for users who belonged to no other member and `user.updated` (a link fewer) for
those who did, and `switch_port.updated` for each port freed.

### user.*

From the web UI (users, member links and privileges, by staff or a member administrator, and a
user's own profile), PeeringDB OAuth logins that create or update users, `user:create`, and the
provisioning API's user endpoints. Adding a user to another member, changing their privilege on
one or removing them from one is a `user.updated` whose change is in `links`:

```json
{
  "type": "user.updated",
  "resource": { "type": "user", "id": 314, "member_id": null },
  "data": {
    "id": 314, "username": "janedoe", "name": "Jane Doe", "email": "jane@example.com",
    "authorisedMobile": "+353 87 1234567", "enabled": true, "custid": 97, "peeringdb_id": null,
    "creator": "api5", "created_at": "2026-11-24T14:03:12+00:00", "updated_at": "2026-11-24T14:05:40+00:00",
    "links": [
      { "id": 512, "custid": 97, "user_id": 314, "privs": 2, "created_by": { "type": "api", "user_id": 3 },
        "last_login_date": null, "last_login_from": null, "last_login_via": null,
        "created_at": "2026-11-24T14:03:12+00:00", "updated_at": "2026-11-24T14:03:12+00:00" }
    ]
  },
  "changes": [ { "path": "/authorisedMobile", "old": null, "new": "+353 87 1234567" } ]
}
```

(Envelope fields left out here and below are as in the member example.)

### contact.*

From the web UI (staff and member administrators), `helpdesk:update-organisations`, and the
provisioning API's contact endpoints. A change of roles alone is a `contact.updated`
(`/roles/{group id}` added or removed); a deleted contact is reported with the roles it had.

```json
{
  "type": "contact.updated",
  "resource": { "type": "contact", "id": 42, "member_id": 97 },
  "data": {
    "id": 42, "custid": 97, "name": "Erika Mustermann", "position": "Head of Network Operations",
    "email": "erika@example.net", "phone": "+49 69 1234567", "mobile": null, "notes": null,
    "roles": [ { "id": 1, "name": "Billing", "type": "ROLE" } ],
    "created_at": "2026-11-24T14:03:12+00:00", "updated_at": "2026-11-24T14:06:02+00:00"
  },
  "changes": [ { "path": "/phone", "old": null, "new": "+49 69 1234567" } ]
}
```

### connection.*

A connection is the virtual interface with its physical interfaces, VLAN interfaces, their
addresses and configured MACs. From the web UI (the wizard, virtual, physical and VLAN interface
forms, MAC addresses - also members' own, where allowed -, patch panel ports setting an
interface's status), and the provisioning API's connection, physical interface, VLAN interface
and MAC endpoints and onboarding. An address assigned or released is
`/vlan_interfaces/{id}/ipv4address`; a MAC added or removed is
`/vlan_interfaces/{id}/mac_addresses/{mac id}`.

```json
{
  "type": "connection.updated",
  "resource": { "type": "connection", "id": 501, "member_id": 97 },
  "data": {
    "id": 501, "custid": 97, "name": null, "description": null, "mtu": null, "trunk": false,
    "lag_framing": false, "fastlacp": false, "channelgroup": null,
    "physical_interfaces": [
      { "id": 77, "status": 1, "speed": 10000, "duplex": "full", "rate_limit": null, "autoneg": true,
        "switchport": 1842, "switchport_name": "Ethernet12", "switch": 4, "switch_name": "fra1-sw1",
        "connection": 501, "switchport_type": 1, "notes": null }
    ],
    "vlan_interfaces": [
      { "id": 812, "connection": 501, "custid": 97, "vlan": 1,
        "ipv4enabled": true, "ipv4address": "192.0.2.17", "ipv4hostname": "example.ix.example.net",
        "ipv4bgpmd5secret_set": false, "ipv4canping": false, "ipv4monitorrcbgp": false, "ipv4maxbgpprefix": null,
        "ipv6enabled": false, "ipv6address": null, "ipv6hostname": null,
        "ipv6bgpmd5secret_set": false, "ipv6canping": false, "ipv6monitorrcbgp": false, "ipv6maxbgpprefix": null,
        "mcastenabled": false, "irrdbfilter": true, "rsclient": true, "rsmorespecifics": false,
        "as112client": false, "busyhost": false, "maxbgpprefix": { "ipv4": null, "ipv6": null },
        "mac_addresses": [
          { "id": 3, "mac": "001122334455", "mac_colon": "00:11:22:33:44:55", "vlan_interface": 812,
            "connection": 501, "vlan": 1, "custid": 97, "member_name": "Example Networks Ltd",
            "created_at": "2026-11-24T14:07:30+00:00" }
        ],
        "created_at": "2026-11-24T14:03:12+00:00", "updated_at": "2026-11-24T14:07:30+00:00" }
    ]
  },
  "changes": [
    { "path": "/vlan_interfaces/812/mac_addresses/3", "old": null,
      "new": { "id": 3, "mac": "001122334455", "mac_colon": "00:11:22:33:44:55", "...": "..." } }
  ]
}
```

`connection.created` carries the whole connection - including the address the API allocated for
`"ipv4address": "auto"` - and empty `changes`. `connection.deleted` carries the connection as it
was before the deletion, MACs included, and comes with a `switch_port.updated` for every port it
freed. Connections of core bundles and reseller fanout connections are connections too.

### switch_port.*

When a port's `type`, `active` or `name` changes, or a physical interface is attached to it,
detached from it or moved. `free` is the predicate of `GET port/free`: no physical interface,
type unset (`0`) or peering (`1`), active switch.

```json
{
  "type": "switch_port.updated",
  "origin": { "channel": "provisioning_api", "route": "api-v4-provisioning@connection-destroy", "...": "..." },
  "resource": { "type": "switch_port", "id": 1842, "member_id": null },
  "data": {
    "id": 1842, "name": "Ethernet12", "switch": 4, "switch_name": "fra1-sw1", "infrastructure": 1,
    "type": 1, "active": true, "physical_interface": null, "connection": null, "custid": null, "free": true
  },
  "changes": [
    { "path": "/physical_interface", "old": 77,    "new": null },
    { "path": "/connection",         "old": 501,   "new": null },
    { "path": "/custid",             "old": 97,    "new": null },
    { "path": "/free",               "old": false, "new": true }
  ]
}
```

`switch_port.created` is sent for ports `switch:snmp-poll` discovers (a new switch: one per port).
Exclude it with `IXP_WEBHOOKS_EXCLUDE_EVENTS=switch_port.created` if that is noise.

### note.*

Staff notes on a member (`admin/api/v4/customer-note`, used by the member overview page).
`private` notes are shown to superusers only. If internal notes must not leave IXP Manager:
`IXP_WEBHOOKS_EXCLUDE_EVENTS=note.*`.

```json
{
  "type": "note.created",
  "origin": { "channel": "web", "route": "customer-notes@create", "api_key_id": null, "...": "..." },
  "resource": { "type": "note", "id": 9, "member_id": 97 },
  "data": { "id": 9, "custid": 97, "title": "Order 4711", "note": "Cross-connect ordered",
            "private": true, "created_at": "2026-11-24T14:08:00+00:00", "updated_at": "2026-11-24T14:08:00+00:00" },
  "changes": []
}
```

### webhook.ping

Sent by `php artisan webhooks:ping` only, at once and outside the queue: `sequence` and
`resource` are `null`, `data` is `{"message": "...", "endpoint": "default"}`. Verify it like any
other event, answer `2xx`, change nothing.

## Delivery, retries and order

- The first attempt is made at the end of the request or command that made the change (see
  `IXP_WEBHOOKS_DELIVER_AFTER_RESPONSE`), retries by `webhooks:deliver`, every minute.
- After a failed attempt, the next comes after 1, 5 and 15 minutes, 1, 2, 4, 8 and 12 hours, then
  24 hours, each give or take 10 %. A `429` or `503` with `Retry-After` is honoured, up to an hour.
- After `IXP_WEBHOOKS_MAX_ATTEMPTS` (11) attempts, or once the event has waited
  `IXP_WEBHOOKS_MAX_AGE_HOURS` (72), the delivery is given up on - the dead letter, status
  `failed`, logged as an error. `php artisan webhooks:retry --failed` puts dead letters back once
  the receiver is fixed.
- **Order:** per endpoint strictly by `sequence`. While the oldest undelivered event waits for its
  retry, nothing younger is sent; once it is delivered or given up on, the queue moves on. Events
  can therefore arrive out of order only after a dead letter, or when `webhooks:retry` re-sends
  an old one.
- Within the same resource, a higher `sequence` always describes a state at least as recent: the
  events of concurrent units are recorded one after the other, each with the state at its
  recording. **Apply an event only if its `sequence` is higher than the last one applied for that
  resource**; since `data` is the complete state, skipping an older one loses nothing.

## Idempotency

Delivery is **at least once**. A receiver can see the same event twice - its `2xx` was lost to a
timeout, say, or an operator re-sent it. Deduplicate on `webhook-id` (= `id`): remember the ids
processed for at least the retry window plus a margin - seven days is comfortable - and answer
`2xx` to a repeat without processing it again. Every attempt carries the same body.

## The loop-prevention contract

API5 both calls the provisioning API and receives the webhooks those calls cause. Without care
it would act on the echo of its own writes. The contract:

1. **API5 sends `X-Correlation-Id`** with every provisioning request: a fresh value per command,
   recorded on API5's side before the call. 1 to 100 characters from `A-Z a-z 0-9 . _ : -` (a
   UUID fits); anything else is answered with `422` before anything is written. The header is
   echoed in the response.
2. **Every event the request causes carries it**, side effects included: `origin.channel` is
   `provisioning_api`, `origin.api_key_id` the key's id, `origin.correlation_id` the header. A
   connection order produces a `connection.created` *and* a `switch_port.updated`, both stamped.
3. **On receipt**, an event whose `origin.correlation_id` is one of API5's own (or, failing
   that, whose `origin.api_key_id` is API5's key) is the echo of API5's own command. API5 does
   not re-run business logic for it, but adopts what IXP Manager decided: ids, the address
   allocated for `auto`, the channel group, the bundle name, defaults.
4. **Webhooks can arrive before the HTTP response** of the call that caused them; the
   correlation id ties the two together either way.
5. **A write that changes nothing produces no event.** API5 writing back exactly what it was
   told cannot start a ping-pong. It still can when both sides normalise a value differently
   (letter case, phone number or MAC formatting): compare with `data` before writing back.
6. **`IXP_WEBHOOKS_SKIP_API_KEYS`** withholds a key's own changes from an endpoint altogether
   (recorded, delivery status `skipped`, never sent; `webhooks:retry --skipped` sends them after
   all). That also withholds the side effects of API5's calls on other resources, such as the
   port a connection took - so the recommended setting is to leave it empty and follow points 1
   to 3.

## Operations

| Command | |
| --- | --- |
| `webhooks:deliver [--endpoint=] [--limit=1000] [--time=50]` | Delivers what is due. Scheduled every minute (in the background, without overlapping). Also turns into events the changes of units of work whose process died after committing, once they are five minutes old. |
| `webhooks:status [--check] [--max-wait=15]` | Configuration problems, deliveries per status, the oldest undelivered event and its last error, the last delivery. With `--check` the exit code is 1 when an endpoint is misconfigured, an event was given up on, or the oldest undelivered event has waited longer than `--max-wait` minutes - for monitoring. |
| `webhooks:retry {delivery*} [--failed] [--skipped] [--endpoint=] [--since=]` | Puts deliveries back into the queue, afresh: no attempts, due now, a new age window. |
| `webhooks:ping [endpoint]` | Sends a signed `webhook.ping` now and prints the response. Works while webhooks are disabled. |
| `webhooks:prune [--days=]` | Deletes events past `IXP_WEBHOOKS_RETENTION_DAYS` whose deliveries are all finished. Scheduled daily at 04:21. |

Failed attempts are logged as warnings, dead letters and recording failures as errors - with
the event id, type, endpoint name, host and HTTP status; never the body, a secret or the full
URL. Payloads carry personal data (contacts, users, billing details): the receiver needs a
processing basis and retention rules of its own.

## Blind spots

What is **not** reported, or not completely:

- **Changes while webhooks are disabled**, and changes recorded before a crash that could not be
  recovered (logged as `Webhooks: could not record ...` / `dropped the change of ...`), are never
  sent. There is no backfill: reconcile with the provisioning API's lists (`GET member` with
  `updated_since`, `GET connection`, `GET user`).
- **Writes that bypass Eloquent**, except the three mass deletes that are watched for (a VLAN
  interface's MACs, a contact's roles, a member's contacts). Not covered, among others:
  - `SwitchController` removing a switch deletes its ports with the query builder: no
    `switch_port.deleted` for them;
  - the fanout teardown (`removeRelatedInterface()`) detaches and deletes a reseller's fanout
    virtual interface with the query builder: no event when that interface has no VLAN
    interfaces;
  - IP address pool changes (`IpAddressController`, including `deleteByNetwork`) - not reported
    at all;
  - SQL run by hand, by migrations or by upgrade scripts.
- **ON DELETE CASCADE** is covered where a member or a connection is deleted through Eloquent;
  a member or connection deleted by other means takes its dependants without events.
- **Not reported at all:** switches, VLANs, infrastructures, facilities, racks, vendors, IP
  address pools, route server filters, patch panels and cross connects, member tags, contact
  group definitions, API keys, learned MAC addresses. Read them with the `GET` endpoints when
  needed.
- **Copies of other resources' fields** are refreshed only when their own resource changes: a
  member renamed does not re-send its connections (whose MACs carry `member_name`), a switch
  renamed or deactivated does not re-send its ports (`switch_name`, `free`).
- **Values computed from the date** - `is_current`, `has_left` - change at midnight without a
  write and so without an event.
- **Deliveries to an endpoint removed from the configuration** stay pending; `webhooks:status`
  lists them.

### For code that removes rows with the query builder

`app( \IXP\Services\Webhooks\Recorder::class )->touch( 'connection', $vi->id )` before such a
delete keeps the resource's state for its event (types: `member`, `user`, `contact`,
`connection`, `switch_port`, `note`). Touching a resource that then does not change produces
nothing.
