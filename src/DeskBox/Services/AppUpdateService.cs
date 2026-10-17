using System.Diagnostics;
using System.Globalization;
using System.Net.Http.Headers;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;
using DeskBox.Core.Persistence;
using DeskBox.Models;

namespace DeskBox.Services;

public sealed partial class AppUpdateService : IAppUpdateService
{
    public const string DefaultManifestUrl = "https://raw.githubusercontent.com/sltalex/SltBox/main/update/stable.json";
    public const string DefaultGitHubLatestReleaseApiUrl = "https://api.github.com/repos/sltalex/SltBox/releases/latest";
    public const string DefaultManualDownloadUrl = "https://github.com/sltalex/SltBox/releases";
    public const string DefaultInstallerMirrorBaseUrl = "http://sltbox.2leaves.cn/releases/";

    private readonly HttpClient _httpClient;
    private readonly string _manifestUrl;
    private readonly string _githubLatestReleaseApiUrl;
    private readonly string _updateRootPath;
    private readonly SemaphoreSlim _downloadGate = new(1, 1);

    public AppUpdateService(
        HttpClient? httpClient = null,
        string? manifestUrl = null,
        string? updateRootPath = null,
        string? githubLatestReleaseApiUrl = null)
    {
        _httpClient = httpClient ?? new HttpClient();
        _httpClient.Timeout = TimeSpan.FromSeconds(20);
        _httpClient.DefaultRequestHeaders.UserAgent.Clear();
        _httpClient.DefaultRequestHeaders.UserAgent.Add(new ProductInfoHeaderValue("DeskBox", GetCurrentAppVersion()));

        _manifestUrl = string.IsNullOrWhiteSpace(manifestUrl)
            ? DefaultManifestUrl
            : manifestUrl;
        _githubLatestReleaseApiUrl = string.IsNullOrWhiteSpace(githubLatestReleaseApiUrl)
            ? DefaultGitHubLatestReleaseApiUrl
            : githubLatestReleaseApiUrl;
        _updateRootPath = string.IsNullOrWhiteSpace(updateRootPath)
            ? DeskBoxDataPathService.Current.UpdatesDirectory
            : updateRootPath;
    }

    public AppUpdateCheckResult? LastCheckResult { get; private set; }
    public DateTime? LastCheckTimeUtc { get; private set; }
    public string? DownloadedInstallerPath { get; private set; }
    public string? DownloadedInstallerVersion { get; private set; }
    public event Action<AppUpdateCheckResult>? CheckCompleted;

    public string ManifestUrl => _manifestUrl;
    public AppUpdateDeliveryKind DeliveryKind => AppUpdateDeliveryKind.DirectInstaller;
    internal static string CurrentInstallerArchitectureSuffix =>
        GetInstallerArchitectureSuffix(RuntimeInformation.ProcessArchitecture);

    public async Task<AppUpdateCheckResult> CheckForUpdatesAsync(CancellationToken cancellationToken = default)
    {
        return await CheckForUpdatesAsync(GetCurrentAppVersion(), cancellationToken);
    }

    public async Task<AppUpdateCheckResult> CheckForUpdatesAsync(string currentVersion, CancellationToken cancellationToken = default)
    {
        var manifestResult = await CheckManifestForUpdatesAsync(currentVersion, cancellationToken);
        if (manifestResult.Status is AppUpdateCheckStatus.UpdateAvailable or AppUpdateCheckStatus.UpToDate or AppUpdateCheckStatus.ArchitectureUnavailable)
        {
            return SetLastCheckResult(manifestResult);
        }

        var githubResult = await CheckGitHubLatestReleaseForUpdatesAsync(currentVersion, cancellationToken);
        if (githubResult.Status is AppUpdateCheckStatus.UpdateAvailable or AppUpdateCheckStatus.UpToDate or AppUpdateCheckStatus.ArchitectureUnavailable)
        {
            return SetLastCheckResult(githubResult);
        }

        return SetLastCheckResult(manifestResult);
    }

    private async Task<AppUpdateCheckResult> CheckManifestForUpdatesAsync(string currentVersion, CancellationToken cancellationToken)
    {
        try
        {
            using var response = await _httpClient.GetAsync(_manifestUrl, cancellationToken);
            response.EnsureSuccessStatusCode();

            await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            var manifest = await JsonSerializer.DeserializeAsync(
                stream,
                AppUpdateJsonContext.Default.UpdateManifest,
                cancellationToken);
            return EvaluateManifestForArchitecture(manifest, currentVersion, CurrentInstallerArchitectureSuffix);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException or IOException)
        {
            return new AppUpdateCheckResult(AppUpdateCheckStatus.Failed, currentVersion, errorMessage: ex.Message);
        }
    }

