$ErrorActionPreference = 'Stop'
$database = 'vflbuchung_email_links_' + [guid]::NewGuid().ToString('N')
$configurationDirectory = Join-Path ([System.IO.Path]::GetTempPath()) $database
[System.IO.Directory]::CreateDirectory($configurationDirectory) | Out-Null
$created = $false
$prepare = @'
set -eu
case "$TEST_DB" in vflbuchung_email_links_*) ;; *) exit 1 ;; esac
case "$TEST_DB:$MYSQL_USER" in *[!a-zA-Z0-9_:]*) exit 1 ;; esac
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot -e "CREATE DATABASE $TEST_DB;"
trap 'mysql -uroot -e "DROP DATABASE IF EXISTS $TEST_DB"' 0
mysql -uroot -e "GRANT ALL PRIVILEGES ON $TEST_DB.* TO '$MYSQL_USER'@'%';"
trap - 0
'@
$cleanup = @'
set -eu
case "$TEST_DB" in vflbuchung_email_links_*) ;; *) exit 1 ;; esac
case "$TEST_DB:$MYSQL_USER" in *[!a-zA-Z0-9_:]*) exit 1 ;; esac
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot -e "REVOKE ALL PRIVILEGES ON $TEST_DB.* FROM '$MYSQL_USER'@'%'; DROP DATABASE $TEST_DB;"
'@
try {
    $prepare | docker compose exec -T -e "TEST_DB=$database" db sh -c "tr -d '\015' | sh"
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the isolated email link test database.' }
    $created = $true
    $testDirectory = Join-Path (Get-Location).Path 'tests'
    docker compose run --rm --no-deps -T --volume "${testDirectory}:/var/www/app/tests:ro" --volume "${configurationDirectory}:/var/www/app/www/config:ro" --volume "${configurationDirectory}:/var/www/app/www/storage:ro" -e "DB_NAME=$database" web php tests/email-links.php
    if ($LASTEXITCODE -ne 0) { throw 'The recipient-bound email link tests failed.' }
} finally {
    if ($created) {
        $cleanup | docker compose exec -T -e "TEST_DB=$database" db sh -c "tr -d '\015' | sh"
        if ($LASTEXITCODE -ne 0) { Write-Warning 'Could not remove the isolated email link test database.' }
    }
    Remove-Item -LiteralPath $configurationDirectory
}
