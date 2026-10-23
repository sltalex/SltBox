using Microsoft.Extensions.DependencyInjection;
using DeskBox.Contracts;

namespace DeskBox.Services;

/// <summary>
/// Central DI registration for all core DeskBox services.
/// These registrations have application lifetime. Feature/window factories
/// own shorter-lived instances; App disposes this container after its consumers.
/// </summary>
public static class ServiceRegistry
{
    /// <summary>
    /// Registers all core application services into the given service collection.
    /// </summary>
    public static IServiceCollection AddDeskBoxServices(this IServiceCollection services)
    {
        // ── Core infrastructure ──────────────────────────────────────────
        services.AddSingleton<SettingsService>();
        services.AddSingleton<DeskBoxDataBackupService>();
        services.AddSingleton<ICredentialStore>(_ => new PasswordVaultCredentialStore());
        services.AddSingleton<DeskBoxAuthSession>(sp =>
            new DeskBoxAuthSession(sp.GetRequiredService<ICredentialStore>()));
        services.AddSingleton<IAuthSession>(sp => sp.GetRequiredService<DeskBoxAuthSession>());
        services.AddSingleton<IAuthenticatedAccountApi>(sp => sp.GetRequiredService<DeskBoxAuthSession>());
        services.AddSingleton<IDingTalkDwsClient>(_ => new DingTalkDwsClient());
        services.AddSingleton<DingTalkAccountService>(sp => new DingTalkAccountService(sp.GetRequiredService<IAuthenticatedAccountApi>()));
        services.AddSingleton<DingTalkCalendarService>(sp =>
            new DingTalkCalendarService(
                sp.GetRequiredService<IDingTalkDwsClient>(),
                sp.GetRequiredService<DingTalkAccountService>()));
        services.AddSingleton<DingTalkGroupDocumentService>(sp =>
            new DingTalkGroupDocumentService(sp.GetRequiredService<IDingTalkDwsClient>()));
        services.AddSingleton<DingTalkGroupDocumentFavorites>(sp =>
            new DingTalkGroupDocumentFavorites(sp.GetRequiredService<IDingTalkDwsClient>(),
                auth: sp.GetRequiredService<IAuthSession>(), account: sp.GetRequiredService<DingTalkAccountService>()));
        services.AddSingleton<DingTalkGroupDocumentFavoritesCloudSyncService>(sp =>
            new DingTalkGroupDocumentFavoritesCloudSyncService(
                sp.GetRequiredService<IAuthenticatedAccountApi>(),
                sp.GetRequiredService<IAuthSession>(),
                sp.GetRequiredService<DingTalkGroupDocumentFavorites>()));
        services.AddSingleton<DingTalkGroupDocumentSyncService>(sp =>
            new DingTalkGroupDocumentSyncService(
                sp.GetRequiredService<DingTalkGroupDocumentService>(),
                sp.GetRequiredService<IDingTalkDwsClient>(),
                sp.GetRequiredService<DingTalkAccountService>(),
                sp.GetRequiredService<IAuthSession>()));
        services.AddSingleton<AccountCloudBackupService>(sp =>
            new AccountCloudBackupService(
                sp.GetRequiredService<IAuthenticatedAccountApi>(),
                sp.GetRequiredService<IAuthSession>(),
                sp.GetRequiredService<SettingsService>(),
                sp.GetRequiredService<DeskBoxDataBackupService>(),
                favorites: sp.GetRequiredService<DingTalkGroupDocumentFavorites>(),
                fruit: sp.GetRequiredService<FruitService>(),
                wildlife: sp.GetRequiredService<WildlifeService>()));
        services.AddSingleton<TreeCloudSyncService>(sp =>
            new TreeCloudSyncService(
                sp.GetRequiredService<IAuthenticatedAccountApi>(),
                sp.GetRequiredService<IAuthSession>(),
                syncAdditionalData: async token =>
                {
                    bool favorites = await sp.GetRequiredService<DingTalkGroupDocumentFavoritesCloudSyncService>().SyncNowAsync(token);
                    bool fruits = await sp.GetRequiredService<FruitService>().RefreshAsync(token);
                    bool wildlife = await sp.GetRequiredService<WildlifeService>().RefreshAsync(token);
                    return favorites && fruits && (wildlife || !WildlifeAssetCatalog.HasCompleteAssets);
                }));
        services.AddSingleton<AquariumService>(sp =>
            new AquariumService(
                sp.GetRequiredService<IAuthSession>(),
                sp.GetRequiredService<IAuthenticatedAccountApi>()));
        services.AddSingleton<FruitService>(sp =>
            new FruitService(
                sp.GetRequiredService<IAuthSession>(),
                sp.GetRequiredService<IAuthenticatedAccountApi>()));
        services.AddSingleton<WildlifeService>(sp =>
            new WildlifeService(
                sp.GetRequiredService<IAuthSession>(),
                sp.GetRequiredService<IAuthenticatedAccountApi>()));
        services.AddSingleton<CloudBackupService>(sp =>
            new CloudBackupService(
                sp.GetRequiredService<DeskBoxDataBackupService>(),
                sp.GetRequiredService<SettingsService>(),
                sp.GetRequiredService<ICredentialStore>()));
        services.AddSingleton<DeskBoxAttachmentHealthService>();
        services.AddSingleton<DeskBoxDiagnosticsBundleService>();
        services.AddSingleton<FileService>();
        services.AddSingleton<ResizeGuideOverlayService>();
        services.AddSingleton<ManagedStorageDesktopShortcutService>();

        // ── Feature services ─────────────────────────────────────────────
        services.AddSingleton<OrganizerService>(sp =>
            new OrganizerService(
                sp.GetRequiredService<SettingsService>(),
                sp.GetRequiredService<FileService>()));
        services.AddSingleton<QuickCaptureService>(_ => new QuickCaptureService());
        services.AddSingleton<LocalizationService>();
        services.AddSingleton<ThemeService>();

        // ── Update (factory-based) ───────────────────────────────────────
        services.AddSingleton<IAppUpdateService>(_ =>
            AppUpdateServiceFactory.Create(AppDistributionService.Current));

        return services;
    }
}