    internal static AppUpdateCheckResult EvaluateManifestForArchitecture(
        AppUpdateManifest? manifest,
        string currentVersion,
        string architectureSuffix)
    {
        if (!IsManifestUsable(manifest) || !TryParseVersion(manifest!.Version, out _))
        {
            return new AppUpdateCheckResult(AppUpdateCheckStatus.InvalidManifest, currentVersion, manifest);
        }

        if (!IsRemoteVersionNewer(currentVersion, manifest.Version))
        {
            return new AppUpdateCheckResult(AppUpdateCheckStatus.UpToDate, currentVersion, manifest);
        }

        if (!TrySelectInstallerForArchitecture(manifest, architectureSuffix))
        {
            return new AppUpdateCheckResult(AppUpdateCheckStatus.ArchitectureUnavailable, currentVersion, manifest);
        }

        return string.IsNullOrWhiteSpace(manifest.Sha256)
            ? new AppUpdateCheckResult(AppUpdateCheckStatus.InvalidManifest, currentVersion, manifest)
            : new AppUpdateCheckResult(AppUpdateCheckStatus.UpdateAvailable, currentVersion, manifest);
    }

    private async Task<AppUpdateCheckResult> CheckGitHubLatestReleaseForUpdatesAsync(string currentVersion, CancellationToken cancellationToken)
    {
        try
        {
            using var response = await _httpClient.GetAsync(_githubLatestReleaseApiUrl, cancellationToken);
            response.EnsureSuccessStatusCode();

            await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            var release = await JsonSerializer.DeserializeAsync(
                stream,
                AppUpdateJsonContext.Default.GitHubRelease,
                cancellationToken);
            var manifest = await CreateManifestFromGitHubReleaseAsync(release, cancellationToken);
            if (manifest is null && release is not null &&
                IsRemoteVersionNewer(currentVersion, release.TagName) &&
                release.Assets.Any(asset => IsInstallerAssetName(asset.Name, "x64") ||
                    IsInstallerAssetName(asset.Name, "arm64")) &&
                !release.Assets.Any(IsInstallerAsset))
            {
                return new AppUpdateCheckResult(AppUpdateCheckStatus.ArchitectureUnavailable, currentVersion);
            }
            if (!IsManifestUsable(manifest) || string.IsNullOrWhiteSpace(manifest!.Sha256))
            {
                return new AppUpdateCheckResult(
                    AppUpdateCheckStatus.InvalidManifest,
                    currentVersion,
                    manifest,
                    "The GitHub release metadata is missing required fields.");
            }

            return IsRemoteVersionNewer(currentVersion, manifest.Version)
                ? new AppUpdateCheckResult(AppUpdateCheckStatus.UpdateAvailable, currentVersion, manifest)
                : new AppUpdateCheckResult(AppUpdateCheckStatus.UpToDate, currentVersion, manifest);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException or IOException)
        {
            return new AppUpdateCheckResult(AppUpdateCheckStatus.Failed, currentVersion, errorMessage: ex.Message);
        }
    }

    public async Task<AppUpdateDownloadResult> DownloadUpdateAsync(
        AppUpdateManifest manifest,
        IProgress<AppUpdateDownloadProgress>? progress = null,
        CancellationToken cancellationToken = default)
    {
        try
        {
            await _downloadGate.WaitAsync(cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            return AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.Cancelled);
        }

        try
        {
            if (string.Equals(DownloadedInstallerVersion, manifest.Version, StringComparison.OrdinalIgnoreCase) &&
                !string.IsNullOrWhiteSpace(DownloadedInstallerPath) &&
                File.Exists(DownloadedInstallerPath))
            {
                if (await IsCachedInstallerValidAsync(manifest, DownloadedInstallerPath, cancellationToken))
                {
                    long size = new FileInfo(DownloadedInstallerPath).Length;
                    progress?.Report(new AppUpdateDownloadProgress(size, size));
                    return AppUpdateDownloadResult.Completed(DownloadedInstallerPath);
                }

                DownloadedInstallerPath = null;
                DownloadedInstallerVersion = null;
            }

            return await DownloadUpdateCoreAsync(manifest, progress, cancellationToken);
        }
        finally
        {
            _downloadGate.Release();
        }
    }

