# Called by the uninstaller. Removes the Windows services, firewall rule and scheduled task. Data and XAMPP are left in place.
foreach ($s in 'AkyeritCBTApache', 'AkyeritCBTDB') {
    if (Get-Service $s -ErrorAction SilentlyContinue) {
        Stop-Service $s -Force -ErrorAction SilentlyContinue
        sc.exe delete $s | Out-Null
    }
}
Get-NetFirewallRule -DisplayName 'Akyerit CBT' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
Unregister-ScheduledTask -TaskName 'AkyeritCBT-Finalize' -Confirm:$false -ErrorAction SilentlyContinue
$menu = Join-Path ([Environment]::GetFolderPath('CommonPrograms')) 'Akyerit CBT'
if (Test-Path $menu) { Remove-Item $menu -Recurse -Force }
$d = [Environment]::GetFolderPath('CommonDesktopDirectory') + '\Akyerit CBT Admin.url'
if (Test-Path $d) { Remove-Item $d -Force }
