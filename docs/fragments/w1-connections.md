<!--
Fragment of docs/provisioning-api.md, work package "connections and physical interfaces"
(wave 1). The sections below extend "## Connections"; "Validation" and "Concurrency" add to the
sections of those names. Sentences in the main document which this package makes untrue:

  - Conventions: "There is no PUT yet: the API creates, reads and deletes, but does not change."
  - What is deliberately absent: "No suspend or offboard endpoints yet." PATCH
    physical-interface/{pi} can now set a port to disabled or not connected; what it cannot do
    is remember the previous state for an exact restore.
  - Machine-readable specification: the list of what answers 409 (see the end of this file).
-->

## Connections

```http
GET    /admin/api/v4/provisioning/connection
GET    /admin/api/v4/provisioning/connection/{vi}
PATCH  /admin/api/v4/provisioning/connection/{vi}
POST   /admin/api/v4/provisioning/connection/{vi}/physical-interface
GET    /admin/api/v4/provisioning/physical-interface/{pi}
PATCH  /admin/api/v4/provisioning/physical-interface/{pi}
POST   /admin/api/v4/provisioning/physical-interface/{pi}/move
DELETE /admin/api/v4/provisioning/physical-interface/{pi}
```

`{vi}` is the connection's virtual interface id - the `id` every connection representation
carries - and `{pi}` a physical interface id from its `physical_interfaces`.

### Reading connections

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
endpoints keep their shape byte for byte - callers parse it - so the fuller one is only returned
by the new endpoints.

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

## Validation

`PATCH /connection/{vi}` uses `UpdateConnection`, which extends the request of the web edit form
of a virtual interface (`StoreVirtualInterface`). Its delta is `trunk`, `lag_framing` and
`fastlacp`: the form posts them, but its request never validates them, so on the web they reach
the model unchecked. `custid`, which those rules require, is always the connection's own.

The physical interface endpoints use `StorePhysicalInterface`, which extends the web request of
the same name, with `UpdatePhysicalInterface` and `MovePhysicalInterface` extending it in turn,
so all three validate a port alike. The delta is `autoneg`. The fanout fields are removed before
validation, because the inherited rules switch on `fanout`.

PATCH requests are completed with the record's current values before validation, as for
`PATCH /member/{cust}`, so a change is validated as the web form would validate it. Unlike there,
only the fields sent are written afterwards: written back, the completed values would undo a
change another request made in the meantime.

`InterfaceParityTest` holds the requests to the web rules plus the declared delta, and sends the
same invalid payloads to the web routes and to the API to check that the same fields fail. It
also checks that the web and the API refuse the same connection changes - a channel group removed
from a LAG with several ports, a channel group in use on the switch - and the removal of an
interface in a core link.

## Concurrency

Channel groups must be unique per switch, and nothing in the schema enforces that. Two requests
for connections on one switch could otherwise pick or check the same number, neither seeing the
other's uncommitted write. Every write of a connection or a physical interface therefore runs in
one transaction which first locks the rows of the switches involved, then the connection's row.
The same lock means two removals cannot each take one of a connection's last two ports.

A switch port taken by another request between the check and the write is refused by the unique
index on `physicalinterface.switchportid`; adding and moving answer that with `409` and roll
everything back.

`409` now also arises from: a channel group in use on the switch, a channel group removed from a
LAG with several ports, LAG framing switched off on a connection with several ports, a second
port on a connection without LAG framing, the last physical interface of a connection, a
connection of a core bundle (`PATCH /connection/{vi}`, adding a port), and an interface on a
fanout port or in a core link (`PATCH`, `/move`, `DELETE`). An unusable or taken switch port is
still a `422`, as for `POST /member/{cust}/connection`.
