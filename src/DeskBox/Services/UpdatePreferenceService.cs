using System.Text.Json;
using System.Text.Json.Serialization;

namespace DeskBox.Services;

public sealed class UpdatePreferenceService
{
    private const string FileName = "update-preferences.json";
    private readonly object _gate = new();
    private readonly string _path;
    private string _skippedVersion;

    public static UpdatePreferenceService Current { get; } = new();

    public UpdatePreferenceService(string? dataDirectory = null)
    {
        string directory = string.IsNullOrWhiteSpace(dataDirectory)
            ? DeskBoxDataPathService.Current.DataDirectory
            : Path.GetFullPath(dataDirectory);
        _path = Path.Combine(directory, FileName);
        _skippedVersion = LoadSkippedVersion();
    }

    public string SkippedVersion
    {
        get
        {
            lock (_gate)
            {
                return _skippedVersion;
            }
        }
    }

    public bool IsSkipped(string? version)
    {
        if (string.IsNullOrWhiteSpace(version))
        {
            return false;
        }

        lock (_gate)
        {
            return string.Equals(
                _skippedVersion,
                version.Trim(),
                StringComparison.OrdinalIgnoreCase);
        }
    }

    public void Skip(string version)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(version);
        string normalized = version.Trim();

        lock (_gate)
        {
            if (string.Equals(_skippedVersion, normalized, StringComparison.OrdinalIgnoreCase))
            {
                return;
            }

            _skippedVersion = normalized;
            SaveLocked();
        }
    }

    private string LoadSkippedVersion()
    {
        try
        {
            UpdatePreferencesFile preferences = Task.Run(() =>
                ResilientJsonStore.LoadAsync(
                    _path,
                    static json => JsonSerializer.Deserialize(
                        json,
                        UpdatePreferencesJsonContext.Default.UpdatePreferencesFile) ??
                        new UpdatePreferencesFile(),
                    static () => new UpdatePreferencesFile(),
                    "UpdatePreferences"))
                .GetAwaiter()
                .GetResult();
            return preferences?.SkippedVersion?.Trim() ?? string.Empty;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            App.Log($"[Update] Failed to read skipped-version preference: {ex.Message}");
            return string.Empty;
        }
    }

    private void SaveLocked()
    {
        string? directory = Path.GetDirectoryName(_path);
        if (string.IsNullOrWhiteSpace(directory))
        {
            throw new IOException("The update preference directory is unavailable.");
        }

        string json = JsonSerializer.Serialize(
            new UpdatePreferencesFile { SkippedVersion = _skippedVersion },
            UpdatePreferencesJsonContext.Default.UpdatePreferencesFile);
        Task.Run(() => ResilientJsonStore.SaveAsync(_path, json))
            .GetAwaiter()
            .GetResult();
    }
}

internal sealed class UpdatePreferencesFile
{
    public string SkippedVersion { get; set; } = string.Empty;
}

[JsonSerializable(typeof(UpdatePreferencesFile))]
internal sealed partial class UpdatePreferencesJsonContext : JsonSerializerContext;
