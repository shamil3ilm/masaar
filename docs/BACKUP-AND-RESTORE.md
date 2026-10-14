# Backup and restore

Masaar backs up two things nightly: the **database** and the **credential store**.

- The database holds every tenant's invoices, hash chains and audit trail. Saudi VAT law requires those records for six years.
- The credential store holds each device's private key and CSID. Without them no tenant can sign.

The code is not backed up. It lives in git and the image registry.

## What runs

| When (server time) | Command | What it does |
|---|---|---|
| 01:00 | `backup:clean` | Prunes old archives. It keeps every archive for 7 days, then a daily one for 30 days, a weekly one for 12 weeks, a monthly one for 12 months and a yearly one for 6 years. It never prunes to fit a size budget. |
| 01:30 | `backup:run` | Dumps MySQL as a consistent snapshot in one transaction, with no table locks, and gzips it. It zips the dump with `storage/app/private/zatca`, encrypts the zip with AES-256, opens it again to check it is readable, and uploads it to `BACKUP_DISKS`. |
| 06:00 | `backup:monitor` | Raises an alert if the newest archive is more than a day old. That catches a scheduler that stopped, as well as a run that never started. |

Every replica runs the scheduler, so each command is `onOneServer` and takes one shared Redis lock. You get one archive a night, not one per container.

Failures page through the alerts channel, and through Slack once `LOG_SLACK_WEBHOOK_URL` is set. Three things page: a failed run, a stale backup, and a run refused because `BACKUP_ARCHIVE_PASSWORD` is unset. A failed cleanup is logged as an error and does not page.

## Configuration

| Variable | Purpose |
|---|---|
| `BACKUP_ARCHIVE_PASSWORD` | **Required.** It encrypts the archive. Without it the run is refused rather than uploaded in the clear. |
| `BACKUP_DISKS` | Where archives go. Defaults to `s3`; separate several with commas. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | The bucket. |
| `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | Only for S3-compatible stores that aren't AWS, such as OCI Object Storage, Cloudflare R2 or MinIO. |
| `DB_DUMP_SSL_FLAG` | `skip-ssl` in the compose stack. See `config/database.php`. |
| `BACKUP_ALERT_AT_MEGABYTES` | Raises an alert once archives use this much of the bucket. The default is 16384. Nothing is deleted to make room. |

Ideally, give the bucket credentials permission to write and list but **not to delete**, and use the bucket's retention rules. Then a stolen server can't destroy its own backups. If you do that, `backup:clean` can't prune, and the bucket's lifecycle rules have to do the pruning instead. On a quota-limited bucket, such as OCI's free tier below, locked archives can't be pruned and will fill it. Choose that trade deliberately.

## On Oracle Cloud (OCI Object Storage)

OCI Object Storage speaks the S3 API, so the `s3` disk works unchanged.

1. **Create a bucket.** Make it private, Standard tier, in your home region. For Saudi data residency that's Jeddah or Riyadh.
2. **Get S3 credentials.** Go to *Identity → your user → Customer Secret Keys → Generate*. The access key goes in `AWS_ACCESS_KEY_ID` and the secret in `AWS_SECRET_ACCESS_KEY`. The secret is shown once, at creation.
3. **Point the disk at OCI:**

   ```dotenv
   AWS_DEFAULT_REGION=me-jeddah-1          # your region identifier
   AWS_BUCKET=masaar-backups
   AWS_ENDPOINT=https://<namespace>.compat.objectstorage.me-jeddah-1.oraclecloud.com
   AWS_USE_PATH_STYLE_ENDPOINT=true
   ```

   The namespace is on the bucket's details page.

The checksum change in `config/filesystems.php` is what makes uploads work. Since 3.337 the AWS PHP SDK attaches checksums to every upload, and OCI rejects them with `501 NotImplemented`.

The Always Free tier gives 20 GB of Object Storage and 50,000 API requests a month. The nightly backup uses a handful of requests. Storage is the limit to watch: retention keeps up to about 67 archives, so `BACKUP_ALERT_AT_MEGABYTES` (16 GB by default) is what tells you the bucket is filling. Size it from the first archives: one archive's size times about 67 should stay under the quota.

## Keys are kept apart, and a restore needs all three

An archive is useless without these, and none of them is inside it:

1. **`BACKUP_ARCHIVE_PASSWORD`** opens the archive.
2. **`APP_KEY`** decrypts the encrypted database columns, such as two-factor secrets.
3. **`ZATCA_CREDENTIAL_KEY`** decrypts the credential files. Include any `ZATCA_CREDENTIAL_PREVIOUS_KEYS` still in force.

Keep all three in the password manager or secret store, not on the server and not in the bucket. Losing the server should cost you nothing that's written down only there.

## Restoring

Rehearse this. CI does it on every push, in the MySQL job's "Back up and restore" step. A restore into a real environment should still be done by hand once a quarter.

1. **Pick the archive.**

   ```bash
   aws s3 ls s3://$AWS_BUCKET/Masaar/ --endpoint-url "$AWS_ENDPOINT"
   aws s3 cp s3://$AWS_BUCKET/Masaar/<archive>.zip . --endpoint-url "$AWS_ENDPOINT"
   ```

2. **Open it.** `unzip` can't read AES-encrypted zips, so use 7-Zip:

   ```bash
   7z x -p"$BACKUP_ARCHIVE_PASSWORD" -orestore <archive>.zip
   ```

   You get `restore/db-dumps/*.sql.gz` and `restore/zatca/`.

3. **Stop the application so nothing writes while you restore.**

   ```bash
   docker compose stop app
   ```

4. **Restore the database.** Restore into an empty database:

   ```bash
   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASSWORD" -e 'DROP DATABASE masaar; CREATE DATABASE masaar'
   gunzip -c restore/db-dumps/*.sql.gz | docker compose exec -T db mysql -u root -p"$DB_ROOT_PASSWORD" masaar
   ```

5. **Restore the credential store.** It goes to the same place on the same volume:

   ```bash
   docker compose cp restore/zatca app:/var/www/html/storage/app/private/
   ```

6. **Start with the original keys.** Before the app starts, `APP_KEY` and `ZATCA_CREDENTIAL_KEY` in `.env` must be the ones in force when the archive was taken.

   ```bash
   docker compose start app
   ```

7. **Verify.**

   ```bash
   docker compose exec app php artisan fatoora:verify-hash-chain
   docker compose exec app php artisan fatoora:check-certificate
   curl -fsS https://$APP_DOMAIN/api/health/ready
   ```

   `verify-hash-chain` must pass for every device. A broken chain means the dump is from inside a write, or the wrong archive was restored. `check-certificate` decrypts each tenant's certificate, which proves the credential key matches.

### What a restore loses

Everything written after the archive was taken, which can be up to a day.

- **Invoices issued in that window** are gone from Masaar, but ZATCA has the cleared and reported ones. Their ICVs and hashes are no longer in the chain, so the next invoice on each device would chain to a stale predecessor.
- **Before reopening:** reconcile against the ERP's records and ZATCA's. Re-issue anything missing in its original order before new invoices are taken. Don't reopen filing until `fatoora:verify-hash-chain` passes and the last ICV per device matches what ZATCA last accepted.

If losing a day is too much, the database needs point-in-time recovery: binary logs shipped offsite, or a managed database with PITR. This nightly archive is the floor, not the ceiling.
