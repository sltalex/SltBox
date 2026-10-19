using System.Numerics;

namespace DeskBox.Controls.WidgetContents;

internal sealed record AquariumFishMotionInput(string InstanceId, string SpeciesId, double X, double Y,
    int? SwimLayer = null);

internal sealed record AquariumFishMotionPlan(
    double DurationSeconds,
    IReadOnlyList<(float Progress, Vector3 Value)> Translation,
    IReadOnlyList<(float Progress, float Value)> Facing,
    bool IsStraggler,
    string SchoolId,
    double SpeedMultiplier);

/// <summary>
/// Pure fish movement planning. Keeping the state generation outside WinUI makes
/// schooling, speed differences and the turn-before-swim contract testable.
/// </summary>
internal static class AquariumFishMotionPlanner
{
    // Several independent legs make one animation loop long enough that the
    // fish do not repeat the same short route every few seconds.
    private const int SegmentCount = 8;
    private const double TurnSecondsMin = .72;
    private const double TurnSecondsRange = .22;
    private const double AccelerationFraction = .24;

    internal static IReadOnlyDictionary<string, AquariumFishMotionPlan> Build(
        IReadOnlyList<AquariumFishMotionInput> inputs, double sceneWidth, double sceneHeight, int seed)
    {
        var plans = new Dictionary<string, AquariumFishMotionPlan>(StringComparer.Ordinal);
        foreach (var speciesGroup in inputs.GroupBy(item => item.SpeciesId, StringComparer.OrdinalIgnoreCase))
        {
            List<AquariumFishMotionInput> members = speciesGroup.OrderBy(item => item.InstanceId, StringComparer.Ordinal).ToList();
            int groupNumber = 0;
            for (int offset = 0; offset < members.Count;)
            {
                int remaining = members.Count - offset;
                int schoolCount = remaining >= 6 ? (remaining + 4) / 5 : 1;
                int baseSize = remaining / schoolCount;
                int extra = remaining % schoolCount;
                int schoolSize = baseSize + (groupNumber < extra ? 1 : 0);
                List<AquariumFishMotionInput> school = members.GetRange(offset, schoolSize);
                string schoolId = $"{speciesGroup.Key}:{groupNumber}";
                BuildSchool(plans, school, schoolId, sceneWidth, sceneHeight, seed + groupNumber * 7919);
                offset += schoolSize;
                groupNumber++;
            }
        }
        return plans;
    }

