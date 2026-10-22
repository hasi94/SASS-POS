# Stops and removes the print agent service. Run from an elevated PowerShell.
param([string]$Name = 'FlexiPOSPrintAgent')

$ErrorActionPreference = 'Stop'
if (Get-Service $Name -ErrorAction SilentlyContinue) {
    Stop-Service $Name -ErrorAction SilentlyContinue
    sc.exe delete $Name | Out-Null
    Write-Host "Removed service '$Name'."
} else {
    Write-Host "Service '$Name' is not installed."
}
