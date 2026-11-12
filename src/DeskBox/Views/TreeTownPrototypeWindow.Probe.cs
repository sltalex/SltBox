using System.Diagnostics;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Windows.Graphics;
using Windows.Storage.Streams;

namespace DeskBox.Views;

internal sealed partial class TreeTownPrototypeWindow
{
    private bool _optionalProbeStarted;

    private sealed record TownProbeSnapshot(bool Ready, bool Authenticated, bool Saving, bool Dirty,
        bool SaveEnabled, long Epoch, long Version, int Stage, int Placements,
        int CanvasWidth, int CanvasHeight, string Status);

    private async Task RunOptionalProbeAsync()
    {
        string? directory = Environment.GetEnvironmentVariable("DESKBOX_TOWN_PROBE_DIRECTORY");
        if (string.IsNullOrWhiteSpace(directory) || _optionalProbeStarted || _closed) return;
        _optionalProbeStarted = true;

        TownProbeSnapshot? initial = null;
        TownProbeSnapshot? saved = null;
        TownProbeSnapshot? reloaded = null;
        TownProbeSnapshot? restored = null;
        string step = "initial_state";
        string? failure = null;
        string? restoreFailure = null;
        string? captureFailure = null;
        bool changedStage = false;
        bool captured = false;
        bool resizeVerified = false;
        try
        {
            directory = Path.GetFullPath(directory);
            Directory.CreateDirectory(directory);
            initial = await WaitForProbeSnapshotAsync(value => value.Ready && !value.Saving &&
                value.Epoch == _session.Epoch && value.Authenticated == _session.IsAuthenticated &&
                (!value.Authenticated || value.Dirty || (value.SaveEnabled &&
                    (value.Status.StartsWith("已载入云端布置", StringComparison.Ordinal) ||
                     value.Status.StartsWith("示例小镇", StringComparison.Ordinal)))));
            if (!initial.Authenticated) throw new InvalidOperationException("Probe requires a signed-in session.");
            if (initial.Dirty) throw new InvalidOperationException("Probe preserves an existing unsaved draft.");
            if (!initial.SaveEnabled ||
                !(initial.Status.StartsWith("已载入云端布置", StringComparison.Ordinal) ||
                  initial.Status.StartsWith("示例小镇", StringComparison.Ordinal)))
                throw new InvalidOperationException("Initial cloud load was not confirmed.");

            int stage = initial.Stage == 5 ? 4 : 5;
            step = "save_changed_stage";
            changedStage = true;
            await SetProbeStageAndSaveAsync(stage, initial.Epoch);
            saved = await WaitForProbeSnapshotAsync(value => value.Version > initial.Version &&
                value.Stage == stage && !value.Saving && !value.Dirty, initial.Epoch);

            step = "reload_cloud_scene";
            await ExecuteProbeScriptAsync("document.getElementById('reload').click()");
            reloaded = await WaitForProbeSnapshotAsync(value => value.Version == saved.Version &&
                value.Stage == stage && !value.Saving && !value.Dirty &&
                value.Status.StartsWith("已载入云端布置", StringComparison.Ordinal), initial.Epoch);
            step = "restore_original_stage";
        }
        catch (Exception ex)
        {
            failure = _closed ? "window_closed" : ex.GetType().Name;
        }
        finally
        {
            if (changedStage && initial is not null && !_closed && _session.Epoch == initial.Epoch)
            {
                try
                {
                    TownProbeSnapshot current = await WaitForProbeSnapshotAsync(
                        value => !value.Saving && value.SaveEnabled, initial.Epoch);
                    await SetProbeStageAndSaveAsync(initial.Stage, initial.Epoch);
                    restored = await WaitForProbeSnapshotAsync(value => value.Version > current.Version &&
                        value.Stage == initial.Stage && !value.Saving && !value.Dirty, initial.Epoch);
                }
                catch (Exception ex) { restoreFailure = _closed ? "window_closed" : ex.GetType().Name; }
            }
            if (failure is null && restoreFailure is null && restored is not null && !_closed)
            {
                try
                {
                    step = "resize_canvas";
                    resizeVerified = await VerifyProbeResizeAsync(restored.Epoch);
                    step = "complete";
                }
                catch (Exception ex) { failure = _closed ? "window_closed" : ex.GetType().Name; }
            }
            if (!string.IsNullOrWhiteSpace(directory))
            {
                try
                {
                    try
                    {
                        await Task.Delay(200);
                        if (!_closed && _web.CoreWebView2 is { } core)
                        {
                            using var stream = new InMemoryRandomAccessStream();
                            await core.CapturePreviewAsync(CoreWebView2CapturePreviewImageFormat.Png, stream);
                            if (stream.Size > 32 * 1024 * 1024) throw new InvalidDataException("Preview exceeds the probe limit.");
                            stream.Seek(0);
                            using var reader = new DataReader(stream.GetInputStreamAt(0));
                            await reader.LoadAsync((uint)stream.Size);
                            byte[] bytes = new byte[checked((int)stream.Size)];
                            reader.ReadBytes(bytes);
                            await File.WriteAllBytesAsync(Path.Combine(directory, "town-prototype-native.png"), bytes);
                            captured = true;
                        }
                    }
                    catch (Exception ex) { captureFailure = ex.GetType().Name; }
                    using var json = new MemoryStream();
                    using (var writer = new Utf8JsonWriter(json, new JsonWriterOptions { Indented = true }))
                    {
                        writer.WriteStartObject();
                        writer.WriteNumber("schema_version", 1);
                        writer.WriteBoolean("success", failure is null && restoreFailure is null && captured && resizeVerified &&
                            saved is not null && reloaded is not null && restored is not null);
                        writer.WriteString("last_step", step);
                        if (failure is not null) writer.WriteString("failure_type", failure);
                        if (restoreFailure is not null) writer.WriteString("restore_failure_type", restoreFailure);
                        if (captureFailure is not null) writer.WriteString("capture_failure_type", captureFailure);
                        writer.WriteBoolean("authenticated", initial?.Authenticated == true);
                        writer.WriteBoolean("native_png_captured", captured);
                        writer.WriteBoolean("resize_verified", resizeVerified);
                        WriteProbeSnapshot(writer, "initial", initial);
                        WriteProbeSnapshot(writer, "saved", saved);
                        WriteProbeSnapshot(writer, "reloaded", reloaded);
                        WriteProbeSnapshot(writer, "restored", restored);
                        writer.WriteEndObject();
                    }
                    await File.WriteAllBytesAsync(Path.Combine(directory, "town-prototype-native-verification.json"), json.ToArray());
                }
                catch (Exception ex)
                {
                    App.Log($"[TownPrototype] Optional verification output failed: {ex.GetType().Name}");
                }
            }
        }
    }

