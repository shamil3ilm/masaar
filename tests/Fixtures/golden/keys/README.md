# Golden-master test keys

Self-signed secp256k1 keypairs used only by the Saudi golden-master tests, so
that every run signs with the same certificate and the snapshots stay stable.

They identify nobody, were never registered with ZATCA, and are excluded from
the container image with the rest of `tests/`. Never use them outside tests.
