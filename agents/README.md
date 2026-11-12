# Monitoring agents

The MVP agents send CPU, memory, disk, and heartbeat data to the Laravel API. They make outbound HTTPS requests and do not accept remote commands.

## Linux

Requirements: Python 3.10+ and a Linux host with `/proc` mounted. No third-party Python packages are used.

1. Register a Linux server in the dashboard and copy its one-time enrollment token.
2. Copy `agents/linux/monitor.py` to the host.
3. Set `DREAM_MONITOR_URL` to the HTTPS URL of the monitor and set `DREAM_MONITOR_ENROLLMENT_TOKEN` for the first run.
4. Run `python3 monitor.py`. The agent stores its per-server credential in `~/.config/dream-hosting-monitor/agent.json` with mode `0600`.
5. Clear the enrollment token from the shell after it has been consumed. Run the script under a dedicated unprivileged service account for ongoing monitoring.

Use a systemd unit for persistence after a manual enrollment. Store the monitor URL in the unit and keep the agent config readable only by its service account. Do not put the enrollment token or agent credential in command-line arguments.

## Windows

Requirements: Windows PowerShell 5.1 or newer, permission to query local CIM metrics, and .NET Framework 4.x for the service host compiler.

1. Register a Windows server in the dashboard and copy its one-time enrollment token.
2. Open an elevated PowerShell window in `agents/windows` and run `.\monitor.ps1 -MonitorUrl https://your-monitor.example -EnrollOnly`.
3. Paste the token at the hidden prompt. The agent sends it only in the enrollment request, then stores the returned credential protected by Windows DPAPI (LocalMachine scope). The credential file and pending metric queue are restricted to SYSTEM, local Administrators, LocalService, and the enrolling account.
4. Optionally verify one cycle with `.\monitor.ps1 -Once`. This sends a heartbeat and one metrics batch.
5. For automatic startup, open an elevated PowerShell session in the project root and register the services without starting them:

   ```powershell
   .\agents\windows\install-service.ps1 -NoStart
   ```

   If an earlier installation attempt staged the files on D: but stopped before registering services, resume with `-ResumeExisting`. This validates the staged files and preserves the existing agent data and service configurations.

   The installer requires Administrator rights and the DPAPI-protected agent configuration created during enrollment. It registers `DreamHostingMonitorWeb`, `DreamHostingMonitorScheduler`, and `DreamHostingMonitorAgent` for automatic startup, runs them as `LocalService`, and configures service recovery. The scheduler service runs `schedule:run` once per minute; scheduled maintenance callbacks execute in the Laravel process to avoid Windows service child-process working-directory failures. Default installation, agent-data, and log paths are on D:. The local Laravel server binds only to `127.0.0.1:8000`; do not expose it publicly. If the enrollment was done under a different Windows account, pass that account's protected config with `-SourceConfigPath`.

   To update an already registered scheduler service to this loop, run `.\agents\windows\repair-scheduler-service.ps1` from an elevated PowerShell window. It backs up the current scheduler configuration on D:, updates only that service, and leaves the web, agent, MySQL, and database data untouched.

6. Before starting the services, confirm `DreamHostingMySQL` is running. If it is stopped, start it from the elevated shell. Stop any existing foreground Laravel, scheduler, or agent processes so there is only one instance. Then start the services:

   ```powershell
   Start-Service -Name DreamHostingMySQL,DreamHostingMonitorWeb,DreamHostingMonitorScheduler,DreamHostingMonitorAgent
   Get-Service -Name DreamHostingMySQL,DreamHostingMonitorWeb,DreamHostingMonitorScheduler,DreamHostingMonitorAgent
   ```

The service sends a heartbeat and CPU, memory, and fixed-disk metrics every 60 seconds. It reports its version and Windows edition/build. If the API is unreachable, it keeps up to 120 encrypted one-sample batches (about two hours) and retries each with the same batch UUID, so an accepted request whose response was lost will be deduplicated safely. A full queue skips adding new samples until space is available. The agent makes outbound HTTPS requests only and does not accept remote commands. HTTP is allowed only for localhost development.

Service logs are written to `D:\DreamHostingServerMonitor\service-logs` and rotate at 5 MB. The supervisor redacts common credential formats before writing child output. The agent also uses a global mutex to prevent a second copy from collecting or submitting metrics concurrently.

### Windows service controls

Run these commands from an elevated PowerShell session. Stop dependent services in reverse order and start them in dependency order:

```powershell
Get-Service -Name DreamHostingMonitorWeb,DreamHostingMonitorScheduler,DreamHostingMonitorAgent
Start-Service -Name DreamHostingMonitorWeb,DreamHostingMonitorScheduler,DreamHostingMonitorAgent
Stop-Service -Name DreamHostingMonitorAgent,DreamHostingMonitorScheduler,DreamHostingMonitorWeb
```

To restart all three, stop them in reverse dependency order and then start them in dependency order:

```powershell
Stop-Service -Name DreamHostingMonitorAgent,DreamHostingMonitorScheduler,DreamHostingMonitorWeb
Start-Service -Name DreamHostingMonitorWeb,DreamHostingMonitorScheduler,DreamHostingMonitorAgent
```

To restart just one service, use `Restart-Service -Name DreamHostingMonitorAgent`, substituting the target service name.

To uninstall the service registrations, first disable the server in the monitor dashboard to revoke its agent credential, then stop and delete the registrations:

```powershell
Stop-Service -Name DreamHostingMonitorAgent,DreamHostingMonitorScheduler,DreamHostingMonitorWeb
sc.exe delete DreamHostingMonitorAgent
sc.exe delete DreamHostingMonitorScheduler
sc.exe delete DreamHostingMonitorWeb
```

Deleting the registrations does not remove files. Keep `D:\DreamHostingServerMonitor\agent-data` if reinstalling; it contains the protected agent credential and queued samples. Review and revoke credentials before separately removing any service, data, or log directories.

## Protocol

- `POST /api/v1/agent/enroll`: one-time token exchange; the returned credential is shown only once.
- `POST /api/v1/agent/heartbeat`: bearer-authenticated liveness check and agent/OS version update.
- `POST /api/v1/agent/metrics`: bearer-authenticated metric batch, with a stable UUID per queued batch for retry idempotency.

The agent validates HTTPS certificates using the operating system trust store. Do not disable certificate validation or put enrollment tokens or agent credentials in command-line arguments, environment variables, scripts, or logs.
