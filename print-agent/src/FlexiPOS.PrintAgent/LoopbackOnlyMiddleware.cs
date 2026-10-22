using System.Net;

namespace FlexiPOS.PrintAgent;

/// <summary>
/// Second line of defence behind the loopback-only listener: a request whose remote
/// address is not loopback, or is unknown, is refused before any endpoint runs.
/// </summary>
internal sealed class LoopbackOnlyMiddleware(RequestDelegate next)
{
    public Task InvokeAsync(HttpContext context)
    {
        var remote = context.Connection.RemoteIpAddress;

        if (remote is null || !IPAddress.IsLoopback(remote))
        {
            context.Response.StatusCode = StatusCodes.Status403Forbidden;
            return Task.CompletedTask;
        }

        return next(context);
    }
}
