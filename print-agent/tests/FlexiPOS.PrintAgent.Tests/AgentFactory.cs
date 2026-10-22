using System.Net;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.AspNetCore.Builder;
using Microsoft.Extensions.DependencyInjection;

namespace FlexiPOS.PrintAgent.Tests;

/// <summary>
/// Hosts the agent in memory. TestServer has no socket, so each request is given the
/// remote address the test names, which is what the loopback guard looks at.
/// </summary>
internal sealed class AgentFactory(IPAddress? remote, params string[] allowedOrigins) : WebApplicationFactory<Program>
{
    protected override void ConfigureWebHost(IWebHostBuilder builder)
    {
        for (var i = 0; i < allowedOrigins.Length; i++)
        {
            builder.UseSetting($"PrintAgent:AllowedOrigins:{i}", allowedOrigins[i]);
        }

        builder.ConfigureServices(services => services.AddTransient<IStartupFilter>(_ => new RemoteAddressFilter(remote)));
    }

    private sealed class RemoteAddressFilter(IPAddress? remote) : IStartupFilter
    {
        public Action<IApplicationBuilder> Configure(Action<IApplicationBuilder> next) => app =>
        {
            app.Use((context, pipeline) =>
            {
                context.Connection.RemoteIpAddress = remote;
                return pipeline();
            });
            next(app);
        };
    }
}
