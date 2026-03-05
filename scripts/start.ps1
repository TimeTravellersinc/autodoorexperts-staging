param(
  [switch]$Rebuild
)

if ($Rebuild) {
  docker compose down -v
}

docker compose up -d

docker compose ps
Write-Host "WordPress: http://localhost:8080"
Write-Host "phpMyAdmin: http://localhost:8081"
