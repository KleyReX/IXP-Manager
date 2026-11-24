<!--
  Fragment of docs/provisioning-api.md - wave 1, work package "reference data".

  Merge notes:
  - The "Reference data" section belongs after "Discovery" and before "Members"; its HTTP
    block lists every endpoint this package adds.
  - In "Discovery", the paragraph on `vlan/{vlan}/address` gains the sentence marked below.
  - "Conventions" may want one more line: `200` is also the answer to a POST which found what
    it would have created (POST /irrdb-source).
  - The OpenAPI fragment adds 9 paths and 9 schemas and replaces 3 paths and 5 schemas. Merged
    over the current specification that makes 20 paths and 33 schemas - the counts in
    "Machine-readable specification" change accordingly (before the other packages' fragments).
-->

## Reference data

```http
GET  /admin/api/v4/provisioning/vlan
GET  /admin/api/v4/provisioning/vlan/{vlan}
GET  /admin/api/v4/provisioning/irrdb-source
GET  /admin/api/v4/provisioning/irrdb-source/{irrdbconfig}
POST /admin/api/v4/provisioning/irrdb-source
GET  /admin/api/v4/provisioning/facility
GET  /admin/api/v4/provisioning/facility/{location}
GET  /admin/api/v4/provisioning/rack
GET  /admin/api/v4/provisioning/rack/{cabinet}
GET  /admin/api/v4/provisioning/vendor
```

What an ordering system needs to place an order and to keep its own records in step with
IXP Manager's. Everything here reads, except the IRRDB source find-or-create.

Several of these extend endpoints which already had callers - `switch`, `infrastructure` and
`vlan/{vlan}/address`. **Every such change is additive:** the existing fields keep their names
and formats, a request without the new parameters gets exactly what it got before, and new
fields only appear next to the old ones. The existing tests for those endpoints are unchanged.

One representation per kind of object, used wherever that object appears: a VLAN looks the
same in `GET vlan`, nested in `GET infrastructure` and in the header of `GET
vlan/{vlan}/address`.

### VLANs

`GET vlan` lists the VLANs with the flags an order depends on. A connection names its VLAN by
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

Filters, which combine: `?infrastructure=<id>`, `?private=1|0`, `?export_to_ixf=1|0`. Booleans
are `1` or `0`, as for `free` on the address listing; `true` is refused with `422`. Ordered by
802.1Q tag, as the web list is.

`GET vlan/{vlan}` is the same object plus `network_info`, the subnet per protocol:

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

### Switches and infrastructures

`GET switch` still lists the active switches by default. Two filters are new:

- `?active=1` (the default), `?active=0` for switches out of service, `?active=all`. A `NULL`
  in the column counts as inactive, as it does for `port/free` and for orders, so every switch
  appears under exactly one of `1` and `0`. Without `active=0`, a switch somebody has just
  deactivated simply vanishes from the listing, which looks the same as a deletion.
- `?infrastructure=<id>`.

Each switch gains `active`, and `rack`, `facility` and `vendor` - ids for `GET rack`, `GET
facility` and `GET vendor`. A switch reaches its facility only through its rack, so without
them an ordering system that sells ports per facility could not tell which switches stand
where. The SNMP community is never part of the response, nor are the SNMP-maintained columns.

`GET infrastructure` gains `country`, `ixf_ix_id`, `peeringdb_ix_id` and
`exclude_from_ixf_export`, the identifiers under which the exchange is known to PeeringDB and
the IX-F database. The nested VLANs carry the full VLAN representation.

### Who holds an address

In `GET vlan/{vlan}/address`, every address which is not free now names who holds it - the
columns the web address list shows:

```json
{ "id": 342, "address": "193.189.82.102", "free": false,
  "assigned_to": { "custid": 97, "member_name": "Example Networks GmbH",
                   "connection": 501, "vlan_interface": 733,
                   "hostname": "example.peering.kleyrex.net" } }
```

`assigned_to` is `null` for a free address, so `free` and `assigned_to` always agree.
`connection` is the virtual interface id `DELETE /connection/{vi}` takes; `hostname` is the
VLAN interface's hostname for that address family.

> Added to the paragraph on `vlan/{vlan}/address` in *Discovery*: `?network=<CIDR>` restricts
> the listing to one network, and every address in use names its holder.

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

`free`, `limit` and `network` combine; `total` counts what the filters matched.

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
  same new source do not both create it: the look-up is a locking read in the same transaction
  as the insert, so under MySQL's default isolation level (REPEATABLE READ) the second waits for
  the first and then finds its row. A source created in the web UI at the same instant can
  still slip past - the web UI permits duplicates anyway - and where duplicates exist already,
  the oldest is the one returned.

`member_count` is the number of members referring to the source, departed members included -
the same count the web UI checks before it lets a source be deleted.

The payload is validated as the web form validates it: `host` required, a host name of at most
255 characters; `source` required, at most 255. The web UI validates these inline in its
controller, so there is no form request to extend; `IrrdbSourceTest` instead sends the same
invalid payloads to the web form and to the API and asserts that the same fields fail. Two
differences, both deliberate: `notes` is validated (`nullable|string`; the web form stores it
unchecked), and a `host` that is not a string is a `422` - the web form's host-name check hands
it to `idn_to_ascii()`, which turns an array into a 500.

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
`GET vendor` has no show endpoint - the list carries every field.

An unknown `?facility=` is a `422`, as an unknown `?infrastructure=` is everywhere else.

### Known limitations

- No endpoint here changes a VLAN, a switch, a facility, a rack or a vendor; those remain web UI
  operations.
- An IRRDB source cannot be changed or deleted through the API.
- A route parameter that is not a number, such as `vlan/1abc`, is looked up as MySQL compares
  it - as `1`. This is how route model binding behaves on every route of this API, not only on
  these.
