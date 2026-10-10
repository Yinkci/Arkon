param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('validate', 'install')]
    [string]$Action
)

$ErrorActionPreference = 'Stop'
$arkonApplication = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
# PHP: ARKON_PHP_BINARY if set, else Laravel Herd's PHP 8.4 if installed, else php on PATH.
$herdPhp = Join-Path $env:USERPROFILE '.config\herd\bin\php84\php.exe'
$arkonPhp = if ($env:ARKON_PHP_BINARY) { $env:ARKON_PHP_BINARY } elseif (Test-Path -LiteralPath $herdPhp) { $herdPhp } else { (Get-Command php -ErrorAction SilentlyContinue).Source }
if (-not $arkonPhp -or -not (Test-Path -LiteralPath $arkonPhp)) { throw 'PHP was not found. Install PHP 8.3+ or set ARKON_PHP_BINARY to your PHP executable.' }
if (-not (Test-Path -LiteralPath (Join-Path $arkonApplication 'artisan'))) { throw 'Arkon was not found. Keep this theme under ArkonLaravel\themes\mysite.' }

Push-Location -LiteralPath $arkonApplication
try {
    & $arkonPhp artisan arkon:theme $Action $PSScriptRoot
    if ($LASTEXITCODE -ne 0) { throw "Arkon theme $Action failed." }
}
finally { Pop-Location }
