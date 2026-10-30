# Test-only actor keys

These eight 4096-bit RSA private keys are public test fixtures. They are
committed to the repository, so anyone can read them. Never use them for a
real user, magazine or instance, and never copy them into any configuration.

`tests/WebTestCase.php` hands them to `KeysGenerator::useKeyPool()` so that
functional tests do not spend about a quarter of a second generating a fresh
key for every local user and magazine. `KeysGenerator` refuses the pool unless
`APP_ENV` is `test`.

They were generated with:

```
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out actor_key_N.pem
```

The repository ignores `*.pem`, so these files are tracked with `git add -f`.
