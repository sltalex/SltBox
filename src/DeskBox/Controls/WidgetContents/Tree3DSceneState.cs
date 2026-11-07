using System.Text.Json.Serialization;

namespace DeskBox.Controls.WidgetContents;

internal sealed record Tree3DSceneState(
    int Stage,
    long TotalEnergy,
    bool ShowEnergy,
    bool Paused,
    IReadOnlyList<Tree3DFruitState> Fruits,
    Tree3DWildlifeState? Wildlife,
    IReadOnlyList<Tree3DDecorationState> Decorations,
    string? Weather,
    bool IsDay,
    bool AnimationEnabled);

internal sealed record Tree3DFruitState(int Index, string PendingId, string FruitId, string Asset,
    double X, double Y, bool Picking, string Quantity = "1");

internal sealed record Tree3DWildlifeState(
    string EncounterId,
    string SpeciesId,
    string Placement,
    string Asset,
    double X,
    double Y,
    double VisualSize,
    double HitSize,
    double Seconds,
    IReadOnlyList<WildlifeMotionFrame> Frames,
    bool FliesAcrossSky,
    bool Capturing);

internal sealed record Tree3DDecorationState(
    string Category,
    string AssetKey,
    string Asset,
    double Size,
    double X,
    double Y,
    double Scale,
    double Rotation,
    int Layer);

[JsonSourceGenerationOptions(WriteIndented = false, PropertyNamingPolicy = JsonKnownNamingPolicy.CamelCase)]
[JsonSerializable(typeof(Tree3DSceneState))]
[JsonSerializable(typeof(Tree3DFruitState))]
[JsonSerializable(typeof(Tree3DWildlifeState))]
[JsonSerializable(typeof(Tree3DDecorationState))]
internal partial class Tree3DJsonContext : JsonSerializerContext;
