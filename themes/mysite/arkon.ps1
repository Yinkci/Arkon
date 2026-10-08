param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('validate', 'install')]
    [string]$Action
)

$ErrorActionPreference = 'Stop'
$arkonApplication = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$arkonPhp = if ($env:ARKON_PHP_BINARY) { $env:ARKON_PHP_BINARY } else { Join-Path $env:USERPROFILE '.config\herd\bin\php84\php.exe' }
if (-not (Test-Path -LiteralPath $arkonPhp)) { throw 'PHP was not found. Set ARKON_PHP_BINARY to your PHP executable.' }
if (-not (Test-Path -LiteralPath (Join-Path $arkonApplication 'artisan'))) { throw 'Arkon was not found. Keep this theme under ArkonLaravel\themes\mysite.' }

Push-Location -LiteralPath $arkonApplication
try {
    & $arkonPhp artisan arkon:theme $Action $PSScriptRoot
    if ($LASTEXITCODE -ne 0) { throw "Arkon theme $Action failed." }
}
finally { Pop-Location }
