# PostScoreboard — Gmail "Scoreboard" Watcher

Watches the `abegail@depthlogistics.com` Gmail inbox. When an email arrives whose
subject contains **"scoreboard"** (case-insensitive, so "RE: Scoreboard" also matches),
sent by an **approved contributor** (the executives returned by
`/wp-json/user/contributor/`, matched on email address) and containing **at least
one image** (emails that merely mention scoreboard in a conversation have none), it:

1. Extracts the email body text and the image attachments (including inline/pasted images).
2. Checks the 3 intranet sections and finds the one whose **latest post is oldest**:
   - `weekly_challenge`, `depth_at_work_3`, `depth_at_work_2`
   (`what_on_you_mind` was in the rotation originally and was removed 2026-07-10;
   to change the rotation, edit `wordpress.cct_slugs` in `config.php`)
3. Uploads the images to the WordPress media library on depthintranet.com.
4. Creates a post in that section: first line of the email body → `title`, remaining
   lines → `description`, up to 3 images → `featured_image` / `image_2` / `image_3`,
   `schedule_date` = today.

Runs every 15 minutes via Windows Task Scheduler. Emails are never posted twice
(processed UIDs are tracked in `state\state.json`, and a UID is only recorded after the
intranet accepted the post — failures retry automatically on the next run).

## One-time setup

1. **Gmail App Password** — for abegail@depthlogistics.com:
   Google Account → Security → 2-Step Verification (must be ON) → App Passwords →
   create one (name it e.g. "scoreboard watcher"). Put it in `config.php` under
   `imap.password`.

2. **WordPress Application Password** — on depthintranet.com:
   WP Admin → Users → your profile → Application Passwords → add "scoreboard watcher".
   Put the username and generated password in `config.php` under `wordpress`.
   The user needs permission to upload media and create items in the 4 JetEngine CCTs.

3. **Test without posting:**
   ```
   C:\wamp64\bin\php\php8.3.6\php.exe C:\wamp64\www\postscoreboard\watch.php --dry-run
   ```
   Send yourself a test email with "scoreboard" in the subject and an image first.
   Dry-run prints what would be posted and which section it would go to, but posts
   nothing and saves no state.

4. **Scheduled task** — already registered: "PostScoreboard Gmail Watcher" runs
   `watch.php` every 15 minutes under the current user (runs while this user is
   logged in). It is safe with unfilled credentials — the script exits immediately
   while `config.php` still has placeholders.

   Fire it manually to verify: `schtasks /Run /TN "PostScoreboard Gmail Watcher"`

   Optional (more robust — runs even when nobody is logged in): re-register it as
   SYSTEM from an *elevated* Command Prompt (same name, replaces the current task):
   ```
   schtasks /Create /TN "PostScoreboard Gmail Watcher" ^
     /TR "\"C:\wamp64\bin\php\php8.3.6\php.exe\" \"C:\wamp64\www\postscoreboard\watch.php\"" ^
     /SC MINUTE /MO 15 /RU SYSTEM /RL LIMITED /F
   ```

## Day-to-day

- Log: `logs\watcher.log` (rotated at 5 MB). Every run logs what it found, where it
  posted, and any errors.
- Dedup state: `state\state.json`. Deleting it may cause emails from the last
  `lookback_days` (default 3) to re-post.
- If posts stop appearing: check the log. HTTP 401/403 means the WP Application
  Password was revoked or the JetEngine CCT REST access changed. IMAP auth errors
  mean the Gmail App Password was revoked.

## Files

| File | Purpose |
|---|---|
| `watch.php` | The entire watcher |
| `config.php` | Credentials — git-ignored, never commit |
| `config.example.php` | Template for config.php |
| `certs/cacert.pem` | Mozilla CA bundle (WAMP's PHP CLI has none configured) |
| `logs/`, `state/`, `tmp/` | Runtime: log, dedup state + lock, transient attachments |
