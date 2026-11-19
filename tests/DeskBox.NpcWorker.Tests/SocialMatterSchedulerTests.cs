using System.Text.Json;

namespace DeskBox.NpcWorker.Tests;

public sealed class SocialMatterSchedulerTests
{
    [Fact]
    public void SelectAssignmentsOnlyProcessesCollectingSupportResponses()
    {
        JsonElement response = JsonDocument.Parse("""
        {
          "matters": [
            {
              "matter_id": "matter-open",
              "state": "open",
              "options": [{"option_id":"repair","action_id":"repair_market"}],
              "responses": [{"response_id":"open-response","resident_id":"npc-1","option_id":"repair","response_kind":"support"}]
            },
            {
              "matter_id": "matter-collecting",
              "state": "collecting",
              "options": [{"option_id":"repair","action_id":"repair_market"}],
              "responses": [
                {"response_id":"decline-response","resident_id":"npc-1","option_id":"repair","response_kind":"decline"},
                {"response_id":"support-response","resident_id":"npc-2","option_id":"repair","response_kind":"support"}
              ]
            }
          ]
        }
        """).RootElement;

        IReadOnlyList<SocialMatterAssignment> assignments = SocialMatterScheduler.SelectAssignments(response, 10);

        SocialMatterAssignment assignment = Assert.Single(assignments);
        Assert.Equal("matter-collecting", assignment.MatterId);
        Assert.Equal("support-response", assignment.ResponseId);
        Assert.Equal("npc-2", assignment.ResidentId);
        Assert.Equal("repair", assignment.OptionId);
        Assert.Equal("repair_market", assignment.ActionId);
    }

    [Fact]
    public void SelectAssignmentsIgnoresAlreadyAssignedResponsesAndUnboundOptions()
    {
        JsonElement response = JsonDocument.Parse("""
        {
          "matters": [{
            "matter_id": "matter-1",
            "state": "collecting",
            "options": [
              {"option_id":"bound","action_id":"bound_action"},
              {"option_id":"unbound","action_id":""}
            ],
            "commitments": [{"idempotency_key":"response:already-assigned"}],
            "responses": [
              {"response_id":"already-assigned","resident_id":"npc-1","option_id":"bound","response_kind":"support"},
              {"response_id":"unbound-response","resident_id":"npc-2","option_id":"unbound","response_kind":"support"},
              {"response_id":"new-response","resident_id":"npc-3","option_id":"bound","response_kind":"support"}
            ]
          }]
        }
        """).RootElement;

        IReadOnlyList<SocialMatterAssignment> assignments = SocialMatterScheduler.SelectAssignments(response, 10);

        SocialMatterAssignment assignment = Assert.Single(assignments);
        Assert.Equal("new-response", assignment.ResponseId);
        Assert.Equal("bound_action", assignment.ActionId);
    }

    [Fact]
    public void SelectAssignmentsStopsAtBatchLimitAcrossMatters()
    {
        JsonElement response = JsonDocument.Parse("""
        {
          "matters": [
            {"matter_id":"matter-1","state":"collecting","options":[{"option_id":"one","action_id":"action-one"}],"responses":[{"response_id":"response-1","resident_id":"npc-1","option_id":"one","response_kind":"support"},{"response_id":"response-2","resident_id":"npc-2","option_id":"one","response_kind":"support"}]},
            {"matter_id":"matter-2","state":"collecting","options":[{"option_id":"two","action_id":"action-two"}],"responses":[{"response_id":"response-3","resident_id":"npc-3","option_id":"two","response_kind":"support"}]}
          ]
        }
        """).RootElement;

        IReadOnlyList<SocialMatterAssignment> assignments = SocialMatterScheduler.SelectAssignments(response, 2);

        Assert.Equal(2, assignments.Count);
        Assert.Equal("response-1", assignments[0].ResponseId);
        Assert.Equal("response-2", assignments[1].ResponseId);
    }

    [Fact]
    public void SelectAssignmentsReturnsEmptyForInvalidOrNonPositiveInput()
    {
        Assert.Empty(SocialMatterScheduler.SelectAssignments(default, 8));
        JsonElement invalid = JsonDocument.Parse("{\"matters\":[]}").RootElement;
        Assert.Empty(SocialMatterScheduler.SelectAssignments(invalid, 0));
    }
}
