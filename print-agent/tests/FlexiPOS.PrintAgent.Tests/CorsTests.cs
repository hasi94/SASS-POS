using System.Net;

namespace FlexiPOS.PrintAgent.Tests;

public class CorsTests
{
    private const string Till = "https://pos.example.test";

    private static async Task<string?> AllowOriginHeader(AgentFactory factory, string origin)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, "/health");
        request.Headers.Add("Origin", origin);
        var response = await factory.CreateClient().SendAsync(request);

        return response.Headers.TryGetValues("Access-Control-Allow-Origin", out var values) ? values.Single() : null;
    }

    [Fact]
    public async Task A_configured_origin_is_allowed()
    {
        using var factory = new AgentFactory(IPAddress.Loopback, Till);

        Assert.Equal(Till, await AllowOriginHeader(factory, Till));
    }

    [Fact]
    public async Task An_origin_that_is_not_configured_is_not_allowed()
    {
        using var factory = new AgentFactory(IPAddress.Loopback, Till);

        Assert.Null(await AllowOriginHeader(factory, "https://evil.example.test"));
    }

    [Fact]
    public async Task With_no_origins_configured_no_browser_is_allowed()
    {
        using var factory = new AgentFactory(IPAddress.Loopback);

        Assert.Null(await AllowOriginHeader(factory, Till));
    }
}
