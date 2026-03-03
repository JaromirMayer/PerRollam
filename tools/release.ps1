param(
  [string]$Version = ""
)

$ErrorActionPreference = "Stop"
$root = (Resolve-Path ".").Path

# Zkusíme si verzi vytáhnout z hlavičky pluginu, pokud není předaná
if ([string]::IsNullOrWhiteSpace($Version)) {
  $mainFile = Join-Path $root "spolek-hlasovani.php"
  if (Test-Path $mainFile) {
    $m = Select-String -Path $mainFile -Pattern '^\s*\*\s*Version:\s*(.+)\s*$' -CaseSensitive:$false
    if ($m) { $Version = $m.Matches[0].Groups[1].Value.Trim() }
  }
}
if ([string]::IsNullOrWhiteSpace($Version)) {
  throw "Neznám verzi. Buď ji zadej: -Version 6.7.3 nebo doplň Version: do hlavičky pluginu."
}

$buildDir = Join-Path $root "build"
$staging  = Join-Path $buildDir "perrollam"
$zipPath  = Join-Path $buildDir ("perrollam-$Version.zip")

# 1) Vyčistit build
if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
New-Item -ItemType Directory -Path $staging | Out-Null

# 2) Zkopírovat zdrojáky bez balastu a bez vendor
$excludeDirs = @(
  ".git", ".github", ".phpdoc", "docs", "node_modules", "nbproject",
  "build", "tests", "Backup", "postupy", "tools", "updates", "vendor"
)

# Robocopy umí krásně /XD vyřadit celé adresáře
$xd = @()
foreach ($d in $excludeDirs) { $xd += (Join-Path $root $d) }

robocopy $root $staging /MIR `
  /XD $xd `
  /XF "*.zip" "phpdoc.xml" "phpdoc.dist.xml" ".DS_Store" "Thumbs.db" `
  /NFL /NDL /NJH /NJS /NC /NS /NP | Out-Null

# 3) V stagingu doinstalovat jen produkční závislosti
Push-Location $staging
if (!(Get-Command composer -ErrorAction SilentlyContinue)) {
  throw "Composer není v PATH. Nainstaluj Composer nebo přidej do PATH."
}
composer install --no-dev --optimize-autoloader
Pop-Location

# 4) Zabalit ZIP tak, aby uvnitř byla kořenová složka perrollam/
Compress-Archive -Path $staging -DestinationPath $zipPath -Force

Write-Host "Hotovo: $zipPath"