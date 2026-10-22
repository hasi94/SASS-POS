using System.Reflection;
using FlexiPOS.PrintAgent;

var builder = WebApplication.CreateBuilder(args);

builder.Host.UseWindowsService(options => options.ServiceName = "FlexiPOS Print Agent");

var options = builder.Configuration.GetSection(PrintAgentOptions.Section).Get<PrintAgentOptions>()
    ?? new PrintAgentOptions();

builder.Services.AddSingleton(options);

builder.WebHost.ConfigureKestrel(kestrel => KestrelBinding.Configure(kestrel, options));

builder.Services.AddCors(cors => cors.AddDefaultPolicy(policy =>
{
    if (options.AllowedOrigins.Length > 0)
    {
        policy.WithOrigins(options.AllowedOrigins).AllowAnyHeader().AllowAnyMethod();
    }
}));

var app = builder.Build();

app.UseMiddleware<LoopbackOnlyMiddleware>();
app.UseCors();

app.MapGet("/health", () => Results.Ok(new { status = "ok" }));

app.MapGet("/v1/info", () => Results.Ok(new
{
    name = "FlexiPOS Print Agent",
    version = Assembly.GetExecutingAssembly().GetName().Version?.ToString(3),
}));

app.Run();

public partial class Program;
