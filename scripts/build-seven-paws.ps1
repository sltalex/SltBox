param([switch]$UseMirror)

$ErrorActionPreference = 'Stop'
$repository = Split-Path -Parent $PSScriptRoot
$source = Join-Path $repository 'modules\SevenPaws'
$assets = Join-Path $repository 'src\DeskBox\Assets\SevenPaws'
# Vite treats # in filesystem paths as URL fragments. Build a source snapshot in a plain path.
$staging = Join-Path ([IO.Path]::GetTempPath()) ('deskbox-sevenpaws-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $staging | Out-Null
robocopy $source $staging /E /XD node_modules dist .git target gen /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -gt 7) { throw "7paws staging failed: $LASTEXITCODE" }
$pnpm = (Get-Command pnpm -ErrorAction Stop).Source
Push-Location $staging
try {
    & $pnpm install --frozen-lockfile
    if ($LASTEXITCODE -ne 0) { throw '7paws dependency installation failed.' }
    & $pnpm build
    if ($LASTEXITCODE -ne 0) { throw '7paws frontend build failed.' }
} finally { Pop-Location }
New-Item -ItemType Directory -Force -Path $assets | Out-Null
Copy-Item -LiteralPath (Join-Path $source 'LICENSE') -Destination (Join-Path $assets 'LICENSE.txt') -Force
Copy-Item -LiteralPath (Join-Path $source 'UPSTREAM.json') -Destination (Join-Path $assets 'UPSTREAM.json') -Force
New-Item -ItemType Directory -Force -Path (Join-Path $source 'dist') | Out-Null
Copy-Item -Path (Join-Path $staging 'dist\*') -Destination (Join-Path $source 'dist') -Recurse -Force
$cargoArgs = @('build', '--manifest-path', (Join-Path $source 'src-tauri\Cargo.toml'), '--release', '--locked', '--features', 'tauri/custom-protocol')
if ($UseMirror) {
    # A config file also works under Windows PowerShell's legacy native argument quoting.
    $mirrorConfig = Join-Path $staging 'cargo-mirror.toml'
    @'
[source.crates-io]
replace-with = "deskbox-mirror"
[source.deskbox-mirror]
registry = "sparse+https://rsproxy.cn/index/"
'@ | Set-Content -LiteralPath $mirrorConfig -Encoding ascii
    $cargoArgs += @('--config', $mirrorConfig)
}
& cargo @cargoArgs
if ($LASTEXITCODE -ne 0) { throw '7paws desktop runtime build failed.' }
$nativeAssets = Join-Path $assets 'native'
New-Item -ItemType Directory -Force -Path $nativeAssets | Out-Null
Copy-Item -LiteralPath (Join-Path $source 'src-tauri\target\release\7paws.exe') -Destination (Join-Path $nativeAssets '7paws.exe') -Force
Write-Output "7paws desktop runtime built: $nativeAssets"
