$ErrorActionPreference = 'Stop'

$database = 'vflbuchung_setup_test'
$prepare = @'
set -eu
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot -e "DROP DATABASE IF EXISTS vflbuchung_setup_test; CREATE DATABASE vflbuchung_setup_test;"
mysql -uroot -e "GRANT ALL PRIVILEGES ON vflbuchung_setup_test.* TO '$MYSQL_USER'@'%';"
'@
$cleanup = @'
set -eu
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot -e "REVOKE ALL PRIVILEGES ON vflbuchung_setup_test.* FROM '$MYSQL_USER'@'%'; DROP DATABASE IF EXISTS vflbuchung_setup_test;"
'@

try {
    $prepare | docker compose exec -T db sh -c "tr -d '\015' | sh"
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare the isolated database setup test.' }

    $testDirectory = Join-Path (Get-Location).Path 'tests'
    docker compose run --rm --no-deps -T --volume "${testDirectory}:/var/www/app/tests:ro" -e "DB_NAME=$database" web php tests/database-setup.php
    if ($LASTEXITCODE -ne 0) { throw 'The isolated database setup test failed.' }
}
finally {
    $cleanup | docker compose exec -T db sh -c "tr -d '\015' | sh"
    if ($LASTEXITCODE -ne 0) { Write-Warning 'Could not remove the isolated database setup test database.' }
}
