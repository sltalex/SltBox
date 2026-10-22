using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using DeskBox.Services;

namespace DeskBox.Core.Persistence;

internal sealed record AccountBackupRestoreResult(bool Found, bool Applied, string? Error)
{
    internal static AccountBackupRestoreResult None { get; } = new(false, false, null);
}

internal sealed class AccountBackupFileCatalog
{
    private const int MaximumFileCount = 128;
    private const int MaximumFileBytes = 2 * 1024 * 1024;
    private const long MaximumTotalBytes = 16 * 1024 * 1024;
    private readonly string _dataDirectory;
    private readonly string _rootDirectory;
    private readonly string _restoreDirectory;
    private readonly string _markerPath;
    private readonly string _rollbackDirectory;
    private readonly string _rollbackJournalPath;
    private readonly Func<string, bool>? _shouldFailApply;

    internal AccountBackupFileCatalog(
        string dataDirectory,
        Func<string, bool>? shouldFailApply = null)
    {
        _dataDirectory = Path.GetFullPath(dataDirectory);
        _rootDirectory = Path.GetDirectoryName(_dataDirectory) ?? _dataDirectory;
        _restoreDirectory = Path.Combine(_rootDirectory, "account-restore");
        _markerPath = Path.Combine(_restoreDirectory, "pending.json");
        _rollbackDirectory = Path.Combine(_restoreDirectory, "apply-rollback");
        _rollbackJournalPath = Path.Combine(_restoreDirectory, "apply-rollback.paths");
        _shouldFailApply = shouldFailApply;
    }

    internal async Task<AccountBackupFileDto[]> CaptureAsync(CancellationToken cancellationToken = default,
        string? ownerUserId = null)
    {
        if (!Directory.Exists(_dataDirectory)) return [];
        DingTalkGroupDocumentFavorites.MigrateLegacyFiles(_dataDirectory);
        var files = new List<AccountBackupFileDto>();
        long totalBytes = 0;
        foreach (string path in Directory.EnumerateFiles(_dataDirectory, "*.json", SearchOption.AllDirectories))
        {
            cancellationToken.ThrowIfCancellationRequested();
            string relative = NormalizeRelativePath(path);
            if (!IsEligibleRelativePath(relative)) continue;
            if (ownerUserId is not null && relative.StartsWith("dingtalk/group-document-favorites-", StringComparison.Ordinal) &&
                Path.GetFileName(relative) != DingTalkGroupDocumentFavorites.AccountFileName(ownerUserId)) continue;
            if (ownerUserId is not null && relative.StartsWith("fruit/fruit-state-account-", StringComparison.OrdinalIgnoreCase) &&
                !relative.Equals($"fruit/fruit-state-account-{SafeAccountId(ownerUserId)}.json", StringComparison.OrdinalIgnoreCase)) continue;
            byte[] bytes = await File.ReadAllBytesAsync(path, cancellationToken);
            if (bytes.Length > MaximumFileBytes || (totalBytes += bytes.Length) > MaximumTotalBytes)
                throw new InvalidDataException("The account backup exceeds its size limit.");
            string content = Encoding.UTF8.GetString(bytes);
            ValidateJson(content, relative);
            files.Add(new AccountBackupFileDto
            {
                Path = relative,
                Content = content,
                Bytes = bytes.Length,
                Sha256 = Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant()
            });
            if (files.Count > MaximumFileCount)
                throw new InvalidDataException("The account backup contains too many JSON files.");
        }
        return [.. files.OrderBy(file => file.Path, StringComparer.Ordinal)];
    }

    internal async Task PrepareRestoreAsync(
        AccountBackupSnapshotDownloadDto snapshot,
        string ownerUserId,
        Func<CancellationToken, Task> createSafetySnapshot,
        CancellationToken cancellationToken = default)
    {
        ValidateSnapshot(snapshot);
        if (snapshot.Files.Any(file => IsOtherAccountData(file.Path, ownerUserId)))
            throw new InvalidDataException("The backup contains favorites belonging to another account.");
        string stagingDirectory = Path.Combine(_restoreDirectory, "staging", Guid.NewGuid().ToString("N"));
        string stagingData = Path.Combine(stagingDirectory, "data");
        Directory.CreateDirectory(stagingData);
        try
        {
            foreach (AccountBackupFileDto file in snapshot.Files)
            {
                cancellationToken.ThrowIfCancellationRequested();
                string destination = SafeDestination(stagingData, file.Path);
                Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
                await File.WriteAllTextAsync(destination, file.Content, new UTF8Encoding(false), cancellationToken);
            }

            await createSafetySnapshot(cancellationToken);
            Directory.CreateDirectory(_restoreDirectory);
            var marker = new AccountRestoreMarker
            {
                SnapshotId = snapshot.Id,
                OwnerUserId = ownerUserId,
                StagingDirectory = stagingDirectory
            };
            await File.WriteAllTextAsync(
                _markerPath,
                JsonSerializer.Serialize(marker, AccountCloudJsonContext.Default.AccountRestoreMarker),
                new UTF8Encoding(false),
                cancellationToken);
        }
        catch
        {
            TryDeleteDirectory(stagingDirectory);
            throw;
        }
    }

