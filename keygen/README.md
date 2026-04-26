# CapstoneNMS keygen

Standalone CLI for minting Ed25519-signed license tokens. Lives outside the Laravel app on purpose: this binary needs the **private** signing key, the runtime never does.

## Setup (one-time, by the licensor)

```bash
cd keygen
composer install
./bin/keygen bootstrap
```

`bootstrap` writes the private key to `keys/private.key` (chmod 600) and prints the matching public key. Paste the public key into `source/config/capstone.php` (`license_public_key_b64`) and commit. Never commit `keys/private.key`.

## Minting a license (every customer order)

```bash
./bin/keygen mint \
    --tier=pro \
    --customer="Acme Corp" \
    --customer-email=billing@acme.example \
    --domains="acme.example,*.acme.example" \
    --expires=2027-04-26 \
    --output=./out/acme-pro
```

Produces:
- `out/acme-pro.dat` — the file the customer uploads in Site Settings → License
- `out/acme-pro.txt` — the same payload as a paste-blob (one long line, customers who'd rather copy/paste than upload a file)

`--tier` carries the cap defaults from `source/config/capstone.php`. Pass `--admins=N --editors=N --authors=N --agents=N` to override individual caps for a one-off enterprise deal.

## Verifying a minted file

```bash
./bin/keygen verify ./out/acme-pro.dat
```

Reads the file, verifies the signature, and prints the parsed payload — useful for sanity-checking before emailing the file to the customer.

## Phase status

This directory is a Phase A skeleton. The actual subcommands ship with Phase B.
