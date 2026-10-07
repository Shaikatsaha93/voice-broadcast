# Work steps: UI redesign + DID sync + number upload

Everything done on top of the base Voice Broadcast app, in order, so the same work can be repeated or continued from another machine/session.

Stack: Laravel 13, PHP 8.3, Livewire, Blade, Tailwind CSS v4 + daisyUI 5, Bootstrap Icons, Vite.

---

## 1. Rename the app ("Laravel" -> "Voice Broadcast")

The navbar, page title and login page print `config('app.name')`, which came from `APP_NAME=Laravel`.

- `.env` and `.env.example`: `APP_NAME="Voice Broadcast"`
- Then `php artisan config:clear`. Restart `php artisan serve` after editing `.env`.

## 2. UI framework: Tailwind v4 + daisyUI 5

Chosen as the middle ground between plain Tailwind and Bootstrap: ready components (`btn`, `card`, `table`, `badge`, `join`, `menu`) plus Tailwind utilities, responsive by default.

```bash
npm install -D tailwindcss @tailwindcss/vite daisyui@latest
npm install bootstrap-icons        # icons only
```

- `vite.config.js`: `laravel({ input: ['resources/css/app.css','resources/js/app.js'] })` plus the `tailwindcss()` plugin.
- `resources/js/app.js`: empty (daisyUI needs no JS; the mobile menu is a CSS dropdown).
- `resources/css/app.css`:
  - `@import 'tailwindcss'`, `@import 'bootstrap-icons/font/bootstrap-icons.css'`
  - `@source` lines for the pagination views and compiled views
  - `@plugin "daisyui" { themes: false; }` and a custom theme `vb` (indigo / pink / sky)
  - Helper classes: `vb-title` (gradient heading), `vb-logo`, `vb-card`, `vb-table`, `vb-stat` + `vb-indigo|pink|sky|emerald|amber` (pastel gradient tiles with dark text)
- `app/Providers/AppServiceProvider.php`: `Paginator::useTailwind()`.

## 3. Views rewritten

All under `resources/views/`:

| Area | Files |
|---|---|
| Layout | `layouts/app.blade.php` (sticky gradient navbar, active link, mobile hamburger menu, alerts, wide container `max-w-[90rem]`) |
| Auth | `auth/login.blade.php` |
| Dashboard / reports | `dashboard.blade.php`, `reports.blade.php`, `livewire/dashboard-stats.blade.php`, `livewire/call-report.blade.php` |
| Campaigns | `campaigns/index.blade.php`, `campaigns/form.blade.php` (`campaigns/show.blade.php` is still pending, see section 9) |
| Audio | `audio/index.blade.php` |
| Admin | `admin/approvals`, `admin/audit`, `admin/dids/{index,show,form,sync}`, `admin/users/{index,show,form}` |
| Component | `components/status.blade.php`: `<x-status :value="..."/>` colored badge (green = answered/completed/approved, blue = running/dialing, yellow = pending/paused/busy, red = failed/rejected/cancelled) |

Dashboard stat grids use `grid-cols-[repeat(auto-fit,minmax(11rem,1fr))]` so any number of tiles fills the row.
Dashboard auto-refresh: `wire:poll.5s` in `livewire/dashboard-stats.blade.php` (polling, not push).

### 3a. Dark / light mode

- `resources/css/app.css`: two daisyUI themes, `vb` (light) and `vb-dark`, plus dark overrides for the body background, `vb-card`, `vb-table` header and `vb-title`.
- `layouts/app.blade.php`: an inline script in `<head>` sets `data-theme` on `<html>` before first paint (saved choice in `localStorage['vb-theme']`, otherwise the OS `prefers-color-scheme`), and defines `vbToggleTheme()`. A moon/sun button in the navbar (also on the login page) toggles it.
- Use theme-aware colors in views (`text-base-content/75`, `bg-base-100`, `divide-base-200`), not fixed ones like `text-slate-600` or `bg-white`, or the text becomes unreadable in dark mode.

## 4. Build and run

```bash
npm run build                 # required after any CSS/view-class change
php artisan view:clear
php artisan serve             # http://127.0.0.1:8000
php artisan queue:listen --tries=1 --timeout=0   # needed for number imports, retries, etc.
```

**Do not run `npm run dev` (Vite dev server).** `app/Http/Middleware/SecurityHeaders.php` sets a CSP (`style-src 'self'`, ...) that blocks the dev server origin (`[::1]:5173`), so the page renders with no CSS. Always use built assets (`npm run build`, and make sure `public/hot` does not exist). To use the dev server, add `http://[::1]:5173` and `ws://[::1]:5173` to the CSP for local env only.