    private static void WriteProbeSnapshot(Utf8JsonWriter writer, string name, TownProbeSnapshot? value)
    {
        if (value is null) return;
        writer.WritePropertyName(name);
        writer.WriteStartObject();
        writer.WriteNumber("scene_version", value.Version);
        writer.WriteNumber("demo_stage", value.Stage);
        writer.WriteNumber("placement_count", value.Placements);
        writer.WriteNumber("canvas_width", value.CanvasWidth);
        writer.WriteNumber("canvas_height", value.CanvasHeight);
        writer.WriteBoolean("dirty", value.Dirty);
        writer.WriteEndObject();
    }

    private async Task SetProbeStageAndSaveAsync(int stage, long expectedEpoch)
    {
        if (_session.Epoch != expectedEpoch) throw new OperationCanceledException("The signed-in session changed.");
        string script = $"(() => {{ const s = window.DeskBoxTownPrototype.snapshot(); if (s.session_epoch !== {expectedEpoch}) throw new Error('session_epoch_changed'); const stage = document.getElementById('stage'); stage.value = '{stage}'; stage.dispatchEvent(new Event('change', {{ bubbles: true }})); if (window.DeskBoxTownPrototype.snapshot().session_epoch !== {expectedEpoch}) throw new Error('session_epoch_changed'); document.getElementById('save').click(); }})()";
        await ExecuteProbeScriptAsync(script);
        if (_session.Epoch != expectedEpoch) throw new OperationCanceledException("The signed-in session changed.");
    }

