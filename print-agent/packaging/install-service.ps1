# Registers the published agent as a Windows service that starts with the machine.
# Run from an elevated PowerShell. Re-running replaces the existing registration.
param(
    [string]$ExePath = (Join-Path (Split-Path $PSScriptRoot -Parent) 'publish/win-x64/FlexiPOS.PrintAgent.exe'),
    [string]$Name = 'FlexiPOSPrintAgent'
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path $ExePath)) { throw "Not found: $ExePath. Run packaging/publish.ps1 first." }

if (Get-Service $Name -ErrorAction SilentlyContinue) {
    Stop-Service $Name -ErrorAction SilentlyContinue
    sc.exe delete $Name | Out-Null
}

New-Service -Name $Name -BinaryPathName "`"$ExePath`"" -DisplayName 'FlexiPOS Print Agent' `
    -Description 'Local print agent for FlexiPOS tills (loopback only).' -StartupType Automatic | Out-Null
sc.exe failure $Name reset= 86400 actions= restart/5000/restart/5000/restart/30000 | Out-Null
Start-Service $Name
Write-Host "Installed and started service '$Name'."
