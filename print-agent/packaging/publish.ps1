# Builds the agent as one self-contained executable for a till (no .NET install needed).
# Output: print-agent/publish/win-x64/ — gitignored.
param([string]$Runtime = 'win-x64')

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$out = Join-Path $root "publish/$Runtime"

dotnet publish (Join-Path $root 'src/FlexiPOS.PrintAgent') `
    -c Release -r $Runtime --self-contained `
    -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true `
    -o $out
if ($LASTEXITCODE -ne 0) { throw 'dotnet publish failed' }

Write-Host "Published to $out"
