using DeskBox.Services;
using DeskBox.Views.SettingsSections;

namespace DeskBox.Tests;

public sealed class EncyclopediaCategorySummaryTests
{
    [Fact]
    public void AtlasEntry_DecodesImageOnlyWhenTheCardIsRealized()
    {
        int calls = 0;
        var entry = new AtlasEntry("狐狸", "小动物", "未发现", () =>
        {
            calls++;
            return null;
        });

        Assert.Equal(0, calls);
        Assert.Null(entry.Image);
        Assert.Null(entry.Image);
        Assert.Equal(1, calls);
    }

    [Fact]
    public void FruitCategories_CountPreviouslyOwnedTypesAfterTheyAreSold()
    {
        FruitCatalogItem[] catalog =
        [
            new("apple", "苹果", "常见水果", 1, 1, 15, 20, 17),
            new("pear", "梨", "常见水果", 2, 1, 15, 20, 17),
            new("berry", "浆果", "浆果", 3, 1, 15, 20, 17)
        ];
        FruitInventoryItem[] inventory =
        [
            new("apple", 0, true),
            new("pear", 2, false)
        ];

        IReadOnlyList<EncyclopediaCategoryProgress> result =
            EncyclopediaCategorySummary.Fruits(catalog, inventory);

        Assert.Equal(new EncyclopediaCategoryProgress("常见水果", 2, 2), result[0]);
        Assert.Equal(new EncyclopediaCategoryProgress("浆果", 0, 1), result[1]);
    }

    [Fact]
    public void AquariumCategories_UseDiscoveryAndInventoryButExcludeCardPacks()
    {
        AquariumCatalogItem[] catalog =
        [
            new("fish-old", "鱼一", "fish", 1, "fish-old"),
            new("fish-new", "鱼二", "fish", 1, "fish-new"),
            new("plant", "水草", "plant", 1, "plant"),
            new("pack", "卡包", "pack", 1, "pack")
        ];
        AquariumState state = new(false, 0, 0,
            [new AquariumInventoryItem("instance", "plant", "2026-01-01")], [], [],
            Discovered: ["fish-old"]);

        IReadOnlyList<EncyclopediaCategoryProgress> result =
            EncyclopediaCategorySummary.Aquarium(catalog, state);

        Assert.Equal(2, result.Count);
        Assert.Equal(new EncyclopediaCategoryProgress("fish", 1, 2), result[0]);
        Assert.Equal(new EncyclopediaCategoryProgress("plant", 1, 1), result[1]);
    }

    [Fact]
    public void SettingsEncyclopedia_HasPrimaryAndSecondaryCategorySurfaces()
    {
        string xaml = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/FruitEncyclopediaSection.xaml"));
        string codeBehind = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/FruitEncyclopediaSection.xaml.cs"));
        string zh = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Strings/zh-CN.json"));

        Assert.Contains("x:Name=\"AquariumTabButton\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Name=\"FruitTabButton\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Name=\"WildlifeTabButton\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Name=\"WildlifeCategories\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Name=\"AquariumCategories\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Name=\"FruitCategories\"", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("永久点亮", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("未发现的仅显示模糊轮廓", xaml, StringComparison.Ordinal);
        Assert.Contains("\"Tree.FruitEncyclopedia\":\"图鉴\"", zh, StringComparison.Ordinal);
        Assert.Contains("{name} {progress.Obtained}/{progress.Total}", codeBehind, StringComparison.Ordinal);
        Assert.DoesNotContain("已获得", codeBehind, StringComparison.Ordinal);
        Assert.DoesNotContain("总数", codeBehind, StringComparison.Ordinal);
    }

    [Fact]
    public void WildlifeCategories_KeepPreviouslyCapturedSpeciesLit()
    {
        WildlifeCatalogItem[] catalog =
        [
            new("butterfly", "蝴蝶", "昆虫"), new("honeybee", "蜜蜂", "昆虫"),
            new("rabbit", "兔子", "小动物")
        ];
        WildlifeInventoryItem[] inventory =
        [new("butterfly", 0, true), new("rabbit", 3, false)];

        var result = EncyclopediaCategorySummary.Wildlife(catalog, inventory);

        Assert.Equal(new EncyclopediaCategoryProgress("昆虫", 1, 2), result[0]);
        Assert.Equal(new EncyclopediaCategoryProgress("小动物", 1, 1), result[1]);
    }
}
