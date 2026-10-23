using System.Text.Json;
using System.Text.Json.Serialization;

namespace DeskBox.Services;

public sealed class UpdatePreferenceService
{
    private const string FileName = "update-preferences.json";
    private readonly object _gate = new();
    private readonly string _path;
    private string _skippedVersion;
    private string _automaticInstallAttemptVersion;

    public static UpdatePreferenceService Current { get; } = new();

    public UpdatePreferenceService(string? dataDirectory = null)
    {
        string directory = string.IsNullOrWhiteSpace(dataDirectory)
            ? DeskBoxDataPathService.Current.DataDirectory
            : Path.GetFullPath(dataDirectory);
        _path = Path.Combine(directory, FileName);
        UpdatePreferencesFile preferences = LoadPreferences();
        _skippedVersion = preferences.SkippedVersion?.Trim() ?? string.Empty;
        _automaticInstallAttemptVersion = preferences.AutomaticInstallAttemptVersion?.Trim() ?? string.Empty;
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

    public string AutomaticInstallAttemptVersion
    {
        get
        {
            lock (_gate)
            {
                return _automaticInstallAttemptVersion;
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

    public bool WasAutomaticInstallAttempted(string? version)
    {
        if (string.IsNullOrWhiteSpace(version)) return false;
        lock (_gate)
        {
            return string.Equals(
                _automaticInstallAttemptVersion,
                version.Trim(),
                StringComparison.OrdinalIgnoreCase);
        }
    }

    public void RecordAutomaticInstallAttempt(string version)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(version);
        lock (_gate)
        {
            string previous = _automaticInstallAttemptVersion;
            _automaticInstallAttemptVersion = version.Trim();
            try { SaveLocked(); }
            catch
            {
                _automaticInstallAttemptVersion = previous;
                throw;
            }
        }
    }

    public void ClearAutomaticInstallAttempt(string version)
    {
        if (string.IsNullOrWhiteSpace(version)) return;
        lock (_gate)
        {
            if (!string.Equals(_automaticInstallAttemptVersion, version.Trim(),
                    StringComparison.OrdinalIgnoreCase)) return;
            string previous = _automaticInstallAttemptVersion;
            _automaticInstallAttemptVersion = string.Empty;
            try { SaveLocked(); }
            catch
            {
                _automaticInstallAttemptVersion = previous;
                throw;
            }
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

    private UpdatePreferencesFile LoadPreferences()
    {
        try
        {
            return Task.Run(() =>
                ResilientJsonStore.LoadAsync(
                    _path,
                    static json => JsonSerializer.Deserialize(
                        json,
                        UpdatePreferencesJsonContext.Default.UpdatePreferencesFile) ??
                        new UpdatePreferencesFile(),
                    static () => new UpdatePreferencesFile(),
                    "UpdatePreferences"))
                .GetAwaiter().GetResult();
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            App.Log($"[Update] Failed to read update preferences: {ex.Message}");
            return new UpdatePreferencesFile();
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
            new UpdatePreferencesFile
            {
                SkippedVersion = _skippedVersion,
                AutomaticInstallAttemptVersion = _automaticInstallAttemptVersion
            },
            UpdatePreferencesJsonContext.Default.UpdatePreferencesFile);
        Task.Run(() => ResilientJsonStore.SaveAsync(_path, json))
            .GetAwaiter()
            .GetResult();
    }
}

internal sealed class UpdatePreferencesFile
{
    public string SkippedVersion { get; set; } = string.Empty;
    public string AutomaticInstallAttemptVersion { get; set; } = string.Empty;
}

[JsonSerializable(typeof(UpdatePreferencesFile))]
internal sealed partial class UpdatePreferencesJsonContext : JsonSerializerContext;
