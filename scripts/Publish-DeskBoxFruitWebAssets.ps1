[CmdletBinding()]
param(
    [switch]$SynchronizeServerConfig,
    [string]$StageDirectory,
    [uri]$FtpRootUri,
    [pscredential]$Credential,
    [switch]$Upload,
    [switch]$PublishServer
)

$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$clientManifestPath = Join-Path $repositoryRoot 'src\DeskBox\Assets\Tree\fruits-manifest.json'
$clientAssetRoot = Join-Path $repositoryRoot 'src\DeskBox\Assets\Tree\Fruits\Source'
$serverManifestPath = Join-Path $repositoryRoot 'server\deskbox-api\config\fruits.json'

function Get-Sha256([string]$Path) {
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash
}

function Get-FtpRequest([uri]$Uri, [string]$Method, [pscredential]$FtpCredential) {
    $request = [System.Net.FtpWebRequest]::Create($Uri)
    $request.Method = $Method
    $request.Credentials = $FtpCredential.GetNetworkCredential()
    $request.UseBinary = $true
    $request.UsePassive = $true
    $request.KeepAlive = $false
    $request.Proxy = $null
    return $request
}

function Get-SafeFtpLocation([uri]$Uri) {
    $authority = if ($Uri.IsDefaultPort) { $Uri.Host } else { "$($Uri.Host):$($Uri.Port)" }
    return "$($Uri.Scheme)://$authority$($Uri.AbsolutePath)"
}

function Get-FtpChildUri([uri]$RootUri, [string]$RelativePath) {
    if ($RelativePath -match '(^|[\\/])\.\.([\\/]|$)' -or $RelativePath.StartsWith('/')) {
        throw "Unsafe FTP relative path: $RelativePath"
    }
    return [uri]::new($RootUri.AbsoluteUri.TrimEnd('/') + '/' + $RelativePath.Replace('\\', '/'))
}

