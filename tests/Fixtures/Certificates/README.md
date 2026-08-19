# Certificate fixtures

A throwaway CA, two certificates it issued, and a CRL revoking one of them.

| File | Serial | In the CRL |
|---|---|---|
| `revoked.pem` | `1000` | yes |
| `good.pem` | `1001` | no |

`crl.pem` and `crl.der` are the same list in the two encodings a distribution
point may serve, so the parser is exercised against both.

Generated once with OpenSSL and committed. Nothing here is secret and no
private keys are included — the CA key stayed outside the repository, so these
cannot be used to issue anything.

Regenerating them changes the serials, and the tests assert on those.
