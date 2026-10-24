# Akyerit CBT - Windows installer

Builds one `Akyerit-CBT-Setup-4.3.exe` that a school can double-click. It installs XAMPP (Apache + PHP + MariaDB) silently if the PC has none, copies the app to `C:\xampp\htdocs\exam`, creates the database with its own private user, registers the web server and database as Windows services that start with the PC, opens the firewall port, creates the first administrator, and puts shortcuts on the desktop and Start menu.

## Build it (on any Windows 10/11 PC with internet)
1. Download the **XAMPP Windows x64 installer** (PHP 8.2 or newer) from https://www.apachefriends.org/download.html and save it in `windows-installer\redist\`.
2. Open PowerShell in this `windows-installer` folder and run:
   `powershell -ExecutionPolicy Bypass -File build.ps1`
   (it installs Inno Setup through winget if needed).
3. The installer appears in `windows-installer\output\`.

## What the school does
Run the .exe as administrator, enter an admin username, password (10+ characters), keep port 80, finish. `access-info.txt` then shows the addresses for the admin PC and for student PCs on the same network.

## Upgrading
Run a newer Setup on the same PC. It detects the existing install, skips the admin page, replaces the program files and keeps the database, uploads, photos, backups and settings.

## Uninstalling
Removes the services, firewall rule, scheduled task and shortcuts. XAMPP, the database and the exam data are left in place on purpose.

## Notes
- Data lives in `C:\xampp\mysql\data`, question pictures in `C:\xampp\htdocs\exam\uploads`, photos in `...\onlineForm\images\Student_Images`.
- The database listens on this PC only (127.0.0.1). The app uses its own database user; the generated password is in `C:\xampp\htdocs\exam\includes\local-config.php`.
- If setup fails the log is `C:\AkyeritCBT\install.log`.
- The installer does not hide the PHP source. For that, encode the app first (ionCube) and point `build.ps1` at the encoded copy.
