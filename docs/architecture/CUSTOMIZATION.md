# Customization

Every customization has a shipped default, and each level below can set its own:

```
shipped default  ->  platform  ->  organization  ->  branch  ->  person
```

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
| `type` | `bool`, `choice` (one of `options`), `int` (`min`, `max`, `step`) or `entries` (a keyed list merged by key down the levels; a level may hide an entry from above) |
| `default` | the shipped value |
| `levels` | which levels may set it, broadest first |
| `lockable` | whether a level may lock its value against the levels below |
| `acrossOrganizations` | a person's value is theirs everywhere (language), not per organization |
| `audit` | `security` (security log), `record` (audit log) or `none` |
| `columns` | a level kept in an existing column (`table.column`) rather than the store |

A key that is not in the catalogue cannot be stored, and the store refuses a
value its definition cannot hold or a level it does not allow.

## Storage

One table, `customizations`: a row per key per holder (`platform`,
`organization:{id}`, `branch:{id}`, `member:{org}:{user}`, `user:{id}`). No live
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

Lists of entries (common charges) and the branch level have screens of their
own (printing) and are left off the generic pages.
