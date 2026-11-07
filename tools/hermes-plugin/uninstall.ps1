<#
.SYNOPSIS
  Removes the WedWorks plugin from one Hermes profile (the opposite of install.ps1).
#>
param(
    [Parameter(Mandatory = $true)][string]$Profile,
    [string]$HermesHome = (Join-Path $env:LOCALAPPDATA 'hermes'),
    [switch]$NoHermesCommands
)

$ErrorActionPreference = 'Stop'
$hermes = Join-Path $HermesHome 'bin\hermes.exe'
$profileDir = Join-Path $HermesHome "profiles\$Profile"
if (-not (Test-Path $profileDir)) { throw "The Hermes profile '$Profile' does not exist." }

if (-not $NoHermesCommands) {
    & $hermes -p $Profile config set platforms.wedworks.enabled false
    & $hermes -p $Profile plugins disable wedworks
}

# the settings (a rewrite of the .env also makes the gateway re-scan and let go of the plugin)
$envFile = Join-Path $profileDir '.env'
if (Test-Path $envFile) {
    $kept = Get-Content $envFile | Where-Object { $_ -notmatch '^\s*WEDWORKS_(URL|HERMES_TOKEN|WAIT|ALLOW_ALL_USERS)=' }
    [IO.File]::WriteAllLines($envFile, [string[]]@($kept), (New-Object Text.UTF8Encoding $false))
    Write-Host 'Removed the WedWorks settings from the profile .env'
}

if (-not $NoHermesCommands) { Start-Sleep -Seconds 8 }
Remove-Item (Join-Path $profileDir 'plugins\wedworks') -Recurse -Force -ErrorAction SilentlyContinue
Write-Host 'Removed the plugin folder. The WedWorks website now uses the fixed chat (or the bridge) again.'