    private async Task<bool> VerifyProbeResizeAsync(long epoch)
    {
        SizeInt32 original = AppWindow.Size;
        TownProbeSnapshot before = await ReadProbeSnapshotAsync();
        bool changed = false;
        try
        {
            AppWindow.Resize(new SizeInt32(Math.Max(400, original.Width - 160),
                Math.Max(320, original.Height - 100)));
            await WaitForProbeSnapshotAsync(value => value.CanvasWidth != before.CanvasWidth ||
                value.CanvasHeight != before.CanvasHeight, epoch);
            changed = true;
        }
        finally
        {
            if (!_closed) AppWindow.Resize(original);
        }
        await WaitForProbeSnapshotAsync(value => value.CanvasWidth == before.CanvasWidth &&
            value.CanvasHeight == before.CanvasHeight, epoch);
        return changed;
    }

    private async Task ExecuteProbeScriptAsync(string script)
    {
        if (_closed || !_ready) throw new OperationCanceledException();
        await _web.ExecuteScriptAsync(script);
        if (_closed) throw new OperationCanceledException();
    }

    private async Task<TownProbeSnapshot> WaitForProbeSnapshotAsync(
        Func<TownProbeSnapshot, bool> predicate, long? expectedEpoch = null)
    {
        var elapsed = Stopwatch.StartNew();
        while (elapsed.Elapsed < TimeSpan.FromSeconds(25))
        {
            if (_closed || !_ready) throw new OperationCanceledException();
            TownProbeSnapshot value = await ReadProbeSnapshotAsync();
            if (expectedEpoch.HasValue && value.Epoch != expectedEpoch.Value)
                throw new OperationCanceledException("The signed-in session changed.");
            if (predicate(value)) return value;
            await Task.Delay(200);
        }
        throw new TimeoutException();
    }

    private async Task<TownProbeSnapshot> ReadProbeSnapshotAsync()
    {
        const string script = "JSON.stringify((() => { const s = window.DeskBoxTownPrototype.snapshot(); return { ready:s.ready, authenticated:s.authenticated, saving:s.saving, dirty:s.dirty, save_enabled:!document.getElementById('save').disabled, epoch:s.session_epoch, version:s.scene_version, stage:s.layout.demo_stage, placements:s.layout.placements.length, canvas_width:s.canvas.width, canvas_height:s.canvas.height, status:s.status }; })())";
        string encoded = await _web.ExecuteScriptAsync(script);
        if (_closed) throw new OperationCanceledException();
        using JsonDocument outer = JsonDocument.Parse(encoded);
        string inner = outer.RootElement.GetString() ?? throw new InvalidDataException("No town snapshot.");
        using JsonDocument document = JsonDocument.Parse(inner);
        JsonElement value = document.RootElement;
        return new TownProbeSnapshot(value.GetProperty("ready").GetBoolean(),
            value.GetProperty("authenticated").GetBoolean(), value.GetProperty("saving").GetBoolean(),
            value.GetProperty("dirty").GetBoolean(), value.GetProperty("save_enabled").GetBoolean(),
            value.GetProperty("epoch").GetInt64(), value.GetProperty("version").GetInt64(),
            value.GetProperty("stage").GetInt32(), value.GetProperty("placements").GetInt32(),
            value.GetProperty("canvas_width").GetInt32(), value.GetProperty("canvas_height").GetInt32(),
            value.GetProperty("status").GetString() ?? string.Empty);
    }
}
