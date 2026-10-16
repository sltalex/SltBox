namespace DeskBox.Contracts;

/// <summary>DeskBox account session used by settings and future sync transports.</summary>
public interface IAuthSession
{
    event Action? SessionChanged;

    bool IsAuthenticated { get; }

    AuthUser? CurrentUser { get; }

    Task<AuthUser> RegisterAsync(string username, string password, CancellationToken cancellationToken = default);

    Task<AuthUser> SignInAsync(string username, string password, CancellationToken cancellationToken = default);

    Task RefreshAsync(CancellationToken cancellationToken = default);

    Task RestoreAsync(CancellationToken cancellationToken = default);

    Task SignOutAsync(CancellationToken cancellationToken = default);
}

public sealed record AuthUser(string Id, string Username, string Role = "user");
