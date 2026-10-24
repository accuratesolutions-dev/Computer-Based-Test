# Runs once at the end of the Akyerit CBT setup (as Administrator). Safe to run again: upgrades keep data.
param([Parameter(Mandatory)][string]$AppDir, [Parameter(Mandatory)][string]$CfgFile)
$ErrorActionPreference = 'Stop'
$log = Join-Path $AppDir 'install.log'
function Log($m) { "$(Get-Date -Format s)  $m" | Add-Content -Path $log -Encoding UTF8 }
function Fail($m) { Log "ERROR: $m"; throw $m }

try {
    Log '--- post-install start ---'
    $cfg = @{}
    if (Test-Path $CfgFile) {
        foreach ($line in Get-Content $CfgFile -Encoding UTF8) {
            $i = $line.IndexOf('='); if ($i -gt 0) { $cfg[$line.Substring(0, $i)] = $line.Substring($i + 1) }
        }
    }
    $xampp = 'C:\xampp'
    $web   = "$xampp\htdocs\exam"
    $php   = "$xampp\php\php.exe"
    $apacheSvc = 'AkyeritCBTApache'; $dbSvc = 'AkyeritCBTDB'

    # 1. XAMPP (Apache + PHP + MariaDB), installed silently only when missing
    if (-not (Test-Path "$xampp\apache\bin\httpd.exe")) {
        $exe = Get-ChildItem "$AppDir\redist" -Filter 'xampp*installer*.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
        if (-not $exe) { Fail 'The XAMPP installer is missing from the setup package.' }
        Log "Installing XAMPP from $($exe.Name) (this takes a few minutes)..."
        $p = Start-Process -FilePath $exe.FullName -ArgumentList '--mode', 'unattended', '--launchapps', '0', '--prefix', $xampp -Wait -PassThru
        Log "XAMPP installer exit code $($p.ExitCode)"
        if (-not (Test-Path "$xampp\apache\bin\httpd.exe")) { Fail 'XAMPP did not install. Install XAMPP manually from apachefriends.org, then run this setup again.' }
    } else { Log 'XAMPP already present.' }

    # 2. Stop our services during an upgrade
    foreach ($s in $apacheSvc, $dbSvc) { if (Get-Service $s -ErrorAction SilentlyContinue) { Stop-Service $s -Force -ErrorAction SilentlyContinue } }

    # 3. Copy the application (never touches uploads, backups, student photos or local-config.php)
    Log 'Copying application files...'
    New-Item -ItemType Directory -Force -Path $web | Out-Null
    robocopy "$AppDir\app" $web /E /XD uploads backups Student_Images /XF local-config.php /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { Fail "File copy failed (robocopy $LASTEXITCODE)." }
    foreach ($d in 'uploads', 'backups', 'onlineForm\images\Student_Images') {
        New-Item -ItemType Directory -Force -Path "$web\$d" | Out-Null
        foreach ($f in '.htaccess', '.gitkeep') {
            if ((Test-Path "$AppDir\app\$d\$f") -and -not (Test-Path "$web\$d\$f")) { Copy-Item "$AppDir\app\$d\$f" "$web\$d\$f" }
        }
    }
    # Opening http://server/ goes straight to the exam login
    [IO.File]::WriteAllText("$xampp\htdocs\index.php", "<?php header('Location: /exam/'); exit;`n", (New-Object Text.UTF8Encoding $false))

    # 4. Web port
    $conf = "$xampp\apache\conf\httpd.conf"
    $text = [IO.File]::ReadAllText($conf)
    $port = 0
    if ($cfg['port']) { $port = [int]$cfg['port'] }
    elseif ($text -match '(?m)^Listen\s+(\d+)') { $port = [int]$Matches[1] }
    if ($port -le 0) { $port = 80 }
    $busy = { param($n) [bool](Get-NetTCPConnection -LocalPort $n -State Listen -ErrorAction SilentlyContinue) }
    if (& $busy $port) {
        foreach ($alt in 8080, 8081, 8088) { if (-not (& $busy $alt)) { Log "Port $port is busy; using $alt instead."; $port = $alt; break } }
    }
    $text = [regex]::Replace($text, '(?m)^Listen\s+\d+', "Listen $port")
    $text = [regex]::Replace($text, '(?m)^ServerName\s+\S+', "ServerName localhost:$port")
    [IO.File]::WriteAllText($conf, $text, (New-Object Text.UTF8Encoding $false))
    Log "Web port: $port"

    # 5. Database engine: local only; then register both servers as auto-start Windows services
    $ini = "$xampp\mysql\bin\my.ini"
    $myini = [IO.File]::ReadAllText($ini)
    if ($myini -notmatch '(?m)^\s*bind-address') {
        $myini = ([regex]'(?m)^\[mysqld\]\s*$').Replace($myini, "[mysqld]`r`nbind-address=127.0.0.1", 1)
        [IO.File]::WriteAllText($ini, $myini, (New-Object Text.UTF8Encoding $false))
    }
    if (-not (Get-Service $dbSvc -ErrorAction SilentlyContinue)) {
        & "$xampp\mysql\bin\mysqld.exe" --install $dbSvc "--defaults-file=$ini" | Out-Null
    }
    if (-not (Get-Service $apacheSvc -ErrorAction SilentlyContinue)) {
        & "$xampp\apache\bin\httpd.exe" -k install -n $apacheSvc | Out-Null
    }
    Set-Service $dbSvc -StartupType Automatic; Set-Service $apacheSvc -StartupType Automatic
    Start-Service $dbSvc
    $ping = "$xampp\mysql\bin\mysqladmin.exe"
    $ok = $false
    $ErrorActionPreference = 'Continue'   # native tools write to stderr; judge them by exit code only
    for ($i = 0; $i -lt 40; $i++) { & $ping -u root ping 2>&1 | Out-Null; if ($LASTEXITCODE -eq 0) { $ok = $true; break }; Start-Sleep -Seconds 2 }
    if (-not $ok) { Fail 'The database did not start. If XAMPP Control Panel is open with MySQL running, stop it (or close XAMPP) and run this setup again.' }

    # 6. Database, app user, local-config.php (kept on upgrades)
    $localCfg = "$web\includes\local-config.php"
    $dbPass = $null
    if (Test-Path $localCfg) {
        $m = [regex]::Match([IO.File]::ReadAllText($localCfg), "'DB_PASS'\s*=>\s*'([^']+)'")
        if ($m.Success) { $dbPass = $m.Groups[1].Value }
    }
    $fresh = -not $dbPass
    if ($fresh) {
        $chars = (48..57) + (65..90) + (97..122)
        $dbPass = -join (1..28 | ForEach-Object { [char]($chars | Get-Random) })
    }
    $sql = @"
CREATE DATABASE IF NOT EXISTS ``cbt_exam`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'cbt'@'localhost' IDENTIFIED BY '$dbPass';
ALTER USER 'cbt'@'localhost' IDENTIFIED BY '$dbPass';
GRANT ALL PRIVILEGES ON ``cbt_exam``.* TO 'cbt'@'localhost';
FLUSH PRIVILEGES;
"@
    $sql | & "$xampp\mysql\bin\mysql.exe" -u root 2>&1 | ForEach-Object { Log "mysql: $_" }
    if ($LASTEXITCODE -ne 0) { Fail 'Could not create the database (is the MySQL root password blank, as XAMPP sets it?).' }
    if ($fresh) {
        $tz = if ($cfg['tz']) { $cfg['tz'] } else { 'Africa/Lagos' }
        $tz = $tz -replace "[^A-Za-z0-9/_+-]", ''
        $body = "<?php`n// Written by the Akyerit CBT installer. Keep private.`nreturn [`n    'DB_HOST' => '127.0.0.1',`n    'DB_PORT' => '3306',`n    'DB_NAME' => 'cbt_exam',`n    'DB_USER' => 'cbt',`n    'DB_PASS' => '$dbPass',`n    'APP_TIMEZONE' => '$tz',`n];`n"
        [IO.File]::WriteAllText($localCfg, $body, (New-Object Text.UTF8Encoding $false))
        Log 'Wrote includes\local-config.php'
    }

    # 7. Web server
    Start-Service $apacheSvc -ErrorAction Stop
    Start-Sleep -Seconds 3

    # 8. Tables, starter data, first administrator
    Push-Location $web
    try {
        $ErrorActionPreference = 'Continue'
        & $php 'bin\init-db.php' 2>&1 | ForEach-Object { Log "init-db: $_" }
        if ($LASTEXITCODE -ne 0) { Fail 'Creating the database tables failed (see install.log).' }
        if ($cfg['admin_user'] -and $cfg['admin_pass']) {
            $count = ((& "$xampp\mysql\bin\mysql.exe" -u root -N -B -e 'SELECT COUNT(*) FROM cbt_exam.admin') | Out-String).Trim()
            if ($count -eq '0') {
                & $php 'bin\create-admin.php' $cfg['admin_user'] $cfg['admin_pass'] | Out-Null
                if ($LASTEXITCODE -ne 0) { Fail 'Creating the administrator failed (username 3-50 letters/digits, password at least 10 characters).' }
                Log "Administrator '$($cfg['admin_user'])' created."
            } else { Log 'An administrator already exists; not changed.' }
        }
    } finally { Pop-Location; $ErrorActionPreference = 'Stop' }

    # 9. Firewall, scheduled auto-marking of abandoned attempts, shortcuts
    try {
        Get-NetFirewallRule -DisplayName 'Akyerit CBT' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
        New-NetFirewallRule -DisplayName 'Akyerit CBT' -Direction Inbound -Protocol TCP -LocalPort $port -Action Allow -Profile Any | Out-Null
    } catch { Log "Firewall rule not created: $_" }
    try {
        $ErrorActionPreference = 'Stop'
        $act = New-ScheduledTaskAction -Execute $php -Argument "`"$web\bin\finalize-expired.php`""
        $trg = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5)
        Register-ScheduledTask -TaskName 'AkyeritCBT-Finalize' -Action $act -Trigger $trg -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
    } catch { Log "Scheduled task not created: $_" }

    $ips = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { $_.IPAddress })
    $suffix = if ($port -eq 80) { '' } else { ":$port" }
    $local = "http://localhost$suffix"
    $links = [ordered]@{ 'Akyerit CBT - Admin' = "$local/exam/admin/"; 'Akyerit CBT - Student exam' = "$local/exam/"; 'Akyerit CBT - Student registration' = "$local/exam/onlineForm/" }
    $ws = New-Object -ComObject WScript.Shell
    $menu = Join-Path ([Environment]::GetFolderPath('CommonPrograms')) 'Akyerit CBT'
    New-Item -ItemType Directory -Force -Path $menu | Out-Null
    foreach ($k in $links.Keys) { $s = $ws.CreateShortcut("$menu\$k.url"); $s.TargetPath = $links[$k]; $s.Save() }
    $s = $ws.CreateShortcut(([Environment]::GetFolderPath('CommonDesktopDirectory') + '\Akyerit CBT Admin.url')); $s.TargetPath = $links['Akyerit CBT - Admin']; $s.Save()

    $info = @("AKYERIT CBT IS INSTALLED", "", "On this computer:", "  Admin:               $local/exam/admin/", "  Student exam:        $local/exam/", "  Student registration: $local/exam/onlineForm/", "", "From other computers on the same network, replace 'localhost' with this computer's address:")
    foreach ($ip in $ips) { $info += "  http://$ip$suffix/exam/" }
    $info += @("", "The exam server starts by itself when this computer starts. Keep this computer on and connected during exams.", "Back up regularly: Admin > Settings > System.", "Setup log: $log")
    $info | Set-Content -Path (Join-Path $AppDir 'access-info.txt') -Encoding UTF8
    Log '--- post-install finished OK ---'
    if (Test-Path $CfgFile) { Remove-Item $CfgFile -Force -ErrorAction SilentlyContinue }
    exit 0
} catch {
    Log "FAILED: $_"
    if (Test-Path $CfgFile) { Remove-Item $CfgFile -Force -ErrorAction SilentlyContinue }
    exit 1
}
