#Requires -RunAsAdministrator
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string] $InstallDirectory = 'D:\DevTools\DreamHostingMonitor',
    [string] $ServiceConfigDirectory = 'D:\DevTools\DreamHostingMonitor\services',
    [string] $BackupDirectory = 'D:\DevTools\DreamHostingMonitor\installer-backups'
)

$ErrorActionPreference = 'Stop'
$serviceName = 'DreamHostingMonitorScheduler'
$configurationPath = Join-Path $ServiceConfigDirectory ($serviceName + '.json')
$serviceHostPath = Join-Path $InstallDirectory 'DreamHostingMonitorService.exe'
$service = Get-Service -Name $serviceName -ErrorAction Stop

foreach ($path in @($InstallDirectory, $ServiceConfigDirectory, $BackupDirectory)) {
    if (-not $path.StartsWith('D:\', [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Scheduler repair files and backups must remain on D:.'
    }
    if (-not (Test-Path -LiteralPath $path -PathType Container)) {
        throw "Required scheduler repair directory was not found: $path"
    }
}

if (-not (Test-Path -LiteralPath $serviceHostPath -PathType Leaf) -or -not (Test-Path -LiteralPath $configurationPath -PathType Leaf)) {
    throw 'The installed scheduler service host or configuration was not found.'
}

$configuration = Get-Content -LiteralPath $configurationPath -Raw | ConvertFrom-Json
if ([string] $configuration.service_name -cne $serviceName) {
    throw 'The installed scheduler configuration has an unexpected service name.'
}
$schedulerExecutableExists = Test-Path -LiteralPath ([string] $configuration.executable) -PathType Leaf
$schedulerWorkingDirectoryExists = Test-Path -LiteralPath ([string] $configuration.working_directory) -PathType Container
$schedulerLogDirectoryExists = Test-Path -LiteralPath (Split-Path -Parent ([string] $configuration.log_path)) -PathType Container
if (-not $schedulerExecutableExists -or -not $schedulerWorkingDirectoryExists -or -not $schedulerLogDirectoryExists) {
    throw 'The installed scheduler executable, working directory, or log directory is unavailable.'
}

$newArguments = 'artisan schedule:run --no-interaction'
$newRestartDelaySeconds = 60
if ([string] $configuration.arguments -ceq $newArguments -and [int] $configuration.restart_delay_seconds -eq $newRestartDelaySeconds) {
    Write-Output 'The scheduler service already uses the schedule:run loop; no changes were made.'
    return
}
if ([string] $configuration.arguments -cne 'artisan schedule:work --no-interaction' -or [int] $configuration.restart_delay_seconds -ne 15) {
    throw 'The scheduler configuration does not match the expected previous version; no changes were made.'
}

$serviceWasRunning = $service.Status -eq [System.ServiceProcess.ServiceControllerStatus]::Running
$action = if ($serviceWasRunning) {
    'Back up and update only the scheduler configuration, then restart that service.'
} else {
    'Back up and update only the scheduler configuration, leaving the service stopped.'
}
if (-not $PSCmdlet.ShouldProcess($serviceName, $action)) {
    return
}

$configuration.arguments = $newArguments
$configuration.restart_delay_seconds = $newRestartDelaySeconds
$candidateJson = ConvertTo-Json -InputObject $configuration -Compress
$fileId = [Guid]::NewGuid().ToString('N')
$candidatePath = Join-Path $ServiceConfigDirectory ($serviceName + '.candidate.' + $fileId + '.json')
$backupPath = Join-Path $BackupDirectory ($serviceName + '.before-repair.' + $fileId + '.json')
$failedConfigBackupPath = Join-Path $BackupDirectory ($serviceName + '.failed-repair.' + $fileId + '.json')
$rollbackBackupPath = Join-Path $BackupDirectory ($serviceName + '.rollback.' + $fileId + '.json')
$configurationReplaced = $false

try {
    [IO.File]::WriteAllText($candidatePath, $candidateJson, [Text.UTF8Encoding]::new($false))
    & $serviceHostPath --validate-config $candidatePath | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'The updated scheduler configuration did not validate.'
    }

    Copy-Item -LiteralPath $configurationPath -Destination $backupPath -ErrorAction Stop
    if ((Get-FileHash -LiteralPath $configurationPath -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $backupPath -Algorithm SHA256).Hash) {
        throw 'The scheduler configuration backup did not verify.'
    }

    if ($serviceWasRunning) {
        Stop-Service -Name $serviceName -ErrorAction Stop
        (Get-Service -Name $serviceName).WaitForStatus([System.ServiceProcess.ServiceControllerStatus]::Stopped, [TimeSpan]::FromSeconds(30))
    }

    [IO.File]::Replace($candidatePath, $configurationPath, $failedConfigBackupPath)
    $configurationReplaced = $true

    if ($serviceWasRunning) {
        Start-Service -Name $serviceName -ErrorAction Stop
        (Get-Service -Name $serviceName).WaitForStatus([System.ServiceProcess.ServiceControllerStatus]::Running, [TimeSpan]::FromSeconds(30))
    }

    if ($serviceWasRunning) {
        Write-Output 'The scheduler service now runs schedule:run and was restarted.'
    } else {
        Write-Output 'The scheduler configuration now uses schedule:run; the service remains stopped.'
    }
    Write-Output "Original scheduler configuration backup: $backupPath"
    Write-Output "Atomic replacement backup: $failedConfigBackupPath"
} catch {
    $rollbackError = $null
    try {
        if ($configurationReplaced) {
            $restorePath = Join-Path $ServiceConfigDirectory ($serviceName + '.restore.' + $fileId + '.json')
            Copy-Item -LiteralPath $backupPath -Destination $restorePath -ErrorAction Stop
            [IO.File]::Replace($restorePath, $configurationPath, $rollbackBackupPath)
        }

        if ($serviceWasRunning) {
            $currentService = Get-Service -Name $serviceName
            if ($currentService.Status -ne [System.ServiceProcess.ServiceControllerStatus]::Running) {
                Start-Service -Name $serviceName -ErrorAction Stop
                (Get-Service -Name $serviceName).WaitForStatus([System.ServiceProcess.ServiceControllerStatus]::Running, [TimeSpan]::FromSeconds(30))
            }
        }
    } catch {
        $rollbackError = $_.Exception.Message
    }

    if ($rollbackError) {
        throw "Scheduler repair failed and rollback also failed. Preserve the D: backup at $backupPath. Recovery error: $rollbackError"
    }

    throw
} finally {
    if (Test-Path -LiteralPath $candidatePath -PathType Leaf) {
        Remove-Item -LiteralPath $candidatePath -Force -ErrorAction SilentlyContinue
    }
    $restorePath = Join-Path $ServiceConfigDirectory ($serviceName + '.restore.' + $fileId + '.json')
    if (Test-Path -LiteralPath $restorePath -PathType Leaf) {
        Remove-Item -LiteralPath $restorePath -Force -ErrorAction SilentlyContinue
    }
}
