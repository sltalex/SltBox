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

    [Fact]
    public void ServerSaleHistoryMapValuesAreLatestSalePriceArrays()
    {
        using JsonDocument response = JsonDocument.Parse("""
            {"sale_history":{"apple":[{"price":21},{"price":19}]}}
            """);
        JsonElement apple = response.RootElement.GetProperty("sale_history").GetProperty("apple");

        FruitSalePricePoint[] prices = FruitJson.ReadSaleHistory(apple).ToArray();
        Assert.Equal([21, 19], prices.Select(x => x.Price));
    }
}
