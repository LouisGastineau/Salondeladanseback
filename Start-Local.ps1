param([switch]$DatabaseOnly)
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$state = Join-Path $root '.local'
$php = Join-Path $root '.tools\php\php.exe'
$database = Join-Path $root '.tools\mariadb\bin\mariadbd.exe'
$config = Join-Path $state 'my.ini'
if (!(Test-Path -LiteralPath $php) -or !(Test-Path -LiteralPath $config)) {
    throw 'Le runtime portable local est absent. Voir Readme.md pour une installation standard PHP/Composer/MariaDB.'
}

function Test-LocalPort([int]$Port) {
    $client = [Net.Sockets.TcpClient]::new()
    try { $client.Connect('127.0.0.1', $Port); return $true }
    catch { return $false }
    finally { $client.Dispose() }
}

if (!(Test-LocalPort 3307)) {
    $process = Start-Process -FilePath $database -ArgumentList "--defaults-file=`"$config`"" -WorkingDirectory $root -WindowStyle Hidden -PassThru
    Set-Content -LiteralPath (Join-Path $state 'database-process.pid') -Value $process.Id
    for ($i = 0; $i -lt 50 -and !(Test-LocalPort 3307); $i++) { Start-Sleep -Milliseconds 200 }
    if (!(Test-LocalPort 3307)) { throw 'MariaDB ne démarre pas. Consulter .local/mariadb.log.' }
}
Write-Output 'MariaDB : 127.0.0.1:3307'
if ($DatabaseOnly) { return }

if (!(Test-LocalPort 8000)) {
    $router = Join-Path $root 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
    $process = Start-Process -FilePath $php -ArgumentList @('-S', '127.0.0.1:8000', "`"$router`"") -WorkingDirectory (Join-Path $root 'public') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $state 'http.stdout.log') -RedirectStandardError (Join-Path $state 'http.stderr.log')
    Set-Content -LiteralPath (Join-Path $state 'http-process.pid') -Value $process.Id
    for ($i = 0; $i -lt 30 -and !(Test-LocalPort 8000); $i++) { Start-Sleep -Milliseconds 200 }
    if (!(Test-LocalPort 8000)) { throw 'Le serveur PHP ne démarre pas. Consulter .local/http.stderr.log.' }
}
Write-Output 'API : http://127.0.0.1:8000/api'
