param(
  [string]$Version = ""
)

$ErrorActionPreference = "Stop"

# UTF-8 bez BOM (důležité pro JSON a parsování)
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = $Utf8NoBom
$OutputEncoding = $Utf8NoBom

# Root projektu (o složku výš než tools/)
$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path

# Update server
$updateBase = "https://updates.solitare.eu/perrollam"
$infoPath   = Join-Path $root "updates\perrollam\info.json"

# Hlavní soubor pluginu
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

function Write-TextNoBom([string]$path, [string]$text) {
  $dir = Split-Path $path
  if ($dir -and !(Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
  [System.IO.File]::WriteAllText($path, $text, $script:Utf8NoBom)
}

function Read-Text([string]$path) {
  return [System.IO.File]::ReadAllText($path, $script:Utf8NoBom)
}

# ------------------------------------------------------------
# 0) Verze: param → poslední git tag → header → konstanta
# ------------------------------------------------------------
$content = [System.IO.File]::ReadAllText($pluginFile, $Utf8NoBom)

if ([string]::IsNullOrWhiteSpace($Version)) {
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
if ([regex]::IsMatch($content, "(?im)^\s*\*\s*Version:\s*.+$")) {
  $content = [regex]::Replace($content, "(?im)^\s*\*\s*Version:\s*.+$", " * Version: $Version")
} else {
  throw "V hlavičce pluginu chybí řádek '* Version: ...' (doplň ho prosím)."
}

if ([regex]::IsMatch($content, "(?im)define\(\s*'SPOLEK_HLASOVANI_VERSION'\s*,\s*'[^']*'\s*\)\s*;")) {
  $content = [regex]::Replace(
    $content,
    "(?im)define\(\s*'SPOLEK_HLASOVANI_VERSION'\s*,\s*'[^']*'\s*\)\s*;",
    "define('SPOLEK_HLASOVANI_VERSION', '$Version');"
  )
} else {
  throw "Nenalezena konstanta define('SPOLEK_HLASOVANI_VERSION', '...'); ve spolek-hlasovani.php"
}

Write-TextNoBom $pluginFile $content

# ------------------------------------------------------------
# 2) Aktualizovat info.json (bez BOM)
# ------------------------------------------------------------
$downloadUrl = "$updateBase/perrollam-$Version.zip"

$wpRequires  = Get-HeaderValue $content "Requires at least"
$phpRequires = Get-HeaderValue $content "Requires PHP"

# načti nebo založ info.json
if (!(Test-Path $infoPath)) {
  $infoObj = [ordered]@{
    version       = $Version
    download_url  = $downloadUrl
    homepage      = "$updateBase/"
    tested        = ""
    requires      = $wpRequires
    requires_php  = $phpRequires
  }
} else {
  # načítáme tolerantně (kdyby tam byl BOM nebo jiné kódování)
  $raw = Get-Content -Path $infoPath -Raw
  $raw = $raw -replace "^\uFEFF", ""  # strip BOM
  $infoObj = $raw | ConvertFrom-Json

  $infoObj.version      = $Version
  $infoObj.download_url = $downloadUrl
  if (-not $infoObj.homepage) { $infoObj.homepage = "$updateBase/" }
  if (-not [string]::IsNullOrWhiteSpace($wpRequires))  { $infoObj.requires     = $wpRequires }
  if (-not [string]::IsNullOrWhiteSpace($phpRequires)) { $infoObj.requires_php = $phpRequires }
}

$json = $infoObj | ConvertTo-Json -Depth 10
Write-TextNoBom $infoPath $json

# ------------------------------------------------------------
# 3) Build ZIP: staging + composer --no-dev
# ------------------------------------------------------------
Require-Command "composer" "Composer není v PATH. Nainstaluj Composer nebo přidej do PATH."

$buildDir = Join-Path $root "build"
$staging  = Join-Path $buildDir "perrollam"
$zipPath  = Join-Path $buildDir ("perrollam-$Version.zip")
$publish  = Join-Path $buildDir "publish"

if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
New-Item -ItemType Directory -Path $staging | Out-Null
New-Item -ItemType Directory -Path $publish | Out-Null

# Minimální runtime obsah
Copy-Item (Join-Path $root "includes") -Destination $staging -Recurse -Force
Copy-Item $pluginFile -Destination $staging -Force

$index = Join-Path $root "index.php"
if (Test-Path $index) { Copy-Item $index -Destination $staging -Force }

# composer.* do stagingu kvůli install
$composerJson = Join-Path $root "composer.json"
$composerLock = Join-Path $root "composer.lock"
if (Test-Path $composerJson) { Copy-Item $composerJson -Destination $staging -Force }
if (Test-Path $composerLock) { Copy-Item $composerLock -Destination $staging -Force }

Push-Location $staging
composer install --no-dev --optimize-autoloader
Pop-Location

# composer.* pryč (není potřeba v pluginu)
Remove-Item (Join-Path $staging "composer.json") -ErrorAction SilentlyContinue
Remove-Item (Join-Path $staging "composer.lock") -ErrorAction SilentlyContinue

# Zip musí obsahovat kořen perrollam/
Compress-Archive -Path $staging -DestinationPath $zipPath -Force

# Publish = připraveno k uploadu na updates.solitare.eu/perrollam
Copy-Item $zipPath -Destination $publish -Force
Copy-Item $infoPath -Destination (Join-Path $publish "info.json") -Force

$uploadTxt = @"
UPLOAD TO: $updateBase/
FILES:
- perrollam-$Version.zip
- info.json
"@
Write-TextNoBom (Join-Path $publish "UPLOAD.txt") $uploadTxt

Write-Host ""
Write-Host "✅ Release build hotový"
Write-Host "Verze:   $Version"
Write-Host "ZIP:     $zipPath"
Write-Host "Publish: $publish"
Write-Host "Uploaduj perrollam-$Version.zip + info.json na: $updateBase/"
Write-Host ""