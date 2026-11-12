using System.Text.Json;

namespace DeskBox.NpcWorker.Tests;

public sealed class NpcWorkerSchedulingTests
{
    [Fact]
    public void DeterministicScheduleUsesConfiguredTownTimezone()
    {
        JsonElement schedule = JsonDocument.Parse("[{\"weekday\":1,\"start_minute\":540,\"end_minute\":600,\"action\":\"work\",\"target_id\":\"farm-zone-3\"}]").RootElement.Clone();
        var npc = new Npc("npc-1", "村民", "farmer", true, false, new NpcRuntime(10, 10, "rest", null, Array.Empty<string>()), schedule, JsonDocument.Parse("{}").RootElement.Clone());
        DateTimeOffset utcMondayAtNineInShanghai = new(2026, 11, 2, 1, 0, 0, TimeSpan.Zero);

        PlannedAction action = DeterministicSchedule.Next(npc, utcMondayAtNineInShanghai, DeterministicSchedule.ResolveTimeZone("Asia/Shanghai"));

        Assert.Equal("work", action.Action);
        Assert.Equal("farm-zone-3", action.TargetId);
    }

    [Fact]
    public void InvalidTimezoneFallsBackToUtcAndOutsideScheduleRests()
    {
        JsonElement schedule = JsonDocument.Parse("[{\"weekday\":1,\"start_minute\":540,\"end_minute\":600,\"action\":\"work\"}]").RootElement.Clone();
        var npc = new Npc("npc-1", "村民", "farmer", true, false, new NpcRuntime(10, 10, "rest", null, Array.Empty<string>()), schedule, JsonDocument.Parse("{}").RootElement.Clone());
        DateTimeOffset utcMondayAtEleven = new(2026, 11, 2, 11, 0, 0, TimeSpan.Zero);

        PlannedAction action = DeterministicSchedule.Next(npc, utcMondayAtEleven, DeterministicSchedule.ResolveTimeZone("not-a-real-time-zone"));

        Assert.Equal("rest", action.Action);
        Assert.Equal("outside_schedule", action.Reason);
    }

    [Fact]
    public void AgentPlanValidatorAcceptsBoundedStepsAndCapabilities()
    {
        JsonElement payload = JsonDocument.Parse("{\"dialogue\":\"先去浇水。\",\"required_capabilities\":[\"bridge_kit\"],\"steps\":[{\"action\":\"walk\",\"target\":\"farm-zone-3\"},{\"action\":\"work\",\"job\":\"water_crop\"}]}").RootElement;

        Assert.True(AgentPlanValidator.TryValidate("work", "farm-zone-3", "完成今天的浇水", payload, out string error), error);
    }

    [Fact]
    public void AgentPlanValidatorRejectsUnknownStepAndCapability()
    {
        JsonElement payload = JsonDocument.Parse("{\"required_capabilities\":[\"teleport\"],\"steps\":[{\"action\":\"teleport\"}]} ").RootElement;

        Assert.False(AgentPlanValidator.TryValidate("walk", "farm-zone-3", "", payload, out string error));
        Assert.Contains("通行能力", error);
    }

    [Fact]
    public void AgentPlanValidatorRejectsTooManySteps()
    {
        string steps = string.Join(',', Enumerable.Repeat("{\"action\":\"walk\"}", 17));
        JsonElement payload = JsonDocument.Parse($"{{\"steps\":[{steps}]}}").RootElement;

        Assert.False(AgentPlanValidator.TryValidate("walk", "farm-zone-3", "", payload, out string error));
        Assert.Contains("16", error);
    }

