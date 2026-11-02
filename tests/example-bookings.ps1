$ErrorActionPreference = 'Stop'
$previousEncoding = $OutputEncoding
$OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$testDatabase = 'vflbuchung_test_' + [guid]::NewGuid().ToString('N')
$created = $false
$setup = @'
set -eu
case "$TEST_DB" in vflbuchung_test_*) ;; *) exit 1 ;; esac
case "$TEST_DB:$MYSQL_USER" in *[!a-zA-Z0-9_:]*) exit 1 ;; esac
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot -e "CREATE DATABASE $TEST_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
trap 'mysql -uroot -e "DROP DATABASE IF EXISTS $TEST_DB"' 0
mysql -uroot -e "GRANT ALL PRIVILEGES ON $TEST_DB.* TO '$MYSQL_USER'@'%';"
trap - 0
'@
$cleanup = @'
set -eu
case "$TEST_DB" in vflbuchung_test_*) ;; *) exit 1 ;; esac
case "$TEST_DB:$MYSQL_USER" in *[!a-zA-Z0-9_:]*) exit 1 ;; esac
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot -e "DROP DATABASE IF EXISTS $TEST_DB; REVOKE ALL PRIVILEGES ON $TEST_DB.* FROM '$MYSQL_USER'@'%';"
'@
try {
    $setup | docker compose exec -T -e "TEST_DB=$testDatabase" db sh -c "tr -d '\015' | sh"
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the isolated example data test database.' }
    $created = $true
    $databaseDirectory = Join-Path (Get-Location).Path 'www\database'
    $testDirectory = Join-Path (Get-Location).Path 'tests'
    docker compose run --rm --no-deps -T --volume "${databaseDirectory}:/var/www/app/example-database:ro" --volume "${testDirectory}:/var/www/app/tests:ro" -e "DB_NAME=$testDatabase" web php tests/example-bookings.php
    if ($LASTEXITCODE -ne 0) { throw 'Example booking checks failed.' }
    # Preserve the SQL file's UTF-8 bytes when capturing a native pipeline in Windows PowerShell.
    $encodedSql = [Convert]::ToBase64String([System.IO.File]::ReadAllBytes((Join-Path $databaseDirectory 'seed_example_bookings.sql')))
    $emptyBookings = @'
set -eu
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot "$TEST_DB" -e "DELETE FROM booking_groups;"
'@
    $emptyBookings | docker compose exec -T -e "TEST_DB=$testDatabase" db sh -c "tr -d '\015' | sh"
    if ($LASTEXITCODE -ne 0) { throw 'Could not empty the isolated example data test database.' }
    $summary = $encodedSql |
        docker compose exec -T -e "TEST_DB=$testDatabase" db bash -o pipefail -c 'export MYSQL_PWD=$MYSQL_ROOT_PASSWORD; base64 --decode --ignore-garbage | mysql --default-character-set=utf8mb4 --batch --skip-column-names -uroot $TEST_DB'
    if ($LASTEXITCODE -ne 0 -or ($summary -join "`n").Trim() -ne "437`t437") {
        throw 'Importing the complete SQL file with the MySQL client failed.'
    }
    $encodedSql |
        docker compose exec -T -e "TEST_DB=$testDatabase" db bash -o pipefail -c 'export MYSQL_PWD=$MYSQL_ROOT_PASSWORD; base64 --decode --ignore-garbage | mysql --default-character-set=utf8mb4 --batch --skip-column-names -uroot $TEST_DB'
    if ($LASTEXITCODE -eq 0) { throw 'The manual SQL import accepted an already populated database.' }
    $countBookings = @'
set -eu
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql --batch --skip-column-names -uroot "$TEST_DB" -e "SELECT COUNT(*) FROM booking_occurrences;"
'@
    $count = $countBookings | docker compose exec -T -e "TEST_DB=$testDatabase" db sh -c "tr -d '\015' | sh"
    if ($LASTEXITCODE -ne 0 -or ($count -join "`n").Trim() -ne '437') { throw 'The rejected SQL import changed the booking inventory.' }
}
finally {
    $cleanupExitCode = 0
    if ($created) {
        $cleanup | docker compose exec -T -e "TEST_DB=$testDatabase" db sh -c "tr -d '\015' | sh"
        $cleanupExitCode = $LASTEXITCODE
    }
    $OutputEncoding = $previousEncoding
    if ($cleanupExitCode -ne 0) { throw 'Could not clean up the isolated example data test database.' }
}
