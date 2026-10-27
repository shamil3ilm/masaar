# Contributing

Audience: anyone sending a change to this repository.

By contributing you agree to the [LICENSE](LICENSE) and the
[Terms of Use](TERMS.md). Report a security issue privately as
[SECURITY.md](SECURITY.md) describes, never in a public issue. Never put real
VAT numbers, certificates, keys or customer data in an issue, a test or a
commit.

## Before you start

- Set up a local copy: [docs/developers/SETUP.md](docs/developers/SETUP.md).
- Find your way around: [docs/developers/CODEBASE-TOUR.md](docs/developers/CODEBASE-TOUR.md).
- Read the rules the tests enforce: [docs/developers/CONVENTIONS.md](docs/developers/CONVENTIONS.md).
- Common changes step by step: [docs/developers/HOW-TO.md](docs/developers/HOW-TO.md).

## Making a change

1. Branch from `main`: `feat/<topic>`, `fix/<topic>` or `docs/<topic>`.
2. Write the failing test first, then the change.
3. Keep the change to what it is for; country behaviour goes behind the
   compliance engine's ports, never into shared code.
4. Run `vendor/bin/pint` on the files you changed.

## Gates

Everything below must pass before review
([docs/developers/TESTING-AND-GATES.md](docs/developers/TESTING-AND-GATES.md)
says how to run each):

- the full suite on SQLite and on MySQL, including `tests/Feature/Architecture`;
- the golden masters, unchanged (never regenerate them without the
  maintainer's approval);
- the ZATCA, PINT AE and PDF/A conformance suites when you touch issuing,
  documents or PDFs;
- `docs/openapi.yaml` and `docs/schema.sql` regenerated when routes or
  migrations change;
- every new user-facing string in Arabic in `lang/ar.json`.

CI runs the same gates on every push; a change to the ZATCA filing path should
carry `[sandbox]` in a commit message to run the sandbox round trip.

## Commits

Conventional commits: `<type>(<scope>): <what changed>`, for example
`fix(invoice): name buyer fields in plain words in the form's errors`.
Types: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`.

## Review

Open a pull request against `main` describing what changed, why, and how it
was tested. A maintainer reviews every change; changes to signing, hashing,
filing or tenant isolation also get a compliance and security read. Answer
review comments in the thread and push follow-up commits rather than
rewriting history.

Questions: shamil3ilm@gmail.com.
