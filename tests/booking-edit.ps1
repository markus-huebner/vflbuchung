$ErrorActionPreference = "Stop"
$previousEncoding = $OutputEncoding
$OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$testDatabase = "vflbuchung_test_" + [guid]::NewGuid().ToString("N")
$created = $false

$setup = @'
set -eu
case "$TEST_DB" in vflbuchung_test_*) ;; *) exit 1 ;; esac
case "$TEST_DB:$MYSQL_USER" in *[!a-zA-Z0-9_:]*) exit 1 ;; esac
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql --user=root --execute="CREATE DATABASE $TEST_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
trap 'mysql --user=root --execute="DROP DATABASE IF EXISTS $TEST_DB"' 0
mysql --user=root --execute="GRANT ALL PRIVILEGES ON $TEST_DB.* TO '$MYSQL_USER'@'%';"
trap - 0
'@
$cleanup = @'
set -eu
case "$TEST_DB" in vflbuchung_test_*) ;; *) exit 1 ;; esac
case "$TEST_DB:$MYSQL_USER" in *[!a-zA-Z0-9_:]*) exit 1 ;; esac
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql --user=root --force --execute="DROP DATABASE IF EXISTS $TEST_DB; REVOKE ALL PRIVILEGES ON $TEST_DB.* FROM '$MYSQL_USER'@'%';"
'@

try {
    $setup | docker compose exec -T -e "TEST_DB=$testDatabase" db sh -c "tr -d '\015' | sh"
    if ($LASTEXITCODE -ne 0) { throw "Could not create the isolated booking test database." }
    $created = $true
    Get-Content database\install.sql -Raw -Encoding UTF8 |
        docker compose exec -T -e "TEST_DB=$testDatabase" db sh -c 'export MYSQL_PWD=$MYSQL_ROOT_PASSWORD; exec mysql -uroot $TEST_DB'
    if ($LASTEXITCODE -ne 0) { throw "Could not install the isolated test schema." }
    Get-Content tests\booking-edit.php -Raw -Encoding UTF8 |
        docker compose run --rm --no-deps -T -e "DB_NAME=$testDatabase" web php
    if ($LASTEXITCODE -ne 0) { throw "Booking editing checks failed." }
} finally {
    $cleanupExitCode = 0
    if ($created) {
        $cleanup | docker compose exec -T -e "TEST_DB=$testDatabase" db sh -c "tr -d '\015' | sh"
        $cleanupExitCode = $LASTEXITCODE
    }
    $OutputEncoding = $previousEncoding
    if ($cleanupExitCode -ne 0) { throw "Could not clean up the isolated booking test database." }
}
