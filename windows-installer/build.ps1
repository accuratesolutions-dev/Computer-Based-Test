# Builds Akyerit-CBT-Setup-<version>.exe. Run on a Windows PC:  powershell -ExecutionPolicy Bypass -File build.ps1
# Needs: (1) the XAMPP installer .exe in windows-installer\redist\  (2) Inno Setup 6.3+ (installed for you via winget if missing).
param([string]$XamppInstaller = '')
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$repo = Split-Path -Parent $here
Set-Location $here

if (-not $XamppInstaller) {
    $f = Get-ChildItem "$here\redist" -Filter 'xampp-windows-x64-*-installer.exe' -ErrorAction SilentlyContinue | Sort-Object Name | Select-Object -Last 1
    if ($f) { $XamppInstaller = $f.FullName }
}
if (-not $XamppInstaller -or -not (Test-Path $XamppInstaller)) {
    Write-Host 'Download the XAMPP "Windows x64 installer" (PHP 8.2 or newer) from https://www.apachefriends.org/download.html' -ForegroundColor Yellow
    Write-Host "and save it in: $here\redist\" -ForegroundColor Yellow
    exit 1
}

# App source: the XAMPP-ready folder of this repository
$src = Join-Path $repo 'exam-xampp'
if (-not (Test-Path "$src\index.php")) { throw "Cannot find the app at $src" }
$stage = "$here\build\stage\app"
if (Test-Path "$here\build") { Remove-Item "$here\build" -Recurse -Force }
New-Item -ItemType Directory -Force -Path $stage | Out-Null
robocopy $src $stage /E /XF local-config.php *.sql /XD .git /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copy failed' }
# Make sure the protected folders ship empty but with their .htaccess
foreach ($d in 'uploads', 'backups', 'onlineForm\images\Student_Images') {
    if (Test-Path "$stage\$d") { Get-ChildItem "$stage\$d" -Force | Where-Object { $_.Name -notin '.htaccess', '.gitkeep' } | Remove-Item -Recurse -Force }
}

$iscc = @("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe", "$env:ProgramFiles\Inno Setup 6\ISCC.exe", "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $iscc) {
    Write-Host 'Installing Inno Setup with winget...'
    winget install -e --id JRSoftware.InnoSetup --accept-package-agreements --accept-source-agreements
    $iscc = @("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe", "$env:ProgramFiles\Inno Setup 6\ISCC.exe", "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $iscc) { throw 'Install Inno Setup 6 from https://jrsoftware.org/isdl.php and run this script again.' }
}
& $iscc "/DXamppExe=$XamppInstaller" "/DStageApp=build\stage\app" "$here\installer.iss"
if ($LASTEXITCODE -ne 0) { throw 'Inno Setup failed.' }
Write-Host "Done. Installer: $here\output\" -ForegroundColor Green
