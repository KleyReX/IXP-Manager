## Portal users

```http
GET    /admin/api/v4/provisioning/user
GET    /admin/api/v4/provisioning/user/{u}
PATCH  /admin/api/v4/provisioning/user/{u}
DELETE /admin/api/v4/provisioning/user/{u}
POST   /admin/api/v4/provisioning/user/{u}/welcome-email
```

Creating a user is still `POST /member/{cust}/user` (see *Members*). These endpoints find,
change and delete users once they exist.

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

A user has one privilege **per member**, not one overall, so the API shows a user in two ways:

- **The person** — `GET /user`, `GET /user/{u}`, `PATCH /user/{u}`: who they are, plus `links`,
  one per member they can act for, each with its `privs`, who made it (`created_by`) and the
  user's last login to that member (`last_login_date`, `last_login_from`, `last_login_via`).
- **A member's user** — everything under `/member/{cust}/user`: the same person fields, plus
  `privs` and `link` for *that* member only. Other members do not appear. It is a superset of what
  `POST /member/{cust}/user` returns.

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
- **`authorisedMobile` is cut to 30 characters**, as on creation: the rule allows 50, the column
  holds 30, and the connection runs without strict mode.
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

## A member's users

```http
GET    /admin/api/v4/provisioning/member/{cust}/user
GET    /admin/api/v4/provisioning/member/{cust}/user/{u}
POST   /admin/api/v4/provisioning/member/{cust}/user/{u}
PATCH  /admin/api/v4/provisioning/member/{cust}/user/{u}
DELETE /admin/api/v4/provisioning/member/{cust}/user/{u}
```

`GET /member/{cust}/user` lists the member's users, ordered by username, each with its privilege
on and link to this member — the list API5 reconciles against. `GET /member/{cust}/user/{u}` reads
one; it is `404` when the user exists but is not linked to the member.

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
rest.

## Validation (users and links)

`PATCH /user/{u}` extends the web edit request (`User\Update`) and adds `enabled`, as creation
does. `DELETE /user/{u}` extends the web delete request unchanged. The web request for linking a
user (`User\CustomerToUser\Store`) is `final`, so the API delegates to it rather than extending it:
its rules are evaluated against the API request, with `privs` declared as an override. The web
validates privilege changes inline in the controller, so the API reproduces those checks and
`UserRulesParityTest` sends the same values to the web route and to the API and compares what each
refuses. The same test compares the rules of the other three with the web's, as `RulesParityTest`
does for members and connections.

A body or query key named like a route parameter (`u`, `cust`) is removed before validation: the
inherited rules read the route's user as `$this->u`, which Laravel resolves from the input first.

## Notes for the merge

- *Onboarding* says "no endpoint lists a member's users, so the replay is how it learns those
  ids". `GET /member/{cust}/user` now does.
- *Conventions* says there is no way to change anything; `PATCH` exists (members, users, links).
- `POST /member/{cust}/user` still answers with `MemberController::userAsArray()`. Switching it to
  `UserPresenter::forMember( $user, $c2u )` keeps every field it has today (`id`, `name`,
  `username`, `email`, `custid`, `privs`, `enabled`) and adds the rest of the member's view; the
  OpenAPI `User` schema can then give way to `MemberUser`.