    internal async Task<AccountBackupRestoreResult> ApplyPendingRestoreAsync(CancellationToken cancellationToken = default)
    {
        if (!File.Exists(_markerPath))
        {
            TryDeleteDirectory(_rollbackDirectory);
            TryDeleteFile(_rollbackJournalPath);
            return AccountBackupRestoreResult.None;
        }
        try
        {
            AccountRestoreMarker marker = JsonSerializer.Deserialize(
                await File.ReadAllTextAsync(_markerPath, cancellationToken),
                AccountCloudJsonContext.Default.AccountRestoreMarker)
                ?? throw new InvalidDataException("The account restore marker is invalid.");
            string stagingRoot = Path.GetFullPath(marker.StagingDirectory);
            string expectedRoot = Path.GetFullPath(Path.Combine(_restoreDirectory, "staging")) + Path.DirectorySeparatorChar;
            if (!stagingRoot.StartsWith(expectedRoot, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("The account restore staging path is invalid.");
            string stagedData = Path.Combine(stagingRoot, "data");
            var staged = Directory.EnumerateFiles(stagedData, "*.json", SearchOption.AllDirectories)
                .Select(path => NormalizeRelativePath(path, stagedData))
                .Where(IsEligibleRelativePath)
                .Where(path => !IsOtherAccountData(path, marker.OwnerUserId))
                .ToHashSet(StringComparer.OrdinalIgnoreCase);

            // A previous process may have stopped between moving the live set
            // aside and committing the restored set. Rebuild the old complete
            // set before retrying so startup never continues with a mixture.
            RestoreRollbackSet();
            Directory.CreateDirectory(_dataDirectory);
            string[] livePaths = EnumerateEligibleFiles(_dataDirectory);
            string[] originalPaths = livePaths
                .Select(path => NormalizeRelativePath(path))
                .OrderBy(path => path, StringComparer.Ordinal)
                .ToArray();
            await WriteRollbackJournalAsync(originalPaths, cancellationToken);
            // The directory itself is the durable signal that an apply
            // transaction is active, even when the original managed set is
            // empty. This lets rollback remove any partially applied files.
            Directory.CreateDirectory(_rollbackDirectory);
            foreach (string livePath in livePaths)
            {
                string relative = NormalizeRelativePath(livePath);
                if (IsOtherAccountData(relative, marker.OwnerUserId)) continue;
                string rollback = SafeDestination(_rollbackDirectory, relative);
                Directory.CreateDirectory(Path.GetDirectoryName(rollback)!);
                File.Move(livePath, rollback, overwrite: true);
            }
            foreach (string relative in staged)
            {
                cancellationToken.ThrowIfCancellationRequested();
                if (_shouldFailApply?.Invoke(relative) == true)
                    throw new IOException("Injected account restore apply failure.");
                string source = SafeDestination(stagedData, relative);
                string destination = SafeDestination(_dataDirectory, relative);
                Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
                string temporary = destination + ".account-restore.tmp";
                File.Copy(source, temporary, overwrite: true);
                File.Move(temporary, destination, overwrite: true);
            }

            // Deleting the marker is the transaction commit point. Until it
            // succeeds, the rollback set remains available for this catch or
            // the next startup after an abrupt process termination.
            File.Delete(_markerPath);
            TryDeleteDirectory(_rollbackDirectory);
            TryDeleteFile(_rollbackJournalPath);
            TryDeleteDirectory(stagingRoot);
            return new AccountBackupRestoreResult(true, true, null);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { throw; }
        catch (Exception ex)
        {
            try { RestoreRollbackSet(); }
            catch (Exception rollbackError)
            {
                App.Log($"[AccountBackup] Restore rollback failed: {rollbackError.GetType().Name}");
            }
            App.Log($"[AccountBackup] Pending restore failed: {ex.GetType().Name}");
            return new AccountBackupRestoreResult(true, false, ex.Message);
        }
    }

    internal void CancelPreparedRestore()
    {
        if (!File.Exists(_markerPath)) return;
        try
        {
            AccountRestoreMarker? marker = JsonSerializer.Deserialize(
                File.ReadAllText(_markerPath),
                AccountCloudJsonContext.Default.AccountRestoreMarker);
            if (marker is not null)
            {
                string stagingRoot = Path.GetFullPath(marker.StagingDirectory);
                string expectedRoot = Path.GetFullPath(Path.Combine(_restoreDirectory, "staging")) + Path.DirectorySeparatorChar;
                if (stagingRoot.StartsWith(expectedRoot, StringComparison.OrdinalIgnoreCase))
                    TryDeleteDirectory(stagingRoot);
            }
        }
        catch (Exception ex)
        {
            App.Log($"[AccountBackup] Prepared restore cleanup failed: {ex.GetType().Name}");
        }
        finally
        {
            TryDeleteFile(_markerPath);
        }
    }

    internal static bool IsEligibleRelativePath(string relativePath)
    {
        string normalized = relativePath.Replace('\\', '/');
        if (!normalized.EndsWith(".json", StringComparison.OrdinalIgnoreCase) ||
            normalized.Equals("weather-cache.json", StringComparison.OrdinalIgnoreCase)) return false;
        string[] segments = normalized.Split('/', StringSplitOptions.RemoveEmptyEntries);
        if (segments.Length == 0 || segments.Any(segment =>
                segment.Equals("cache", StringComparison.OrdinalIgnoreCase) ||
                segment.Equals("sync", StringComparison.OrdinalIgnoreCase) ||
                segment.Equals("attachments", StringComparison.OrdinalIgnoreCase))) return false;
        string name = segments[^1];
        return !name.EndsWith(".bak", StringComparison.OrdinalIgnoreCase) &&
               !name.EndsWith(".tmp", StringComparison.OrdinalIgnoreCase) &&
               !name.Contains(".corrupt-", StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsOtherAccountData(string path, string ownerId)
    {
        string normalized = path.Replace('\\', '/');
        return normalized.StartsWith("dingtalk/group-document-favorites-account-", StringComparison.OrdinalIgnoreCase) &&
                !string.Equals(Path.GetFileName(normalized), DingTalkGroupDocumentFavorites.AccountFileName(ownerId), StringComparison.OrdinalIgnoreCase)
            || normalized.StartsWith("fruit/fruit-state-account-", StringComparison.OrdinalIgnoreCase) &&
                !normalized.Equals($"fruit/fruit-state-account-{SafeAccountId(ownerId)}.json", StringComparison.OrdinalIgnoreCase);
    }

    private void ValidateSnapshot(AccountBackupSnapshotDownloadDto snapshot)
    {
        if (snapshot.Files.Length is < 1 or > MaximumFileCount)
            throw new InvalidDataException("The account backup file count is invalid.");
        long total = 0;
        var paths = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (AccountBackupFileDto file in snapshot.Files)
        {
            if (!IsSafeRelativeJsonPath(file.Path) || !IsEligibleRelativePath(file.Path) || !paths.Add(file.Path))
                throw new InvalidDataException("The account backup contains an unsafe path.");
            byte[] bytes = Encoding.UTF8.GetBytes(file.Content);
            total += bytes.Length;
            if (bytes.Length != file.Bytes || bytes.Length > MaximumFileBytes || total > MaximumTotalBytes ||
                !string.Equals(Convert.ToHexString(SHA256.HashData(bytes)), file.Sha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("The account backup failed integrity validation.");
            ValidateJson(file.Content, file.Path);
        }
        if (snapshot.FileCount > 0 && snapshot.FileCount != snapshot.Files.Length)
            throw new InvalidDataException("The account backup file count does not match its manifest.");
        if (snapshot.TotalBytes > 0 && snapshot.TotalBytes != total)
            throw new InvalidDataException("The account backup size does not match its manifest.");
        if (!string.IsNullOrWhiteSpace(snapshot.Sha256))
        {
            using IncrementalHash manifest = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
            foreach (AccountBackupFileDto file in snapshot.Files.OrderBy(item => item.Path, StringComparer.Ordinal))
            {
                byte[] line = Encoding.UTF8.GetBytes($"{file.Path}\0{file.Sha256.ToLowerInvariant()}\0{file.Bytes}\n");
                manifest.AppendData(line);
            }
            if (!string.Equals(Convert.ToHexString(manifest.GetHashAndReset()), snapshot.Sha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("The account backup manifest hash does not match.");
        }
    }

    private static bool IsSafeRelativeJsonPath(string path)
    {
        if (string.IsNullOrWhiteSpace(path) || Path.IsPathRooted(path) || path.Contains('\\') ||
            !path.EndsWith(".json", StringComparison.OrdinalIgnoreCase)) return false;
        char[] invalid = Path.GetInvalidFileNameChars();
        return path.Split('/').All(segment => segment is not "" and not "." and not ".." && segment.IndexOfAny(invalid) < 0);
    }

    private static void ValidateJson(string content, string path)
    {
        try { using JsonDocument _ = JsonDocument.Parse(content); }
        catch (JsonException ex) { throw new InvalidDataException($"Managed JSON file '{path}' is invalid.", ex); }
    }

    private string NormalizeRelativePath(string path, string? root = null) =>
        Path.GetRelativePath(root ?? _dataDirectory, path).Replace(Path.DirectorySeparatorChar, '/');

    private static string[] EnumerateEligibleFiles(string root)
    {
        if (!Directory.Exists(root)) return [];
        return Directory.EnumerateFiles(root, "*.json", SearchOption.AllDirectories)
            .Where(path => IsEligibleRelativePath(
                Path.GetRelativePath(root, path).Replace(Path.DirectorySeparatorChar, '/')))
            .ToArray();
    }

    private void RestoreRollbackSet()
    {
        if (!Directory.Exists(_rollbackDirectory))
        {
            TryDeleteFile(_rollbackJournalPath);
            return;
        }
        if (!File.Exists(_rollbackJournalPath))
            throw new InvalidDataException("The account restore rollback journal is missing.");
        string[] originalPaths = JsonSerializer.Deserialize(
            File.ReadAllText(_rollbackJournalPath),
            AccountCloudJsonContext.Default.StringArray)
            ?? throw new InvalidDataException("The account restore rollback journal is invalid.");
        var originals = originalPaths.ToHashSet(StringComparer.OrdinalIgnoreCase);
        var rolledBack = EnumerateEligibleFiles(_rollbackDirectory)
            .Select(path => NormalizeRelativePath(path, _rollbackDirectory))
            .ToHashSet(StringComparer.OrdinalIgnoreCase);
        Directory.CreateDirectory(_dataDirectory);
        foreach (string livePath in EnumerateEligibleFiles(_dataDirectory))
        {
            string relative = NormalizeRelativePath(livePath);
            if (!originals.Contains(relative) || rolledBack.Contains(relative))
                File.Delete(livePath);
        }
        foreach (string rollbackPath in EnumerateEligibleFiles(_rollbackDirectory))
        {
            string relative = NormalizeRelativePath(rollbackPath, _rollbackDirectory);
            string destination = SafeDestination(_dataDirectory, relative);
            Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
            File.Move(rollbackPath, destination, overwrite: true);
        }
        TryDeleteDirectory(_rollbackDirectory);
        TryDeleteFile(_rollbackJournalPath);
    }

    private async Task WriteRollbackJournalAsync(string[] originalPaths, CancellationToken cancellationToken)
    {
        Directory.CreateDirectory(_restoreDirectory);
        string temporary = _rollbackJournalPath + ".tmp";
        await File.WriteAllTextAsync(
            temporary,
            JsonSerializer.Serialize(originalPaths, AccountCloudJsonContext.Default.StringArray),
            new UTF8Encoding(false),
            cancellationToken);
        File.Move(temporary, _rollbackJournalPath, overwrite: true);
    }

    private static string SafeDestination(string root, string relative)
    {
        if (!IsSafeRelativeJsonPath(relative)) throw new InvalidDataException("The account backup path is unsafe.");
        string fullRoot = Path.GetFullPath(root) + Path.DirectorySeparatorChar;
        string result = Path.GetFullPath(Path.Combine(root, relative.Replace('/', Path.DirectorySeparatorChar)));
        if (!result.StartsWith(fullRoot, StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException("The account backup path escapes its root.");
        return result;
    }

    internal static string SafeAccountId(string value)
    {
        string safe = string.Concat(value.Where(character => char.IsAsciiLetterOrDigit(character) || character is '-' or '_'));
        return safe.Length == 0
            ? Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(value))).ToLowerInvariant()[..32]
            : safe[..Math.Min(64, safe.Length)];
    }

    internal static void DeleteFileIfExists(string path)
    {
        if (File.Exists(path)) File.Delete(path);
    }

    private static void TryDeleteFile(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); } catch { }
    }

    private static void TryDeleteDirectory(string path)
    {
        try { if (Directory.Exists(path)) Directory.Delete(path, recursive: true); } catch { }
    }
}
