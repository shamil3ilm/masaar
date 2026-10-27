# Customization

Audience: engineers adding a setting or preference, and administrators wondering where a value comes from.

Every customization has a shipped default, and each level below can set its own:

```
shipped default  ->  platform  ->  (business type)  ->  organization  ->  branch  ->  till  ->  person
```

The business type step applies only to a definition with a `preset`: the
organization's business type suggests a value between the platform's and the
organization's own, so the type suggests and the organization decides.
<!-- app/Domains/Platform/Customization/Definition.php ($preset); Catalogue::BUSINESS_TYPES -->

The nearest level that sets a value wins, unless a level above locked its own
(`lockable` definitions only, such as requiring two-factor sign-in). Code asks
`Customizations::resolve($key, $scope)` and gets one `Resolved` answer: the
value, the level it came from (`source`, null for the shipped default), what the
asked level sets itself (`own`), any lock above (`lockedBy`) and what every
level sets (`levels`).

## Definitions

`App\Domains\Platform\Customization\Catalogue` is the one list. Each
`Definition` declares:

| Field | Meaning |
|---|---|
| `type` | `bool`, `choice` (one of `options`), `int` (`min`, `max`, `step`), `entries` (a keyed list merged by key down the levels; a level may hide an entry from above), `id` (one record's id, checked by the screen that sets it), `record` (named `fields`, merged field by field), `days` (whole numbers, each once, in order, such as reminder days) or `decimal` (an amount kept as a two-place string so no float rounds it) |
| `default` | the shipped value |
| `levels` | which levels may set it, broadest first |
| `lockable` | whether a level may lock its value against the levels below |
| `acrossOrganizations` | a person's value is theirs everywhere (language), not per organization |
| `audit` | `security` (security log), `record` (audit log) or `none` |
| `columns` | a level kept in an existing column (`table.column`) rather than the store |
| `country` | only some countries use it: an organization sees it only where its module's `Presentation::usesCustomization()` says so (`printing.language_order`) |
| `preset` | the field of the organization's business type that defaults it |
| `console` | a person's preference that belongs to the admin console, left off the portal's preferences |
| `ownScreen` | set on a screen of its own (such as the branch switcher), left off the generic pages |

A key that is not in the catalogue cannot be stored, and the store refuses a
value its definition cannot hold or a level it does not allow.

## Storage

One table, `customizations`: a row per key per holder (`platform`,
`organization:{id}`, `branch:{id}`, `till:{id}`, `member:{org}:{user}`, `user:{id}`). No live
row means "as the level above": resetting a value soft-deletes its row with
who reset it, and setting it again brings the same row back. Each row records
who made it and who last changed it. Values are
JSON in a text column, because MySQL reorders the keys of a JSON column.

A definition may keep one level in a column that issuing paths already read:
the organization's `prices_include_vat` and `require_two_factor`, the person's
`locale`. Null in such a column means "as the level above"; the model's
attribute reads the effective value through the resolver.

Each holder's rows are cached as one entry, forgotten on every write and again
after the transaction commits. Column-kept levels are read from their row each
time, because other code writes those columns too.

## Screens

`<x-customization>` draws one customization the same way on every page: what is
in effect, where it comes from, the control for this level's own value and
"Reset to inherited" when the level has one. `CustomizationForm` handles what
every such page posts, accepting only keys the page's level shows.

| Page | Level |
|---|---|
| Admin › Settings › Defaults for everyone | platform (`platform.configure`) |
| Portal › Settings | organization (its administrators) |
| Portal › Preferences | the person (everyone, a viewer too) |

Lists of entries (common charges) and the branch and till levels have screens
of their own (printing, on the branch page) and are left off the generic pages.
A till is one of the organization's EGS units; only the printing
customizations go down to it.
