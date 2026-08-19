# Saxane Real Estate MS - Vendor asset downloader
#
# The app ships with every third-party asset already vendored in this folder,
# so it runs fully OFFLINE with no CDN. You only need this script to restore
# the files if they are ever deleted, or to bump a version below.
#
# Usage (PowerShell):
#   powershell -ExecutionPolicy Bypass -File "<path to this file>"
# Or just double-click download-vendor.bat next to it.

$ErrorActionPreference = 'Stop'
$ProgressPreference     = 'SilentlyContinue'

$root = $PSScriptRoot
$bi   = Join-Path $root 'bootstrap-icons'
$biF  = Join-Path $bi   'fonts'
$cj   = Join-Path $root 'chartjs'
$it   = Join-Path $root 'inter'
$itF  = Join-Path $it   'fonts'
$fi   = Join-Path $root 'flag-icons'

New-Item -ItemType Directory -Force -Path $biF, $cj, $itF, $fi | Out-Null

# --- Pinned versions -------------------------------------------------------
$biVersion = '1.11.3'
$cjVersion = '4.4.1'
$fiVersion = '7.5.0'
$biBase    = "https://cdn.jsdelivr.net/npm/bootstrap-icons@$biVersion/font"

$files = @(
    @{ Url = "$biBase/bootstrap-icons.min.css";    Out = (Join-Path $bi  'bootstrap-icons.min.css') },
    @{ Url = "$biBase/fonts/bootstrap-icons.woff2"; Out = (Join-Path $biF 'bootstrap-icons.woff2') },
    @{ Url = "$biBase/fonts/bootstrap-icons.woff";  Out = (Join-Path $biF 'bootstrap-icons.woff') },
    @{ Url = "https://cdn.jsdelivr.net/npm/chart.js@$cjVersion/dist/chart.umd.min.js"; Out = (Join-Path $cj 'chart.umd.min.js') }
)

# The country flags for the phone field. Only the countries phoneCountries()
# offers are vendored - the full package is ~260 flags and the app shows 18.
$fiBase   = "https://cdn.jsdelivr.net/npm/flag-icons@$fiVersion"
$fiCodes  = @('so','ke','et','dj','ug','tz','ae','sa','qa','tr','eg','gb','us','ca','se','no','nl','de')
foreach ($code in $fiCodes) {
    $files += @{ Url = "$fiBase/flags/4x3/$code.svg"; Out = (Join-Path $fi "$code.svg") }
}
$files += @{ Url = "$fiBase/LICENSE"; Out = (Join-Path $fi 'LICENSE') }

foreach ($f in $files) {
    Write-Host "Downloading $($f.Url) ..." -ForegroundColor Cyan
    Invoke-WebRequest -Uri $f.Url -OutFile $f.Out -UseBasicParsing -TimeoutSec 60
    Write-Host ("  -> {0} ({1:N0} bytes)" -f (Split-Path $f.Out -Leaf), (Get-Item $f.Out).Length) -ForegroundColor Green
}

# --- Inter (self-hosted from Google Fonts) ---------------------------------
# Google serves Inter as a single variable woff2 per subset. We keep latin and
# latin-ext and rebuild inter.css with local, relative font URLs.
Write-Host "Downloading Inter font faces ..." -ForegroundColor Cyan

$ua      = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
$gfUrl   = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700&display=swap'
$gfCss   = (Invoke-WebRequest -Uri $gfUrl -UserAgent $ua -UseBasicParsing -TimeoutSec 60).Content
$faceRx  = [regex]'/\*\s*(?<sub>[a-z\-]+)\s*\*/\s*(?<block>@font-face\s*\{[^}]*\})'
$subsets = @('latin', 'latin-ext')

$lines = New-Object System.Collections.Generic.List[string]
$lines.Add('/* Inter - self-hosted variable font (Google Fonts, SIL Open Font License 1.1)')
$lines.Add('   Subsets: latin, latin-ext. No CDN required - fully offline. */')
$lines.Add('')

$done = @{}
foreach ($m in $faceRx.Matches($gfCss)) {
    $sub = $m.Groups['sub'].Value
    if ($subsets -notcontains $sub -or $done[$sub]) { continue }
    $done[$sub] = $true

    $block = $m.Groups['block'].Value
    $src   = [regex]::Match($block, 'url\((https://[^)]+)\)').Groups[1].Value
    $range = [regex]::Match($block, 'unicode-range:\s*([^;]+);').Groups[1].Value
    $file  = Join-Path $itF "inter-$sub.woff2"

    Invoke-WebRequest -Uri $src -OutFile $file -UseBasicParsing -TimeoutSec 60
    Write-Host ("  -> inter-$sub.woff2 ({0:N0} bytes)" -f (Get-Item $file).Length) -ForegroundColor Green

    $lines.Add('/* ' + $sub + ' */')
    $lines.Add('@font-face {')
    $lines.Add("  font-family: 'Inter';")
    $lines.Add('  font-style: normal;')
    $lines.Add('  font-weight: 100 900;')
    $lines.Add('  font-display: swap;')
    $lines.Add("  src: url('./fonts/inter-$sub.woff2') format('woff2');")
    $lines.Add("  unicode-range: $range;")
    $lines.Add('}')
    $lines.Add('')
}

# UTF-8 without BOM - a BOM can break the first rule of an @import-ed stylesheet.
[System.IO.File]::WriteAllText(
    (Join-Path $it 'inter.css'),
    ($lines -join "`r`n"),
    (New-Object System.Text.UTF8Encoding($false))
)

Write-Host ""
Write-Host "Done. All vendor assets are local - the app works fully offline." -ForegroundColor Green
