<#
.SYNOPSIS
  Installs the WedWorks plugin into one Hermes profile and switches it on.

.DESCRIPTION
  What it does, in order:
    1. Copies the plugin (and the shared chat code) into the profile's own plugins folder.
    2. Writes the settings into the profile's .env: WEDWORKS_URL, WEDWORKS_HERMES_TOKEN, WEDWORKS_ALLOW_ALL_USERS.
       The token is asked for hidden (or taken from the WEDWORKS_HERMES_TOKEN environment variable); it is never printed.
    3. Enables the plugin for that profile and gives the WedWorks channel NO tools (platform_toolsets.wedworks = []).
    4. The running Hermes gateway notices the change and connects the plugin by itself: no restart.

  Run it for the profile that talks to suppliers, for example:   .\install.ps1 -Profile wedworks-chat
  Undo it with:                                                  .\uninstall.ps1 -Profile wedworks-chat
#>
param(
    [Parameter(Mandatory = $true)][string]$Profile,
    [string]$Url = 'http://127.0.0.1:8081',
    [string]$Token = $env:WEDWORKS_HERMES_TOKEN,
    [string]$HermesHome = (Join-Path $env:LOCALAPPDATA 'hermes'),
    [switch]$NoHermesCommands   # only copy files and write the .env (used by the tests)
)

$ErrorActionPreference = 'Stop'
$hermes = Join-Path $HermesHome 'bin\hermes.exe'
$profileDir = Join-Path $HermesHome "profiles\$Profile"

if (-not $NoHermesCommands -and -not (Test-Path $hermes)) { throw "Hermes was not found at $hermes" }
if (-not (Test-Path $profileDir)) { throw "The Hermes profile '$Profile' does not exist. Create it first:  hermes profile create $Profile" }
if ($Url -notmatch '^https?://') { throw "The address must start with http:// or https://  (got '$Url')" }

$source = Join-Path $PSScriptRoot 'wedworks'
$shared = Join-Path $PSScriptRoot '..\hermes-shared\wedworks_protocol.py'
$destination = Join-Path $profileDir 'plugins\wedworks'
$envFile = Join-Path $profileDir '.env'

# 1. the plugin files
New-Item -ItemType Directory -Force -Path $destination | Out-Null
Get-ChildItem $source -File | Copy-Item -Destination $destination -Force
Copy-Item $shared -Destination $destination -Force
Write-Host "Copied the plugin to $destination"

# 2. the settings in the profile's .env
function Set-EnvLine([string]$Path, [string]$Key, [string]$Value) {
    $lines = if (Test-Path $Path) { @(Get-Content $Path) } else { @() }
    $found = $false
    $lines = $lines | ForEach-Object { if ($_ -match "^\s*$Key=") { $found = $true; "$Key=$Value" } else { $_ } }
    if (-not $found) { $lines += "$Key=$Value" }
    [IO.File]::WriteAllLines($Path, [string[]]$lines, (New-Object Text.UTF8Encoding $false))
}

function Get-EnvValue([string]$Path, [string]$Key) {
    if (-not (Test-Path $Path)) { return '' }
    $line = Get-Content $Path | Where-Object { $_ -match "^\s*$Key=" } | Select-Object -First 1
    if ($line) { return ($line -replace "^\s*$Key=", '').Trim() } else { return '' }
}

if (-not $Token) {
    $existing = Get-EnvValue $envFile 'WEDWORKS_HERMES_TOKEN'
    $secure = Read-Host -AsSecureString "Hermes token from WedWorks Admin > Settings (press Enter to keep the saved one$(if (-not $existing) { ', none saved yet' }))"
    $Token = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
    if (-not $Token) { $Token = $existing }
}
if (-not $Token) { throw 'No token. Create one in WedWorks Admin > Settings > Hermes agents, then run this again.' }

Set-EnvLine $envFile 'WEDWORKS_URL' $Url.TrimEnd('/')
Set-EnvLine $envFile 'WEDWORKS_HERMES_TOKEN' $Token
Set-EnvLine $envFile 'WEDWORKS_ALLOW_ALL_USERS' 'true'
Write-Host "Saved the settings in $envFile (the token is not shown)"

if ($NoHermesCommands) { Write-Host 'Skipped the Hermes commands (-NoHermesCommands).'; return }

# 3. switch it on, with no tools for this channel
# switch it off first so a plugin that is already running is recreated with the new settings (a running one keeps the old token)
& $hermes -p $Profile config set platforms.wedworks.enabled false
Start-Sleep -Seconds 5
& $hermes -p $Profile plugins enable wedworks
& $hermes -p $Profile config set platforms.wedworks.enabled true
& $hermes -p $Profile config set platform_toolsets.wedworks '[]'

# 4. the running gateway re-scans when the profile's files change; wait a moment and show the result
Start-Sleep -Seconds 8
Write-Host ''
Write-Host 'Tools enabled for the WedWorks channel (there should be none):'
$tools = & $hermes -p $Profile tools list --platform wedworks 2>&1 | Select-String -Pattern 'enabled' | Where-Object { $_ -notmatch 'disabled' }
if ($tools) { $tools | ForEach-Object { Write-Host "  $_" } } else { Write-Host '  none' }
Write-Host ''
Write-Host 'Platform status:'
& $hermes -p $Profile status 2>&1 | Select-String -Pattern 'Platforms' | ForEach-Object { Write-Host "  $_" }
Write-Host ''
Write-Host "Done. In WedWorks Admin > Settings the card should say 'Hermes online via wedworks-plugin' within a minute."
