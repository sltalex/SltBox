using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.Json.Serialization.Metadata;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

/// <summary>Local, DWS-account-scoped stars for indexed group-document links.</summary>
public sealed class DingTalkGroupDocumentFavorites
{
    private const string FilePrefix = "group-document-favorites-";
    private static readonly SemaphoreSlim StoreGate = new(1, 1);
    private readonly IDingTalkDwsClient _dws;
    private readonly string _stateDirectory;
    private readonly string? _legacyStateDirectory;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private volatile HashSet<string> _keys = new(StringComparer.Ordinal);
    private volatile DingTalkGroupDocument[] _favoriteDocuments = [];
    private string? _statePath;

    public DingTalkGroupDocumentFavorites(IDingTalkDwsClient dws, string? stateDirectory = null,
        string? legacyStateDirectory = null)
    {
        _dws = dws;
        _stateDirectory = stateDirectory ?? Path.Combine(
            DeskBoxDataPathService.Current.DataDirectory, "dingtalk");
        _legacyStateDirectory = legacyStateDirectory ?? (stateDirectory is null
            ? Path.Combine(DeskBoxDataPathService.Current.DataDirectory, "sync", "dingtalk")
            : null);
    }

    public event Action? Changed;

    public bool IsFavorite(string url)
    {
        string urlKey = DocumentKey(url);
        return _keys.Contains(urlKey) || _favoriteDocuments.Any(document => DocumentKey(document.Url) == urlKey);
    }

    public bool IsFavorite(DingTalkGroupDocument document) =>
        _keys.Contains(DocumentIdentity(document)) ||
        _keys.Contains(LegacyGroupIdentity(document)) ||
        _keys.Contains(DocumentKey(document.Url));

    public IReadOnlyList<DingTalkGroupDocument> FavoriteDocuments => _favoriteDocuments;

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
                documents[key] = document;
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
                    if (!documents.TryGetValue(key, out DingTalkGroupDocument? current))
                        documents[key] = document;
                    else if (current.Title == "群文档" && document.Title != "群文档")
                        documents[key] = current with { Title = document.Title };
                }
            }
            catch (Exception error) when (error is IOException or JsonException) { }
        }

        bool needsWrite = !File.Exists(destination) || documents.Count != (store.Documents?.Length ?? 0) ||
            documents.Any(pair => !(store.Documents ?? []).Any(item =>
                TryDocumentKey(item.Url, out string? key) && key == pair.Key && item == pair.Value));
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
            DingTalkDwsAuthStatus status = await GetAuthStatusAsync(cancellationToken);
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
            DingTalkDwsAuthStatus status = await GetAuthStatusAsync(cancellationToken);
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
            DingTalkDwsAuthStatus status = await GetAuthStatusAsync(cancellationToken);
            await StoreGate.WaitAsync(cancellationToken);
            try
            {
                await LoadCoreAsync(status, cancellationToken);
                var next = new HashSet<string>(_keys, StringComparer.Ordinal);
                var known = _favoriteDocuments.ToDictionary(DocumentIdentity, StringComparer.Ordinal);
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
                _keys = next;
                _favoriteDocuments = [.. known.Values.OrderBy(DocumentIdentity, StringComparer.Ordinal)];
            }
            finally { StoreGate.Release(); }
        }
        finally { _gate.Release(); }
        if (changed) Changed?.Invoke();
    }

    private async Task LoadCoreAsync(DingTalkDwsAuthStatus status, CancellationToken cancellationToken)
    {
        if (!status.Authenticated || !status.TokenValid ||
            string.IsNullOrWhiteSpace(status.CorpId) || string.IsNullOrWhiteSpace(status.UserId))
        {
            ClearVisibleState();
            throw new DingTalkDwsAuthorizationRequiredException("DWS authorization is required.");
        }

        string accountKey = $"{status.CorpId.Trim()}\u001f{status.UserId.Trim()}";
        // Never expose the previous account's stars if the next account's
        // local file cannot be read or parsed.
        ClearVisibleState();
        byte[] digest = SHA256.HashData(Encoding.UTF8.GetBytes(accountKey));
        string fileName = $"group-document-favorites-{Convert.ToHexString(digest).ToLowerInvariant()}.json";
        string path = Path.Combine(_stateDirectory, fileName);
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
        _keys = urls;
        _favoriteDocuments = [.. documents.Values.OrderBy(DocumentIdentity, StringComparer.Ordinal)];
        _statePath = path;
    }

    private async Task<DingTalkDwsAuthStatus> GetAuthStatusAsync(CancellationToken cancellationToken)
    {
        try { return await _dws.GetAuthStatusAsync(cancellationToken); }
        catch
        {
            ClearVisibleState();
            throw;
        }
    }

    private void ClearVisibleState()
    {
        _keys = new HashSet<string>(StringComparer.Ordinal);
        _favoriteDocuments = [];
        _statePath = null;
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
        throw new ArgumentException("A DingTalk document URL is required.", nameof(url));
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

    private static bool IsLegacyUrlKey(string key) => key.StartsWith("https://", StringComparison.Ordinal);

    private static bool TryDocumentKey(string? url, out string? key)
    {
        key = null;
        if (!Uri.TryCreate(url, UriKind.Absolute, out Uri? uri) ||
            uri.Scheme != Uri.UriSchemeHttps ||
            !string.Equals(uri.Host, "alidocs.dingtalk.com", StringComparison.OrdinalIgnoreCase))
            return false;
        key = uri.GetLeftPart(UriPartial.Path);
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
