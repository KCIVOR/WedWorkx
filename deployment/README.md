# cPanel package

Download `wedworks-cpanel.zip` from this folder using GitHub's Download raw file button. It contains the application, compiled `public/build` assets, and production `vendor` dependencies. No npm or Composer commands are needed on cPanel.

Extract the ZIP outside `public_html`, for example `/home/YOURUSER/wedworks`. Set the domain document root to `/home/YOURUSER/wedworks/public`. Do not expose the whole application folder to the web.

The ZIP includes hidden files such as `public/.htaccess` and empty Laravel storage directories. It excludes credentials, uploaded files, local database data, logs, development packages, and machine-specific config caches.

For an existing installation, back up first and preserve its `.env` and `storage` contents when updating. Remove `public/hot` if present; it points browsers at a development server.

For a NEW installation, the package does not create the database or admin account. You still need a server-specific `.env` with a unique APP_KEY, database tables and initial campaign/admin records, writable storage directories, the public storage link, and the scheduler cron job. These can be prepared separately through cPanel File Manager, phpMyAdmin, and Cron Jobs or with hosting support. Do not copy the development database or credentials into the live site.

See `docs/DEPLOYMENT.md` in the repository for server requirements and layout. Never upload a Windows-generated Laravel config or route cache to Linux.
