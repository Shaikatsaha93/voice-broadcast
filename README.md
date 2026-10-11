# Voice Broadcast Platform (V1)

Laravel 13 · PHP 8.3+ · MariaDB/MySQL · Redis · Livewire + Blade · Tailwind · Asterisk (AMI/ARI, PJSIP)

Four roles (System Admin / Super Admin / Admin / User) + **Reseller and Own DIDs** with **SIP trunk registration from the portal** + **DID balance billed per pulse** (reseller) or **MRC** (own) + **validity dates** for DIDs and accounts + **DID concurrency** + campaigns + number lists + audio + approval + Asterisk calling + retry (all / per result) + call status + reporting. Nothing else (no IVR, SMS, tenants...).

## Requirements
PHP 8.3+ (`pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `redis` or Predis), Composer, Node 20+, MariaDB 10.6+/MySQL 8, Redis 6+, FFmpeg/ffprobe, Asterisk 18+ (PJSIP).

## Installation
```bash
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build
php artisan migrate --seed          # roles + permissions
php artisan app:create-admin admin@example.com   # first System Admin, prompts for password (min 12 chars)
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
Hierarchy, top to bottom: **System Admin → Super Admin → Admin → User**. Ownership is stored in `users.created_by` and `dids.created_by`; everybody sees only themself and the accounts below them (`User::descendantIds()`).

| Role | Can do |
|---|---|
| **System Admin** (top) | everything: create Super Admins, Admins and Users, register any DID, approve any campaign, run campaigns, see all activity of every account, download / replace any audio, and the **Audit** log (System Admin only) |
| **Super Admin** | create Admins and Users; sees only their own tree (themself, the Admins and Users they created and everyone below them); approves campaigns of that tree, runs campaigns, registers DIDs inside that tree, downloads / replaces audio of that tree. Never sees another Super Admin's data. No Audit access |
| **Admin** | create **users only**; register DIDs; run their own campaigns (paid from the Admin balance, see *Admin balance*); see only their own users' activity, campaigns, audio, reports and DIDs. **No approval power** (their campaigns are approved by their Super Admin) and no audio download, replace or delete. Two Admins never see each other's data. No Audit access |
| **User** | create and run own campaigns on the DIDs assigned to them, upload audio, see own reports and DID balance |

System Admin, Super Admin and Admin can do everything a User can (create, edit, submit, start, pause, resume, cancel, delete and retry campaigns) for their own campaigns and the campaigns of the accounts below them. Admin and User accounts have a **required validity date** (see below). The first System Admin is created with `php artisan app:create-admin`.

## Audio
Users upload WAV or MP3; the file is probed and normalized to 8 kHz mono WAV before it is `READY`. A System Admin / Super Admin can **Download original** of any audio in their tree, convert it, and **Replace** it (not while a campaign using it is RUNNING or QUEUED); both actions are audited. Only System Admins and Super Admins can delete audio (one that no campaign uses); an Admin can neither replace nor delete audio.

## DIDs, SIP trunk, balance and MRC
* **Register a DID** (Admin / Super Admin / System Admin): number, max concurrent calls, SIP host / port / username / password, **DID type** and a **validity date**. Saving writes the trunk to `ASTERISK_PJSIP_FILE` (auth, aor, endpoint, identify, registration named `sip-<id>`), reloads PJSIP through AMI and the page shows the registration status (refreshed every minute by `dids:sip-status`). The SIP password is stored encrypted.
* **DID type** (radio buttons on the form):
  * **Reseller DID**: call charge is required, "charge ৳ X for every N sec" (a *pulse*, `rate_per_pulse`, `pulse_seconds`), plus an opening balance, and an **Opening balance valid until** date.
  * **Own DID**: a **monthly recurring charge (MRC)**, required and always more than 0, and an **MRC valid until** date. No per-call charge.
* **Balance check** (reseller DIDs): before every call `DidSlotManager::acquire` requires `balance >= rate × (calls in flight + 1)`. Not enough balance → no call, the campaign gets `blocked_reason = INSUFFICIENT_BALANCE` and continues when balance is added.
* **Charging**: when a call finishes, `DidBilling` charges `max(1, ceil(billsec / pulse_seconds))` pulses (answered calls only) and writes a `did_transactions` ledger row. Balance can be added / removed on the DID page; the cost is shown per call in the report and the balance on the user's dashboard. MRC is only stored and shown; it is not charged automatically.
* **Validity date** (required): the last valid day. From the next day `dids:expire` (every minute) sets the DID **inactive**, removes it from the Asterisk trunk config and writes `did.expired` to the audit log. Campaigns on an expired DID wait (`blocked_reason = DID_EXPIRED`) until the date is extended. An expired DID cannot be activated or saved as active without a new date.
* **Dialing**: the leading `88` of `880XXXXXXXXXX` is dropped in the dial string (`8801624374389` → `01624374389`); stored numbers and reports keep the full number.

## Admin balance (two-level billing)
A Super Admin / System Admin gives every **Admin** account a **call charge** (taka per pulse of N seconds) and a **balance** when creating it; the balance can be topped up or reduced later on the Admin's page (ledger in `user_transactions`, every change audited). When a **User** belongs to an Admin (the Admin created the User), or the Admin runs a campaign themself, every answered call is charged **twice**: the DID balance at the DID's rate, and the Admin's balance at the Admin's rate (`DidBilling::chargeAdmin`; stored in `call_attempts.admin_id / admin_pulses / admin_cost`). Before every call `DidSlotManager::acquire` checks both balances (`admin_balance` denial, campaign `blocked_reason = ADMIN_BALANCE`): when the Admin's balance is used up, no call is generated even if the DID still has balance, and the campaign continues by itself after a top-up. The **Call report** shows managers per call the user, *Charged* (what the user paid on the DID), *Admin cost* and *Profit* (Charged - Admin cost), summary cards for the same, a **Cost by user and DID** table with totals, and the same columns in the CSV export. Users created directly by a Super Admin / System Admin have no Admin to bill, and an Admin without a wallet (older accounts) is not billed.

## Dashboards and reports
* **Dashboard** (refreshes every 5 s): System Admin / Super Admin see users, DIDs, pending and running campaigns, active and today's calls and DID usage for their scope; an **Admin** additionally sees **My balance** and **Call charge** (no pending-approval card, Admins cannot approve); a **User** sees assigned DIDs, DID balance, own campaigns and today's results.
* **Call report** (filters: campaign, DID, status Answered / No answer / Busy / Failed / Cancelled, phone, date): for managers it adds *User*, *Charged* (what the user paid on the DID), *Admin cost* and *Profit* (Charged - Admin cost) per call, summary cards, a **Cost by user and DID** table with totals, and the same columns in the CSV export. A User sees the plain *Cost* only.

## Account validity
An **Admin** account also needs a call charge and a balance when it is created (see *Admin balance*). Admin and User accounts need a **Valid until** date when they are created or edited (System Admin / Super Admin accounts do not expire). `users:expire` (every minute) sets accounts whose date has passed to **inactive** (`user.expired` in the audit log); inactive users cannot log in and a live session is ended on the next request. An expired account cannot be activated without a new date.

## Database / Redis / Queue / FFmpeg
* Create DB: `CREATE DATABASE broadcast CHARACTER SET utf8mb4;` then `php artisan migrate --seed`.
* Redis: used for the distributed DID lock, cache, queue and sessions. If Redis is down the DB row lock still guarantees DID capacity.
* Queue: `php artisan queue:work --queue=default --tries=3 --max-time=3600` (several workers are safe).
* FFmpeg: `sudo apt install ffmpeg`. Uploaded WAV/MP3 is probed (integrity) and normalized to 8 kHz mono PCM WAV before status `READY`.

## Running
```bash
php artisan serve                 # dev only
php artisan queue:work            # workers
php artisan schedule:work         # scheduler (prod: cron `* * * * * php artisan schedule:run`): calls:reconcile, dids:sip-status, users:expire, dids:expire
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
* **Approval does not dial.** Flow: user creates DRAFT → imports numbers → submits (`PENDING_APPROVAL`) → the Super Admin above that user (or the System Admin) approves (`APPROVED`) → approval **starts it automatically** (`RUNNING`, or `QUEUED` when `scheduled_at` is in the future; the scheduler starts it when due). The user does not press Start again. Nothing calls before approval.
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
Roles `system_admin` / `super_admin` / `admin` / `user` (see *Roles*; tables `roles, permissions, role_user, permission_role`). Server-side only: route middleware `role:`, Policies (`CampaignPolicy`, `DidPolicy`, `AudioFilePolicy`, `CallAttemptPolicy`, `UserPolicy`), Form Request authorization/validation (DID must be assigned+active, audio must be own, concurrency ≤ DID max), query scopes `visibleTo($user)` and `User::limitToVisibleOwners` on every list/report, authorized audio streaming (private disk). Reports (Livewire) re-scope on every request, so tampering with filters can't widen access. Inactive users are logged out on the next request.

## Security
CSRF, Blade escaping, Eloquent bindings, validated uploads (extension + MIME + size + ffprobe integrity, random filenames, private disk), login throttling, hashed passwords + session regeneration, secure headers (`SecurityHeaders` middleware), env-only secrets, audit log (`audit_logs`) for users, DIDs, assignments, concurrency changes and every campaign state change.

## Updating an existing install
```bash
php artisan migrate --force    # new columns / tables
php artisan db:seed --force    # creates the System Admin role and refreshes role labels / permissions
php artisan view:clear && sudo supervisorctl restart all
sudo bash deploy/harden.sh /var/www/broadcast   # see below; run after every copy of the code
```
An older Super Admin account that should be the top account must be changed to the **System Admin** role (Users page, or `app:create-admin` for a new one); the old Admin accounts keep working but need a call charge and balance before their users' calls are billed to them.

## Protecting the code on the server
After **every** deploy (rsync / git pull resets file modes) run, as root:
```bash
sudo bash deploy/harden.sh /var/www/broadcast
```
It sets the app to `www-data:www-data`, directories `750`, files `640`, `.env` `600` (no other login user can even list the folder), tightens nginx (no server version, dot files / sqlite / logs / config files denied, only `public/index.php` is executed; the app itself sends the security headers) and PHP (`expose_php=Off`, `display_errors=Off`), then reloads both. Also: keep `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, keep MariaDB / Redis / Asterisk AMI+ARI bound to `127.0.0.1`, use SSH keys only (`PasswordAuthentication no`, `PermitRootLogin no`) and a firewall (only 22/80/443), and serve the site over HTTPS with `SESSION_SECURE_COOKIE=true`. Nothing can hide the code from `root` / users with `sudo`, so give those out sparingly.

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
