$ErrorActionPreference = 'Stop'
$state = Join-Path $PSScriptRoot '.local'
$pidFile = Join-Path $state 'http-process.pid'
if (Test-Path -LiteralPath $pidFile) {
    $process = Get-Process -Id ([int](Get-Content -LiteralPath $pidFile)) -ErrorAction SilentlyContinue
    $expected = Join-Path $PSScriptRoot '.tools\php\php.exe'
    if ($process -and $process.Path -eq $expected) { Stop-Process -Id $process.Id }
}
$dbPidFile = Join-Path $state 'database-process.pid'
if (Test-Path -LiteralPath $dbPidFile) {
    $process = Get-Process -Id ([int](Get-Content -LiteralPath $dbPidFile)) -ErrorAction SilentlyContinue
    $expected = Join-Path $PSScriptRoot '.tools\mariadb\bin\mariadbd.exe'
    if ($process -and $process.Path -eq $expected) {
        $client = Join-Path $PSScriptRoot '.tools\mariadb\bin\mariadb-admin.exe'
        $options = Join-Path $state 'admin.cnf'
        & $client "--defaults-extra-file=$options" shutdown
        if ($LASTEXITCODE -ne 0) { throw 'Arrêt MariaDB refusé.' }
    }
}
Write-Output 'Serveurs locaux arrêtés.'
