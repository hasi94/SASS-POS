# FlexiPOS Print Agent

.NET 8 service that runs on the till and answers the browser on a **loopback-only** port.
Design: [docs/architecture.md](../docs/architecture.md) §16.

This is the bootstrap (FP-220): solution, loopback service, packaging. Pairing, the print queue,
printer discovery and ESC/POS output arrive with FP-239.

## Layout

| Path | What |
|---|---|
| `src/FlexiPOS.PrintAgent` | ASP.NET Core host; runs as a console app or a Windows service |
| `tests/FlexiPOS.PrintAgent.Tests` | xUnit, in-memory host plus one real-listener test |
| `packaging/` | `publish.ps1`, `install-service.ps1`, `uninstall-service.ps1` |

## Commands

```bash
dotnet build
dotnet test
dotnet run --project src/FlexiPOS.PrintAgent   # http://127.0.0.1:9631/health
```

```powershell
./packaging/publish.ps1           # self-contained single exe in publish/win-x64
./packaging/install-service.ps1   # elevated; registers and starts the service
```

## Security posture

- Listens on `127.0.0.1` only. The endpoint is built from the port alone, so no URL setting can widen it.
- A request whose remote address is not loopback is refused with 403 even if the listener were changed.
- No browser origin may call the agent until `PrintAgent:AllowedOrigins` lists it. Development adds `http://localhost:4200`.
- Nothing is authenticated yet; endpoints are `/health` and `/v1/info` only. The one-time pairing code lands before any endpoint that prints or reads state.

## Configuration

`PrintAgent:Port` (default 9631) and `PrintAgent:AllowedOrigins`, via `appsettings.json` or environment variables (`PrintAgent__Port`).

## Not done yet

- MSI installer (deployment doc calls for one); the scripts above are the interim.
- Code signing, auto-update via the heartbeat.
