<?php
// ================================================================
//  ToolTrack backup settings.  Edit this file, then run
//  Check-Backup.bat to confirm everything is found.
//  (CLI-only: this file does nothing if opened in a browser.)
// ================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

return [

    // Backup dates/times are always computed in this timezone, no
    // matter what the PC's own clock/timezone or php.ini say.
    'timezone' => 'Asia/Manila',

    // -- WHERE LOCAL BACKUPS GO -----------------------------------
    // Leave '' to use  <folder above XAMPP>\ToolTrack-Backups
    // (e.g. C:\ToolTrack-Backups).  If the PC has a second drive or a
    // USB drive, point this there so a dead system drive doesn't take
    // the backups with it, e.g.  'D:/ToolTrack-Backups'
    // Must NOT be inside htdocs/the project (anything in htdocs can be
    // downloaded by URL, and these backups contain student data).
    'local_dir' => '',

    // -- GOOGLE DRIVE ---------------------------------------------
    // Needs "Google Drive for desktop" installed and signed in on this
    // PC.  Leave '' to auto-detect  <letter>:/My Drive  (Stream mode)
    // or  %USERPROFILE%/My Drive.  Or set it explicitly, e.g.
    //   'G:/My Drive'   or   'C:/Users/Admin/My Drive'
    // Use forward slashes.
    'gdrive_dir'       => '',
    'gdrive_subfolder' => 'ToolTrack-Backups',   // created inside Drive

    // -- DATABASE -------------------------------------------------
    // Host/name/user/password are read automatically from
    // api/bootstrap.php, so they never go out of sync.  Only fill
    // these in to override that.
    'db_host' => '',
    'db_port' => 3306,
    'db_name' => '',
    'db_user' => '',
    'db_pass' => null,
    // '' = auto-find <xampp>/mysql/bin/mysqldump.exe
    'mysqldump' => '',

    // -- HOW LONG TO KEEP THINGS (same rules locally and on Drive) -
    'keep_db_days'          => 60,   // one database backup per day
    'keep_all_files_days'   => 2,    // keep EVERY project snapshot this recent
    'keep_daily_files_days' => 30,   // then only the last one of each day, up to this age

    // -- ADVANCED ------------------------------------------------
    // Wait until files have been untouched this long before taking a
    // project snapshot (avoids zipping a half-finished save/copy).
    'quiet_seconds'        => 20,
    'cloud_resync_minutes' => 60,    // re-check Drive for missing files this often
    // Top-level project folders NOT included in project snapshots.
    'exclude' => ['.git', '_incoming', 'backups'],
];
