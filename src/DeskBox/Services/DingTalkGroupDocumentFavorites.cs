using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.Json.Serialization.Metadata;
using DeskBox.Core.Persistence;
using DeskBox.Contracts;

namespace DeskBox.Services;

/// <summary>Account-scoped document favorites stored with the user's backup data.</summary>
public sealed class DingTalkGroupDocumentFavorites : IDisposable
{
    private const string FilePrefix = "group-document-favorites-";
    private static readonly SemaphoreSlim StoreGate = new(1, 1);
    private readonly IDingTalkDwsClient _dws;
    private readonly IAuthSession? _auth;
    private readonly DingTalkAccountService? _account;
    private readonly string _stateDirectory;
    private readonly string? _legacyStateDirectory;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private volatile HashSet<string> _keys = new(StringComparer.Ordinal);
    private volatile DingTalkGroupDocument[] _favoriteDocuments = [];
    private string? _statePath;
    private string? _ownerKey;

    public DingTalkGroupDocumentFavorites(IDingTalkDwsClient dws, string? stateDirectory = null,
        string? legacyStateDirectory = null, IAuthSession? auth = null, DingTalkAccountService? account = null)
    {
        _dws = dws;
        _auth = auth;
        _account = account;
        if (_auth is not null) _auth.SessionChanged += SessionChanged;
        _stateDirectory = stateDirectory ?? Path.Combine(
            DeskBoxDataPathService.Current.DataDirectory, "dingtalk");
        _legacyStateDirectory = legacyStateDirectory ?? (stateDirectory is null
            ? Path.Combine(DeskBoxDataPathService.Current.DataDirectory, "sync", "dingtalk")
            : null);
    }

    public event Action? Changed;

    private bool IsCurrentOwner => _auth is null ||
        (_auth.IsAuthenticated && _ownerKey == "sltbox:" + _auth.CurrentUser?.Id);

    private void SessionChanged()
    {
        if (_auth?.IsAuthenticated == true && _ownerKey == "sltbox:" + _auth.CurrentUser?.Id) return;
        ClearVisibleState();
        Changed?.Invoke();
    }

    public void Dispose()
    {
        if (_auth is not null) _auth.SessionChanged -= SessionChanged;
    }

