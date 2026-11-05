<# : Hamster-Drucker-einrichten.cmd
@echo off
rem DocHamster - sets up the printer "Hamster" on this Windows computer.
rem This file is a Windows command and a PowerShell script at the same time:
rem these first lines start PowerShell, which then runs the rest of this file.
rem Source (MIT licence): clients/windows/Hamster-Drucker-einrichten.cmd in the DocHamster repository.
set "HAMSTER_SELF=%~f0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-Expression ([IO.File]::ReadAllText($env:HAMSTER_SELF))"
echo.
pause
exit /b
#>

# ---------------------------------------------------------------------------
# What this script does, and nothing else:
#  1. Asks Windows once for administrator rights, then
#     - creates the folder C:\ProgramData\DocHamster\Spool,
#     - adds the printer "Hamster". It uses the "Microsoft Print To PDF" driver
#       that comes with Windows (no driver of its own) and writes every print
#       into that folder as a PDF.
#  2. Links this computer to your DocHamster account with the one-time code
#     below. The key it gets back is stored encrypted for your Windows user
#     (Windows DPAPI) in %LOCALAPPDATA%\DocHamster.
#  3. Puts a small background script into your Autostart folder. It sends
#     every print to the "Druckerablage" in DocHamster; offline, it waits and
#     sends later.
#  4. Adds "DocHamster Drucker" to Windows "Apps", which removes all of it.
# The download only fills in the two values below; everything else is the
# file from the repository.
# ---------------------------------------------------------------------------
$ErrorActionPreference = 'Stop'
$ServerUrl = '__DOCHAMSTER_URL__'
$PairingCode = '__PAIRING_CODE__'

$PrinterName = 'Hamster'
$SpoolFile = Join-Path $env:ProgramData 'DocHamster\Spool\hamster.pdf'
$UserDir = Join-Path $env:LOCALAPPDATA 'DocHamster'
$UploaderPath = Join-Path $UserDir 'hamster-uploader.ps1'
$UninstallPath = Join-Path $UserDir 'uninstall.ps1'
$ConfigPath = Join-Path $UserDir 'config.json'
$StartupLink = Join-Path ([Environment]::GetFolderPath('Startup')) 'DocHamster Drucker.lnk'
$UninstallKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\DocHamsterPrinter'
$PowerShellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

function Stop-Setup([string] $message) {
    Write-Host ''
    Write-Host $message -ForegroundColor Red
    exit 1
}

if ($PairingCode -notmatch '^[A-Za-z0-9]+$' -or $ServerUrl -notmatch '^https?://') {
    Stop-Setup 'Bitte diese Datei in DocHamster herunterladen: Druckerablage > Drucker einrichten.'
}

Write-Host 'DocHamster: Drucker "Hamster" einrichten' -ForegroundColor Cyan
Write-Host ''

# --- 1. Printer (needs administrator rights) --------------------------------
$machineScript = @'
$ErrorActionPreference = 'Stop'
try {
    $spoolDir = Join-Path $env:ProgramData 'DocHamster\Spool'
    $port = Join-Path $spoolDir 'hamster.pdf'
    $driver = 'Microsoft Print To PDF'

    New-Item -ItemType Directory -Force -Path $spoolDir | Out-Null
    # Users (S-1-5-32-545) may take the printed files out of the folder.
    & icacls.exe $spoolDir /grant '*S-1-5-32-545:(OI)(CI)M' | Out-Null

    if (-not (Get-PrinterDriver -Name $driver -ErrorAction SilentlyContinue)) {
        Enable-WindowsOptionalFeature -Online -FeatureName 'Printing-PrintToPDFServices-Features' -NoRestart | Out-Null
    }
    if (-not (Get-PrinterPort -Name $port -ErrorAction SilentlyContinue)) {
        Add-PrinterPort -Name $port
    }
    if (-not (Get-Printer -Name 'Hamster' -ErrorAction SilentlyContinue)) {
        Add-Printer -Name 'Hamster' -DriverName $driver -PortName $port
    }
    # Finished jobs stay listed for a moment, so the background script can read the document name.
    Set-Printer -Name 'Hamster' -KeepPrintedJobs $true
    exit 0
} catch {
    Write-Host $_ -ForegroundColor Red
    Start-Sleep -Seconds 10
    exit 1
}
'@