    private static void BuildSchool(Dictionary<string, AquariumFishMotionPlan> plans,
        IReadOnlyList<AquariumFishMotionInput> school, string schoolId, double sceneWidth, double sceneHeight, int seed)
    {
        double centerX = school.Average(item => item.X * sceneWidth);
        double centerY = school.Average(item => item.Y * sceneHeight);
        double initialCenterY = centerY;
        string species = school[0].SpeciesId;
        double speed = AquariumArtworkCatalog.TryGetProfile(species, out var artwork) && artwork.SpeedMultiplier > 0
            ? artwork.SpeedMultiplier : .75;
        var random = new Random(StableHash($"{schoolId}:{seed}"));
        var paths = school.ToDictionary(item => item.InstanceId, _ => new List<(float, Vector3)> { (0, Vector3.Zero) }, StringComparer.Ordinal);
        var facings = school.ToDictionary(item => item.InstanceId, _ => new List<(float, float)> { (0, random.Next(2) == 0 ? -1 : 1) }, StringComparer.Ordinal);
        var current = school.ToDictionary(item => item.InstanceId, item => (X: item.X * sceneWidth, Y: item.Y * sceneHeight), StringComparer.Ordinal);
        var facing = school.ToDictionary(item => item.InstanceId, item => facings[item.InstanceId][0].Item2, StringComparer.Ordinal);
        var initialFacing = school.ToDictionary(item => item.InstanceId,
            item => facings[item.InstanceId][0].Item2, StringComparer.Ordinal);
        double elapsed = 0;
        double total = 0;
        var segments = new List<(double Turn, double Swim, Dictionary<string, (double X, double Y)> Targets, Dictionary<string, float> Headings)>();
        for (int segment = 0; segment < SegmentCount; segment++)
        {
            double turn = TurnSecondsMin + random.NextDouble() * TurnSecondsRange;
            double swim = (5.2 + random.NextDouble() * 2.0) / Math.Max(.35, speed);
            var targets = new Dictionary<string, (double X, double Y)>(StringComparer.Ordinal);
            var headings = new Dictionary<string, float>(StringComparer.Ordinal);
            double direction = random.Next(2) == 0 ? -1 : 1;
            double travel = 125 + random.NextDouble() * 120;
            if (segment < SegmentCount - 1)
            {
                centerX += direction * travel;
                centerY = Math.Clamp(centerY + (random.NextDouble() - .5) * 80, sceneHeight * .18, sceneHeight * .78);
            }
            foreach (AquariumFishMotionInput member in school)
            {
                bool returningToLoop = segment == SegmentCount - 1;
                bool straggler = StableHash($"{member.InstanceId}:{segment}") % 11 == 0;
                double lag = straggler ? 75 : 0;
                double x = returningToLoop
                    ? member.X * sceneWidth
                    : centerX - direction * lag + (random.NextDouble() - .5) * (straggler ? 52 : 28);
                double y = returningToLoop
                    ? member.Y * sceneHeight
                    : member.SwimLayer is int
                        ? member.Y * sceneHeight + (centerY - initialCenterY) * .18 +
                          (random.NextDouble() - .5) * (straggler ? 24 : 12)
                        : centerY + (random.NextDouble() - .5) * (straggler ? 48 : 24);
                (double minY, double maxY) = member.SwimLayer is int layer
                    ? AquariumSwimDepth.Band(layer)
                    : (.14, .82);
                targets[member.InstanceId] = (x, Math.Clamp(y, sceneHeight * minY, sceneHeight * maxY));
                double deltaX = x - current[member.InstanceId].X;
                headings[member.InstanceId] = Math.Abs(deltaX) > 4
                    ? (float)(deltaX >= 0 ? 1 : -1)
                    : facing[member.InstanceId];
            }
            segments.Add((turn, swim, targets, headings));
            foreach (AquariumFishMotionInput member in school)
                current[member.InstanceId] = targets[member.InstanceId];
            total += turn + swim;
        }
        total += TurnSecondsMin;

        foreach (AquariumFishMotionInput member in school)
        {
            elapsed = 0;
            foreach (var segment in segments)
            {
                elapsed += segment.Turn;
                float turnEnd = (float)(elapsed / total);
                // Translation is held during the turn. The head flips at the
                // end of the turn, and only then does the linear swim begin.
                paths[member.InstanceId].Add((Math.Max(0, turnEnd - .006f), paths[member.InstanceId][^1].Item2));
                paths[member.InstanceId].Add((turnEnd, paths[member.InstanceId][^1].Item2));
                facings[member.InstanceId].Add((Math.Max(0, turnEnd - .006f), facing[member.InstanceId]));
                facings[member.InstanceId].Add((turnEnd, segment.Headings[member.InstanceId]));
                elapsed += segment.Swim;
                double accelerationSeconds = segment.Swim * AccelerationFraction;
                float accelerationMid = (float)((elapsed - segment.Swim + accelerationSeconds * .5) / total);
                float accelerationEnd = (float)((elapsed - segment.Swim + accelerationSeconds) / total);
                (double x, double y) target = segment.Targets[member.InstanceId];
                Vector3 start = paths[member.InstanceId][^1].Item2;
                Vector3 translation = new((float)(target.x - member.X * sceneWidth), (float)(target.y - member.Y * sceneHeight), 0);
                // The first part of each swim is a linear acceleration ramp:
                // displacement grows quadratically, then the remaining leg
                // uses a constant linear velocity.
                double accelerationDistance = .5 * AccelerationFraction / (1 - .5 * AccelerationFraction);
                paths[member.InstanceId].Add((accelerationMid, Vector3.Lerp(start, translation, (float)(accelerationDistance * .25))));
                paths[member.InstanceId].Add((accelerationEnd, Vector3.Lerp(start, translation, (float)accelerationDistance)));
                float swimEnd = (float)(elapsed / total);
                paths[member.InstanceId].Add((swimEnd, translation));
                facing[member.InstanceId] = segment.Headings[member.InstanceId];
            }
            // The last leg reaches the original position while facing the
            // direction of travel. Turn back only after the fish is stationary
            // at the loop origin, so the next loop cannot begin by swimming
            // backwards.
            double restoreTurn = TurnSecondsMin;
            elapsed += restoreTurn;
            float restoreEnd = (float)(elapsed / total);
            paths[member.InstanceId].Add((Math.Max(0, restoreEnd - .006f), paths[member.InstanceId][^1].Item2));
            paths[member.InstanceId].Add((restoreEnd, paths[member.InstanceId][^1].Item2));
            facings[member.InstanceId].Add((Math.Max(0, restoreEnd - .006f), facing[member.InstanceId]));
            facings[member.InstanceId].Add((restoreEnd, initialFacing[member.InstanceId]));
            bool isStraggler = false;
            for (int segmentIndex = 0; segmentIndex < segments.Count; segmentIndex++)
            {
                if (StableHash($"{member.InstanceId}:{segmentIndex}") % 11 == 0)
                {
                    isStraggler = true;
                    break;
                }
            }
            plans[member.InstanceId] = new AquariumFishMotionPlan(total, paths[member.InstanceId], facings[member.InstanceId], isStraggler, schoolId, speed);
        }
    }

    private static int StableHash(string value)
        => (int)(AquariumSwimDepth.StableHash(value) & 0x7fffffff);
}
