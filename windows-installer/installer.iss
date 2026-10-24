; Akyerit CBT Windows installer (Inno Setup 6.3 or newer). Built by build.ps1, which passes /DXamppExe and /DStageApp.
#ifndef XamppExe
  #error Pass /DXamppExe=<path to the XAMPP installer>. Use build.ps1.
#endif
#ifndef StageApp
  #define StageApp "build\stage\app"
#endif
#define AppName "Akyerit CBT"
#define AppVersion "4.3"

[Setup]
AppId={{B3C1A5D0-6E3F-4C44-9A1B-7E2D5C0A4F10}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=Akyerit
DefaultDirName=C:\AkyeritCBT
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir=output
OutputBaseFilename=Akyerit-CBT-Setup-{#AppVersion}
Compression=lzma2/fast
SolidCompression=no
WizardStyle=modern
UninstallDisplayName={#AppName}
CloseApplications=no

[Files]
Source: "{#StageApp}\*"; DestDir: "{app}\app"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "{#XamppExe}"; DestDir: "{app}\redist"; Flags: deleteafterinstall
Source: "scripts\post-install.ps1"; DestDir: "{app}\scripts"; Flags: ignoreversion
Source: "scripts\uninstall-services.ps1"; DestDir: "{app}\scripts"; Flags: ignoreversion
Source: "scripts\restart-server.cmd"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{commonprograms}\Akyerit CBT\Restart exam server"; Filename: "{app}\restart-server.cmd"

[Run]
Filename: "{app}\access-info.txt"; Description: "Show how to open the exam system"; Flags: postinstall shellexec skipifsilent

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\scripts\uninstall-services.ps1"""; Flags: runhidden waituntilterminated; RunOnceId: "RemoveServices"

[Code]
var
  CfgPage: TInputQueryWizardPage;
  IsUpgrade: Boolean;

function InitializeSetup(): Boolean;
begin
  IsUpgrade := FileExists('C:\xampp\htdocs\exam\includes\local-config.php');
  Result := True;
end;

procedure InitializeWizard;
begin
  CfgPage := CreateInputQueryPage(wpWelcome, 'Exam server settings', 'Create the administrator account',
    'This account signs in on the Admin page. Choose a password of at least 10 characters. Setup then installs the web server and database; this takes several minutes.');
  CfgPage.Add('Administrator username (letters, digits . _ -):', False);
  CfgPage.Add('Password (at least 10 characters):', True);
  CfgPage.Add('Confirm password:', True);
  CfgPage.Add('Web port (80 is best; setup uses 8080 if 80 is busy):', False);
  CfgPage.Add('Time zone (for example Africa/Lagos):', False);
  CfgPage.Values[0] := 'admin';
  CfgPage.Values[3] := '80';
  CfgPage.Values[4] := 'Africa/Lagos';
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := IsUpgrade and (PageID = CfgPage.ID);
end;

function ValidUser(const S: String): Boolean;
var
  I: Integer;
  C: Char;
begin
  Result := (Length(S) >= 3) and (Length(S) <= 50);
  for I := 1 to Length(S) do
  begin
    C := S[I];
    if not (((C >= 'a') and (C <= 'z')) or ((C >= 'A') and (C <= 'Z')) or ((C >= '0') and (C <= '9')) or (C = '.') or (C = '_') or (C = '-')) then
      Result := False;
  end;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  P: Integer;
begin
  Result := True;
  if (CurPageID = CfgPage.ID) and not IsUpgrade then
  begin
    if not ValidUser(CfgPage.Values[0]) then
    begin
      MsgBox('The username must be 3-50 characters: letters, digits, dot, underscore or dash.', mbError, MB_OK);
      Result := False;
    end else if Length(CfgPage.Values[1]) < 10 then
    begin
      MsgBox('The password must be at least 10 characters.', mbError, MB_OK);
      Result := False;
    end else if CfgPage.Values[1] <> CfgPage.Values[2] then
    begin
      MsgBox('The two passwords are not the same.', mbError, MB_OK);
      Result := False;
    end else
    begin
      P := StrToIntDef(CfgPage.Values[3], 0);
      if (P < 1) or (P > 65535) then
      begin
        MsgBox('Enter a port number between 1 and 65535 (normally 80).', mbError, MB_OK);
        Result := False;
      end;
    end;
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Cfg, Params: String;
  Code: Integer;
begin
  if CurStep = ssPostInstall then
  begin
    Cfg := '';
    if not IsUpgrade then
      Cfg := 'admin_user=' + CfgPage.Values[0] + #13#10 + 'admin_pass=' + CfgPage.Values[1] + #13#10 +
             'port=' + CfgPage.Values[3] + #13#10 + 'tz=' + CfgPage.Values[4] + #13#10;
    SaveStringToFile(ExpandConstant('{tmp}\cbt-setup.cfg'), Cfg, False);
    WizardForm.StatusLabel.Caption := 'Installing the web server and database (several minutes)...';
    Params := '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\scripts\post-install.ps1') + '" -AppDir "' +
              ExpandConstant('{app}') + '" -CfgFile "' + ExpandConstant('{tmp}\cbt-setup.cfg') + '"';
    if not Exec('powershell.exe', Params, '', SW_HIDE, ewWaitUntilTerminated, Code) or (Code <> 0) then
      MsgBox('Setup copied the files, but configuring the server did not finish.' + #13#10#13#10 +
             'Please open this log and send it to support:' + #13#10 + ExpandConstant('{app}\install.log'), mbError, MB_OK);
  end;
end;
