using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Runtime.InteropServices;
using System.Text;
using DeskBox.Core.Persistence;
using DeskBox.Models;
using DeskBox.Services;
using DeskBox.ViewModels;

namespace DeskBox.Tests;

public sealed class AppUpdateServiceTests : IDisposable
{
    private readonly string _tempRoot = Path.Combine(Path.GetTempPath(), "DeskBox.Tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public void DirectUpdateSources_PointToSltalexRelease()
    {
        Assert.Equal("https://raw.githubusercontent.com/sltalex/SltBox/main/update/stable.json", AppUpdateService.DefaultManifestUrl);
        Assert.Equal("https://api.github.com/repos/sltalex/SltBox/releases/latest", AppUpdateService.DefaultGitHubLatestReleaseApiUrl);
        Assert.Equal("https://github.com/sltalex/SltBox/releases", AppUpdateService.DefaultManualDownloadUrl);
        Assert.Equal("http://sltbox.2leaves.cn/releases/", AppUpdateService.DefaultInstallerMirrorBaseUrl);
    }

    [Fact]
    public void X64OnlyManifest_ReportsNoCompatibleUpdateOnArm64()
    {
        var manifest = new AppUpdateManifest
        {
            Version = "1.5.6",
            DownloadUrl = "https://github.com/sltalex/SltBox/releases/download/v1.5.6/DeskBox_Setup_1.5.6_x64.exe",
            Sha256 = new string('A', 64),
            Size = 1234
        };

        var result = AppUpdateService.EvaluateManifestForArchitecture(manifest, "1.5.5", "arm64");

        Assert.Equal(AppUpdateCheckStatus.ArchitectureUnavailable, result.Status);
        Assert.False(result.IsUpdateAvailable);
        Assert.False(AppUpdateService.TrySelectInstallerForArchitecture(manifest, "arm64"));
    }

    [Fact]
    public void X64OnlyManifest_IsUpToDateWhenArm64AlreadyHasThatVersion()
    {
        var manifest = new AppUpdateManifest
        {
            Version = "1.5.6",
            DownloadUrl = "https://github.com/sltalex/SltBox/releases/download/v1.5.6/DeskBox_Setup_1.5.6_x64.exe",
            Sha256 = new string('A', 64),
            Size = 1234
        };

        var result = AppUpdateService.EvaluateManifestForArchitecture(manifest, "1.5.6", "arm64");

        Assert.Equal(AppUpdateCheckStatus.UpToDate, result.Status);
        Assert.False(result.IsUpdateAvailable);
    }

    [Fact]
    public void InstallerAssetSelection_UsesTheCurrentProcessArchitecture()
    {
        Assert.Equal("x64", AppUpdateService.GetInstallerArchitectureSuffix(Architecture.X64));
        Assert.Equal("arm64", AppUpdateService.GetInstallerArchitectureSuffix(Architecture.Arm64));
        Assert.True(AppUpdateService.IsInstallerAssetName("DeskBox_Setup_1.2.4_x64.exe", "x64"));
        Assert.False(AppUpdateService.IsInstallerAssetName("DeskBox_Setup_1.2.4_arm64.exe", "x64"));
        Assert.True(AppUpdateService.IsInstallerAssetName("DeskBox_Setup_1.2.4_arm64.exe", "arm64"));
        Assert.True(AppUpdateService.IsInstallerAssetName("DeskBox_Setup_1.4.8_x64.exe", "x64"));
        Assert.True(AppUpdateService.IsInstallerAssetName("DeskBox_Setup_1.4.8_arm64.exe", "arm64"));
        Assert.True(AppUpdateService.IsInstallerAssetName("SltBox_Setup_2026101602_x64.exe", "x64"));
        Assert.True(AppUpdateService.IsInstallerAssetName("SltBox_Setup_2026101602_arm64.exe", "arm64"));
        Assert.False(AppUpdateService.IsInstallerAssetName("DeskBox_Setup_1.4.3_x64_Full.exe", "x64"));
        Assert.False(AppUpdateService.IsInstallerAssetName("DeskBox_Setup_1.4.3_arm64_Full.exe", "arm64"));
        Assert.True(AppUpdateService.IsInstallerDownloadCompatibleWithArchitecture(
            "https://deskbox.fun/DeskBox_Setup_1.2.4_arm64.exe",
            "arm64"));
        Assert.False(AppUpdateService.IsInstallerDownloadCompatibleWithArchitecture(
            "https://deskbox.fun/DeskBox_Setup_1.2.4_x64.exe",
            "arm64"));
        Assert.True(AppUpdateService.IsInstallerDownloadCompatibleWithArchitecture(
            "https://example.com/download/latest",
            "arm64"));
        Assert.True(AppUpdateService.IsInstallerDownloadCompatibleWithArchitecture(
            "https://sltbox.2leaves.cn/releases/SltBox_Setup_2026101602_x64.exe",
            "x64"));
        Assert.Equal(
            $"SltBox_Setup_1.2.4_{AppUpdateService.CurrentInstallerArchitectureSuffix}.exe",
            AppUpdateService.GetInstallerFileName(new Uri("https://example.com/download/latest"), "1.2.4"));
    }

    [Fact]
    public void ManifestInstallerSelection_UsesIndependentArm64Metadata()
    {
        var manifest = new AppUpdateManifest
        {
            Version = "1.3.8",
            DownloadUrl = "https://deskbox.fun/DeskBox_Setup_1.3.8_x64.exe",
            Sha256 = new string('A', 64),
            Size = 101,
            Arm64DownloadUrl = "https://deskbox.fun/DeskBox_Setup_1.3.8_arm64.exe",
            Arm64Sha256 = new string('B', 64),
            Arm64Size = 202
        };

        Assert.True(AppUpdateService.TrySelectInstallerForArchitecture(manifest, "arm64"));
        Assert.EndsWith("_arm64.exe", manifest.DownloadUrl, StringComparison.OrdinalIgnoreCase);
        Assert.Equal(new string('B', 64), manifest.Sha256);
        Assert.Equal(202, manifest.Size);
    }

    [Fact]
    public void ManifestInstallerSelection_LeavesPrimaryMetadataForX64()
    {
        var manifest = new AppUpdateManifest
        {
            Version = "1.3.8",
            DownloadUrl = "https://deskbox.fun/DeskBox_Setup_1.3.8_x64.exe",
            Sha256 = new string('A', 64),
            Size = 101,
            Arm64DownloadUrl = "https://deskbox.fun/DeskBox_Setup_1.3.8_arm64.exe",
            Arm64Sha256 = new string('B', 64),
            Arm64Size = 202
        };

        Assert.True(AppUpdateService.TrySelectInstallerForArchitecture(manifest, "x64"));
        Assert.EndsWith("_x64.exe", manifest.DownloadUrl, StringComparison.OrdinalIgnoreCase);
        Assert.Equal(new string('A', 64), manifest.Sha256);
        Assert.Equal(101, manifest.Size);
    }

    [Fact]
    public void LocalizedSummary_PrefersEnglishWhenLocaleIsMissing()
    {
        var manifest = new AppUpdateManifest
        {
            Summary =
            {
                ["zh-CN"] = "中文摘要",
                ["zh-TW"] = "繁體摘要",
                ["en-US"] = "English summary"
            }
        };

        Assert.Equal("English summary", manifest.GetLocalizedSummary("de-DE"));
        Assert.Equal("中文摘要", manifest.GetLocalizedSummary("zh-CN"));
        Assert.Equal("繁體摘要", manifest.GetLocalizedSummary("zh-TW"));
        Assert.Equal("繁體摘要", manifest.GetLocalizedSummary("zh-MO"));
    }

    [Theory]
    [InlineData("https://pan.quark.cn/s/version-specific", "https://mirror.example.com/deskbox")]
    [InlineData("javascript:alert('unsafe')", "http://mirror.example.com/deskbox")]
    [InlineData("", "")]
    public void ManualUpdateDownloadUrl_AlwaysOpensOfficialDownloadPage(string manualUrl, string mirrorUrl)
    {
        var manifest = new AppUpdateManifest
        {
            ManualDownloadUrl = manualUrl,
            MirrorUrl = mirrorUrl
        };

        Assert.Equal(
            "https://github.com/sltalex/SltBox/releases",
            SettingsViewModel.GetManualUpdateDownloadUrl(manifest));
        Assert.Equal("https://github.com/sltalex/SltBox/releases", SettingsViewModel.GetManualUpdateDownloadUrl(null));
    }

    [Theory]
    [InlineData("**拖拽更加可靠。** 使用 `RequestedOperation`。", "拖拽更加可靠。 使用 RequestedOperation。")]
    [InlineData("## Highlights\n- Read [details](https://deskbox.fun/download)\n- Fixed alignment", "Highlights Read details Fixed alignment")]
    [InlineData(null, "")]
    public void UpdateSummaryPreview_ShowsReadableText(string? markdown, string expected)
    {
        Assert.Equal(expected, SettingsViewModel.GetUpdateSummaryPreview(markdown));
    }

    [Theory]
    [InlineData("1.2.1", "1.2.2", true)]
    [InlineData("1.2.1", "v1.2.2", true)]
    [InlineData("1.2.1", "1.2.2-beta.1", true)]
    [InlineData("1.2.1", "1.2.1", false)]
    [InlineData("1.2.1", "1.2.0", false)]
    [InlineData("1.2.1", "not-a-version", false)]
    [InlineData("1.5.6", "2026101001", true)]
    [InlineData("2026101001", "2026101001", false)]
    [InlineData("2026101001", "2026101101", true)]
    public void IsRemoteVersionNewer_ComparesSemanticVersionPrefix(string currentVersion, string remoteVersion, bool expected)
    {
        Assert.Equal(expected, AppUpdateService.IsRemoteVersionNewer(currentVersion, remoteVersion));
    }

    [Fact]
    public async Task CheckForUpdatesAsync_ReadsManifestAndDetectsUpdate()
    {
        using var httpClient = CreateHttpClient(_ => new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent(
                """
                {
                  "ScHeMaVeRsIoN": "1",
                  "version": "1.2.2",
                  "downloadUrl": "https://github.com/sltalex/SltBox/releases/download/v1.2.2/DeskBox_Setup_1.2.2_x64.exe",
                  "manualDownloadUrl": "https://pan.quark.cn/s/version-specific",
                  "sha256": "abc",
                  "SiZe": "1234",
                  "summary": {
                    "zh-CN": "修复多屏定位"
                  },
                  "futureManifestField": { "ignored": true }
                }
                """,
                Encoding.UTF8,
                "application/json")
        });

        var service = new AppUpdateService(httpClient, "https://deskbox.fun/update/stable.json", _tempRoot);

        var result = await service.CheckForUpdatesAsync("1.2.1");

        Assert.Equal(AppUpdateCheckStatus.UpdateAvailable, result.Status);
        Assert.NotNull(result.Manifest);
        Assert.Equal(1, result.Manifest.SchemaVersion);
        Assert.Equal("1.2.2", result.Manifest.Version);
        Assert.Equal(1234, result.Manifest.Size);
        Assert.Equal("https://pan.quark.cn/s/version-specific", result.Manifest.ManualDownloadUrl);
        Assert.Same(result, service.LastCheckResult);
    }