function Ensure-FtpDirectory([uri]$DirectoryUri, [pscredential]$FtpCredential) {
    $request = Get-FtpRequest $DirectoryUri ([System.Net.WebRequestMethods+Ftp]::MakeDirectory) $FtpCredential
    try {
        $response = $request.GetResponse()
        try { } finally { $response.Dispose() }
    } catch [System.Net.WebException] {
        $ftpResponse = $_.Exception.Response -as [System.Net.FtpWebResponse]
        if ($ftpResponse -isnot [System.Net.FtpWebResponse] -or $ftpResponse.StatusCode -ne [System.Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
            throw
        }
    }
}

function Send-FtpFile([string]$LocalPath, [uri]$RemoteUri, [pscredential]$FtpCredential) {
    $uploadRequest = Get-FtpRequest $RemoteUri ([System.Net.WebRequestMethods+Ftp]::UploadFile) $FtpCredential
    $uploadRequest.ContentLength = (Get-Item -LiteralPath $LocalPath).Length
    $requestStream = $uploadRequest.GetRequestStream()
    try {
        $sourceStream = [System.IO.File]::OpenRead($LocalPath)
        try { $sourceStream.CopyTo($requestStream) } finally { $sourceStream.Dispose() }
    } finally { $requestStream.Dispose() }
    $response = $uploadRequest.GetResponse()
    try { } finally { $response.Dispose() }
    Test-FtpFile $LocalPath $RemoteUri $FtpCredential
    Write-Host "Uploaded and verified: $(Get-SafeFtpLocation $RemoteUri)"
}

function Test-FtpFile([string]$LocalPath, [uri]$RemoteUri, [pscredential]$FtpCredential) {
    $sizeRequest = Get-FtpRequest $RemoteUri ([System.Net.WebRequestMethods+Ftp]::GetFileSize) $FtpCredential
    $sizeResponse = $sizeRequest.GetResponse()
    try {
        if ($sizeResponse.ContentLength -ne (Get-Item -LiteralPath $LocalPath).Length) {
            throw "Remote size mismatch for $(Get-SafeFtpLocation $RemoteUri)"
        }
    } finally { $sizeResponse.Dispose() }

    $downloadPath = [System.IO.Path]::GetTempFileName()
    try {
        $downloadRequest = Get-FtpRequest $RemoteUri ([System.Net.WebRequestMethods+Ftp]::DownloadFile) $FtpCredential
        $downloadResponse = $downloadRequest.GetResponse()
        try {
            $input = $downloadResponse.GetResponseStream()
            try {
                $output = [System.IO.File]::Open($downloadPath, [System.IO.FileMode]::Create, [System.IO.FileAccess]::Write)
                try { $input.CopyTo($output) } finally { $output.Dispose() }
            } finally { $input.Dispose() }
        } finally { $downloadResponse.Dispose() }
        if ((Get-Sha256 $LocalPath) -ne (Get-Sha256 $downloadPath)) {
            throw "Remote SHA-256 mismatch for $(Get-SafeFtpLocation $RemoteUri)"
        }
    } finally { Remove-Item -LiteralPath $downloadPath -Force -ErrorAction SilentlyContinue }
}

if (!(Test-Path -LiteralPath $clientManifestPath) -or !(Test-Path -LiteralPath $serverManifestPath)) {
    throw 'Fruit manifest files are missing.'
}

$clientManifest = Get-Content -LiteralPath $clientManifestPath -Raw -Encoding utf8 | ConvertFrom-Json
$serverManifest = Get-Content -LiteralPath $serverManifestPath -Raw -Encoding utf8 | ConvertFrom-Json
$clientItems = @($clientManifest.items)
$serverItems = @($serverManifest.items)
if ($clientItems.Count -ne 112 -or $serverItems.Count -ne 112) {
    throw "Expected 112 client and server fruit entries; found $($clientItems.Count) and $($serverItems.Count)."
}

$clientById = @{}
foreach ($item in $clientItems) {
    $file = [System.IO.Path]::GetFileName([string]$item.assetFile)
    if ([string]::IsNullOrWhiteSpace([string]$item.id) -or $file -notmatch '^fruit_r\d{2}_c\d{2}\.png$') {
        throw "Invalid client PNG mapping for '$($item.id)'."
    }
    $path = Join-Path $clientAssetRoot $file
    if (!(Test-Path -LiteralPath $path)) { throw "Missing client PNG: $path" }
    if ($clientById.ContainsKey([string]$item.id)) { throw "Duplicate client fruit ID: $($item.id)" }
    $clientById[[string]$item.id] = [pscustomobject]@{ File = $file; Path = $path }
}

if ((@($clientById.Values.File | Sort-Object -Unique).Count) -ne 112) {
    throw 'Client fruit PNG mappings are not unique.'
}

$serverIds = @($serverItems | ForEach-Object { [string]$_.id })
if (@($serverIds | Sort-Object -Unique).Count -ne 112 -or @($serverIds | Where-Object { -not $clientById.ContainsKey($_) }).Count -ne 0) {
    throw 'Server and client fruit IDs do not match exactly.'
}

foreach ($item in $serverItems) {
    $expectedFile = $clientById[[string]$item.id].File
    if ($SynchronizeServerConfig) {
        $item | Add-Member -NotePropertyName assetFile -NotePropertyValue $expectedFile -Force
    } elseif ([string]$item.assetFile -cne $expectedFile) {
        throw "Server PNG mapping does not match the client manifest for '$($item.id)'. Run with -SynchronizeServerConfig to repair it."
    }
}

if ($SynchronizeServerConfig) {
    [System.IO.File]::WriteAllText($serverManifestPath, ($serverManifest | ConvertTo-Json -Depth 8), [System.Text.UTF8Encoding]::new($false))
    Write-Host "Synchronized 112 server asset mappings: $serverManifestPath"
}

$files = @($serverItems | ForEach-Object { $clientById[[string]$_.id] } | Sort-Object File)
if (![string]::IsNullOrWhiteSpace($StageDirectory)) {
    New-Item -ItemType Directory -Force -Path $StageDirectory | Out-Null
    foreach ($file in $files) { Copy-Item -LiteralPath $file.Path -Destination (Join-Path $StageDirectory $file.File) -Force }
    Write-Host "Staged $($files.Count) fruit PNGs: $StageDirectory"
}

if ($Upload) {
    if ($null -eq $FtpRootUri -or $FtpRootUri.Scheme -ne 'ftp') { throw 'Upload requires an ftp:// website-root URI.' }
    if (![string]::IsNullOrEmpty($FtpRootUri.UserInfo)) { throw 'Put FTP credentials in the credential prompt, not the FTP URI.' }
    if ($null -eq $Credential) { $Credential = Get-Credential -Message 'Enter the FTP credential for the DeskBox web assets' }
    $assetDirectoryUri = Get-FtpChildUri $FtpRootUri 'assets/fruits/'
    Ensure-FtpDirectory $assetDirectoryUri $Credential
    foreach ($file in $files) {
        Send-FtpFile $file.Path (Get-FtpChildUri $assetDirectoryUri $file.File) $Credential
    }
    if ($PublishServer) {
        $serverFiles = @(
            [pscustomobject]@{ LocalPath = (Join-Path $repositoryRoot 'server\deskbox-api\api\_app\fruit.php'); RelativePath = 'api/_app/fruit.php' },
            [pscustomobject]@{ LocalPath = (Join-Path $repositoryRoot 'server\deskbox-api\api\admin\index.php'); RelativePath = 'api/admin/index.php' },
            [pscustomobject]@{ LocalPath = $serverManifestPath; RelativePath = 'config/fruits.json' }
        )
        foreach ($serverFile in $serverFiles) {
            Send-FtpFile $serverFile.LocalPath (Get-FtpChildUri $FtpRootUri $serverFile.RelativePath) $Credential
        }
    }
}

if (!$SynchronizeServerConfig -and [string]::IsNullOrWhiteSpace($StageDirectory) -and !$Upload) {
    Write-Host "Validated $($files.Count) client PNG mappings. Use -SynchronizeServerConfig, -StageDirectory, or -Upload to perform an action."
}
