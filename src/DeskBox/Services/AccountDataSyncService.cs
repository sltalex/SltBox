using DeskBox.Contracts;

namespace DeskBox.Services;

/// <summary>Synchronizes account data that is independent of tree progress.</summary>
internal sealed class AccountDataSyncService
{
    private readonly IAuthSession _auth;
    private readonly DingTalkGroupDocumentFavoritesCloudSyncService _favorites;
    private readonly FruitService _fruit;
    private readonly WildlifeService _wildlife;

    internal AccountDataSyncService(IAuthSession auth,
        DingTalkGroupDocumentFavoritesCloudSyncService favorites,
        FruitService fruit, WildlifeService wildlife)
    {
        _auth = auth;
        _favorites = favorites;
        _fruit = fruit;
        _wildlife = wildlife;
    }

    internal async Task<bool> SyncNowAsync(CancellationToken cancellationToken = default)
    {
        string? ownerId = _auth.CurrentUser?.Id;
        if (!_auth.IsAuthenticated || ownerId is null) return false;

        bool favorites = await _favorites.SyncNowAsync(cancellationToken);
        if (_auth.CurrentUser?.Id != ownerId) return false;
        bool fruits = await _fruit.RefreshAsync(cancellationToken);
        if (_auth.CurrentUser?.Id != ownerId) return false;
        bool wildlife = await _wildlife.RefreshAsync(cancellationToken);
        return _auth.CurrentUser?.Id == ownerId && favorites && fruits &&
            (wildlife || !WildlifeAssetCatalog.HasCompleteAssets);
    }
}
