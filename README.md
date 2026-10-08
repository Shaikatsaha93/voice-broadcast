# Voice Broadcast Platform (V1)

Laravel 13 · PHP 8.3+ · MariaDB/MySQL · Redis · Livewire + Blade · Tailwind · Asterisk (AMI/ARI, PJSIP)

Three roles (Super Admin / Admin / User) + DIDs with **SIP trunk registration from the portal** + **DID balance billed per pulse** + **DID concurrency** + campaigns + number lists + audio + approval + Asterisk calling + retry (all / per result) + call status + reporting. Nothing else (no IVR, SMS, tenants...).

## Requirements
PHP 8.3+ (`pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `redis` or Predis), Composer, Node 20+, MariaDB 10.6+/MySQL 8, Redis 6+, FFmpeg/ffprobe, Asterisk 18+ (PJSIP).

## Installation
```bash
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build
php artisan migrate --seed          # roles + permissions
php artisan app:create-admin admin@example.com   # first Super Admin, prompts for password (min 12 chars)
```

## Environment (`.env`)
| Key | Meaning |
|---|---|
| `DB_*` | MariaDB/MySQL connection |
| `REDIS_CLIENT/HOST`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis` | production values |
| `ASTERISK_HOST`, `ASTERISK_AMI_PORT/USERNAME/PASSWORD` | AMI (originate + events) |
| `ASTERISK_ARI_URL/USERNAME/PASSWORD` | ARI (reconciliation + forced hangup only) |
| `ASTERISK_TRUNK` | fallback dial string (`PJSIP/{number}@trunk`) for a DID without its own SIP trunk. A DID with SIP details dials through its own `sip-<id>` endpoint |
| `ASTERISK_CONTEXT` | dialplan context run on answer |
| `ASTERISK_SOUNDS_DIR` | folder owned by the `asterisk` user (e.g. `/var/lib/asterisk/sounds/broadcast`) where the normalized audio is copied for playback |
| `ASTERISK_PJSIP_FILE` / `ASTERISK_PJSIP_TRANSPORT` | file the portal writes SIP trunks to (`/etc/asterisk/pjsip_broadcast.conf`) and the PJSIP transport name (`transport-udp`) |
| `APP_TIMEZONE` | application time zone, shown times and `scheduled_at` use it (e.g. `Asia/Dhaka`) |
| `BROADCAST_CURRENCY` | symbol shown before every balance / rate / cost (default `৳`) |
| `BROADCAST_RETRY_DELAY` | default seconds between automatic retries (300) |
| `ASTERISK_DRY_RUN` | `true` = never touch Asterisk (local dev) |
| `DID_SHARED_ASSIGNMENT` | `false` (default) = one user per DID; `true` = a DID may belong to many users |
| `BROADCAST_SLOT_TTL` | seconds before an un-refreshed DID slot is considered stale (180) |
| `BROADCAST_COUNTRY_PREFIX` | prefix for numbers starting with `0` (default `880`) |
| `FFMPEG_BINARY`, `FFPROBE_BINARY` | binary paths |

No Asterisk credential is hardcoded; everything comes from `.env`.

## Roles
| Role | Can do |
|---|---|
| **Super Admin** | everything: create Admins and Users, register any DID, approve any campaign, run campaigns, see all activity and the **Audit** log (Super Admin only) |
| **Admin** | create **normal users only**; register DIDs; approve campaigns of their own users; run their own campaigns; see only their own users' activity, campaigns, audio, reports and DIDs. Two admins never see each other's data. No Audit access |
| **User** | create and run own campaigns on the DIDs assigned to them, upload audio, see own reports and DID balance |

Admins and Super Admins can do everything a User can (create, edit, submit, start, pause, resume, cancel, delete and retry campaigns) for their own campaigns and, for an Admin, their users' campaigns. Only Admin / Super Admin see the **Delete** button for audio. Ownership is stored in `users.created_by` and `dids.created_by`.

## DIDs, SIP trunk and balance
* **Register a DID** (Admin / Super Admin) with the number, max concurrent calls, SIP host / port / username / password, and an opening balance. Saving writes the trunk to `ASTERISK_PJSIP_FILE` (auth, aor, endpoint, identify, registration named `sip-<id>`), reloads PJSIP through AMI and the page shows the registration status (refreshed every minute by `dids:sip-status`). The SIP password is stored encrypted.
* **Rate**: "charge ৳ X for every N sec" (a *pulse*). Fields: `rate_per_pulse`, `pulse_seconds`.
* **Balance check**: before every call `DidSlotManager::acquire` requires `balance >= rate × (calls in flight + 1)`. Not enough balance → no call, the campaign gets `blocked_reason = INSUFFICIENT_BALANCE` and continues when balance is added.
* **Charging**: when a call finishes, `DidBilling` charges `max(1, ceil(billsec / pulse_seconds))` pulses (answered calls only) and writes a `did_transactions` ledger row. Balance can be added / removed on the DID page; the cost is shown per call in the report and the balance on the user's dashboard.

## Database / Redis / Queue / FFmpeg
* Create DB: `CREATE DATABASE broadcast CHARACTER SET utf8mb4;` then `php artisan migrate --seed`.
* Redis: used for the distributed DID lock, cache, queue and sessions. If Redis is down the DB row lock still guarantees DID capacity.
* Queue: `php artisan queue:work --queue=default --tries=3 --max-time=3600` (several workers are safe).
* FFmpeg: `sudo apt install ffmpeg`. Uploaded WAV/MP3 is probed (integrity) and normalized to 8 kHz mono PCM WAV before status `READY`.

## Running
```bash
php artisan serve                 # dev only
php artisan queue:work            # workers
php artisan schedule:work         # scheduler (prod: cron `* * * * * php artisan schedule:run`)
php artisan asterisk:listen       # AMI event listener (long running)
php artisan test                  # tests (uses DB `broadcast_test`)
```

## Asterisk configuration
1. `manager.conf`: an AMI user with `read = call,system,user` and `write = originate,call`. `ari.conf`/`http.conf` for ARI (read channels / hangup).
2. Originate uses `ChannelId = call_ref` so **Asterisk Uniqueid == our `call_attempts.call_ref`**; every AMI event is matched to its attempt with that id. Caller ID is always `DID number` of the campaign (never user input).
3. Dialplan on answer (context `broadcast`), plays the audio and emits the playback events:
```
[broadcast]
exten => s,1,NoOp(${CALL_REF})
 same => n,Answer()
 same => n,UserEvent(PlaybackStart,Uniqueid: ${UNIQUEID})
 same => n,Playback(${AUDIO_FILE})
 same => n,UserEvent(PlaybackComplete,Uniqueid: ${UNIQUEID})
 same => n,Hangup()
```
`AUDIO_FILE` is the **absolute** path of the normalized WAV **without extension**. Asterisk runs with `-U asterisk -G asterisk` and cannot read the web user's private storage, so `AsteriskAudio` copies the audio to `ASTERISK_SOUNDS_DIR` (create it as `mkdir -p /var/lib/asterisk/sounds/broadcast && chown asterisk:www-data … && chmod 2775 …`). Add `#include pjsip_broadcast.conf` to `/etc/asterisk/pjsip.conf` so the portal-managed SIP trunks are loaded, and make the file writable by the web user.

## Business rules and decisions
* **DID assignment**: many-to-many table `did_user`. With `DID_SHARED_ASSIGNMENT=false` (default) assigning a DID that already has a user is rejected (enforced under a row lock).
* **Approval does not dial.** Flow: user creates DRAFT → imports numbers → submits (`PENDING_APPROVAL`) → Super Admin, or the Admin of that user, approves (`APPROVED`) → the owner/admin presses **Start** (`RUNNING`, or `QUEUED` when `scheduled_at` is in the future; the scheduler starts it when due). Nothing calls automatically after creation or approval.
* **Retry convention**: `max_attempts` = maximum *total* attempts (3 → attempts 1,2,3). Automatically retried: NO_ANSWER, BUSY, TEMPORARY_FAILURE. Not retried: answered, INVALID_NUMBER, CANCELLED. A per-campaign `retry_delay_seconds` is applied.
* **Manual retry** (campaign page, COMPLETED / CANCELLED / RUNNING / PAUSED): *Call results* cards **Answered / No answer / Busy / Failed (/ Cancelled)** show the number of calls, the same numbers as the call report. Select one or more cards (or *Select all*) and press **Retry**: every number that had a call with that result is queued again with a fresh attempt budget (`retry_base`). A completed or cancelled campaign goes back to RUNNING.
* "Answered" in reports = attempt has `answered_at`; its terminal status is `COMPLETED`.
* **Unanswered calls**: carriers end an unanswered call after their ring timeout (about 30 s), often with Q.850 cause 21. A cause 21 after 20 s or more of ringing (or an originate failure with reason 0 after 20 s) is stored as **NO_ANSWER**; a real decline within a few seconds is **BUSY**. Hangup events carry the real cause, so a failed `OriginateResponse` is finalized only after a 3 s grace period (`FinalizeOriginateFailure`).
* Number list: CSV only (XLSX is the optional item and is not implemented). First column = phone. Header/blank/invalid rows count as invalid. Numbers starting `0` get the country prefix.

## DID concurrency algorithm
`did_slots` holds one row per in-flight call (`call_attempt_id` unique). `DidSlotManager::acquire`:
1. Takes a distributed lock `did-slot:{did_id}` (Redis) – an optimization.
2. In a DB transaction runs `SELECT ... FOR UPDATE` on the DID row (the actual safety guarantee, works even if Redis fails).
3. Checks the DID is active, `slots(did) < max_concurrent_calls`, and `slots(campaign) < min(requested_concurrency, did max)`; inserts the slot with `expires_at`.
Slots are released when the attempt is finalized (hangup/failure/cancel). Capacity is therefore shared by all campaigns on the DID and independent between DIDs. `ParallelRaceTest` races 12 real OS processes for a 3-slot DID.

**Recovery** (`calls:reconcile`, every minute): removes slots of finalized attempts; for expired slots asks Asterisk (ARI `/channels`) – live calls are refreshed, missing ones are finalized as `TEMPORARY_FAILURE` (retryable) and the slot freed; if Asterisk is unreachable slots are only force-released after 2× TTL; aborts attempts stuck in `QUEUED` (worker crash before origination); re-dispatches running campaigns; starts due `QUEUED` campaigns.

## Campaign lifecycle
`DRAFT → PENDING_APPROVAL → APPROVED → (QUEUED) → RUNNING ⇄ PAUSED → COMPLETED`; also `REJECTED`, `CANCELLED`, `FAILED`. Transitions are explicit in `CampaignStatus::transitions()` and enforced in `CampaignService`. Pause/Cancel stop originating immediately: not-yet-originated attempts are aborted (slot freed, attempt not counted); calls already in progress finish.

## Call lifecycle
`QUEUED → DIALING → RINGING → ANSWERED → PLAYBACK_STARTED → PLAYBACK_COMPLETED → COMPLETED` or terminal `NO_ANSWER | BUSY | FAILED | TEMPORARY_FAILURE | INVALID_NUMBER | CANCELLED`. Recipient states: `PENDING, IN_PROGRESS, RETRY_PENDING, ANSWERED, EXHAUSTED, FAILED, CANCELLED`. Every attempt is its own `call_attempts` row (`unique(recipient_id, attempt_no)`). Events are idempotent (`processed_events` key), rank-monotonic (a late "Ringing" never overrides "Up") and finalization happens exactly once (row lock + `finalized` flag). Duplicate `OriginateBroadcastCall` jobs lose the `QUEUED→DIALING` race and do nothing; `tries = 1` prevents automatic re-origination.

## Authorization model
Roles `super_admin` / `admin` / `user` (see *Roles*; tables `roles, permissions, role_user, permission_role`). Server-side only: route middleware `role:`, Policies (`CampaignPolicy`, `DidPolicy`, `AudioFilePolicy`, `CallAttemptPolicy`, `UserPolicy`), Form Request authorization/validation (DID must be assigned+active, audio must be own, concurrency ≤ DID max), query scopes `visibleTo($user)` and `User::limitToVisibleOwners` on every list/report, authorized audio streaming (private disk). Reports (Livewire) re-scope on every request, so tampering with filters can't widen access. Inactive users are logged out on the next request.

## Security
CSRF, Blade escaping, Eloquent bindings, validated uploads (extension + MIME + size + ffprobe integrity, random filenames, private disk), login throttling, hashed passwords + session regeneration, secure headers (`SecurityHeaders` middleware), env-only secrets, audit log (`audit_logs`) for users, DIDs, assignments, concurrency changes and every campaign state change.

## Production deployment (Ubuntu 22.04/24.04)
```bash
sudo apt install -y nginx mariadb-server redis-server ffmpeg supervisor php8.3-fpm php8.3-{cli,mysql,mbstring,xml,curl,zip,redis,gd} composer unzip
sudo mysql -e "CREATE DATABASE broadcast CHARACTER SET utf8mb4; CREATE USER 'broadcast'@'localhost' IDENTIFIED BY '<strong>'; GRANT ALL ON broadcast.* TO 'broadcast'@'localhost';"
cd /var/www/broadcast && composer install --no-dev -o && npm ci && npm run build
cp .env.example .env && php artisan key:generate   # set APP_ENV=production APP_DEBUG=false, DB_*, Redis, ASTERISK_*, ASTERISK_DRY_RUN=false
php artisan migrate --force --seed && php artisan app:create-admin you@example.com
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```
**Nginx** (`/etc/nginx/sites-available/broadcast`):
```
server { listen 80; server_name example.com; root /var/www/broadcast/public; index index.php;
  client_max_body_size 60M;
  location / { try_files $uri $uri/ /index.php?$query_string; }
  location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
  location ~ /\.(?!well-known) { deny all; } }
```
Enable HTTPS (certbot) so session cookies are `Secure` (`SESSION_SECURE_COOKIE=true`).

**Supervisor** (`/etc/supervisor/conf.d/broadcast.conf`):
```
[program:broadcast-worker]
command=php /var/www/broadcast/artisan queue:work redis --tries=3 --max-time=3600
numprocs=4
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
user=www-data
stopwaitsecs=3600

[program:broadcast-ami]
command=php /var/www/broadcast/artisan asterisk:listen
autostart=true
autorestart=true
user=www-data
```
**Scheduler** cron: `* * * * * www-data cd /var/www/broadcast && php artisan schedule:run >> /dev/null 2>&1`

Required processes: Nginx+PHP-FPM (web), queue workers, scheduler, Redis, MariaDB, Asterisk (+ the AMI listener). Make sure the web/worker host can reach Asterisk AMI (5038) and ARI (8088) – restrict both by firewall.

After a deploy: `sudo supervisorctl restart broadcast-worker:* broadcast-ami`.

## Testing
`php artisan test` (some tests assume the earlier two-role model and may need updating) – authorization (IDOR), concurrency (limit, two DIDs, shared DID, real parallel race, release, stale recovery), campaign state (approval gate, pause/resume/cancel), calls (attempt creation, out-of-order/duplicate events, retry/exhaustion, duplicate jobs). Requires MariaDB database `broadcast_test`.
