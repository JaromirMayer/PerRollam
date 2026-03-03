param(
  [string]$Version = ""
)

$ErrorActionPreference = "Stop"

# UTF-8 výstup (ať se nerozsypou znaky)
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$OutputEncoding = [Console]::OutputEncoding

# Root projektu (o složku výš než tools/)
$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path

# Update server
$updateBase = "https://updates.solitare.eu/perrollam"
$infoPath   = Join-Path $root "updates\perrollam\info.json"

# Hlavní soubor pluginu (uprav, pokud se jmenuje jinak)
$pluginFile = Join-Path $root "spolek-hlasovani.php"
if (!(Test-Path $pluginFile)) { throw "Nenalezen hlavní soubor pluginu: $pluginFile" }

# ------------------------------------------------------------
# Helpers
# ------------------------------------------------------------
function Get-HeaderValue([string]$text, [string]$key) {
  $pattern = '(?im)^\s*\*\s*' + [regex]::Escape($key) + ':\s*(.+?)\s*$'
  $m = [regex]::Match($text, $pattern)
  if ($m.Success) { return $m.Groups[1].Value.Trim() }
  return ""
}

function Require-Command([string]$cmd, [string]$msg) {
  if (!(Get-Command $cmd -ErrorAction SilentlyContinue)) { throw $msg }
}

# ------------------------------------------------------------
# 0) Verze: param → poslední git tag → header → konstanta
# ------------------------------------------------------------
$content = Get-Content -Path $pluginFile -Raw -Encoding UTF8

if ([string]::IsNullOrWhiteSpace($Version)) {
  # Git tag je preferovaný zdroj
  if (Get-Command git -ErrorAction SilentlyContinue) {
    $tag = (& git describe --tags --abbrev=0 2>$null)
    if ($LASTEXITCODE -eq 0 -and $tag) {
      $Version = $tag.Trim().TrimStart('v')
    }
  }
}

if ([string]::IsNullOrWhiteSpace($Version)) {
  $Version = Get-HeaderValue $content "Version"
}

if ([string]::IsNullOrWhiteSpace($Version)) {
  $m = [regex]::Match($content, "(?im)define\(\s*'SPOLEK_HLASOVANI_VERSION'\s*,\s*'([^']+)'\s*\)\s*;")
  if ($m.Success) { $Version = $m.Groups[1].Value.Trim() }
}

if ([string]::IsNullOrWhiteSpace($Version)) {
  throw "Neznám verzi. Zadej -Version 6.7.7 nebo vytvoř git tag v6.7.7."
}

# ------------------------------------------------------------
# 1) Přepsat verzi v pluginu: header + konstanta
# ------------------------------------------------------------

# Header Version:
if ([regex]::IsMatch($content, "(?im)^\s*\*\s*Version:\s*.+$")) {
  $content = [regex]::Replace($content, "(?im)^\s*\*\s*Version:\s*.+$", " * Version: $Version")
} else {
  throw "V hlavičce pluginu chybí řádek '* Version: ...' (doplň ho prosím)."
}

# Konstanty:
if ([regex]::IsMatch($content, "(?im)define\(\s*'SPOLEK_HLASOVANI_VERSION'\s*,\s*'[^']*'\s*\)\s*;")) {
  $content = [regex]::Replace(
    $content,
    "(?im)define\(\s*'SPOLEK_HLASOVANI_VERSION'\s*,\s*'[^']*'\s*\)\s*;",
    "define('SPOLEK_HLASOVANI_VERSION', '$Version');"
  )
} else {
  throw "Nenalezena konstanta define('SPOLEK_HLASOVANI_VERSION', '...'); ve spolek-hlasovani.php"
}

Set-Content -Path $pluginFile -Value $content -Encoding UTF8

# ------------------------------------------------------------
# 2) Aktualizovat info.json (lokálně)
# ------------------------------------------------------------
$downloadUrl = "$updateBase/perrollam-$Version.zip"

$wpRequires  = Get-HeaderValue $content "Requires at least"
$phpRequires = Get-HeaderValue $content "Requires PHP"

if (!(Test-Path $infoPath)) {
  New-Item -ItemType Directory -Force -Path (Split-Path $infoPath) | Out-Null
  $info = [ordered]@{
    version       = $Version
    download_url  = $downloadUrl
    homepage      = "$updateBase/"
    tested        = ""
    requires      = $wpRequires
    requires_php  = $phpRequires
  }
  ($info | ConvertTo-Json -Depth 10) | Set-Content -Path $infoPath -Encoding UTF8
} else {
  $info = Get-Content -Path $infoPath -Raw -Encoding UTF8 | ConvertFrom-Json
  $info.version      = $Version
  $info.download_url = $downloadUrl
  if (-not $info.homepage) { $info.homepage = "$updateBase/" }
  if (-not [string]::IsNullOrWhiteSpace($wpRequires))  { $info.requires     = $wpRequires }
  if (-not [string]::IsNullOrWhiteSpace($phpRequires)) { $info.requires_php = $phpRequires }
  $info | ConvertTo-Json -Depth 10 | Set-Content -Path $infoPath -Encoding UTF8
}

# ------------------------------------------------------------
# 3) Build ZIP: staging + composer --no-dev
# ------------------------------------------------------------
Require-Command "composer" "Composer není v PATH. Nainstaluj Composer nebo přidej do PATH."
# Git není nutný pro build, jen pro auto-Version

$buildDir = Join-Path $root "build"
$staging  = Join-Path $buildDir "perrollam"
$zipPath  = Join-Path $buildDir ("perrollam-$Version.zip")
$publish  = Join-Path $buildDir "publish"

if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
New-Item -ItemType Directory -Path $staging | Out-Null
New-Item -ItemType Directory -Path $publish | Out-Null

# Kopíruj minimální runtime obsah
Copy-Item (Join-Path $root "includes") -Destination $staging -Recurse -Force
Copy-Item $pluginFile -Destination $staging -Force

$index = Join-Path $root "index.php"
if (Test-Path $index) { Copy-Item $index -Destination $staging -Force }

# composer.* do stagingu (kvůli install), pak odstraníme
$composerJson = Join-Path $root "composer.json"
$composerLock = Join-Path $root "composer.lock"
if (Test-Path $composerJson) { Copy-Item $composerJson -Destination $staging -Force }
if (Test-Path $composerLock) { Copy-Item $composerLock -Destination $staging -Force }

Push-Location $staging
composer install --no-dev --optimize-autoloader
Pop-Location

Remove-Item (Join-Path $staging "composer.json") -ErrorAction SilentlyContinue
Remove-Item (Join-Path $staging "composer.lock") -ErrorAction SilentlyContinue

Compress-Archive -Path $staging -DestinationPath $zipPath -Force

Copy-Item $zipPath -Destination $publish -Force
Copy-Item $infoPath -Destination (Join-Path $publish "info.json") -Force

@"
UPLOAD TO: $updateBase/
FILES:
- perrollam-$Version.zip
- info.json
"@ | Set-Content -Path (Join-Path $publish "UPLOAD.txt") -Encoding UTF8

Write-Host ""
Write-Host "✅ Release build hotový"
Write-Host "Verze:   $Version"
Write-Host "ZIP:     $zipPath"
Write-Host "Publish: $publish"
Write-Host "Uploaduj perrollam-$Version.zip + info.json na: $updateBase/"
Write-Host ""