    private async Task<AppUpdateDownloadResult> DownloadUpdateCoreAsync(
        AppUpdateManifest manifest,
        IProgress<AppUpdateDownloadProgress>? progress,
        CancellationToken cancellationToken)
    {
        if (!string.Equals(DownloadedInstallerVersion, manifest.Version, StringComparison.OrdinalIgnoreCase))
        {
            DownloadedInstallerPath = null;
            DownloadedInstallerVersion = null;
        }

        if (!TrySelectInstallerForArchitecture(
                manifest,
                CurrentInstallerArchitectureSuffix) ||
            !IsManifestUsable(manifest))
        {
            return AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.InvalidManifest);
        }

        if (string.IsNullOrWhiteSpace(manifest.Sha256))
        {
            return AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.HashMissing);
        }

        if (!Uri.TryCreate(manifest.DownloadUrl, UriKind.Absolute, out var downloadUri))
        {
            return AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.InvalidManifest);
        }

        if (!IsInstallerDownloadCompatibleWithArchitecture(
                manifest.DownloadUrl,
                CurrentInstallerArchitectureSuffix))
        {
            return AppUpdateDownloadResult.Failed(
                AppUpdateDownloadFailureKind.InvalidManifest,
                "The installer architecture does not match this SltBox process.");
        }

        string targetDirectory = Path.Combine(_updateRootPath, SanitizePathSegment(manifest.Version));
        string fileName = GetInstallerFileName(downloadUri, manifest.Version);
        string targetPath = Path.Combine(targetDirectory, fileName);
        string tempPath = targetPath + ".tmp";

        Uri mirrorUri = new(new Uri(DefaultInstallerMirrorBaseUrl), Uri.EscapeDataString(fileName));
        AppUpdateDownloadResult? lastFailure = null;
        long partialBytes;
        try
        {
            partialBytes = File.Exists(tempPath) ? new FileInfo(tempPath).Length : 0;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            return AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.FileSystem, ex.Message);
        }
        if (partialBytes > 0)
        {
            progress?.Report(new AppUpdateDownloadProgress(partialBytes, manifest.Size > 0 ? manifest.Size : null));
        }

        foreach (Uri sourceUri in new[] { downloadUri, mirrorUri })
        {
            try
            {
                Directory.CreateDirectory(targetDirectory);
                if (manifest.Size >= ResumableRangeDownloader.DefaultPartSize)
                {
                    ResumableRangeDownloader.Result ranged = await ResumableRangeDownloader.DownloadAsync(
                        _httpClient,
                        sourceUri,
                        tempPath,
                        manifest.Size,
                        progress,
                        cancellationToken: cancellationToken);
                    if (ranged == ResumableRangeDownloader.Result.Unsupported)
                        ResumableRangeDownloader.DiscardState(tempPath);
                    if (ranged == ResumableRangeDownloader.Result.Completed)
                    {
                        string rangedHash;
                        await using (var completedStream = new FileStream(
                            tempPath, FileMode.Open, FileAccess.Read, FileShare.Read,
                            bufferSize: 1024 * 128, useAsync: true))
                        {
                            rangedHash = Convert.ToHexString(await SHA256.HashDataAsync(completedStream, cancellationToken));
                        }
                        if (!string.Equals(rangedHash, NormalizeSha256(manifest.Sha256), StringComparison.OrdinalIgnoreCase))
                        {
                            TryDelete(tempPath);
                            lastFailure = AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.HashMismatch);
                            continue;
                        }
                        File.Move(tempPath, targetPath, overwrite: true);
                        progress?.Report(new AppUpdateDownloadProgress(manifest.Size, manifest.Size));
                        return CompleteDownload(manifest.Version, targetPath);
                    }
                }
                long requestedOffset = partialBytes;
                using var request = new HttpRequestMessage(HttpMethod.Get, sourceUri);
                if (requestedOffset > 0)
                {
                    request.Headers.Range = new RangeHeaderValue(requestedOffset, null);
                }

                using var response = await _httpClient.SendAsync(
                    request,
                    HttpCompletionOption.ResponseHeadersRead,
                    cancellationToken);

                if (response.StatusCode == System.Net.HttpStatusCode.RequestedRangeNotSatisfiable && requestedOffset > 0)
                {
                    TryDelete(tempPath);
                    partialBytes = 0;
                    continue;
                }

                response.EnsureSuccessStatusCode();

                bool append = requestedOffset > 0 &&
                    response.StatusCode == System.Net.HttpStatusCode.PartialContent &&
                    response.Content.Headers.ContentRange?.From == requestedOffset;
                if (!append)
                {
                    // A server that ignores Range returned the complete file.
                    // Start over instead of appending a duplicate prefix.
                    partialBytes = 0;
                }

                long bytesReceived = append ? requestedOffset : 0;
                long? totalBytes = response.Content.Headers.ContentLength;
                if (append && totalBytes is not null)
                {
                    totalBytes += requestedOffset;
                }
                else if (totalBytes is null && manifest.Size > 0)
                {
                    totalBytes = manifest.Size;
                }

                await using (var remoteStream = await response.Content.ReadAsStreamAsync(cancellationToken))
                await using (var fileStream = new FileStream(
                    tempPath,
                    append ? FileMode.Append : FileMode.Create,
                    FileAccess.Write,
                    FileShare.None,
                    bufferSize: 1024 * 128,
                    useAsync: true))
                {
                    byte[] buffer = new byte[1024 * 128];
                    int bytesRead;
                    while ((bytesRead = await remoteStream.ReadAsync(buffer.AsMemory(0, buffer.Length), cancellationToken)) > 0)
                    {
                        await fileStream.WriteAsync(buffer.AsMemory(0, bytesRead), cancellationToken);
                        bytesReceived += bytesRead;
                        partialBytes = bytesReceived;
                        progress?.Report(new AppUpdateDownloadProgress(bytesReceived, totalBytes));
                    }

                    await fileStream.FlushAsync(cancellationToken);
                }

                string actualSha256;
                {
                    await using var completedStream = new FileStream(
                        tempPath, FileMode.Open, FileAccess.Read, FileShare.Read,
                        bufferSize: 1024 * 128, useAsync: true);
                    actualSha256 = Convert.ToHexString(
                        await SHA256.HashDataAsync(completedStream, cancellationToken));
                }

                if (!string.Equals(actualSha256, NormalizeSha256(manifest.Sha256), StringComparison.OrdinalIgnoreCase))
                {
                    TryDelete(tempPath);
                    partialBytes = 0;
                    lastFailure = AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.HashMismatch);
                    continue;
                }

                File.Move(tempPath, targetPath, overwrite: true);
                progress?.Report(new AppUpdateDownloadProgress(bytesReceived, totalBytes ?? bytesReceived));
                return CompleteDownload(manifest.Version, targetPath);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                // Keep the partial file so a later update attempt can resume.
                return AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.Cancelled);
            }
            catch (Exception ex) when (
                ex is HttpRequestException or
                System.Net.Http.HttpIOException or
                TaskCanceledException)
            {
                // Network failures leave the partial file available for Range.
                lastFailure = AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.Network, ex.Message);
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
                return AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.FileSystem, ex.Message);
            }
        }

        return lastFailure ?? AppUpdateDownloadResult.Failed(AppUpdateDownloadFailureKind.Network);
    }

    private static async Task<bool> IsCachedInstallerValidAsync(
        AppUpdateManifest manifest,
        string installerPath,
        CancellationToken cancellationToken)
    {
        if ((manifest.Size > 0 && new FileInfo(installerPath).Length != manifest.Size) ||
            string.IsNullOrWhiteSpace(manifest.Sha256))
        {
            return false;
        }

        await using FileStream stream = new(
            installerPath,
            FileMode.Open,
            FileAccess.Read,
            FileShare.Read,
            bufferSize: 64 * 1024,
            options: FileOptions.Asynchronous | FileOptions.SequentialScan);
        string actual = Convert.ToHexString(await SHA256.HashDataAsync(stream, cancellationToken));
        return string.Equals(actual, NormalizeSha256(manifest.Sha256), StringComparison.OrdinalIgnoreCase);
    }

    private AppUpdateDownloadResult CompleteDownload(string version, string targetPath)
    {
        DownloadedInstallerVersion = version;
        DownloadedInstallerPath = targetPath;
        return AppUpdateDownloadResult.Completed(targetPath);
    }

    public AppUpdateInstallResult StartInstallerHelper(string installerPath, bool silent = true)
    {
        if (string.IsNullOrWhiteSpace(installerPath) || !File.Exists(installerPath))
        {
            return AppUpdateInstallResult.Failed("The downloaded installer no longer exists.");
        }

        string? appPath = Environment.ProcessPath;
        if (string.IsNullOrWhiteSpace(appPath) || !File.Exists(appPath))
        {
            appPath = Path.Combine(AppContext.BaseDirectory, "DeskBox.exe");
        }

        if (!TryGetInstallDirectory(appPath, out string installDirectory))
        {
            return AppUpdateInstallResult.Failed(
                "SltBox could not determine the current installation directory. Please use the manual installer instead.");
        }

        try
        {
            string helperPath = PrepareDetachedUpdaterHelper(AppContext.BaseDirectory, _updateRootPath);
            if (!File.Exists(helperPath))
            {
                return AppUpdateInstallResult.Failed("DeskBox.Updater.exe was not found in the application directory.");
            }

            var startInfo = new ProcessStartInfo
            {
                FileName = helperPath,
                UseShellExecute = false,
                CreateNoWindow = true,
                WorkingDirectory = AppContext.BaseDirectory
            };
            startInfo.ArgumentList.Add("--pid");
            startInfo.ArgumentList.Add(Environment.ProcessId.ToString(CultureInfo.InvariantCulture));
            startInfo.ArgumentList.Add("--installer");
            startInfo.ArgumentList.Add(installerPath);
            startInfo.ArgumentList.Add("--app");
            startInfo.ArgumentList.Add(appPath);
            startInfo.ArgumentList.Add("--install-dir");
            startInfo.ArgumentList.Add(installDirectory);
            if (silent)
            {
                startInfo.ArgumentList.Add("--silent");
            }

            Process.Start(startInfo);
            return AppUpdateInstallResult.Started();
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            return AppUpdateInstallResult.Failed(ex.Message);
        }
    }

    internal static bool TryGetInstallDirectory(string? appPath, out string installDirectory)
    {
        installDirectory = string.Empty;
        if (string.IsNullOrWhiteSpace(appPath) || !File.Exists(appPath))
        {
            return false;
        }

        try
        {
            string fullAppPath = Path.GetFullPath(appPath);
            if (!string.Equals(
                    Path.GetFileName(fullAppPath),
                    "DeskBox.exe",
                    StringComparison.OrdinalIgnoreCase))
            {
                return false;
            }

            string? directory = Path.GetDirectoryName(fullAppPath);
            if (string.IsNullOrWhiteSpace(directory) || !Directory.Exists(directory))
            {
                return false;
            }

            installDirectory = directory;
            return true;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or ArgumentException or NotSupportedException)
        {
            return false;
        }
    }

    internal static string PrepareDetachedUpdaterHelper(string appDirectory, string updateRootPath)
    {
        string sourceHelperPath = Path.Combine(appDirectory, "DeskBox.Updater.exe");
        if (!File.Exists(sourceHelperPath))
        {
            return sourceHelperPath;
        }

        string helperRoot = Path.Combine(updateRootPath, "helper");

        // Clean up stale helper directories from previous updates.
        try
        {
            if (Directory.Exists(helperRoot))
            {
                foreach (var oldDir in Directory.GetDirectories(helperRoot))
                {
                    try
                    {
                        Directory.Delete(oldDir, recursive: true);
                    }
                    catch (Exception ex)
                    {
                        App.Log($"[Update] Failed to clean old helper directory '{oldDir}': {ex.Message}");
                    }
                }
            }
        }
        catch (Exception ex)
        {
            App.Log($"[Update] Failed to enumerate helper directories: {ex.Message}");
        }

        string helperDirectory = Path.Combine(
            helperRoot,
            $"{DateTimeOffset.UtcNow:yyyyMMddHHmmss}-{Environment.ProcessId.ToString(CultureInfo.InvariantCulture)}");
        Directory.CreateDirectory(helperDirectory);

        foreach (var sourcePath in Directory.EnumerateFiles(appDirectory, "DeskBox.Updater.*", SearchOption.TopDirectoryOnly))
        {
            string targetPath = Path.Combine(helperDirectory, Path.GetFileName(sourcePath));
            File.Copy(sourcePath, targetPath, overwrite: true);
        }

        return Path.Combine(helperDirectory, "DeskBox.Updater.exe");
    }

    public static string GetCurrentAppVersion()
    {
        return Assembly.GetExecutingAssembly()
            .GetCustomAttribute<AssemblyInformationalVersionAttribute>()
            ?.InformationalVersion
            .Split('+')[0] ??
            Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ??
            "0.0.0";
    }

    public static bool IsRemoteVersionNewer(string currentVersion, string remoteVersion)
    {
        if (!TryParseVersion(currentVersion, out var current) ||
            !TryParseVersion(remoteVersion, out var remote))
        {
            return false;
        }

        return remote > current;
    }

    public static bool TryParseVersion(string? value, out Version version)
    {
        version = new Version(0, 0, 0);
        if (string.IsNullOrWhiteSpace(value))
        {
            return false;
        }

        string normalized = value.Trim();
        if (normalized.StartsWith('v') || normalized.StartsWith('V'))
        {
            normalized = normalized[1..];
        }

        int suffixIndex = normalized.IndexOfAny(['-', '+']);
        if (suffixIndex >= 0)
        {
            normalized = normalized[..suffixIndex];
        }

        if (TryNormalizeCompactDateVersion(normalized, out string compactDateVersion))
        {
            normalized = compactDateVersion;
        }

        return Version.TryParse(normalized, out version!);
    }

    private static bool TryNormalizeCompactDateVersion(string value, out string normalized)
    {
        normalized = value;
        if (value.Length != 10 || !value.All(char.IsAsciiDigit))
        {
            return false;
        }

        if (!int.TryParse(value.AsSpan(0, 4), out int year) ||
            !int.TryParse(value.AsSpan(4, 2), out int month) ||
            !int.TryParse(value.AsSpan(6, 2), out int day) ||
            !int.TryParse(value.AsSpan(8, 2), out int revision) ||
            month is < 1 or > 12 ||
            day is < 1 or > 31)
        {
            return false;
        }

        try
        {
            _ = new DateOnly(year, month, day);
        }
        catch (ArgumentOutOfRangeException)
        {
            return false;
        }

        normalized = $"{year}.{month}.{day}.{revision}";
        return true;
    }

    public static bool IsManifestUsable(AppUpdateManifest? manifest)
    {
        return manifest is not null &&
            manifest.SchemaVersion == 1 &&
            !string.IsNullOrWhiteSpace(manifest.Version) &&
            Uri.TryCreate(manifest.DownloadUrl, UriKind.Absolute, out Uri? downloadUri) &&
            IsTrustedInstallerDownloadUri(downloadUri) &&
            (string.IsNullOrWhiteSpace(manifest.Arm64DownloadUrl) ||
                (Uri.TryCreate(manifest.Arm64DownloadUrl, UriKind.Absolute, out Uri? arm64Uri) &&
                    IsTrustedInstallerDownloadUri(arm64Uri)));
    }

    /// <summary>
    /// The manifest must name this repository's HTTPS release. Its hash
    /// verifies bytes from GitHub or the fixed HTTP mirror; pinning the
    /// manifest's installer origin prevents a feed from naming an arbitrary
    /// third-party release.
    /// </summary>
    private static bool IsTrustedInstallerDownloadUri(Uri uri)
    {
        if (!string.Equals(uri.Scheme, "https", StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        // GitHub hosts third-party repositories, so trusting the whole domain
        // would let a tampered manifest point at any attacker repository with
        // a matching hash. Only this project's own release downloads count;
        // the actual asset bytes arrive via redirect from
        // objects.githubusercontent.com, which the HTTP client follows and
        // which therefore must never be trusted as a manifest origin itself.
        return string.Equals(uri.Host, "github.com", StringComparison.OrdinalIgnoreCase) &&
            uri.AbsolutePath.StartsWith(
                "/sltalex/SltBox/releases/download/",
                StringComparison.OrdinalIgnoreCase);
    }

    internal static bool TrySelectInstallerForArchitecture(
        AppUpdateManifest manifest,
        string architectureSuffix)
    {
        if (string.Equals(architectureSuffix, "x64", StringComparison.OrdinalIgnoreCase))
        {
            return IsInstallerDownloadCompatibleWithArchitecture(
                manifest.DownloadUrl,
                architectureSuffix);
        }

        if (!string.Equals(architectureSuffix, "arm64", StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        if (string.IsNullOrWhiteSpace(manifest.Arm64DownloadUrl))
        {
            // Backward compatibility for an ARM64-only manifest that already
            // used the primary installer fields.
            return IsInstallerDownloadCompatibleWithArchitecture(
                manifest.DownloadUrl,
                architectureSuffix);
        }

        manifest.DownloadUrl = manifest.Arm64DownloadUrl;
        manifest.Sha256 = manifest.Arm64Sha256;
        manifest.Size = manifest.Arm64Size;
        return IsInstallerDownloadCompatibleWithArchitecture(
            manifest.DownloadUrl,
            architectureSuffix);
    }

    private async Task<AppUpdateManifest?> CreateManifestFromGitHubReleaseAsync(
        GitHubReleaseResponse? release,
        CancellationToken cancellationToken)
    {
        if (release is null || string.IsNullOrWhiteSpace(release.TagName))
        {
            return null;
        }

        var installerAsset = release.Assets.FirstOrDefault(IsInstallerAsset);
        if (installerAsset is null || string.IsNullOrWhiteSpace(installerAsset.BrowserDownloadUrl))
        {
            return null;
        }

        string sha256 = ExtractSha256FromDigest(installerAsset.Digest);
        if (string.IsNullOrWhiteSpace(sha256))
        {
            var shaAsset = release.Assets.FirstOrDefault(asset =>
                string.Equals(asset.Name, installerAsset.Name + ".sha256", StringComparison.OrdinalIgnoreCase));
            sha256 = await DownloadSha256FromAssetAsync(shaAsset, cancellationToken);
        }

        if (string.IsNullOrWhiteSpace(sha256))
        {
            return null;
        }

        string version = NormalizeVersionText(release.TagName);
        return new AppUpdateManifest
        {
            SchemaVersion = 1,
            Channel = "stable",
            Version = version,
            DownloadUrl = installerAsset.BrowserDownloadUrl,
            ManualDownloadUrl = DefaultManualDownloadUrl,
            MirrorUrl = DefaultManualDownloadUrl,
            Sha256 = sha256,
            Size = Math.Max(0, installerAsset.Size),
            ReleaseNotesUrl = release.HtmlUrl,
            ReleaseNotes = string.IsNullOrWhiteSpace(release.Body)
                ? []
                : new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
                {
                    ["en-US"] = release.Body
                },
            Summary =
            {
                ["zh-CN"] = $"SltBox {version} 已发布，可从 GitHub Releases 下载更新。",
                ["zh-TW"] = $"SltBox {version} 已發布，可從 GitHub Releases 下載更新。",
                ["en-US"] = $"SltBox {version} is available from GitHub Releases.",
                ["ja-JP"] = $"SltBox {version} は GitHub Releases から入手できます。",
                ["de-DE"] = $"SltBox {version} ist über GitHub Releases verfügbar.",
                ["pt-BR"] = $"O SltBox {version} está disponível no GitHub Releases.",
                ["hi-IN"] = $"SltBox {version} GitHub Releases से उपलब्ध है।",
                ["es-ES"] = $"SltBox {version} está disponible en GitHub Releases.",
                ["fr-FR"] = $"SltBox {version} est disponible sur GitHub Releases.",
                ["ar-SA"] = $"يتوفر SltBox {version} في إصدارات GitHub.",
                ["bn-BD"] = $"SltBox {version} GitHub Releases-এ পাওয়া যাচ্ছে।",
                ["ru-RU"] = $"SltBox {version} доступен в GitHub Releases."
            }
        };
    }

    private async Task<string> DownloadSha256FromAssetAsync(GitHubReleaseAsset? shaAsset, CancellationToken cancellationToken)
    {
        if (shaAsset is null || string.IsNullOrWhiteSpace(shaAsset.BrowserDownloadUrl))
        {
            return string.Empty;
        }

        using var response = await _httpClient.GetAsync(shaAsset.BrowserDownloadUrl, cancellationToken);
        response.EnsureSuccessStatusCode();
        string content = await response.Content.ReadAsStringAsync(cancellationToken);
        return ExtractSha256FromText(content);
    }

    private AppUpdateCheckResult SetLastCheckResult(AppUpdateCheckResult result)
    {
        LastCheckResult = result;
        LastCheckTimeUtc = DateTime.UtcNow;
        CheckCompleted?.Invoke(result);
        return result;
    }

    internal static string GetInstallerFileName(Uri downloadUri, string version)
    {
        string fileName = Path.GetFileName(downloadUri.LocalPath);
        return string.IsNullOrWhiteSpace(fileName) ||
            !fileName.EndsWith(".exe", StringComparison.OrdinalIgnoreCase)
            ? $"SltBox_Setup_{SanitizePathSegment(version)}_{CurrentInstallerArchitectureSuffix}.exe"
            : SanitizeFileName(fileName);
    }

    internal static string GetInstallerArchitectureSuffix(Architecture architecture)
    {
        return architecture switch
        {
            Architecture.Arm64 => "arm64",
            Architecture.X64 => "x64",
            _ => string.Empty
        };
    }

    private static string SanitizePathSegment(string value)
    {
        string sanitized = SanitizeFileName(value);
        return string.IsNullOrWhiteSpace(sanitized) ? "latest" : sanitized;
    }

    private static string SanitizeFileName(string value)
    {
        var invalidChars = Path.GetInvalidFileNameChars();
        return string.Concat(value.Select(ch => invalidChars.Contains(ch) ? '_' : ch));
    }

    private static string NormalizeSha256(string sha256)
    {
        return sha256.Replace(" ", string.Empty, StringComparison.Ordinal)
            .Replace("-", string.Empty, StringComparison.Ordinal)
            .Trim()
            .ToUpperInvariant();
    }

    private static bool IsInstallerAsset(GitHubReleaseAsset asset)
    {
        return IsInstallerAssetName(asset.Name, CurrentInstallerArchitectureSuffix);
    }

    internal static bool IsInstallerAssetName(string assetName, string architectureSuffix)
    {
        return !string.IsNullOrWhiteSpace(architectureSuffix) &&
            IsKnownInstallerAssetPrefix(assetName) &&
            assetName.EndsWith($"_{architectureSuffix}.exe", StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsKnownInstallerAssetPrefix(string assetName)
    {
        return assetName.StartsWith("DeskBox_Setup_", StringComparison.OrdinalIgnoreCase) ||
            assetName.StartsWith("SltBox_Setup_", StringComparison.OrdinalIgnoreCase);
    }

    internal static bool IsInstallerDownloadCompatibleWithArchitecture(
        string downloadUrl,
        string architectureSuffix)
    {
        if (string.IsNullOrWhiteSpace(architectureSuffix) ||
            !Uri.TryCreate(downloadUrl, UriKind.Absolute, out Uri? uri))
        {
            return false;
        }

        string fileName = Path.GetFileName(uri.LocalPath);
        if (!IsKnownInstallerAssetPrefix(fileName))
        {
            // Keep compatibility with a server endpoint that returns a
            // redirect or content-disposition filename instead of exposing
            // the final installer name in the URL.
            return true;
        }

        return fileName.EndsWith($"_{architectureSuffix}.exe", StringComparison.OrdinalIgnoreCase);
    }

    private static string ExtractSha256FromDigest(string? digest)
    {
        if (string.IsNullOrWhiteSpace(digest))
        {
            return string.Empty;
        }

        const string prefix = "sha256:";
        string value = digest.Trim();
        if (value.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
        {
            value = value[prefix.Length..];
        }

        return IsSha256Hex(value) ? NormalizeSha256(value) : string.Empty;
    }

    private static string ExtractSha256FromText(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return string.Empty;
        }

        foreach (var token in value.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries))
        {
            string normalized = NormalizeSha256(token);
            if (IsSha256Hex(normalized))
            {
                return normalized;
            }
        }

        return string.Empty;
    }

    private static bool IsSha256Hex(string value)
    {
        return value.Length == 64 && value.All(Uri.IsHexDigit);
    }

    private static string NormalizeVersionText(string value)
    {
        string normalized = value.Trim();
        return normalized.StartsWith('v') || normalized.StartsWith('V')
            ? normalized[1..]
            : normalized;
    }

    private static void TryDelete(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
        }
    }

    private sealed class GitHubReleaseResponse
    {
        [JsonPropertyName("tag_name")]
        public string TagName { get; set; } = string.Empty;

        [JsonPropertyName("html_url")]
        public string HtmlUrl { get; set; } = string.Empty;

        [JsonPropertyName("body")]
        public string Body { get; set; } = string.Empty;

        public GitHubReleaseAsset[] Assets { get; set; } = [];
    }

    private sealed class GitHubReleaseAsset
    {
        public string Name { get; set; } = string.Empty;

        [JsonPropertyName("browser_download_url")]
        public string BrowserDownloadUrl { get; set; } = string.Empty;

        public long Size { get; set; }

        public string Digest { get; set; } = string.Empty;
    }

    [JsonSourceGenerationOptions(
        JsonSerializerDefaults.Web,
        GenerationMode = JsonSourceGenerationMode.Metadata,
        PropertyNameCaseInsensitive = true)]
    [JsonSerializable(typeof(AppUpdateManifest), TypeInfoPropertyName = "UpdateManifest")]
    [JsonSerializable(typeof(GitHubReleaseResponse), TypeInfoPropertyName = "GitHubRelease")]
    private sealed partial class AppUpdateJsonContext : JsonSerializerContext
    {
    }
}
