# Security policy

Numra for WooCommerce runs inside `wp-admin` with administrator privileges and
holds an API key that reads a shared fraud ledger and spends the merchant's
paid quota. A weakness in it is worth something to the people that ledger
exists to describe, so please treat one accordingly.

## Supported versions

| Version | Supported |
|---|---|
| 1.18.x | Yes |
| 1.17.x and earlier | No |

Only the current release line receives security fixes. Distribution is Numra's
own update channel rather than wordpress.org, so a fix reaches a store as an
update offer it still has to accept — which is why disclosure timing matters
more here than it would for a hosted service.

## Reporting a vulnerability

Mail **security@numra.ma** with "WooCommerce plugin" in the subject.

**Do not open a public issue, pull request or discussion for a security
problem.** Every store still running the released version is exposed from the
moment the report is public until the moment they take the update, and stores
do not take updates the same afternoon.

Useful to include:

- the plugin version, the WordPress version and the PHP version;
- what an attacker can do, and what they need in order to do it — an
  unauthenticated visitor, a subscriber, an editor, or an administrator;
- the smallest reproduction you can manage.

Please do not send a live API key, a webhook secret, or a real customer's
phone number. A rotated test key and an invented number make the same point.

## What to expect

- An acknowledgement within three working days.
- An assessment, and either a fix or an explanation of why we do not consider
  it one, within ten working days.
- A fixed release, an entry in [CHANGELOG.md](CHANGELOG.md), and a published
  advisory once stores have had a reasonable window to update.
- Credit under whatever name you want, or none.

We will ask you to hold public disclosure until the fixed release has been
offered to stores. We will not ask you to hold it indefinitely.

## In scope

Anything that exposes the stored API key: in page source, in an AJAX response,
in a log line, in an error message, or through an option that autoloads where
it should not. Anything reachable without the capability it should require —
the AJAX handlers, the connect callback, the settings save. Anything that
defeats the connect flow's `state` check, or lets a crafted callback URL
persist a connection. Any stored or reflected cross-site scripting in the
admin screens, and any missing or forgeable nonce on an action that changes
state.

## Out of scope

Anything that requires an administrator account to already be compromised — an
administrator can install arbitrary plugins, so that is not an escalation.
Findings against WordPress core, WooCommerce, or another plugin, unless this
plugin is what makes them exploitable. Missing hardening headers on the
surrounding site. Reports produced by a scanner with no reproduction.

If the finding is in the Numra API rather than in this plugin, mail the same
address and say so.
