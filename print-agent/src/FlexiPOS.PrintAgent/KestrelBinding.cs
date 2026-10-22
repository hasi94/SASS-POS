using System.Net;
using Microsoft.AspNetCore.Server.Kestrel.Core;

namespace FlexiPOS.PrintAgent;

internal static class KestrelBinding
{
    /// <summary>
    /// Listen on the loopback interface only. A configured URL cannot widen this: the endpoint
    /// is built from the port alone, so no setting binds a public address.
    /// </summary>
    public static void Configure(KestrelServerOptions kestrel, PrintAgentOptions options) =>
        kestrel.Listen(IPAddress.Loopback, options.Port);
}
