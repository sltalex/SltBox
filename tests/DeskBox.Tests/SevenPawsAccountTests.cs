using System.Text.Json.Nodes;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class SevenPawsAccountTests
{
    [Fact]
    public void PartiallyWrittenCatsCannotUploadAnEmptyAppearance()
    {
        string root = Path.Combine(Path.GetTempPath(), "sevenpaws-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        try
        {
            File.WriteAllText(Path.Combine(root, "cats.json"), "[{\"id\":");
            Assert.ThrowsAny<System.Text.Json.JsonException>(() => SevenPawsDesktopService.ReadOutfit(root));
        }
        finally { Directory.Delete(root, recursive: true); }
    }
    [Fact]
    public void CloudAppearancePreservesDevicePositionAndKeyLabels()
    {
        string root = Path.Combine(Path.GetTempPath(), "sevenpaws-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        try
        {
            File.WriteAllText(Path.Combine(root, "settings.json"), "{\"coat\":\"tuxedo\",\"x\":123,\"y\":456,\"scale\":0.35,\"showKeyLabels\":true,\"privacyMode\":false}");
            SevenPawsDesktopService.ApplyOutfit(root, new JsonObject { ["coat"] = "orange", ["cats"] = new JsonArray() });
            var saved = JsonNode.Parse(File.ReadAllText(Path.Combine(root, "settings.json")))!;
            Assert.Equal("orange", saved["coat"]!.GetValue<string>());
            Assert.Equal(123, saved["x"]!.GetValue<int>());
            Assert.Equal(456, saved["y"]!.GetValue<int>());
            Assert.Equal(0.35, saved["scale"]!.GetValue<double>());
            Assert.True(saved["showKeyLabels"]!.GetValue<bool>());
            Assert.False(saved["privacyMode"]!.GetValue<bool>());
            var outfit = SevenPawsDesktopService.ReadOutfit(root);
            Assert.Equal(new[] { "coat", "cats" }, outfit.Select(x => x.Key));
        }
        finally { Directory.Delete(root, recursive: true); }
    }
}
