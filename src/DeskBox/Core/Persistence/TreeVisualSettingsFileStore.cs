using System.Text.Json;
using System.Text.Json.Serialization;
using DeskBox.Services;

namespace DeskBox.Core.Persistence;

/// <summary>Atomic local persistence for the tree presentation preference.</summary>
internal sealed class TreeVisualSettingsFileStore(string path)
{
    internal TreeVisualSettings Load()
    {
        foreach (string candidate in new[] { path, path + ".bak" })
        {
            try
            {
                if (!File.Exists(candidate)) continue;
                return JsonSerializer.Deserialize(File.ReadAllText(candidate),
                    TreeVisualSettingsJsonContext.Default.TreeVisualSettings) ?? new TreeVisualSettings();
            }
            catch (Exception ex) when (ex is IOException or JsonException or UnauthorizedAccessException)
            {
                App.Log($"[Tree3D] Presentation settings load failed: {ex.GetType().Name}");
            }
        }
        return new TreeVisualSettings();
    }

    internal void Save(TreeVisualSettings settings)
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(path)!);
            string temp = path + ".tmp";
            File.WriteAllText(temp, JsonSerializer.Serialize(settings,
                TreeVisualSettingsJsonContext.Default.TreeVisualSettings));
            if (File.Exists(path)) File.Replace(temp, path, path + ".bak", true);
            else File.Move(temp, path);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            App.Log($"[Tree3D] Presentation settings save failed: {ex.GetType().Name}");
        }
    }
}

internal sealed class TreeVisualSettings
{
    public bool UseThreeD { get; set; }
}

[JsonSourceGenerationOptions(WriteIndented = false)]
[JsonSerializable(typeof(TreeVisualSettings))]
internal sealed partial class TreeVisualSettingsJsonContext : JsonSerializerContext;