    public async Task AddManualAsync(string title, string url, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(title) || title.Trim().Length > 255)
            throw new ArgumentException("A document name of up to 255 characters is required.", nameof(title));
        if (string.IsNullOrWhiteSpace(url) || url.Length > 4096)
            throw new ArgumentException("A document URL is required.", nameof(url));
        _ = DocumentKey(url.Trim());
        await SetFavoriteAsync(new DingTalkGroupDocument(string.Empty, title.Trim(), url.Trim(),
            DateTimeOffset.UtcNow, "manual"), true, cancellationToken);
    }

    internal static string AccountFileName(string userId) => FileName("sltbox:" + userId);
    private static string FileName(string ownerKey) => FilePrefix + (ownerKey.StartsWith("sltbox:", StringComparison.Ordinal) ? "account-" : "") +
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(ownerKey))).ToLowerInvariant() + ".json";

    public bool IsFavorite(string url)
    {
        string urlKey = DocumentKey(url);
        return IsCurrentOwner && (_keys.Contains(urlKey) || _favoriteDocuments.Any(document => DocumentKey(document.Url) == urlKey));
    }

    public bool IsFavorite(DingTalkGroupDocument document) =>
        IsCurrentOwner && (_keys.Contains(DocumentIdentity(document)) ||
        _keys.Contains(LegacyGroupIdentity(document)) ||
        _keys.Contains(DocumentKey(document.Url)));

    public IReadOnlyList<DingTalkGroupDocument> FavoriteDocuments => IsCurrentOwner ? _favoriteDocuments : [];

    /// <summary>Returns the current account's favorite snapshot for account sync.</summary>
    internal async Task<DingTalkGroupDocumentFavoritesStore> ExportSnapshotAsync(
        CancellationToken cancellationToken = default)
    {
        await LoadAsync(cancellationToken);
        return new DingTalkGroupDocumentFavoritesStore
        {
            DocumentUrls = [.. _keys.Where(IsLegacyUrlKey).Order(StringComparer.Ordinal)],
            Documents = [.. _favoriteDocuments]
        };
    }

    /// <summary>Merges a remote account snapshot without resurrecting invalid URLs.</summary>
    internal async Task MergeSnapshotAsync(
        DingTalkGroupDocumentFavoritesStore snapshot,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(snapshot);
        foreach (string url in snapshot.DocumentUrls ?? [])
        {
            try { await SetFavoriteAsync(url, true, cancellationToken); }
            catch (ArgumentException) { }
        }

        foreach (DingTalkGroupDocument document in snapshot.Documents ?? [])
        {
            try { await SetFavoriteAsync(document, true, cancellationToken); }
            catch (ArgumentException) { }
        }
    }

    internal async Task ReplaceSnapshotAsync(
        DingTalkGroupDocumentFavoritesStore snapshot,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(snapshot);
        await _gate.WaitAsync(cancellationToken);
        try
        {
            string owner = await GetOwnerKeyAsync(cancellationToken);
            await StoreGate.WaitAsync(cancellationToken);
            try
            {
                await LoadCoreAsync(owner, cancellationToken);
                var keys = new HashSet<string>(StringComparer.Ordinal);
                var documents = new Dictionary<string, DingTalkGroupDocument>(StringComparer.Ordinal);
                foreach (string url in snapshot.DocumentUrls ?? [])
                    if (TryDocumentKey(url, out string? key) && key is not null) keys.Add(key);
                foreach (DingTalkGroupDocument document in snapshot.Documents ?? [])
                {
                    if (!TryDocumentKey(document.Url, out string? urlKey) || urlKey is null) continue;
                    string key = DocumentIdentity(document);
                    keys.Remove(urlKey);
                    keys.Add(key);
                    documents[key] = document;
                }
                EnsureCurrentOwner(owner);
                await SaveCoreAsync(_statePath!, keys, documents.Values, cancellationToken);
                EnsureCurrentOwner(owner);
                _keys = keys;
                _favoriteDocuments = [.. documents.Values.OrderBy(DocumentIdentity, StringComparer.Ordinal)];
            }
            finally { StoreGate.Release(); }
        }
        finally { _gate.Release(); }
        Changed?.Invoke();
    }

    internal static string CanonicalKey(string url) => DocumentKey(url);

    internal static string CanonicalKey(DingTalkGroupDocument document) => DocumentIdentity(document);

    // Backups may run before the widget is opened or DWS has authenticated.
    // Migrate only known favorite stores, never the device-local sync index.
    internal static void MigrateLegacyFiles(string dataDirectory)
    {
        StoreGate.Wait();
        try { MigrateLegacyFilesCore(dataDirectory); }
        finally { StoreGate.Release(); }
    }

    private static void MigrateLegacyFilesCore(string dataDirectory)
    {
        string legacyDirectory = Path.Combine(dataDirectory, "sync", "dingtalk");
        if (!Directory.Exists(legacyDirectory)) return;
        string managedDirectory = Path.Combine(dataDirectory, "dingtalk");
        foreach (string source in Directory.EnumerateFiles(legacyDirectory, FilePrefix + "*.json"))
        {
            string name = Path.GetFileName(source);
            if (name.Length != FilePrefix.Length + 64 + ".json".Length ||
                !name.AsSpan(FilePrefix.Length, 64).ToArray().All(Uri.IsHexDigit)) continue;
            string destination = Path.Combine(managedDirectory, name);
            string digest = name.Substring(FilePrefix.Length, 64);
            string indexPath = Path.Combine(legacyDirectory, $"group-documents-{digest}.json");
            MigrateLegacyFile(source, destination, indexPath);
        }
    }

    private static void MigrateLegacyFile(string source, string destination, string indexPath)
    {
        DingTalkGroupDocumentFavoritesStore? store;
        try
        {
            // An existing managed file is authoritative: a removed star must never
            // be reintroduced by the older sync copy.
            store = DeserializeStore(File.ReadAllText(File.Exists(destination) ? destination : source),
                DingTalkGroupDocumentFavoritesJsonContext.Default.DingTalkGroupDocumentFavoritesStore);
        }
        catch (JsonException) { return; }
        if (store is null) return;

        var keys = new HashSet<string>(StringComparer.Ordinal);
        foreach (string url in store.DocumentUrls ?? [])
            if (TryDocumentKey(url, out string? key) && key is not null) keys.Add(key);
        foreach (DingTalkGroupDocument document in store.Documents ?? [])
            if (TryDocumentKey(document.Url, out string? key) && key is not null) keys.Add(key);

        var documents = new Dictionary<string, DingTalkGroupDocument>(StringComparer.Ordinal);
        foreach (DingTalkGroupDocument document in store.Documents ?? [])
            if (TryDocumentKey(document.Url, out string? key) && key is not null && keys.Contains(key))
                documents[DocumentIdentity(document)] = document;
        if (File.Exists(indexPath))
        {
            try
            {
                DingTalkGroupDocumentStore? index = DeserializeStore(File.ReadAllText(indexPath),
                    DingTalkGroupDocumentJsonContext.Default.DingTalkGroupDocumentStore);
                foreach (DingTalkGroupDocument document in (index?.Documents ?? [])
                    .OrderByDescending(item => item.Title != "群文档")
                    .ThenByDescending(item => item.LastSeenAt))
                {
                    if (!TryDocumentKey(document.Url, out string? key) || key is null || !keys.Contains(key)) continue;
                    string identity = DocumentIdentity(document);
                    if (documents.TryGetValue(identity, out DingTalkGroupDocument? current))
                    {
                        if (current.Title == "群文档" && document.Title != "群文档")
                            documents[identity] = current with { Title = document.Title };
                    }
                    else if (!documents.Values.Any(item => DocumentKey(item.Url) == key))
                        documents[identity] = document;
                }
            }
            catch (Exception error) when (error is IOException or JsonException) { }
        }

        bool needsWrite = !File.Exists(destination) || documents.Count != (store.Documents?.Length ?? 0) ||
            documents.Any(pair => !(store.Documents ?? []).Any(item =>
                TryDocumentKey(item.Url, out _) && DocumentIdentity(item) == pair.Key && item == pair.Value));
        string? json = null;
        if (needsWrite)
        {
            store.Documents = [.. documents.Values.OrderBy(item => DocumentKey(item.Url), StringComparer.Ordinal)];
            json = SerializeStore(store,
                DingTalkGroupDocumentFavoritesJsonContext.Default.DingTalkGroupDocumentFavoritesStore);
        }
        // Preserve the old copy for manual recovery, but prevent a later
        // whole-snapshot restore from resurrecting stars outside that snapshot.
        DingTalkFavoritesFileMigration.Commit(source, destination, json);
    }

    public async Task LoadAsync(CancellationToken cancellationToken = default)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            string status = await GetOwnerKeyAsync(cancellationToken);
            await StoreGate.WaitAsync(cancellationToken);
            try { await LoadCoreAsync(status, cancellationToken); }
            finally { StoreGate.Release(); }
        }
        finally { _gate.Release(); }
    }

    public async Task SetFavoriteAsync(string url, bool favorite, CancellationToken cancellationToken = default)
        => await SetFavoriteCoreAsync(url, null, favorite, cancellationToken);

    public async Task SetFavoriteAsync(DingTalkGroupDocument document, bool favorite,
        CancellationToken cancellationToken = default)
        => await SetFavoriteCoreAsync(document.Url, document, favorite, cancellationToken);

    private async Task SetFavoriteCoreAsync(string url, DingTalkGroupDocument? document, bool favorite,
        CancellationToken cancellationToken)
    {
        string urlKey = DocumentKey(url);
        string key = document is null ? urlKey : DocumentIdentity(document);
        bool changed = false;
        await _gate.WaitAsync(cancellationToken);
        try
        {
            string status = await GetOwnerKeyAsync(cancellationToken);
            await StoreGate.WaitAsync(cancellationToken);
            try
            {
                await LoadCoreAsync(status, cancellationToken);
                var next = new HashSet<string>(_keys, StringComparer.Ordinal);
                var documents = _favoriteDocuments.ToDictionary(DocumentIdentity, StringComparer.Ordinal);
                if (document is not null && next.Remove(urlKey)) changed = true;
                if (document is not null)
                {
                    string legacyGroupKey = LegacyGroupIdentity(document);
                    if (legacyGroupKey != key && next.Remove(legacyGroupKey)) changed = true;
                    if (legacyGroupKey != key && documents.Remove(legacyGroupKey)) changed = true;
                }
                changed |= favorite ? next.Add(key) : next.Remove(key);
                if (favorite && document is not null &&
                    (!documents.TryGetValue(key, out DingTalkGroupDocument? old) ||
                     old.GroupName != document.GroupName || old.Title != document.Title ||
                     old.Url != document.Url || old.ConversationId != document.ConversationId))
                {
                    documents[key] = document;
                    changed = true;
                }
                if (!favorite) documents.Remove(key);
                if (!changed) return;
                await SaveCoreAsync(_statePath!, next, documents.Values, cancellationToken);
                EnsureCurrentOwner(status);
                _keys = next;
                _favoriteDocuments = [.. documents.Values.OrderBy(DocumentIdentity, StringComparer.Ordinal)];
            }
            finally { StoreGate.Release(); }
        }
        finally { _gate.Release(); }
        if (changed) Changed?.Invoke();
    }

    public async Task UpdateDocumentMetadataAsync(IReadOnlyList<DingTalkGroupDocument> documents,
        CancellationToken cancellationToken = default)
    {
        bool changed = false;
        await _gate.WaitAsync(cancellationToken);
        try
        {
            string status = await GetOwnerKeyAsync(cancellationToken);
            await StoreGate.WaitAsync(cancellationToken);
            try
            {
                await LoadCoreAsync(status, cancellationToken);
                var next = new HashSet<string>(_keys, StringComparer.Ordinal);
                var known = _favoriteDocuments.ToDictionary(DocumentIdentity, StringComparer.Ordinal);
                foreach (DingTalkGroupDocument old in known.Values.ToArray())
                {
                    DingTalkGroupDocument repaired = DingTalkGroupDocumentRepair.Repair(old, documents);
                    if (old == repaired) continue;
                    next.Remove(DocumentIdentity(old));
                    known.Remove(DocumentIdentity(old));
                    next.Add(DocumentIdentity(repaired));
                    known[DocumentIdentity(repaired)] = repaired;
                    changed = true;
                }
                foreach (DingTalkGroupDocument indexed in documents)
                {
                    if (!TryDocumentKey(indexed.Url, out string? urlKey) || urlKey is null) continue;
                    string key = DocumentIdentity(indexed);
                    string legacyGroupKey = LegacyGroupIdentity(indexed);
                    bool legacyUrl = next.Contains(urlKey);
                    bool legacyGroup = legacyGroupKey != key && next.Contains(legacyGroupKey);
                    if (!next.Contains(key) && !legacyUrl && !legacyGroup) continue;
                    if (legacyUrl || legacyGroup)
                    {
                        next.Remove(urlKey);
                        next.Remove(legacyGroupKey);
                        next.Add(key);
                        if (legacyGroup && known.Remove(legacyGroupKey, out DingTalkGroupDocument? legacyDocument) &&
                            !known.ContainsKey(key))
                            known[key] = legacyDocument with { ConversationId = indexed.ConversationId };
                        changed = true;
                    }
                    DingTalkGroupDocument document = indexed;
                    if (known.TryGetValue(key, out DingTalkGroupDocument? named) &&
                        document.Title == "群文档" && named.Title != "群文档")
                        document = document with { Title = named.Title };
                    if (known.TryGetValue(key, out DingTalkGroupDocument? old) &&
                        old.GroupName == document.GroupName && old.Title == document.Title &&
                        old.Url == document.Url && old.ConversationId == document.ConversationId) continue;
                    known[key] = document;
                    changed = true;
                }
                if (!changed) return;
                await SaveCoreAsync(_statePath!, next, known.Values, cancellationToken);
                EnsureCurrentOwner(status);
                _keys = next;
                _favoriteDocuments = [.. known.Values.OrderBy(DocumentIdentity, StringComparer.Ordinal)];
            }
            finally { StoreGate.Release(); }
        }
        finally { _gate.Release(); }
        if (changed) Changed?.Invoke();
    }

    private async Task LoadCoreAsync(string accountKey, CancellationToken cancellationToken)
    {
        // Never expose the previous account's stars if the next account's
        // local file cannot be read or parsed.
        ClearVisibleState();
        EnsureCurrentOwner(accountKey);
        string fileName = FileName(accountKey);
        string path = Path.Combine(_stateDirectory, fileName);
        if (_auth is not null)
            await MigrateDwsOwnerAsync(path, accountKey, cancellationToken);
        string? legacyPath = _legacyStateDirectory is null ? null : Path.Combine(_legacyStateDirectory, fileName);
        if (legacyPath is not null && File.Exists(legacyPath))
        {
            string indexPath = Path.Combine(_legacyStateDirectory!,
                "group-documents-" + fileName.Substring(FilePrefix.Length, 64) + ".json");
            MigrateLegacyFile(legacyPath, path, indexPath);
        }
        var urls = new HashSet<string>(StringComparer.Ordinal);
        var documents = new Dictionary<string, DingTalkGroupDocument>(StringComparer.Ordinal);
        string? source = File.Exists(path) ? path : null;
        if (source is not null)
        {
            string json = await File.ReadAllTextAsync(source, cancellationToken);
            DingTalkGroupDocumentFavoritesStore? store = DeserializeStore(
                json, DingTalkGroupDocumentFavoritesJsonContext.Default.DingTalkGroupDocumentFavoritesStore);
            foreach (string url in store?.DocumentUrls ?? [])
            {
                if (TryDocumentKey(url, out string? key) && key is not null) urls.Add(key);
            }
            foreach (DingTalkGroupDocument document in store?.Documents ?? [])
            {
                if (!TryDocumentKey(document.Url, out string? urlKey) || urlKey is null) continue;
                string key = DocumentIdentity(document);
                urls.Remove(urlKey);
                urls.Add(key);
                documents[key] = document;
            }
        }
        EnsureCurrentOwner(accountKey);
        _ownerKey = accountKey;
        _keys = urls;
        _favoriteDocuments = [.. documents.Values.OrderBy(DocumentIdentity, StringComparer.Ordinal)];
        _statePath = path;
    }

    private async Task<string> GetOwnerKeyAsync(CancellationToken cancellationToken)
    {
        try
        {
            if (_auth is not null)
            {
                if (!_auth.IsAuthenticated || _auth.CurrentUser is not { } user)
                    throw new DingTalkDwsAuthorizationRequiredException("Sign in to SltBox to use favorites.");
                return "sltbox:" + user.Id;
            }
            DingTalkDwsAuthStatus status = await _dws.GetAuthStatusAsync(cancellationToken);
            if (!status.Authenticated || !status.TokenValid ||
                string.IsNullOrWhiteSpace(status.CorpId) || string.IsNullOrWhiteSpace(status.UserId))
                throw new DingTalkDwsAuthorizationRequiredException("DWS authorization is required.");
            return $"{status.CorpId.Trim()}\u001f{status.UserId.Trim()}";
        }
        catch
        {
            ClearVisibleState();
            throw;
        }
    }

    private void EnsureCurrentOwner(string key)
    {
        if (_auth is not null && (!_auth.IsAuthenticated || key != "sltbox:" + _auth.CurrentUser?.Id))
            throw new DingTalkDwsAuthorizationRequiredException("The signed-in account changed.");
    }

    private async Task MigrateDwsOwnerAsync(string path, string owner, CancellationToken cancellationToken)
    {
        if (_account is null) return;
        bool HasLegacy(string? directory) => directory is not null && Directory.Exists(directory) &&
            Directory.EnumerateFiles(directory, FilePrefix + "*.json").Any(file =>
                Path.GetFileName(file).Length == FilePrefix.Length + 64 + 5);
        if (!HasLegacy(_stateDirectory) && !HasLegacy(_legacyStateDirectory)) return;
        try
        {
            var identity = await _account.GetAsync(cancellationToken);
            EnsureCurrentOwner(owner);
            if (identity is null) return;
            var status = await _dws.GetAuthStatusAsync(cancellationToken);
            EnsureCurrentOwner(owner);
            if (!status.Authenticated || !status.TokenValid || status.CorpId != identity.CorpId ||
                status.UserId != identity.DingtalkUserId) return;
            string name = FileName($"{status.CorpId.Trim()}\u001f{status.UserId.Trim()}");
            string source = Path.Combine(_stateDirectory, name);
            if (_legacyStateDirectory is not null && File.Exists(Path.Combine(_legacyStateDirectory, name)))
                MigrateLegacyFile(Path.Combine(_legacyStateDirectory, name), source,
                    Path.Combine(_legacyStateDirectory, name.Replace(FilePrefix, "group-documents-")));
            if (File.Exists(source))
            {
                var context = DingTalkGroupDocumentFavoritesJsonContext.Default.DingTalkGroupDocumentFavoritesStore;
                var legacy = DeserializeStore(await File.ReadAllTextAsync(source, cancellationToken), context);
                var current = File.Exists(path)
                    ? DeserializeStore(await File.ReadAllTextAsync(path, cancellationToken), context) : null;
                var merged = new DingTalkGroupDocumentFavoritesStore
                {
                    DocumentUrls = [.. (current?.DocumentUrls ?? []).Concat(legacy?.DocumentUrls ?? []).Distinct()],
                    Documents = [.. (current?.Documents ?? []).Concat(legacy?.Documents ?? []).DistinctBy(DocumentIdentity)]
                };
                EnsureCurrentOwner(owner);
                DingTalkFavoritesFileMigration.Commit(source, path, SerializeStore(merged, context));
            }
        }
        catch (Exception error) when (error is IOException or InvalidOperationException or
            HttpRequestException or System.ComponentModel.Win32Exception or JsonException or TimeoutException or AuthApiException)
        {
            // Existing favorites remain available for migration when authorization recovers.
            App.Log($"[DingTalkGroupDocs] Favorite migration deferred: {error.GetType().Name}");
        }
    }

    private void ClearVisibleState()
    {
        _keys = new HashSet<string>(StringComparer.Ordinal);
        _favoriteDocuments = [];
        _statePath = null;
        _ownerKey = null;
    }

    private static async Task SaveCoreAsync(string path, HashSet<string> urls,
        IEnumerable<DingTalkGroupDocument> documents, CancellationToken cancellationToken)
    {
        var store = new DingTalkGroupDocumentFavoritesStore
        {
            DocumentUrls = [.. urls.Where(IsLegacyUrlKey).Order(StringComparer.Ordinal)],
            Documents = [.. documents.OrderBy(DocumentIdentity, StringComparer.Ordinal)]
        };
        string json = SerializeStore(
            store, DingTalkGroupDocumentFavoritesJsonContext.Default.DingTalkGroupDocumentFavoritesStore);
        cancellationToken.ThrowIfCancellationRequested();
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        await ResilientJsonStore.SaveAsync(path, json);
    }

    private static T? DeserializeStore<T>(string json, JsonTypeInfo<T> jsonTypeInfo)
        => JsonSerializer.Deserialize(json, jsonTypeInfo);

    private static string SerializeStore<T>(T value, JsonTypeInfo<T> jsonTypeInfo)
        => JsonSerializer.Serialize(value, jsonTypeInfo);

    private static string DocumentKey(string url)
    {
        if (TryDocumentKey(url, out string? key) && key is not null) return key;
        throw new ArgumentException("An HTTP or HTTPS document URL is required.", nameof(url));
    }

    private static string DocumentIdentity(DingTalkGroupDocument document)
    {
        string groupKey = string.IsNullOrWhiteSpace(document.ConversationId)
            ? "name:" + document.GroupName.Trim().ToUpperInvariant()
            : "id:" + document.ConversationId.Trim();
        return $"{groupKey}\u001f{DocumentKey(document.Url)}";
    }

    private static string LegacyGroupIdentity(DingTalkGroupDocument document) =>
        $"name:{document.GroupName.Trim().ToUpperInvariant()}\u001f{DocumentKey(document.Url)}";

    private static bool IsLegacyUrlKey(string key) => key.StartsWith("https://", StringComparison.Ordinal) || key.StartsWith("http://", StringComparison.Ordinal);

    private static bool TryDocumentKey(string? url, out string? key)
    {
        key = null;
        if (!Uri.TryCreate(url, UriKind.Absolute, out Uri? uri) ||
            (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp) ||
            string.IsNullOrWhiteSpace(uri.Host) || !string.IsNullOrEmpty(uri.UserInfo))
            return false;
        key = string.Equals(uri.Host, "alidocs.dingtalk.com", StringComparison.OrdinalIgnoreCase)
            ? uri.GetLeftPart(UriPartial.Path) : uri.AbsoluteUri;
        return true;
    }
}

internal sealed class DingTalkGroupDocumentFavoritesStore
{
    public string[] DocumentUrls { get; set; } = [];
    public DingTalkGroupDocument[] Documents { get; set; } = [];
}

[JsonSerializable(typeof(DingTalkGroupDocumentFavoritesStore))]
internal sealed partial class DingTalkGroupDocumentFavoritesJsonContext : JsonSerializerContext
{
}
