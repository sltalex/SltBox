using System.Text.Json;

/// <summary>
/// Selects deterministic, idempotent social-matter commitments from the
/// server-owned projection.  The worker never invents an action payload: the
/// selected option's action_id is sent back to the PHP service, which performs
/// the final candidate, route, inventory, deadline and task validation.
/// </summary>
public sealed record SocialMatterAssignment(
    string MatterId,
    string ResponseId,
    string ResidentId,
    string OptionId,
    string ActionId);

public static class SocialMatterScheduler
{
    public static IReadOnlyList<SocialMatterAssignment> SelectAssignments(JsonElement listResponse, int maxAssignments)
    {
        if (maxAssignments <= 0 || listResponse.ValueKind != JsonValueKind.Object ||
            !listResponse.TryGetProperty("matters", out JsonElement matters) || matters.ValueKind != JsonValueKind.Array)
            return Array.Empty<SocialMatterAssignment>();

        int limit = Math.Clamp(maxAssignments, 1, 200);
        var result = new List<SocialMatterAssignment>(Math.Min(limit, 16));
        foreach (JsonElement matter in matters.EnumerateArray())
        {
            if (result.Count >= limit || matter.ValueKind != JsonValueKind.Object) break;
            if (!StringEquals(matter, "state", "collecting")) continue;
            string matterId = StringValue(matter, "matter_id");
            if (matterId.Length == 0) continue;

            var options = new Dictionary<string, string>(StringComparer.Ordinal);
            if (matter.TryGetProperty("options", out JsonElement optionValues) && optionValues.ValueKind == JsonValueKind.Array)
            {
                foreach (JsonElement option in optionValues.EnumerateArray())
                {
                    string optionId = StringValue(option, "option_id");
                    string actionId = StringValue(option, "action_id");
                    if (optionId.Length > 0 && actionId.Length > 0) options.TryAdd(optionId, actionId);
                }
            }

            var assignedResponseIds = new HashSet<string>(StringComparer.Ordinal);
            if (matter.TryGetProperty("commitments", out JsonElement commitments) && commitments.ValueKind == JsonValueKind.Array)
            {
                foreach (JsonElement commitment in commitments.EnumerateArray())
                {
                    string key = StringValue(commitment, "idempotency_key");
                    const string prefix = "response:";
                    if (key.StartsWith(prefix, StringComparison.Ordinal) && key.Length > prefix.Length)
                        assignedResponseIds.Add(key[prefix.Length..]);
                }
            }

            if (!matter.TryGetProperty("responses", out JsonElement responses) || responses.ValueKind != JsonValueKind.Array) continue;
            foreach (JsonElement response in responses.EnumerateArray())
            {
                if (result.Count >= limit || response.ValueKind != JsonValueKind.Object) break;
                if (!StringEquals(response, "response_kind", "support")) continue;
                string responseId = StringValue(response, "response_id");
                string residentId = StringValue(response, "resident_id");
                string optionId = StringValue(response, "option_id");
                if (responseId.Length == 0 || residentId.Length == 0 || optionId.Length == 0 || assignedResponseIds.Contains(responseId)) continue;
                if (!options.TryGetValue(optionId, out string? actionId) || actionId.Length == 0) continue;

                result.Add(new SocialMatterAssignment(matterId, responseId, residentId, optionId, actionId));
                assignedResponseIds.Add(responseId);
            }
        }

        return result;
    }

    private static bool StringEquals(JsonElement value, string property, string expected) =>
        string.Equals(StringValue(value, property), expected, StringComparison.Ordinal);

    private static string StringValue(JsonElement value, string property) =>
        value.ValueKind == JsonValueKind.Object && value.TryGetProperty(property, out JsonElement propertyValue) && propertyValue.ValueKind == JsonValueKind.String
            ? propertyValue.GetString()?.Trim() ?? string.Empty
            : string.Empty;
}
