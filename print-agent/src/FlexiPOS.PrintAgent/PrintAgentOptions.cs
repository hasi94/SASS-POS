namespace FlexiPOS.PrintAgent;

public sealed class PrintAgentOptions
{
    public const string Section = "PrintAgent";

    public const int DefaultPort = 9631;

    /// <summary>TCP port on the loopback interface. 0 lets the OS choose, which only tests use.</summary>
    public int Port { get; set; } = DefaultPort;

    /// <summary>Browser origins allowed to call the agent. Empty means no browser may.</summary>
    public string[] AllowedOrigins { get; set; } = [];
}
