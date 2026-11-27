param(
  [string]$Configuration = "Release"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$manifest = Get-Content (Join-Path $root "src-tauri/win7/runtime.json") -Raw | ConvertFrom-Json
$targetDir = Join-Path $root "src-tauri/target-win7"
$release = Join-Path $targetDir $Configuration.ToLowerInvariant()
$exe = Join-Path $release "7paws.exe"
if (-not (Test-Path $exe)) {
  throw "Win7 executable not found: $exe. Run pnpm tauri:win7 first."
}

$cabName = "Microsoft.WebView2.FixedVersionRuntime.$($manifest.version).x64.cab"
$cab = Join-Path $targetDir $cabName
if (-not (Test-Path $cab)) {
  Invoke-WebRequest -Uri $manifest.url -OutFile $cab
}
if ((Get-Item $cab).Length -ne $manifest.size) {
  throw "WebView2 runtime CAB size mismatch."
}
$actualHash = (Get-FileHash -Algorithm SHA256 -Path $cab).Hash.ToLowerInvariant()
if ($actualHash -ne $manifest.sha256.ToLowerInvariant()) {
  throw "WebView2 runtime SHA-256 mismatch: $actualHash"
}

$runtime = Join-Path $release "WebView2Runtime"
$extract = Join-Path $release "runtime-extract"
if (Test-Path $extract) {
  Remove-Item -Recurse -Force -Path $extract
}
New-Item -ItemType Directory -Path $extract | Out-Null
& (Join-Path $env:SystemRoot "System32\expand.exe") $cab -F:* $extract | Out-Host
if ($LASTEXITCODE -ne 0) {
  throw "Failed to extract WebView2 runtime CAB."
}
$payload = Join-Path $extract "Microsoft.WebView2.FixedVersionRuntime.$($manifest.version).x64"
$runtimeExe = Join-Path $payload "msedgewebview2.exe"
if (-not (Test-Path $runtimeExe)) {
  throw "Fixed runtime extraction did not produce msedgewebview2.exe."
}
if (Test-Path $runtime) {
  Remove-Item -Recurse -Force -Path $runtime
}
Move-Item -Path $payload -Destination $runtime
Remove-Item -Recurse -Force -Path $extract
$runtimeExe = Join-Path $runtime "msedgewebview2.exe"
$runtimeVersion = (Get-Item $runtimeExe).VersionInfo.ProductVersion
if ($runtimeVersion -ne $manifest.version) {
  throw "Unexpected WebView2 runtime version: $runtimeVersion"
}

& node (Join-Path $root "scripts/verify-win7-pe.mjs") $exe
if ($LASTEXITCODE -ne 0) {
  throw "Win7 PE verification failed."
}

$version = (Get-Content (Join-Path $root "package.json") -Raw | ConvertFrom-Json).version
$stage = Join-Path $release "bundle/portable/7paws-win7"
if (Test-Path $stage) {
  Remove-Item -Recurse -Force -Path $stage
}
New-Item -ItemType Directory -Path $stage | Out-Null
Copy-Item -Path $exe -Destination $stage
Copy-Item -Path (Join-Path $root "src-tauri/win7/README-Win7.txt") -Destination $stage
Copy-Item -Path (Join-Path $root "src-tauri/win7/DEBUG-Win7.txt") -Destination $stage
Copy-Item -Path (Join-Path $root "src-tauri/win7/THIRD_PARTY_NOTICES.txt") -Destination $stage
Copy-Item -Path (Join-Path $root "src-tauri/win7/LICENSE-VC-LTL.txt") -Destination $stage
Copy-Item -Recurse -Path $runtime -Destination $stage

$out = Join-Path $release "bundle/portable"
$zip = Join-Path $out "7paws_${version}-win7-x64-portable.zip"
if (Test-Path $zip) {
  Remove-Item -Force -Path $zip
}
Compress-Archive -Path (Join-Path $stage "*") -DestinationPath $zip -CompressionLevel Optimal
Write-Host "Win7 portable package: $zip"
