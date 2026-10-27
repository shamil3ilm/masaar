# Backup and restore

The platform backs up two things nightly: the **database** and the **credential store**.

- The database holds every tenant's invoices, hash chains and audit trail. Saudi VAT law requires those records for at least six years, longer for some.
- The credential store holds each device's private key and CSID. Without them no tenant can sign.

The code is not backed up. It lives in git and the image registry.

## What runs

| When (server time) | Command | What it does |
|---|---|---|
| 01:00 | `backup:clean` | Prunes old archives. It keeps every archive for 7 days, then a daily one for 30 days, a weekly one for 12 weeks, a monthly one for 12 months and a yearly one for 6 years. It never prunes to fit a size budget. |
| 01:30 | `backup:run` | Dumps MySQL as a consistent snapshot in one transaction, with no table locks, and gzips it. It zips the dump with `storage/app/private/zatca`, encrypts the zip with AES-256, opens it again to check it is readable, and uploads it to `BACKUP_DISKS`. |
| 06:00 | `backup:monitor` | Raises an alert if the newest archive is more than a day old. That catches a scheduler that stopped, as well as a run that never started. |

Every replica runs the scheduler, so each command is `onOneServer` and takes one lock in the shared cache, database or Redis. You get one archive a night, not one per container.

Failures page through the alerts channel, and through its chat webhook once one is configured. Three things page: a failed run, a stale backup, and a run refused because `BACKUP_ARCHIVE_PASSWORD` is unset. A failed cleanup is logged as an error and does not page.

## Configuration

| Variable | Purpose |
|---|---|
| `BACKUP_ARCHIVE_PASSWORD` | **Required.** It encrypts the archive. Without it the run is refused rather than uploaded in the clear. |
| `BACKUP_DISKS` | Where archives go. Defaults to `backups`; separate several disks with commas. |
| `BACKUP_STORAGE_ENDPOINT`, `BACKUP_STORAGE_REGION`, `BACKUP_STORAGE_BUCKET` | The object store and bucket. |
| `BACKUP_STORAGE_KEY`, `BACKUP_STORAGE_SECRET` | The store's access key and secret. |
| `BACKUP_STORAGE_PATH_STYLE` | Path-style bucket addressing. Defaults to `true`, which most stores expect. |
| `DB_DUMP_SSL_FLAG` | `skip-ssl` in the compose stack. See `config/database.php`. |
| `BACKUP_ALERT_AT_MEGABYTES` | Raises an alert once archives use this much of the bucket. The default is 16384. Nothing is deleted to make room. |

Ideally, give the bucket credentials permission to write and list but **not to delete**, and use the bucket's retention rules. Then a stolen server can't destroy its own backups. If you do that, `backup:clean` can't prune, and the bucket's lifecycle rules have to do the pruning instead. On a bucket with a quota, locked archives can't be pruned and will fill it. Choose that trade deliberately.

## Setting up the object store

Any provider whose object storage speaks the S3 protocol will do. Nothing in the code names one: changing provider means changing the `BACKUP_STORAGE_*` values, not the code.

1. **Create a bucket.** Make it private, in a region that meets your data-residency obligations.
2. **Create an access key and secret** for the S3-compatible API. Many providers show the secret only once, at creation.
3. **Set the environment.** The endpoint, region and bucket come from the provider's console:

   ```dotenv
   BACKUP_STORAGE_ENDPOINT=https://<the provider's S3-compatible endpoint>
   BACKUP_STORAGE_REGION=<region identifier>
   BACKUP_STORAGE_BUCKET=platform-backups
   BACKUP_STORAGE_KEY=<access key>
   BACKUP_STORAGE_SECRET=<secret>
   ```

4. **Confirm it works** before relying on it, inside the app container: `php artisan backup:run`, then `php artisan backup:list`.

The `backups` disk in `config/filesystems.php` sends upload checksums only where an operation requires one. The S3 client library has added them to every upload since 3.337, and many stores refuse them with `501 NotImplemented`.

**Sizing.** Retention keeps up to about 67 archives. One archive's size times 67 should stay under the bucket's quota, and `BACKUP_ALERT_AT_MEGABYTES` should be set below that quota.

## Keys are kept apart, and a restore needs all three

An archive is useless without these, and none of them is inside it:

1. **`BACKUP_ARCHIVE_PASSWORD`** opens the archive.
2. **`APP_KEY`** decrypts the encrypted database columns, such as two-factor secrets.
3. **`ZATCA_CREDENTIAL_KEY`** decrypts the credential files. Include any `ZATCA_CREDENTIAL_PREVIOUS_KEYS` still in force.

Keep all three in the password manager or secret store, not on the server and not in the bucket. Losing the server should cost you nothing that's written down only there.

## Restoring

Rehearse this. CI does it on every push, in the MySQL job's "Back up and restore" step. A restore into a real environment should still be done by hand once a quarter.

1. **Pick the archive.** `php artisan backup:list` shows what the bucket holds. Archives are under `<APP_NAME>/` in the bucket. Download the one you want with the provider's console or any S3-protocol client.

2. **Open it.** `unzip` can't read AES-encrypted zips, so use 7-Zip:

   ```bash
   7z x -p"$BACKUP_ARCHIVE_PASSWORD" -orestore <archive>.zip
   ```

   You get `restore/db-dumps/*.sql.gz` and `restore/zatca/`.

3. **Stop the application so nothing writes while you restore.** Name both
   compose files, as [DEPLOYMENT.md](DEPLOYMENT.md) does:

   ```bash
   alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
   dc stop app
   ```

4. **Restore the database** into an empty one, named as `DB_DATABASE`
   (`platform` in the template):

   ```bash
   dc exec -T db mysql -u root -p"$DB_ROOT_PASSWORD" -e 'DROP DATABASE platform; CREATE DATABASE platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
   gunzip -c restore/db-dumps/*.sql.gz | dc exec -T db mysql -u root -p"$DB_ROOT_PASSWORD" platform
   ```

5. **Restore the credential store.** It goes to the same place on the same volume:

   ```bash
   dc cp restore/zatca app:/var/www/html/storage/app/private/
   ```

6. **Start with the original keys.** Before the app starts, `APP_KEY` and `ZATCA_CREDENTIAL_KEY` in `.env` must be the ones in force when the archive was taken.

   ```bash
   dc start app
   ```

7. **Verify.**

   ```bash
   dc exec app php artisan fatoora:verify-hash-chain
   dc exec app php artisan fatoora:check-certificate
   curl -fsS https://$APP_DOMAIN/api/health/ready
   ```

   `verify-hash-chain` must pass for every device. A broken chain means the dump is from inside a write, or the wrong archive was restored. `check-certificate` decrypts each tenant's certificate, which proves the credential key matches.

### What a restore loses

Everything written after the archive was taken, which can be up to a day.

- **Invoices issued in that window** are gone from the platform, but ZATCA has the cleared and reported ones. Their ICVs and hashes are no longer in the chain, so the next invoice on each device would chain to a stale predecessor.
- **Before reopening:** reconcile against the ERP's records and ZATCA's. Re-issue anything missing in its original order before new invoices are taken. Don't reopen filing until `fatoora:verify-hash-chain` passes and the last ICV per device matches what ZATCA last accepted.

If losing a day is too much, the database needs point-in-time recovery: binary logs shipped offsite, or a managed database with PITR. This nightly archive is the floor, not the ceiling.
