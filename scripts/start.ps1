param(
  [switch]$Rebuild
)

if ($Rebuild) {
  docker compose down -v
}

docker compose up -d

$pluginsCount = docker exec autodoorexperts_wp sh -lc "ls -1 /var/www/html/wp-content/plugins 2>/dev/null | wc -l"
if ([int]$pluginsCount -eq 0) {
  Write-Host "Seeding plugins volume from local snapshot..."
  docker cp "site/wp-content/plugins/." "autodoorexperts_wp:/var/www/html/wp-content/plugins/"
}

docker compose ps
Write-Host "WordPress: http://localhost:8080"
Write-Host "phpMyAdmin: http://localhost:8081"