    [Fact]
    public void DeterministicFallbackDialogueUsesConfiguredSpeechStyle()
    {
        JsonElement talkPayload = JsonDocument.Parse("{\"target_npc_id\":\"npc-2\"}").RootElement.Clone();
        JsonElement warmProfile = JsonDocument.Parse("{\"speech_style\":\"温柔鼓励\"}").RootElement.Clone();
        JsonElement conciseProfile = JsonDocument.Parse("{\"speech_style\":\"简洁直接\"}").RootElement.Clone();
        var warm = new Npc("npc-1", "花青", "merchant", true, false, new NpcRuntime(10, 10, "rest", null, Array.Empty<string>()), JsonDocument.Parse("[]").RootElement.Clone(), warmProfile);
        var concise = new Npc("npc-2", "周岩", "craftsman", true, false, new NpcRuntime(10, 10, "rest", null, Array.Empty<string>()), JsonDocument.Parse("[]").RootElement.Clone(), conciseProfile);

        PlannedAction warmAction = DeterministicSchedule.FillMissingDialogue(warm, new PlannedAction("talk", "npc-2", talkPayload, "fallback"));
        PlannedAction conciseAction = DeterministicSchedule.FillMissingDialogue(concise, new PlannedAction("talk", "npc-1", talkPayload, "fallback"));

        string warmText = warmAction.Payload.GetProperty("dialogue").GetString()!;
        string conciseText = conciseAction.Payload.GetProperty("dialogue").GetString()!;
        Assert.NotEqual(warmText, conciseText);
        Assert.Contains("辛苦", warmText);
        Assert.Contains("先把", conciseText);
    }

    [Fact]
    public void DeterministicFallbackDialoguePreservesExistingModelText()
    {
        JsonElement profile = JsonDocument.Parse("{\"speech_style\":\"简洁直接\"}").RootElement.Clone();
        JsonElement payload = JsonDocument.Parse("{\"dialogue\":\"模型已经给出的句子\"}").RootElement.Clone();
        var npc = new Npc("npc-1", "村民", "farmer", true, false, new NpcRuntime(10, 10, "rest", null, Array.Empty<string>()), JsonDocument.Parse("[]").RootElement.Clone(), profile);

        PlannedAction action = DeterministicSchedule.FillMissingDialogue(npc, new PlannedAction("talk", "npc-2", payload, "model"));

        Assert.Equal("模型已经给出的句子", action.Payload.GetProperty("dialogue").GetString());
    }

    [Fact]
    public void SocialScheduleResolvesNearestEligibleNpcWhenTargetIsAPlace()
    {
        JsonElement emptySchedule = JsonDocument.Parse("[]").RootElement.Clone();
        JsonElement profile = JsonDocument.Parse("{}").RootElement.Clone();
        var source = new Npc("npc-1", "花青", "merchant", true, false,
            new NpcRuntime(10, 10, "rest", null, Array.Empty<string>()), emptySchedule, profile);
        var nearest = new Npc("npc-2", "林野", "farmer", true, false,
            new NpcRuntime(11, 10, "rest", null, Array.Empty<string>()), emptySchedule, profile);
        var farther = new Npc("npc-3", "周岩", "craftsman", true, false,
            new NpcRuntime(20, 20, "rest", null, Array.Empty<string>()), emptySchedule, profile);

        PlannedAction action = DeterministicSchedule.ResolveTalkTarget(source,
            new PlannedAction("talk", "town-square", JsonDocument.Parse("{\"dialogue\":\"你好\"}").RootElement.Clone(), "schedule"),
            new[] { source, farther, nearest });

        Assert.Equal("npc-2", action.TargetId);
    }

    [Fact]
    public void SocialSchedulePreservesExplicitNpcTarget()
    {
        JsonElement emptySchedule = JsonDocument.Parse("[]").RootElement.Clone();
        JsonElement profile = JsonDocument.Parse("{}").RootElement.Clone();
        var source = new Npc("npc-1", "花青", "merchant", true, false,
            new NpcRuntime(10, 10, "rest", null, Array.Empty<string>()), emptySchedule, profile);
        var target = new Npc("npc-2", "林野", "farmer", true, false,
            new NpcRuntime(11, 10, "rest", null, Array.Empty<string>()), emptySchedule, profile);

        PlannedAction action = DeterministicSchedule.ResolveTalkTarget(source,
            new PlannedAction("talk", "npc-2", JsonDocument.Parse("{}").RootElement.Clone(), "model"),
            new[] { source, target });

        Assert.Equal("npc-2", action.TargetId);
    }
}
