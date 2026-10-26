[CmdletBinding()]
param(
    [Parameter(Mandatory)][uri]$FtpRootUri,
    [Parameter(Mandatory)][pscredential]$Credential,
    [hashtable]$ExpectedRemoteHashes = @{},
    [switch]$Upload
)

$ErrorActionPreference = 'Stop'
if ($FtpRootUri.Scheme -ne 'ftp' -or $FtpRootUri.UserInfo) {
    throw 'Use an FTP root URI without embedded credentials.'
}
$root = Split-Path -Parent $PSScriptRoot
$files = @(
    'config/wildlife.json',
    'config/wildlife-legacy.json',
    'api/_app/wildlife.php',
    'api/v1/wildlife/catalog.php',
    'api/v1/wildlife/state.php',
    'api/v1/wildlife/encounter.php',
    'api/v1/wildlife/capture.php',
    'api/v1/wildlife/restore.php'
)

function New-Request([uri]$uri, [string]$method) {
    $request = [System.Net.FtpWebRequest]::Create($uri)
    $request.Method = $method
    $request.Credentials = $Credential.GetNetworkCredential()
    $request.UseBinary = $true
    $request.UsePassive = $true
    $request.KeepAlive = $false
    $request.Proxy = $null
    $request.Timeout = 20000
    $request.ReadWriteTimeout = 20000
    return $request
}

function Child-Uri([string]$relative) {
    if ($relative -match '(^|/)\.\.(/|$)' -or $relative.StartsWith('/')) { throw 'Unsafe remote path.' }
    return [uri]::new($FtpRootUri.AbsoluteUri.TrimEnd('/') + '/' + $relative)
}

function Remote-Bytes([string]$relative) {
    $request = New-Request (Child-Uri $relative) ([System.Net.WebRequestMethods+Ftp]::DownloadFile)
    $response = $request.GetResponse()
    try {
        $stream = $response.GetResponseStream()
        try {
            $buffer = [System.IO.MemoryStream]::new()
            try { $stream.CopyTo($buffer); return ,$buffer.ToArray() }
            finally { $buffer.Dispose() }
        } finally { $stream.Dispose() }
    } finally { $response.Dispose() }
}

function Ensure-Directory([string]$relative) {
    $request = New-Request (Child-Uri $relative) ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)
    try { $response = $request.GetResponse(); $response.Dispose() }
    catch [System.Net.WebException] {
        $ftpResponse = $_.Exception.Response -as [System.Net.FtpWebResponse]
        if ($null -eq $ftpResponse -or $ftpResponse.StatusCode -ne [System.Net.FtpStatusCode]::ActionNotTakenFileUnavailable) { throw }
    }
}

$probe = Remote-Bytes 'api/_app/bootstrap.php'
if ($probe.Length -lt 20 -or ![System.Text.Encoding]::UTF8.GetString($probe, 0, [Math]::Min(5, $probe.Length)).StartsWith('<?php')) {
    throw 'FTP root does not look like the DeskBox API website root.'
}
Write-Output 'FTP root verified against the existing API bootstrap.'

$identical = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
foreach ($relative in $files) {
    try {
        $existing = Remote-Bytes $relative
    } catch [System.Net.WebException] {
        $ftpResponse = $_.Exception.Response -as [System.Net.FtpWebResponse]
        if ($null -ne $ftpResponse -and $ftpResponse.StatusCode -eq [System.Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
            continue
        }
        throw
    }
    $local = Join-Path (Join-Path $root 'server/deskbox-api') ($relative.Replace('/', '\'))
    $source = [System.IO.File]::ReadAllBytes($local)
    $existingHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData($existing))
    $localHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData($source))
    if ($existingHash -eq $localHash) {
        [void]$identical.Add($relative)
        if (!$Upload) { Write-Output "Already verified $relative sha256=$($localHash.ToLowerInvariant())" }
        continue
    }
    if (!$Upload) {
        Write-Output "Differs $relative remoteSha256=$($existingHash.ToLowerInvariant()) localSha256=$($localHash.ToLowerInvariant())"
        continue
    }
    if ($existingHash -ne $ExpectedRemoteHashes[$relative]) {
        throw "Remote file already differs from local: $relative"
    }
}
if (!$Upload) { return }

Ensure-Directory 'api/v1/wildlife'
foreach ($relative in $files) {
    if ($identical.Contains($relative)) {
        Write-Output "Already verified $relative"
        continue
    }
    $local = Join-Path (Join-Path $root 'server/deskbox-api') ($relative.Replace('/', '\'))
    if (!(Test-Path -LiteralPath $local)) { throw "Missing local file: $relative" }
    $source = [System.IO.File]::ReadAllBytes($local)
    $request = New-Request (Child-Uri $relative) ([System.Net.WebRequestMethods+Ftp]::UploadFile)
    $request.ContentLength = $source.Length
    $stream = $request.GetRequestStream()
    try { $stream.Write($source, 0, $source.Length) }
    finally { $stream.Dispose() }
    $response = $request.GetResponse()
    $response.Dispose()
    $remote = Remote-Bytes $relative
    $localHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData($source))
    $remoteHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData($remote))
    if ($remote.Length -ne $source.Length -or $remoteHash -ne $localHash) {
        throw "Upload verification failed: $relative"
    }
    Write-Output "Verified $relative bytes=$($source.Length) sha256=$($localHash.ToLowerInvariant())"
}
