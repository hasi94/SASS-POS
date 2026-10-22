using System.Net;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting.Server;
using Microsoft.AspNetCore.Hosting.Server.Features;
using Microsoft.Extensions.DependencyInjection;

namespace FlexiPOS.PrintAgent.Tests;

public class KestrelBindingTests
{
    [Fact]
    public async Task The_real_listener_binds_the_loopback_address_only()
    {
        var builder = WebApplication.CreateBuilder();
        builder.WebHost.ConfigureKestrel(kestrel =>
            KestrelBinding.Configure(kestrel, new PrintAgentOptions { Port = 0 }));
        await using var app = builder.Build();

        await app.StartAsync();
        try
        {
            var addresses = app.Services.GetRequiredService<IServer>()
                .Features.Get<IServerAddressesFeature>()!.Addresses.ToList();

            var address = Assert.Single(addresses);
            Assert.Equal("127.0.0.1", new Uri(address).Host);
        }
        finally
        {
            await app.StopAsync();
        }
    }
}