    [Fact]
    public async Task CheckForUpdatesAsync_FallsBackToGitHubReleaseWhenManifestUnavailable()
    {
        using var httpClient = CreateHttpClient(request =>
        {
            string url = request.RequestUri!.ToString();
            if (url.Contains("stable.json", StringComparison.OrdinalIgnoreCase))
            {
                return new HttpResponseMessage(HttpStatusCode.NotFound);
            }

            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(
                    """
                    {
                      "tag_name": "v1.2.4",
                      "html_url": "https://github.com/sltalex/SltBox/releases/tag/v1.2.4",
                      "body": "## Highlights\n- Improved update flow",
                      "assets": [
                        {
                          "name": "DeskBox_Setup_1.2.4_x64.exe",
                          "browser_download_url": "https://github.com/sltalex/SltBox/releases/download/v1.2.4/DeskBox_Setup_1.2.4_x64.exe",
                          "size": 23423211,
                          "digest": "sha256:8ecb3092ae5bd6883f8a75bbea03d9800251e0c23fdbe1bf4d91fd3a62565561"
                        }
                      ]
                    }
                    """,
                    Encoding.UTF8,
                    "application/json")
            };
        });

        var service = new AppUpdateService(
            httpClient,
            "https://deskbox.fun/update/stable.json",
            _tempRoot,
            AppUpdateService.DefaultGitHubLatestReleaseApiUrl);

