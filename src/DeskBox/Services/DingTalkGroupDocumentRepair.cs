using System.Text.RegularExpressions;

namespace DeskBox.Services;

internal static class DingTalkGroupDocumentRepair
{
    // Replacement characters have lost their original bytes. Only recover a name
    // when an intact record for the same document identifies one unambiguous group.
    internal static DingTalkGroupDocument Repair(DingTalkGroupDocument document,
        IReadOnlyList<DingTalkGroupDocument> evidence)
    {
        if (document.ConversationId == "manual") return document;
        string name = document.GroupName;
        bool damaged = name.Contains('\uFFFD');
        string pattern = "^" + Regex.Replace(Regex.Escape(name), "\uFFFD+",
            match => ".{1," + match.Length + "}") + "$";
        var candidates = evidence.Where(item => item.ConversationId != "manual" &&
                !item.GroupName.Contains('\uFFFD') &&
                SameUrl(item.Url, document.Url) &&
                (string.IsNullOrEmpty(document.ConversationId) || item.ConversationId == document.ConversationId) &&
                (damaged ? Regex.IsMatch(item.GroupName, pattern, RegexOptions.CultureInvariant,
                    TimeSpan.FromMilliseconds(100)) : item.GroupName == name))
            .ToArray();
        string[] names = candidates.Select(item => item.GroupName).Distinct(StringComparer.Ordinal).ToArray();
        if (names.Length != 1) return document;
        string[] ids = candidates.Select(item => item.ConversationId).Where(id => !string.IsNullOrEmpty(id))
            .Distinct(StringComparer.Ordinal).ToArray();
        if (ids.Length > 1) return document;
        return document with { GroupName = names[0], ConversationId = ids.SingleOrDefault() ?? document.ConversationId };
    }

    internal static DingTalkGroupDocument[] Normalize(IEnumerable<DingTalkGroupDocument> documents)
    {
        DingTalkGroupDocument[] source = documents.ToArray();
        return source.Select(item => Repair(item, source))
            .OrderByDescending(item => item.Title != "群文档")
            .ThenByDescending(item => item.LastSeenAt)
            .DistinctBy(item => (string.IsNullOrEmpty(item.ConversationId) ? "name:" + item.GroupName : "id:" + item.ConversationId,
                UrlKey(item.Url))).ToArray();
    }

    private static bool SameUrl(string left, string right) => UrlKey(left) == UrlKey(right);
    private static string UrlKey(string url) => Uri.TryCreate(url, UriKind.Absolute, out Uri? uri)
        ? uri.GetLeftPart(UriPartial.Path) : url;
}
