using System.Net;
using System.Net.Http.Json;
using System.Text.Json;

namespace FlexiPOS.PrintAgent.Tests;

public class AgentEndpointsTests
{
    [Fact]
    public async Task Health_answers_ok_to_a_loopback_caller()
    {
        using var factory = new AgentFactory(IPAddress.Loopback);
        var response = await factory.CreateClient().GetAsync("/health");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var body = await response.Content.ReadFromJsonAsync<JsonElement>();
        Assert.Equal("ok", body.GetProperty("status").GetString());
    }

    [Fact]
    public async Task Info_names_the_agent_and_its_version()
    {
        using var factory = new AgentFactory(IPAddress.IPv6Loopback);
        var body = await factory.CreateClient().GetFromJsonAsync<JsonElement>("/v1/info");

        Assert.Equal("FlexiPOS Print Agent", body.GetProperty("name").GetString());
        Assert.Equal("0.1.0", body.GetProperty("version").GetString());
    }

    [Theory]
    [InlineData("192.168.1.20")]
    [InlineData("10.0.0.5")]
    [InlineData("2001:db8::1")]
    public async Task A_caller_that_is_not_loopback_is_refused(string address)
    {
        using var factory = new AgentFactory(IPAddress.Parse(address));
        var response = await factory.CreateClient().GetAsync("/health");

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
    }

    [Fact]
    public async Task A_caller_with_no_known_address_is_refused()
    {
        using var factory = new AgentFactory(null);
        var response = await factory.CreateClient().GetAsync("/health");

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
    }
}
