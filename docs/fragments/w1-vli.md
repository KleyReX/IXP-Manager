<!--
  Fragment of docs/provisioning-api.md - wave 1, work package "VLAN interfaces and MAC
  addresses". Two sections to insert after "## Connections", additions to "## Validation",
  "## Concurrency" and "## What is deliberately absent", and, at the end, the statements
  elsewhere in the document which this package makes untrue.
-->

## VLAN interfaces

```http
POST   /admin/api/v4/provisioning/connection/{vi}/vlan-interface
GET    /admin/api/v4/provisioning/vlan-interface/{vli}
PATCH  /admin/api/v4/provisioning/vlan-interface/{vli}
DELETE /admin/api/v4/provisioning/vlan-interface/{vli}
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
the same numbers under the names `PATCH` takes.

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

## Configured MAC addresses

```http
GET    /admin/api/v4/provisioning/vlan-interface/{vli}/mac
POST   /admin/api/v4/provisioning/vlan-interface/{vli}/mac
DELETE /admin/api/v4/provisioning/mac/{l2a}
GET    /admin/api/v4/provisioning/mac?mac=&member=&vlan=
```

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

## Validation — additions

The VLAN interface requests extend the web's `StoreVlanInterface`, which serves the web's create
and edit forms alike. The delta is empty: the web rules cover every field, and
`virtualinterfaceid` — a hidden field there — comes from the route here. Four rules are replaced,
each checked by `VlanInterfaceRulesParityTest`:

- `ipv4address` / `ipv6address`, to accept `"auto"`, as for connections — the conditional
  `required` stays.
- `ipv4hostname` / `ipv6hostname`: the web rule with `bail` in front, and nothing else. Without
  it a hostname which is not a string reaches `IdnValidate`, whose `idn_to_ascii()` throws a
  `TypeError` — `"ipv4hostname": ["x"]` is a `500` on the web. `POST /member/{cust}/connection`
  inherits the same rule from the wizard and still has that `500`.

`VlanInterfaceRulesParityTest` also fails when upstream adds a field to the edit form, so that
whether `PATCH` takes it is decided rather than forgotten.

The MAC endpoint has no web form request to extend — the web controller checks inline
(`Api\V4\Layer2AddressController.php:90-108`). `StoreMacAddress` restates those checks as rules
and `MacAddressRulesParityTest` sends the same MACs to both and compares the verdicts. A `mac`
which is not a string is a `500` on the web; here it is a `422`.

Neither the web rules nor these range-check the max prefixes: a negative value is stored as `0`
by MySQL (IXP Manager does not run it in strict mode), which the router configuration reads as
"no interface limit, use the member's". Send `null` to say that.

## Concurrency — additions

Every write takes its locks in one order — the connection, the interface, the VLAN, then the
addresses — so that two of them queue rather than deadlock. The checks made during validation
(one interface per VLAN, a MAC unique within a VLAN) are repeated under those locks; a request
which lost a race to another one gets `409`, as does one whose change another request made
impossible in between (an address gone, say), and nothing is written. A lost race for an address
is refused by the unique index and is `409` too.

A MAC's uniqueness within a VLAN exists only in that check — the schema enforces uniqueness per
interface — so every MAC write locks the VLAN row and checks again before it inserts.

## What is deliberately absent — additions

- **No `"auto"` MD5 secret.** The web form generates one in the browser; an API that generated one
  would have to return it, and secrets are never returned. The caller generates the secret it
  hands to the member.
- **No duplicate endpoint.** The web's "duplicate VLAN interface" is `POST
  /connection/{vi}/vlan-interface` plus `POST /vlan-interface/{vli}/mac` here — or, to move rather
  than copy, `PATCH` with `vlanid`.
- **The VLAN's infrastructure is not checked against the connection's switch.** Neither the web
  nor `POST /member/{cust}/connection` checks it; doing it here alone would make the endpoints
  disagree.

## Statements elsewhere which this package makes untrue

- *Conventions*: "There is no `PUT` yet: the API creates, reads and deletes, but does not change."
  — `PATCH /vlan-interface/{vli}` changes (as `PATCH /member/{cust}` already does).
- *Connections*: the OpenAPI `Connection` schema says the VLAN interface deliberately does not
  report `mcastenabled`, `rsmorespecifics`, `busyhost`, the MD5 secrets, `canping` and
  `monitorrcbgp`. `GET /vlan-interface/{vli}` reports all of them, the secrets as booleans.
- *Machine-readable specification*: the path and schema counts.
