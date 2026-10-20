using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class DingTalkGroupDocumentRepairTests
{
    [Fact]
    public void DamagedLegacyNameUsesIntactEvidenceAndCollapsesDuplicate()
    {
        var correct = new DingTalkGroupDocument("森马海外订单出库群 Semir overseas warehouse", "出库任务管理",
            "https://alidocs.dingtalk.com/i/nodes/orders", DateTimeOffset.UtcNow, "group-a");
        var damaged = correct with { GroupName = "森马��外订单出库群 Semir overseas warehouse", ConversationId = "" };
        var legacy = correct with { ConversationId = "" };
        Assert.Equal(correct, Assert.Single(DingTalkGroupDocumentRepair.Normalize([correct, damaged, legacy])));
    }

    [Fact]
    public void DifferentGroupsWithSharedDocumentRemainSeparate()
    {
        var first = new DingTalkGroupDocument("订单群", "任务", "https://alidocs.dingtalk.com/i/nodes/orders",
            DateTimeOffset.UtcNow, "a");
        var second = first with { ConversationId = "b" };
        Assert.Equal(2, DingTalkGroupDocumentRepair.Normalize([first, second]).Length);
        var ambiguous = first with { ConversationId = "", GroupName = "�单群" };
        Assert.Equal(ambiguous, DingTalkGroupDocumentRepair.Repair(ambiguous, [first, second]));
    }

    [Fact]
    public void DamagedTextWithoutUniqueEvidenceIsPreserved()
    {
        var damaged = new DingTalkGroupDocument("�单群", "任务", "https://alidocs.dingtalk.com/i/nodes/orders",
            DateTimeOffset.UtcNow);
        Assert.Equal(damaged, DingTalkGroupDocumentRepair.Repair(damaged, []));
        Assert.Equal(damaged, DingTalkGroupDocumentRepair.Repair(damaged,
            [damaged with { GroupName = "订单群" }, damaged with { GroupName = "账单群" }]));
    }
}
