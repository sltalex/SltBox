using System.Text.Json.Serialization;

namespace DeskBox.Services;

internal sealed class AuthCredentialsRequest
{
    [JsonPropertyName("username")]
    public string Username { get; set; } = string.Empty;

    [JsonPropertyName("password")]
    public string Password { get; set; } = string.Empty;

    [JsonPropertyName("device_id")]
    public string DeviceId { get; set; } = string.Empty;
}

internal sealed class AuthRefreshRequest
{
    [JsonPropertyName("refresh_token")]
    public string RefreshToken { get; set; } = string.Empty;
}

internal sealed class AuthUserDto
{
    [JsonPropertyName("id")]
    public string Id { get; set; } = string.Empty;

    [JsonPropertyName("username")]
    public string Username { get; set; } = string.Empty;

    [JsonPropertyName("role")]
    public string Role { get; set; } = "user";
}

internal sealed class AuthTokensDto
{
    [JsonPropertyName("access_token")]
    public string AccessToken { get; set; } = string.Empty;

    [JsonPropertyName("refresh_token")]
    public string RefreshToken { get; set; } = string.Empty;

    [JsonPropertyName("access_expires_at")]
    public DateTimeOffset AccessExpiresAt { get; set; }

    [JsonPropertyName("refresh_expires_at")]
    public DateTimeOffset RefreshExpiresAt { get; set; }
}

internal sealed class AuthSessionResponse
{
    [JsonPropertyName("user")]
    public AuthUserDto User { get; set; } = new();

    [JsonPropertyName("session")]
    public AuthTokensDto Session { get; set; } = new();
}

internal sealed class AuthUserResponse
{
    [JsonPropertyName("user")]
    public AuthUserDto User { get; set; } = new();
}

internal sealed class AuthErrorResponse
{
    [JsonPropertyName("code")]
    public string Code { get; set; } = string.Empty;

    [JsonPropertyName("message")]
    public string Message { get; set; } = string.Empty;
}

internal sealed class StoredAuthSession
{
    [JsonPropertyName("user")]
    public AuthUserDto User { get; set; } = new();

    [JsonPropertyName("accessToken")]
    public string AccessToken { get; set; } = string.Empty;

    [JsonPropertyName("refreshToken")]
    public string RefreshToken { get; set; } = string.Empty;

    [JsonPropertyName("accessExpiresAt")]
    public DateTimeOffset AccessExpiresAt { get; set; }

    [JsonPropertyName("refreshExpiresAt")]
    public DateTimeOffset RefreshExpiresAt { get; set; }
}

[JsonSourceGenerationOptions(
    GenerationMode = JsonSourceGenerationMode.Metadata,
    PropertyNamingPolicy = JsonKnownNamingPolicy.CamelCase)]
[JsonSerializable(typeof(AuthCredentialsRequest))]
[JsonSerializable(typeof(AuthRefreshRequest))]
[JsonSerializable(typeof(AuthSessionResponse))]
[JsonSerializable(typeof(AuthUserResponse))]
[JsonSerializable(typeof(AuthErrorResponse))]
[JsonSerializable(typeof(StoredAuthSession))]
internal sealed partial class AuthJsonContext : JsonSerializerContext
{
}
