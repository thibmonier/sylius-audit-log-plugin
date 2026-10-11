# Functional specification — admin audit log

"Who changed what, when" in the Sylius 2 back office: a global, technical trail of the changes
made to the main resources, with field-level diffs. Research date: 2026-10-11.

## 1. Existing solutions

| Package | Status | Why not |
|---|---|---|
| `acseo/sylius-admin-tracker-plugin` | v1 (2024-08), MIT, `sylius/sylius: *` | Sylius 1 code: `@SyliusAdmin\Crud` templates, Semantic UI, `Doctrine\Common\EventSubscriber` (removed with DoctrineBundle 3); `postUpdate` listener that only logs admins and a hard-coded list of short class names. [GitHub](https://github.com/acseo/SyliusAdminTracker) |
| `dreamcommerce/object-audit-bundle` | 1.2.4 (2022), **LGPL-2.1** | `sylius/resource-bundle ^1.6`, PHP 7. [Packagist](https://packagist.org/packages/dreamcommerce/object-audit-bundle) |
| `brille24/sylius-order-log-plugin` | 0.6.1 (2023), MIT | `sylius/sylius ^1.11`, orders only. [Packagist](https://packagist.org/packages/brille24/sylius-order-log-plugin) |
| `monsieurbiz/sylius-order-history-plugin` | 1.1.1 (2025), MIT | `sylius/sylius >=1.12 <2.0`, order timeline. [Packagist](https://packagist.org/packages/monsieurbiz/sylius-order-history-plugin) |
| `damienharper/auditor-bundle` (ex DataDog AuditBundle) | 7.2.1 (2026-03), MIT | See below. [Packagist](https://packagist.org/packages/damienharper/auditor-bundle) |

No audit plugin supports Sylius 2. Sylius 2 itself only keeps an *address log* of orders
(`sylius_address_log_entry`, the order *History* page).

### auditor-bundle

MIT and well maintained, but:

- the current line (7.x, auditor 4.x) requires **PHP ≥ 8.4 and Symfony 8 only**
  (`symfony/*: ^8.0`); the line still supporting PHP 8.2 / Symfony 6.4–7 (6.3, auditor 3.4)
  is the previous major. Supporting Sylius 2.2 + 2.3 on Symfony 6.4/7.4/8.0 would force a
  version split of this plugin or pin users to a legacy major;
- it writes **one audit table per audited entity** (`<table>_audit`): a global grid
  ("everything administrator X did yesterday") needs a UNION over dozens of tables, and every
  newly audited entity needs a schema change;
- its viewer is a separate Bootstrap UI, not a Sylius grid; translations of Sylius
  resources (product names live in `ProductTranslation`) are audited as separate entities,
  and there is no notion of owner (variant → product) for history cards.

Decision: **own lightweight listener**, one table, built for Sylius (resources, translations,
owners, grid, Twig hooks).

## 2. Design

### 2.1 Capture

Doctrine ORM events ([reference](https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/events.html)):

- `onFlush`: scheduled insertions, updates, deletions, collection updates and deletions are
  read. Old values are known only here (`getEntityChangeSet`, `getOriginalEntityData`);
  deleted entities still have their id.
- `postFlush`: inserted entities have their ids; entries are written with **DBAL inserts**
  built from the entry's ORM mapping (custom entry classes supported). No `persist()` /
  `flush()`: no recursion, no interference with the caller's unit of work.

`postFlush` runs after the commit of the flush (when the flush opened the transaction) or
inside the caller's transaction (when there was one, e.g. `wrapInTransaction`): the inserts
run in a savepoint so that a failing insert cannot abort the caller's transaction
(PostgreSQL). The alternative — writing inside the flush transaction — needs either
`persist` + `computeChangeSet` in `onFlush` (impossible for the ids of inserted entities) or
a DBAL middleware hooking `commit()` (fragile across DBAL 3/4). Accepted trade-off: if PHP
dies between the commit and the audit insert, that entry is lost.

Each `onFlush` pushes its batch on a stack popped by its `postFlush`: a flush nested in
another `postFlush` listener keeps its own changes, and a failed flush (no `postFlush`) never
leaks into the next one. Errors are logged and swallowed.

### 2.2 What is an entry

One entry per audited entity per flush: date (UTC), action (create / update / delete),
resource type (configuration key), class, id, **label snapshot**, **owner** (aggregate root:
the product of a variant or of a channel price, the order of a payment), field-level changes
(JSON), actor (type, id, name snapshot), source, IP (optional), request id, route or command.
No foreign key: the trail outlives the resources and the administrators.

Changes:

- scalar: `{"old": x, "new": y}`; dates in ISO 8601; arrays (JSON columns) JSON-encoded;
  enums by value; texts truncated to `max_value_length` (500) with "…";
- references (to-one): `{"id": "3", "label": "Fashion Web Store (FASHION_WEB)"}`;
- many-to-many: `{"added": [...], "removed": [...]}` (a replaced collection is diffed);
- masked: `{"masked": true}`;
- translations: folded into the translated resource as `name (fr_FR)`;
- deletes: the old values of every field; creates: the new values; no-op updates (same
  number with another type, as decimals hydrated as strings) are dropped; an update with no
  remaining change writes nothing.

### 2.3 Actor and source

Actor from the security token: `AdminUserInterface` → *admin* (id, username),
`ShopUserInterface` → *customer* (customer id, e-mail); without user: *cli* when there is no
HTTP request, *system* otherwise. Source from the Sylius section resolver (admin, admin API,
shop, shop API), `cli` without request. Request id: `X-Request-Id` header when valid, random
otherwise; one per request or console command.

Recorded by default: admin, admin API and command line. The shop changes carts, stock
(`onHold`) and customers all the time; recording it is opt-in (`sources`). Fixture loading is
an ignored command.

### 2.4 Default resources

Products (+ variants, channel prices), taxons, promotions (+ coupons, rules, actions), catalog
promotions (+ scopes, actions), shipping and payment methods, tax rates, channels,
administrators, customers, addresses, orders (+ payments, shipments). Stock is audited through
`ProductVariant::onHand`. Ignored noise: `createdAt`, `updatedAt`, `lastLogin` (every admin
login), canonical fields, `version`, the nested-set fields of taxons, the computed
`lowestPriceBeforeDiscount` / `appliedPromotions` of channel prices.

### 2.5 Back office

- *Configuration › Audit log*: Sylius grid (25/50/100 per page), filters: date range,
  resource (type + id, an id also matches the entries owned by the resource), action, actor
  name, actor type, request id. Read-only: no create / edit / delete route.
- Entry page: details (date in local time and UTC, resource with link to its admin page,
  owner, actor, source, route, IP, link to the other changes of the same request) and a diff
  table (masked fields, added / removed badges).
- History card (`history_limit` entries, "See all (n)" → filtered grid) on the product show
  and edit pages, order and customer show pages, promotion edit page.
- Access: the admin firewall (`ROLE_ADMINISTRATION_ACCESS`), like every Sylius admin page.

## 3. GDPR

The audit trail is a processing of personal data (administrators' names, customers' data in
diffs). Measures:

- **Minimisation** (art. 5(1)(c)): secrets are never stored (masked fields and patterns:
  passwords, salts, tokens, payment `details`, gateway `config`); IP addresses are off by
  default and anonymised (last byte / 80 bits zeroed, Symfony `IpUtils::anonymize`) when
  enabled; long texts truncated.
- **Storage limitation** (art. 5(1)(e)): `retention_days` (365 by default) and
  `tmonier:audit-log:purge` (batched deletes, `--dry-run`) to schedule. The CNIL recommends
  keeping traceability logs **between six months and one year**
  ([CNIL, recommandation journalisation](https://www.cnil.fr/fr/la-cnil-publie-une-recommandation-relative-aux-mesures-de-journalisation)).
- **Right to erasure** (art. 17): `tmonier:audit-log:anonymize-customer` (by e-mail, or by id
  for a deleted customer). Entries about the customer, its addresses, its orders and its own
  shop changes: personal resources lose all values and their label, others lose the values of
  `personal_fields`, and the e-mail is removed from free texts; IP addresses are dropped.
  Who changed what and when stays (legitimate interest: security, accountability).
- **Security** (art. 32): read access is limited to administrators; entries cannot be edited
  from the admin.

## 4. Performance

- The policy (enabled, source, ignored command) is checked before reading the unit of work.
- Class → resource matching is cached per class; ignored and masked fields are hash lookups.
- Labels are resolved only for changed entities and changed references.
- One INSERT per entry, in the same connection, after the flush; no extra SELECT.
- Indexes: `created_at`, `(owner_type, owner_id, created_at)` for the history cards,
  `(resource_type, resource_id)`, `(actor_type, actor_id)`, `request_id`.
- Bulk jobs (imports) write one entry per entity: exclude their command (`ignored_commands`)
  or skip entries in a `pre_write` listener when they are not wanted.

## 5. Extension points

- Configuration: any Doctrine class or interface as a resource, with an owner property path
  and its own ignored fields.
- `LabelResolverInterface` and `OwnerResolverInterface` (autoconfigured tags, priorities).
- `AuditLogEntryEvent::PRE_WRITE`: enrich, rewrite or skip an entry.
- Replaceable entry model (Sylius resource `tmonier_audit_log.audit_log_entry`).

## 6. Out of scope (v1)

Changes made outside the ORM (DBAL, SQL, bulk DQL), read access logging, Sylius Plus RBAC,
restoring an old version, export, an API endpoint for the log.
