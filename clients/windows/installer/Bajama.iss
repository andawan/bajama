#define AppName "BAJAMA"
#define AppVersion "1.0.0"
#define AppPublisher "BAJAMA"
#define AppExeName "Bajama.Windows.exe"

[Setup]
AppId={{D3D57132-9472-4AF0-9B3D-8A7F3E57B0A1}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={localappdata}\Programs\BAJAMA
DefaultGroupName=BAJAMA
OutputDir=..\..\..\artifacts
OutputBaseFilename=BAJAMA-Setup-{#AppVersion}-win-x64
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64
ArchitecturesAllowed=x64
PrivilegesRequired=lowest
UninstallDisplayIcon={app}\{#AppExeName}
SetupLogging=yes

[Languages]
Name: "indonesian"; MessagesFile: "compiler:Languages\Indonesian.isl"
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Buat shortcut di Desktop"; GroupDescription: "Shortcut tambahan:"; Flags: unchecked

[Files]
Source: "..\publish\win-x64\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\BAJAMA"; Filename: "{app}\{#AppExeName}"
Name: "{autodesktop}\BAJAMA"; Filename: "{app}\{#AppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#AppExeName}"; Description: "Jalankan BAJAMA"; Flags: postinstall nowait skipifsilent
