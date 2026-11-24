<!--
Fragment of docs/provisioning-api.md, work package "offboarding" (wave 1b).

Merge notes:
  - "### Offboarding a member" belongs at the end of "## Members" (after "### Billing and
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

## Concurrency

An offboarding locks the member's row first, then its connections in id order. A second
offboarding of the same member waits, and then finds nothing left to do. A connection ordered for
the member at the same moment waits for the offboarding as well - its insert takes a shared lock
on the member's row for the foreign key - and is created after it: it is not torn down, and the
member then has a connection again. A deadlock is retried, from a fresh read of everything.

## What is deliberately absent

- **No deletion of members, and no suspension with an exact restore.**
  `POST /member/{cust}/offboard` closes a member and releases its ports, addresses and MACs; it
  deletes neither the member nor its users. Hard deletion (`CustomerAggregator::deleteObject()`)
  is for members created by mistake and stays in the web UI. A suspension which can be undone
  exactly needs somewhere to record what each field held before; there is no such place in the
  schema today.
