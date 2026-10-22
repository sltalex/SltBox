using System.Text.Json;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class FruitJsonTests
{
    [Fact]
    public void ServerHistoryMapValuesArePricePointArrays()
    {
        using JsonDocument response = JsonDocument.Parse("""
            {"history":{"apple":[{"day":"2026-10-22","price":18}]}}
            """);
        JsonElement apple = response.RootElement.GetProperty("history").GetProperty("apple");

        FruitPricePoint price = Assert.Single(FruitJson.ReadHistory(apple));
        Assert.Equal("2026-10-22", price.Day);
        Assert.Equal(18, price.Price);
    }
}