        var result = await service.CheckForUpdatesAsync("1.2.3");

        Assert.Equal(AppUpdateCheckStatus.UpdateAvailable, result.Status);
        Assert.NotNull(result.Manifest);
        Assert.Equal("1.2.4", result.Manifest.Version);
        Assert.Equal("https://github.com/sltalex/SltBox/releases/download/v1.2.4/DeskBox_Setup_1.2.4_x64.exe", result.Manifest.DownloadUrl);
        Assert.Equal("8ECB3092AE5BD6883F8A75BBEA03D9800251E0C23FDBE1BF4D91FD3A62565561", result.Manifest.Sha256);
        Assert.Equal(23423211, result.Manifest.Size);
        Assert.Equal("https://github.com/sltalex/SltBox/releases/tag/v1.2.4", result.Manifest.ReleaseNotesUrl);
        Assert.Equal("## Highlights\n- Improved update flow", result.Manifest.GetReleaseNotesForLocale("en-US"));
    }

    [Fact]
    public async Task CheckForUpdatesAsync_GitHubFallbackReportsNoInstallerForThisArchitecture()
    {
        string otherArchitecture = AppUpdateService.CurrentInstallerArchitectureSuffix == "arm64"
            ? "x64"
            : "arm64";
        using var httpClient = CreateHttpClient(request =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("stable.json", StringComparison.OrdinalIgnoreCase))
            {
                return new HttpResponseMessage(HttpStatusCode.NotFound);
            }

            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent($$"""
                    {
                      "tag_name": "v1.5.6",
                      "assets": [
                        {
                          "name": "DeskBox_Setup_1.5.6_{{otherArchitecture}}.exe",
                          "browser_download_url": "https://github.com/sltalex/SltBox/releases/download/v1.5.6/DeskBox_Setup_1.5.6_{{otherArchitecture}}.exe",
                          "size": 1234,
                          "digest": "sha256:8ecb3092ae5bd6883f8a75bbea03d9800251e0c23fdbe1bf4d91fd3a62565561"
                        }
                      ]
                    }
                    """, Encoding.UTF8, "application/json")
            };
        });

        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);
        var result = await service.CheckForUpdatesAsync("1.5.5");

        Assert.Equal(AppUpdateCheckStatus.ArchitectureUnavailable, result.Status);
        Assert.False(result.IsUpdateAvailable);
        Assert.Null(result.Manifest);
    }

    [Fact]
    public async Task CheckForUpdatesAsync_FallsBackToGitHubShaAssetWhenDigestMissing()
    {
        using var httpClient = CreateHttpClient(request =>
        {
            string url = request.RequestUri!.ToString();
            if (url.Contains("stable.json", StringComparison.OrdinalIgnoreCase))
            {
                return new HttpResponseMessage(HttpStatusCode.NotFound);
            }

            if (url.EndsWith(".sha256", StringComparison.OrdinalIgnoreCase))
            {
                return new HttpResponseMessage(HttpStatusCode.OK)
                {
                    Content = new StringContent(
                        "8ECB3092AE5BD6883F8A75BBEA03D9800251E0C23FDBE1BF4D91FD3A62565561  DeskBox_Setup_1.2.4_x64.exe",
                        Encoding.UTF8,
                        "text/plain")
                };
            }

            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(
                    """
                    {
                      "tag_name": "v1.2.4",
                      "html_url": "https://github.com/sltalex/SltBox/releases/tag/v1.2.4",
                      "assets": [
                        {
                          "name": "DeskBox_Setup_1.2.4_x64.exe",
                          "browser_download_url": "https://github.com/sltalex/SltBox/releases/download/v1.2.4/DeskBox_Setup_1.2.4_x64.exe",
                          "size": 23423211
                        },
                        {
                          "name": "DeskBox_Setup_1.2.4_x64.exe.sha256",
                          "browser_download_url": "https://github.com/sltalex/SltBox/releases/download/v1.2.4/DeskBox_Setup_1.2.4_x64.exe.sha256",
                          "size": 95
                        }
                      ]
                    }
                    """,
                    Encoding.UTF8,
                    "application/json")
            };
        });

        var service = new AppUpdateService(
            httpClient,
            "https://deskbox.fun/update/stable.json",
            _tempRoot,
            AppUpdateService.DefaultGitHubLatestReleaseApiUrl);

        var result = await service.CheckForUpdatesAsync("1.2.3");

        Assert.Equal(AppUpdateCheckStatus.UpdateAvailable, result.Status);
        Assert.NotNull(result.Manifest);
        Assert.Equal("8ECB3092AE5BD6883F8A75BBEA03D9800251E0C23FDBE1BF4D91FD3A62565561", result.Manifest.Sha256);
    }

    [Fact]
    public async Task DownloadUpdateAsync_RejectsInstallerWithoutHash()
    {
        using var httpClient = CreateHttpClient(_ => new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new ByteArrayContent([1, 2, 3])
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = "1.2.2",
            DownloadUrl = "https://github.com/sltalex/SltBox/releases/download/v1.2.2/DeskBox_Setup_1.2.2_x64.exe"
        });

        Assert.False(result.Success);
        Assert.Equal(AppUpdateDownloadFailureKind.HashMissing, result.FailureKind);
    }

    [Fact]
    public async Task DownloadUpdateAsync_VerifiesSha256AndWritesInstaller()
    {
        byte[] payload = Encoding.UTF8.GetBytes("deskbox-installer");
        string sha256 = Convert.ToHexString(SHA256.HashData(payload));
        using var httpClient = CreateHttpClient(_ => new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new ByteArrayContent(payload)
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = "1.2.2",
            DownloadUrl = "https://github.com/sltalex/SltBox/releases/download/v1.2.2/DeskBox_Setup_1.2.2_x64.exe",
            Sha256 = sha256,
            Size = payload.Length
        });

        Assert.True(result.Success, $"{result.FailureKind}: {result.ErrorMessage}");
        Assert.True(File.Exists(result.FilePath));
        Assert.Equal(payload, await File.ReadAllBytesAsync(result.FilePath!));
    }

    [Fact]
    public async Task DownloadUpdateAsync_ReusesVerifiedInstallerForTheSameVersion()
    {
        byte[] payload = Encoding.UTF8.GetBytes("cached-installer");
        int requests = 0;
        using var httpClient = CreateHttpClient(_ =>
        {
            requests++;
            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new ByteArrayContent(payload)
            };
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);
        var manifest = new AppUpdateManifest
        {
            Version = "1.2.6",
            DownloadUrl = $"https://github.com/sltalex/SltBox/releases/download/v1.2.6/DeskBox_Setup_1.2.6_{AppUpdateService.CurrentInstallerArchitectureSuffix}.exe",
            Sha256 = Convert.ToHexString(SHA256.HashData(payload)),
            Size = payload.Length
        };

        AppUpdateDownloadResult first = await service.DownloadUpdateAsync(manifest);
        AppUpdateDownloadResult second = await service.DownloadUpdateAsync(manifest);

        Assert.True(first.Success);
        Assert.True(second.Success);
        Assert.Equal(first.FilePath, second.FilePath);
        Assert.Equal(manifest.Version, service.DownloadedInstallerVersion);
        Assert.Equal(first.FilePath, service.DownloadedInstallerPath);
        Assert.Equal(1, requests);
    }

    [Fact]
    public async Task DownloadUpdateAsync_UsesConcurrentRangesForLargeInstaller()
    {
        byte[] payload = new byte[checked((int)(ResumableRangeDownloader.DefaultPartSize * 2 + 123))];
        Random.Shared.NextBytes(payload);
        int requests = 0;
        using var httpClient = CreateHttpClient(request =>
        {
            Interlocked.Increment(ref requests);
            long start = request.Headers.Range?.Ranges.Single().From ?? 0;
            long end = request.Headers.Range?.Ranges.Single().To ?? payload.LongLength - 1;
            end = Math.Min(end, payload.LongLength - 1);
            var response = new HttpResponseMessage(HttpStatusCode.PartialContent)
            {
                Content = new ByteArrayContent(payload.AsSpan((int)start, (int)(end - start + 1)).ToArray())
            };
            response.Headers.ETag = new EntityTagHeaderValue("\"fixture\"");
            response.Content.Headers.ContentRange = new ContentRangeHeaderValue(start, end, payload.LongLength);
            return response;
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = "1.2.9",
            DownloadUrl = "https://github.com/sltalex/SltBox/releases/download/v1.2.9/DeskBox_Setup_1.2.9_x64.exe",
            Sha256 = Convert.ToHexString(SHA256.HashData(payload)),
            Size = payload.LongLength
        });

        Assert.True(result.Success, $"{result.FailureKind}: {result.ErrorMessage}");
        Assert.True(requests >= 3);
        Assert.Equal(payload, await File.ReadAllBytesAsync(result.FilePath!));
    }

    [Fact]
    public async Task DownloadUpdateAsync_ResumesPartialFileWithRange()
    {
        byte[] prefix = Encoding.UTF8.GetBytes("deskbox-");
        byte[] suffix = Encoding.UTF8.GetBytes("installer");
        byte[] payload = [.. prefix, .. suffix];
        string version = "1.2.3";
        string architecture = AppUpdateService.CurrentInstallerArchitectureSuffix;
        string fileName = $"DeskBox_Setup_{version}_{architecture}.exe";
        string tempPath = Path.Combine(_tempRoot, version, fileName + ".tmp");
        Directory.CreateDirectory(Path.GetDirectoryName(tempPath)!);
        await File.WriteAllBytesAsync(tempPath, prefix);
        HttpRequestMessage? capturedRequest = null;
        using var httpClient = CreateHttpClient(request =>
        {
            capturedRequest = request;
            var response = new HttpResponseMessage(HttpStatusCode.PartialContent)
            {
                Content = new ByteArrayContent(suffix)
            };
            response.Content.Headers.ContentRange = new System.Net.Http.Headers.ContentRangeHeaderValue(
                prefix.Length,
                payload.Length - 1,
                payload.Length);
            return response;
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = version,
            DownloadUrl = $"https://github.com/sltalex/SltBox/releases/download/v{version}/{fileName}",
            Sha256 = Convert.ToHexString(SHA256.HashData(payload)),
            Size = payload.Length
        });

        Assert.True(result.Success, $"{result.FailureKind}: {result.ErrorMessage}");
        Assert.NotNull(capturedRequest);
        Assert.Equal(prefix.Length, capturedRequest!.Headers.Range!.Ranges.Single().From);
        Assert.Equal(payload, await File.ReadAllBytesAsync(result.FilePath!));
        Assert.False(File.Exists(tempPath));
    }

    [Fact]
    public async Task DownloadUpdateAsync_RestartsWhenServerIgnoresRange()
    {
        byte[] payload = Encoding.UTF8.GetBytes("complete-installer");
        string version = "1.2.5";
        string architecture = AppUpdateService.CurrentInstallerArchitectureSuffix;
        string fileName = $"DeskBox_Setup_{version}_{architecture}.exe";
        string tempPath = Path.Combine(_tempRoot, version, fileName + ".tmp");
        Directory.CreateDirectory(Path.GetDirectoryName(tempPath)!);
        await File.WriteAllTextAsync(tempPath, "old-prefix");
        long? requestedOffset = null;
        using var httpClient = CreateHttpClient(request =>
        {
            requestedOffset = request.Headers.Range?.Ranges.Single().From;
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = new ByteArrayContent(payload) };
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = version,
            DownloadUrl = $"https://github.com/sltalex/SltBox/releases/download/v{version}/{fileName}",
            Sha256 = Convert.ToHexString(SHA256.HashData(payload)),
            Size = payload.Length
        });

        Assert.True(result.Success, $"{result.FailureKind}: {result.ErrorMessage}");
        Assert.Equal("old-prefix".Length, requestedOffset);
        Assert.Equal(payload, await File.ReadAllBytesAsync(result.FilePath!));
    }

    [Fact]
    public async Task DownloadUpdateAsync_PreservesPartialFileAfterNetworkFailure()
    {
        byte[] prefix = Encoding.UTF8.GetBytes("partial");
        string version = "1.2.4";
        string architecture = AppUpdateService.CurrentInstallerArchitectureSuffix;
        string fileName = $"DeskBox_Setup_{version}_{architecture}.exe";
        string tempPath = Path.Combine(_tempRoot, version, fileName + ".tmp");
        Directory.CreateDirectory(Path.GetDirectoryName(tempPath)!);
        await File.WriteAllBytesAsync(tempPath, prefix);
        using var httpClient = CreateHttpClient(_ => throw new TaskCanceledException("network interruption"));
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = version,
            DownloadUrl = $"https://github.com/sltalex/SltBox/releases/download/v{version}/{fileName}",
            Sha256 = new string('A', 64),
            Size = prefix.Length + 4
        });

        Assert.False(result.Success);
        Assert.Equal(AppUpdateDownloadFailureKind.Network, result.FailureKind);
        Assert.Equal(prefix, await File.ReadAllBytesAsync(tempPath));
    }

    [Fact]
    public async Task DownloadUpdateAsync_UsesHttpMirrorAfterGitHubFailure()
    {
        byte[] payload = Encoding.UTF8.GetBytes("mirror-installer");
        string sha256 = Convert.ToHexString(SHA256.HashData(payload));
        var requested = new List<Uri>();
        using var httpClient = CreateHttpClient(request =>
        {
            requested.Add(request.RequestUri!);
            return requested.Count == 1
                ? new HttpResponseMessage(HttpStatusCode.ServiceUnavailable)
                : new HttpResponseMessage(HttpStatusCode.OK) { Content = new ByteArrayContent(payload) };
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = "1.2.2",
            DownloadUrl = "https://github.com/sltalex/SltBox/releases/download/v1.2.2/DeskBox_Setup_1.2.2_x64.exe",
            Sha256 = sha256,
            Size = payload.Length
        });

        Assert.True(result.Success, $"{result.FailureKind}: {result.ErrorMessage}");
        Assert.Equal(2, requested.Count);
        Assert.Equal("http://sltbox.2leaves.cn/releases/DeskBox_Setup_1.2.2_x64.exe", requested[1].AbsoluteUri);
        Assert.Equal(payload, await File.ReadAllBytesAsync(result.FilePath!));
    }

    [Fact]
    public async Task DownloadUpdateAsync_RejectsWrongHashFromBothSources()
    {
        int requests = 0;
        using var httpClient = CreateHttpClient(_ =>
        {
            requests++;
            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new ByteArrayContent(Encoding.UTF8.GetBytes("wrong-installer"))
            };
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = "1.2.2",
            DownloadUrl = "https://github.com/sltalex/SltBox/releases/download/v1.2.2/DeskBox_Setup_1.2.2_x64.exe",
            Sha256 = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes("expected-installer")))
        });

        Assert.False(result.Success);
        Assert.Equal(AppUpdateDownloadFailureKind.HashMismatch, result.FailureKind);
        Assert.Equal(2, requests);
    }

    [Fact]
    public async Task DownloadUpdateAsync_RejectsInstallerForAnotherArchitecture()
    {
        byte[] payload = Encoding.UTF8.GetBytes("deskbox-installer");
        string sha256 = Convert.ToHexString(SHA256.HashData(payload));
        string otherArchitecture = AppUpdateService.CurrentInstallerArchitectureSuffix == "arm64"
            ? "x64"
            : "arm64";
        using var httpClient = CreateHttpClient(_ => new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new ByteArrayContent(payload)
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = "1.2.2",
            DownloadUrl = $"https://github.com/sltalex/SltBox/releases/download/v1.2.2/DeskBox_Setup_1.2.2_{otherArchitecture}.exe",
            Sha256 = sha256,
            Size = payload.Length
        });

        Assert.False(result.Success);
        Assert.Equal(AppUpdateDownloadFailureKind.InvalidManifest, result.FailureKind);
    }

    [Fact]
    public async Task DownloadUpdateAsync_ReportsCallerCancellationSeparately()
    {
        using var httpClient = CreateHttpClient(_ => new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new ByteArrayContent([1, 2, 3])
        });
        var service = new AppUpdateService(httpClient, updateRootPath: _tempRoot);
        using var cancellation = new CancellationTokenSource();
        cancellation.Cancel();

        var result = await service.DownloadUpdateAsync(new AppUpdateManifest
        {
            Version = "1.2.2",
            DownloadUrl = $"https://github.com/sltalex/SltBox/releases/download/v1.2.2/DeskBox_Setup_1.2.2_{AppUpdateService.CurrentInstallerArchitectureSuffix}.exe",
            Sha256 = "00"
        }, cancellationToken: cancellation.Token);

        Assert.False(result.Success);
        Assert.Equal(AppUpdateDownloadFailureKind.Cancelled, result.FailureKind);
    }

    [Fact]
    public async Task PrepareDetachedUpdaterHelper_CopiesUpdaterOutsideAppDirectory()
    {
        string appDirectory = Path.Combine(_tempRoot, "app");
        string updateRoot = Path.Combine(_tempRoot, "updates");
        Directory.CreateDirectory(appDirectory);

        string sourceExe = Path.Combine(appDirectory, "DeskBox.Updater.exe");
        string sourceRuntimeConfig = Path.Combine(appDirectory, "DeskBox.Updater.runtimeconfig.json");
        string nativeModule = Path.Combine(appDirectory, "deskbox_native.dll");
        await File.WriteAllTextAsync(sourceExe, "exe");
        await File.WriteAllTextAsync(sourceRuntimeConfig, "{}");
        await File.WriteAllTextAsync(nativeModule, "native");

        string detachedExe = AppUpdateService.PrepareDetachedUpdaterHelper(appDirectory, updateRoot);

        Assert.True(File.Exists(detachedExe));
        Assert.NotEqual(sourceExe, detachedExe);
        Assert.StartsWith(Path.Combine(updateRoot, "helper"), detachedExe, StringComparison.OrdinalIgnoreCase);
        Assert.Equal("exe", await File.ReadAllTextAsync(detachedExe));
        Assert.True(File.Exists(Path.Combine(Path.GetDirectoryName(detachedExe)!, "DeskBox.Updater.runtimeconfig.json")));
        Assert.False(File.Exists(Path.Combine(Path.GetDirectoryName(detachedExe)!, "deskbox_native.dll")));
    }

    [Fact]
    public void TryGetInstallDirectory_ReturnsCurrentDeskBoxExecutableDirectory()
    {
        string installDirectory = Directory.CreateDirectory(Path.Combine(_tempRoot, "D-drive", "DeskBox")).FullName;
        string appPath = Path.Combine(installDirectory, "DeskBox.exe");
        File.WriteAllText(appPath, "exe");

        bool resolved = AppUpdateService.TryGetInstallDirectory(appPath, out string actualDirectory);

        Assert.True(resolved);
        Assert.Equal(installDirectory, actualDirectory, ignoreCase: true);
        Assert.False(AppUpdateService.TryGetInstallDirectory(
            Path.Combine(installDirectory, "OtherApp.exe"),
            out _));
    }

    public void Dispose()
    {
        if (Directory.Exists(_tempRoot))
        {
            Directory.Delete(_tempRoot, recursive: true);
        }
    }

    private static HttpClient CreateHttpClient(Func<HttpRequestMessage, HttpResponseMessage> handler)
    {
        return new HttpClient(new StubHttpMessageHandler(handler));
    }

    private sealed class StubHttpMessageHandler : HttpMessageHandler
    {
        private readonly Func<HttpRequestMessage, HttpResponseMessage> _handler;

        public StubHttpMessageHandler(Func<HttpRequestMessage, HttpResponseMessage> handler)
        {
            _handler = handler;
        }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            return Task.FromResult(_handler(request));
        }
    }

    [Theory]
    [InlineData("https://deskbox.fun/update/DeskBox_Setup.exe", false)]
    [InlineData("https://cdn.deskbox.fun/DeskBox_Setup.exe", false)]
    [InlineData("https://github.com/sltalex/SltBox/releases/download/v1.5.6/DeskBox_Setup.exe", true)]
    [InlineData("https://github.com/sltalex/DeskBox/releases/download/v1.5.6/DeskBox_Setup.exe", false)]
    [InlineData("https://sltbox.2leaves.cn/releases/DeskBox_Setup_2026101304_x64.exe", false)]
    [InlineData("https://sltbox.2leaves.cn/releases/other.exe", false)]
    [InlineData("https://sltbox.2leaves.cn/other/DeskBox_Setup_2026101304_x64.exe", false)]
    [InlineData("http://sltbox.2leaves.cn/releases/DeskBox_Setup_2026101304_x64.exe", false)]
    [InlineData("https://github.com/Tianyu199509/DeskBox/releases/download/v1.5.2/DeskBox_Setup.exe", false)]
    [InlineData("http://deskbox.fun/update/DeskBox_Setup.exe", false)]
    [InlineData("https://evil.example.com/DeskBox_Setup.exe", false)]
    [InlineData("https://deskbox.fun.evil.example.com/DeskBox_Setup.exe", false)]
    [InlineData("https://github.evil.example.com/DeskBox_Setup.exe", false)]
    [InlineData("https://github.com/attacker/evil/releases/download/v1.0.0/DeskBox_Setup.exe", false)]
    [InlineData("https://github.com/Tianyu199509/DeskBox/blob/main/DeskBox_Setup.exe", false)]
    [InlineData("https://objects.githubusercontent.com/some-asset-path", false)]
    public void IsManifestUsable_PinsTheInstallerOriginToTrustedHttpsHosts(
        string downloadUrl,
        bool expected)
    {
        var manifest = new AppUpdateManifest
        {
            Version = "1.5.2.0",
            DownloadUrl = downloadUrl
        };

        Assert.Equal(expected, AppUpdateService.IsManifestUsable(manifest));
    }

    [Fact]
    public void IsManifestUsable_RejectsAnUntrustedArm64DownloadUrl()
    {
        var manifest = new AppUpdateManifest
        {
            Version = "1.5.2.0",
            DownloadUrl = "https://github.com/sltalex/SltBox/releases/download/v1.5.6/DeskBox_Setup_1.5.6_x64.exe",
            Arm64DownloadUrl = "http://evil.example.com/DeskBox_Setup_arm64.exe"
        };

        Assert.False(AppUpdateService.IsManifestUsable(manifest));
    }
}
