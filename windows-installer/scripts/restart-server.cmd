@echo off
:: Restarts the exam server (run as Administrator).
net stop AkyeritCBTApache
net stop AkyeritCBTDB
net start AkyeritCBTDB
net start AkyeritCBTApache
pause
