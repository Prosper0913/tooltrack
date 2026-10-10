# ToolTrack backups

Automatic backups to **two places**: a folder on this PC and **Google Drive**.

| What | When | Kept |
|---|---|---|
| **Database** (borrowers, tools, transactions, users, audit trail…) | Once a day, at midnight Philippine time. If the PC was off at midnight, it is taken as soon as the PC and MySQL are on. | 60 days |
| **Project files** (the code, as a zip) | Within about 2 minutes of any file changing | Every snapshot for 2 days, then the last one of each day for 30 days |

The scheduled task wakes up every 2 minutes, does only what is due, and goes back to sleep.
If Google Drive is signed out, the local backup still happens and the Drive copy is retried automatically.

## One-time setup (≈10 minutes)

1. **Install Google Drive for desktop** on this PC and sign in with the account that should hold the backups. Check that a `My Drive` folder appears (usually as drive `G:`).
2. **Enable zip in PHP** (usually already on): open `C:\xampp\php\php.ini`, find `;extension=zip` and delete the `;` if present.
3. **Optional – choose where local backups go.** Open `backup\config.php`. By default they go to `C:\ToolTrack-Backups` (next to the XAMPP folder). If the PC has a second drive or a USB stick, set `'local_dir'` to a folder there, e.g. `'D:/ToolTrack-Backups'`. Use forward slashes. *Never put it inside htdocs.*
4. Start **MySQL** in XAMPP, then double-click **`Check-Backup.bat`**. Every line should say `OK` (Google Drive may say `--` if auto-detect can't find it – then set `'gdrive_dir'` in `config.php`, e.g. `'G:/My Drive'`).
5. Double-click **`Install-Backup.bat`**. If it complains about permissions, right-click it → *Run as administrator*.
6. Wait two minutes and double-click **`Check-Backup.bat`** again. You should see a database backup and project snapshot with today's date, and Drive "up to date". Look in `ToolTrack-Backups` inside Google Drive on another device to be sure.

## Day to day

- Nothing to do. Open **`Check-Backup.bat`** now and then (it shows last-success times and any problems).
- **`Backup-Now.bat`** takes an extra backup immediately – good habit before shutting the PC down for the day, or before an update.
- The log is `ToolTrack-Backups\_system\backup.log`.

> **Important limitation.** The database is backed up once a day. If the PC is switched off at night, the day's backup happens the next morning when it starts – so it contains everything up to the previous day, but **anything entered today is not backed up until tomorrow**. If the PC could break today, run `Backup-Now.bat` at the end of the day.

## Restoring

**Database** (from the newest `tooltrack_db_…zip`, in `db\` locally or in Google Drive):

1. Unzip it – you get one `.sql` file.
2. Start MySQL in XAMPP. Then either:
   - phpMyAdmin → **Import** → choose the `.sql` file → Go. *(Works even if the database doesn't exist yet.)*
   - or in a command prompt: `C:\xampp\mysql\bin\mysql.exe -u root < "C:\path\to\tooltrack_db_2026-10-09_00-00-05.sql"` (type the real filename – `*` does not work here).

   ⚠ Restoring **replaces** the current tables with the backup's.

**Project files** (from a `tooltrack_files_…zip`): unzip it inside `C:\xampp\htdocs` – it recreates the `tooltrack` folder. Newer files overwrite older ones.

**If the whole PC dies:** install XAMPP on the new PC → copy this project folder back (unzip the newest `tooltrack_files_…zip` into `htdocs`) → import the newest `tooltrack_db_…zip` → install Google Drive for desktop + run `Install-Backup.bat` to resume backing up.

## Where things are

```
ToolTrack-Backups\            (local; Drive has the same, inside ToolTrack-Backups on My Drive)
  db\      tooltrack_db_YYYY-MM-DD_HH-MM-SS.zip
  files\   tooltrack_files_YYYY-MM-DD_HH-MM-SS.zip
  _system\ backup.log, state.json   (local only)
```
All times are Philippine time, whatever the PC's own clock/timezone says.

## Troubleshooting

| You see | Meaning / fix |
|---|---|
| `MySQL running  FAIL` | MySQL isn't started in XAMPP. Backups simply wait and run once it is. |
| `Google Drive … does not exist` | Google Drive for desktop isn't running or isn't signed in. Open it. Local backups are unaffected; missing copies are uploaded automatically afterwards. |
| `PHP zip extension MISSING` | Step 2 above. |
| `could not finish the zip (a file may be locked)` | A file was in use at that moment; it retries on the next run. |
| Nothing new in the folders | Open `Check-Backup.bat`; make sure the "ToolTrack Backup" task exists in Windows *Task Scheduler*, and that you're signed in to Windows (the task runs for the user who installed it). |

To stop scheduled backups: **`Uninstall-Backup.bat`** (existing backups stay).

## Safety notes

- Backups contain student names/ID numbers and login password hashes. Keep the Google Drive folder private to the people who should see it.
- The `backup` folder is blocked from web access (`.htaccess`), and backups are stored outside `htdocs` on purpose.
- The older `backup.php` / `run_backup.bat` are not used by this system. `backup.php` saves a copy to `htdocs\tooltrack\backups`, which would be downloadable from the website – delete that `backups` folder if it exists.