After CSS changes: `npm run build`, then hard refresh (Ctrl+F5).

## 5. DID from Asterisk (trunk sync)

Admin no longer has to type the dial string by hand.

- `app/Services/Asterisk/AsteriskARIService.php`: `pjsipEndpoints()` calls ARI `GET /endpoints/PJSIP` (returns name + state, `null` if unreachable).
- `app/Services/Asterisk/AsteriskService.php`: `trunks()`; in `ASTERISK_DRY_RUN=true` returns sample trunks `trunk` (online) and `backup-trunk` (offline).
- `app/Http/Controllers/Admin/DidController.php`: `sync()` (list trunks) and `importSynced()` (create DID with `trunk = PJSIP/{number}@<trunk>`, trunk must be in the live list).
- Routes (before the `dids` resource): `GET admin/dids/sync` (`admin.dids.sync`), `POST admin/dids/sync` (`admin.dids.sync.import`).
- UI: DIDs page button **From Asterisk** -> `admin/dids/sync.blade.php`. Offline trunks cannot be added.

How a call is built (`AsteriskService::originate`): channel = DID trunk with `{number}` replaced by the recipient, CallerID = `"label" <did number>`, context `broadcast`, exten `s`, variables `CALL_REF` and `AUDIO_FILE`. The DID number is only the caller ID; the trunk decides routing. Label is just a display name (admin lists, CallerID name).

## 6. Number upload on campaign create + sample file

- `app/Http/Requests/CampaignRequest.php`: optional `numbers` file on create only (same rules as `ImportNumbersRequest`, `prohibited` on update).
- `app/Http/Controllers/CampaignController.php`:
  - `store()` uses `$request->safe()->except('numbers')` and queues the import when a file is present.
  - Shared private `queueImport()` used by `store()` and `import()`.
  - `sampleNumbers()` streams `campaign-numbers-sample.csv`.
- Route: `GET campaigns/sample-numbers` (`campaigns.sample`), declared before the `campaigns` resource.
- `campaigns/form.blade.php`: `enctype="multipart/form-data"`, file input, **Download sample CSV** link and accepted formats.
- Sample content: header `phone` + three placeholder rows `8801XXXXXXXXX`. Placeholders are invalid on purpose so an untouched sample can never trigger a real call.
- `app/Jobs/ProcessNumberImport.php`: the first row is skipped if its first cell has no digit (header line). Number format: first CSV column; `8801712345678`, `01712345678`, `+8801712345678` are accepted (`BROADCAST_COUNTRY_PREFIX`, default `880`).

## 7. Tests

```bash
php artisan test
```

Needs the `broadcast_test` MariaDB database (see `phpunit.xml`). Last run: **32 passed**.
New tests: `tests/Feature/DidSyncTest.php` and `tests/Feature/CampaignCreateWithNumbersTest.php` (sync dry-run, unknown trunk rejected, role check, numbers on create, bad file type, sample download, header skip).

## 8. Gotchas learned

- Blade treats `@{{ ... }}` as an escaped literal. To print `@name`, use `{{ '@'.$name }}`.
- `.env` changes can make `php artisan serve` restart or exit, and `composer dev` then stops everything. Restart the processes afterwards.
- `php artisan dev` needs an interactive terminal. When running in the background start `serve` and `queue:listen` separately.
- Text contrast: stat tiles use pastel gradients with dark text; muted text is `text-slate-600` (not `text-base-content/60`).

## 9. Still to do

1. **`resources/views/campaigns/show.blade.php`** still has old Tailwind-style classes (it renders and passes tests, but is not converted to daisyUI). It also needs a "Download sample CSV" link next to its **Upload CSV** form.
2. **Asterisk is not installed yet.** Needs: PJSIP trunk, `manager.conf` (AMI), `ari.conf` + `http.conf` (ARI), dialplan `[broadcast]` context with extension `s` that plays `AUDIO_FILE` and uses `CALL_REF`, RTP ports, then `.env`: `ASTERISK_HOST`, `ASTERISK_AMI_*`, `ASTERISK_ARI_*`, `ASTERISK_DRY_RUN=false`.
3. **First real test:** one campaign with a single own number; check CallerID, audio and status. The provider must allow the DID number as caller ID.
4. **Production server:** Nginx + PHP-FPM, MariaDB, Redis, FFmpeg, Supervisor (queue worker, `php artisan asterisk:listen`, scheduler), HTTPS, backups. See `README.md` for requirements and env keys.
5. **Optional:** real push updates with Laravel Reverb instead of polling; DID list showing live trunk registration state.