Write-Host '1. Drucker "Hamster" anlegen (Windows fragt nach Administratorrechten) ...'
try {
    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($machineScript))
    $elevated = Start-Process -FilePath $PowerShellExe -Verb RunAs -Wait -PassThru -ArgumentList "-NoProfile -ExecutionPolicy Bypass -EncodedCommand $encoded"
} catch {
    Stop-Setup 'Abgebrochen: ohne Administratorrechte kann Windows keinen Drucker anlegen.'
}
if ($elevated.ExitCode -ne 0) {
    Stop-Setup 'Der Drucker konnte nicht angelegt werden.'
}

# --- 2. Link this computer to the account ------------------------------------
Write-Host '2. Computer mit DocHamster verknüpfen ...'
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
$body = @{ code = $PairingCode; name = $env:COMPUTERNAME } | ConvertTo-Json
try {
    $answer = Invoke-RestMethod -Method Post -Uri "$ServerUrl/api/printer/pair" -ContentType 'application/json; charset=utf-8' `
        -Headers @{ Accept = 'application/json' } -Body ([Text.Encoding]::UTF8.GetBytes($body))
} catch {
    $status = 0
    if ($_.Exception.Response) { $status = [int] $_.Exception.Response.StatusCode }
    if ($status -eq 404) {
        Stop-Setup 'Diese Datei ist schon benutzt oder abgelaufen. Bitte in DocHamster neu herunterladen.'
    }
    Stop-Setup "DocHamster ist nicht erreichbar ($ServerUrl). Bitte Internetverbindung prüfen und die Datei erneut starten."
}

New-Item -ItemType Directory -Force -Path (Join-Path $UserDir 'Outbox') | Out-Null
$secret = ConvertTo-SecureString $answer.token -AsPlainText -Force | ConvertFrom-SecureString
@{ server = $ServerUrl; token = $secret; spool = $SpoolFile; printer = $PrinterName } | ConvertTo-Json |
    Set-Content -LiteralPath $ConfigPath -Encoding UTF8

# --- 3. Background script ------------------------------------------------------
$uploader = @'
# DocHamster: sends what is printed on "Hamster" to the Druckerablage.
# Started with Windows from the Autostart folder; written by Hamster-Drucker-einrichten.cmd.
$ErrorActionPreference = 'Stop'
$UserDir = Join-Path $env:LOCALAPPDATA 'DocHamster'
$Outbox = Join-Path $UserDir 'Outbox'
$Failed = Join-Path $Outbox 'failed'
$LogFile = Join-Path $UserDir 'printer.log'

function Write-Log([string] $message) {
    try {
        if ((Test-Path -LiteralPath $LogFile) -and (Get-Item -LiteralPath $LogFile).Length -gt 1MB) {
            Remove-Item -LiteralPath $LogFile -Force
        }
        Add-Content -LiteralPath $LogFile -Value ((Get-Date -Format 's') + ' ' + $message)
    } catch { }
}

# Only one copy runs per Windows user.
$mutex = New-Object System.Threading.Mutex($false, 'Local\DocHamsterPrinter')
if (-not $mutex.WaitOne(0)) { exit 0 }

$config = Get-Content -LiteralPath (Join-Path $UserDir 'config.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$secure = ConvertTo-SecureString $config.token
$token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
$SpoolFile = $config.spool
$PrinterName = $config.printer

[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Net.Http
$client = New-Object System.Net.Http.HttpClient
$client.Timeout = [TimeSpan]::FromMinutes(5)
$client.DefaultRequestHeaders.Authorization = New-Object System.Net.Http.Headers.AuthenticationHeaderValue('Bearer', $token)
$client.DefaultRequestHeaders.Accept.Add((New-Object System.Net.Http.Headers.MediaTypeWithQualityHeaderValue('application/json')))
New-Item -ItemType Directory -Force -Path $Failed | Out-Null

function Test-Unlocked([string] $path) {
    try {
        $stream = [IO.File]::Open($path, 'Open', 'ReadWrite', 'None')
        $stream.Close()
        return $true
    } catch {
        return $false
    }
}

# The name the program gave the print (e.g. "Rechnung.docx"), from the finished job.
function Get-PrintTitle {
    try {
        $jobs = @(Get-PrintJob -PrinterName $PrinterName -ErrorAction Stop | Sort-Object SubmittedTime -Descending)
    } catch {
        return ''
    }
    $done = @($jobs | Where-Object { "$($_.JobStatus)" -match 'Printed|Retained|Complete' })
    $newest = if ($done.Count -gt 0) { $done[0] } elseif ($jobs.Count -gt 0) { $jobs[0] } else { $null }
    foreach ($job in $done) {
        try { Remove-PrintJob -InputObject $job } catch { }
    }
    if ($null -eq $newest) { return '' }

    return ("$($newest.DocumentName)" -replace '^Microsoft (Word|Excel|PowerPoint) - ', '').Trim()
}

# Sends everything waiting in the outbox; returns the seconds until the next try.
function Send-Outbox {
    foreach ($pdf in @(Get-ChildItem -LiteralPath $Outbox -Filter '*.pdf' -File | Sort-Object Name)) {
        $form = New-Object System.Net.Http.MultipartFormDataContent
        $file = New-Object System.Net.Http.ByteArrayContent -ArgumentList (,[IO.File]::ReadAllBytes($pdf.FullName))
        $file.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/pdf')
        $form.Add($file, 'file', 'druck.pdf')
        $titleFile = [IO.Path]::ChangeExtension($pdf.FullName, '.txt')
        if (Test-Path -LiteralPath $titleFile) {
            $title = [IO.File]::ReadAllText($titleFile, [Text.Encoding]::UTF8)
            $form.Add((New-Object System.Net.Http.StringContent($title, [Text.Encoding]::UTF8)), 'title')
        }

        try {
            $status = [int] $client.PostAsync($config.server + '/api/printer/upload', $form).GetAwaiter().GetResult().StatusCode
        } catch {
            Write-Log ('Offline, will try again: ' + $_.Exception.Message)
            return 60
        } finally {
            $form.Dispose()
        }

        if ($status -eq 201) {
            Remove-Item -LiteralPath $pdf.FullName -Force
            if (Test-Path -LiteralPath $titleFile) { Remove-Item -LiteralPath $titleFile -Force }
            Write-Log ('Sent ' + $pdf.Name)
        } elseif ($status -eq 401 -or $status -eq 403) {
            Write-Log "DocHamster refused this computer ($status): locked or no upload right. Prints wait in the outbox."
            return 600
        } elseif ($status -eq 413 -or $status -eq 422) {
            Write-Log "DocHamster did not take $($pdf.Name) ($status); kept in Outbox\failed."
            Move-Item -LiteralPath $pdf.FullName -Destination $Failed -Force
            if (Test-Path -LiteralPath $titleFile) { Move-Item -LiteralPath $titleFile -Destination $Failed -Force }
        } else {
            Write-Log "DocHamster answered $status, will try again."
            return 60
        }
    }

    return 30
}

Write-Log 'Started.'
$lastSize = -1
$nextSend = Get-Date
while ($true) {
    try {
        if (Test-Path -LiteralPath $SpoolFile) {
            $size = (Get-Item -LiteralPath $SpoolFile).Length
            # Done when the size stays the same for a second and Windows has let go of the file.
            if ($size -gt 0 -and $size -eq $lastSize -and (Test-Unlocked $SpoolFile)) {
                $target = Join-Path $Outbox ((Get-Date -Format 'yyyyMMdd-HHmmss-fff') + '.pdf')
                Move-Item -LiteralPath $SpoolFile -Destination $target
                $title = Get-PrintTitle
                if ($title -ne '') {
                    [IO.File]::WriteAllText([IO.Path]::ChangeExtension($target, '.txt'), $title, [Text.Encoding]::UTF8)
                }
                $lastSize = -1
                $nextSend = Get-Date
            } else {
                $lastSize = $size
            }
        } else {
            $lastSize = -1
        }

        if ((Get-Date) -ge $nextSend) {
            $nextSend = (Get-Date).AddSeconds((Send-Outbox))
        }
    } catch {
        Write-Log ('Error: ' + $_.Exception.Message)
        $nextSend = (Get-Date).AddSeconds(60)
    }
    Start-Sleep -Seconds 1
}
'@

$uninstaller = @'
# DocHamster: removes the printer "Hamster" again (Windows "Apps" > "DocHamster Drucker").
# Written by Hamster-Drucker-einrichten.cmd.
param([switch] $Machine)

if ($Machine) {
    # Runs with administrator rights: printer, its port and the folder.
    $spoolDir = Join-Path $env:ProgramData 'DocHamster\Spool'
    Remove-Printer -Name 'Hamster' -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    Remove-PrinterPort -Name (Join-Path $spoolDir 'hamster.pdf') -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $env:ProgramData 'DocHamster') -Recurse -Force -ErrorAction SilentlyContinue
    exit 0
}

$powerShellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
try {
    Start-Process -FilePath $powerShellExe -Verb RunAs -Wait -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $PSCommandPath + '" -Machine')
} catch {
    exit 1
}

Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" |
    Where-Object { $_.CommandLine -like '*hamster-uploader.ps1*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Remove-Item -LiteralPath (Join-Path ([Environment]::GetFolderPath('Startup')) 'DocHamster Drucker.lnk') -Force -ErrorAction SilentlyContinue
Remove-Item -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\DocHamsterPrinter' -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -LiteralPath (Join-Path $env:LOCALAPPDATA 'DocHamster') -Recurse -Force -ErrorAction SilentlyContinue
'@

Write-Host '3. Hintergrundprogramm einrichten ...'
# With BOM: Windows PowerShell reads a script file without one in the old ANSI code page.
$utf8Bom = New-Object System.Text.UTF8Encoding($true)
[IO.File]::WriteAllText($UploaderPath, $uploader, $utf8Bom)
[IO.File]::WriteAllText($UninstallPath, $uninstaller, $utf8Bom)

# An older copy (set up before) still holds the old key.
Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" |
    Where-Object { $_.CommandLine -like '*hamster-uploader.ps1*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

$uploaderArguments = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $UploaderPath + '"'
$shell = New-Object -ComObject WScript.Shell
$link = $shell.CreateShortcut($StartupLink)
$link.TargetPath = $PowerShellExe
$link.Arguments = $uploaderArguments
$link.WindowStyle = 7
$link.Description = 'DocHamster Drucker'
$link.Save()
Start-Process -FilePath $PowerShellExe -ArgumentList $uploaderArguments -WindowStyle Hidden

# --- 4. Entry under Windows "Apps" ---------------------------------------------
New-Item -Path $UninstallKey -Force | Out-Null
New-ItemProperty -Path $UninstallKey -Name 'DisplayName' -Value 'DocHamster Drucker' -Force | Out-Null
New-ItemProperty -Path $UninstallKey -Name 'Publisher' -Value 'DocHamster' -Force | Out-Null
New-ItemProperty -Path $UninstallKey -Name 'UninstallString' -Value ('"' + $PowerShellExe + '" -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $UninstallPath + '"') -Force | Out-Null
New-ItemProperty -Path $UninstallKey -Name 'NoModify' -Value 1 -PropertyType DWord -Force | Out-Null
New-ItemProperty -Path $UninstallKey -Name 'NoRepair' -Value 1 -PropertyType DWord -Force | Out-Null

Write-Host ''
Write-Host 'Fertig. In jedem Programm gibt es jetzt den Drucker "Hamster".' -ForegroundColor Green
Write-Host 'Was Sie darauf drucken, landet in DocHamster in der Druckerablage.'
