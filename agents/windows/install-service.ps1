#Requires -RunAsAdministrator
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string] $SourceDirectory = $PSScriptRoot,
    [string] $ProjectDirectory = (Join-Path $PSScriptRoot '..\..'),
    [string] $InstallDirectory = 'D:\DevTools\DreamHostingMonitor',
    [string] $DataDirectory = 'D:\DreamHostingServerMonitor\agent-data',
    [string] $LogDirectory = 'D:\DreamHostingServerMonitor\service-logs',
    [string] $SourceConfigPath = (Join-Path $env:LOCALAPPDATA 'DreamHostingMonitor\agent.json'),
    [string] $PhpPath = 'C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe',
    [switch] $NoStart
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath $ProjectDirectory).Path
$serviceSource = Join-Path $SourceDirectory 'DreamHostingMonitorService.cs'
$monitorSource = Join-Path $SourceDirectory 'monitor.ps1'
$compilerCandidates = @(
    (Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'),
    (Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe')
)
$compilerPath = $compilerCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
$serviceController = Join-Path $env:WINDIR 'System32\sc.exe'
$icaclsPath = Join-Path $env:WINDIR 'System32\icacls.exe'
$agentServiceName = 'DreamHostingMonitorAgent'
$schedulerServiceName = 'DreamHostingMonitorScheduler'
$webServiceName = 'DreamHostingMonitorWeb'
$serviceNames = @($webServiceName, $schedulerServiceName, $agentServiceName)
$temporaryExecutable = Join-Path $env:TEMP ("DreamHostingMonitorService.{0}.exe" -f [Guid]::NewGuid().ToString('N'))
$createdServices = [System.Collections.Generic.List[string]]::new()
$projectAclSnapshot = $null
$projectAclsChanged = $false

function Add-DirectoryAccessRule {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][System.Security.Principal.SecurityIdentifier] $Identity,
        [Parameter(Mandatory = $true)][System.Security.AccessControl.FileSystemRights] $Rights,
        [System.Security.AccessControl.InheritanceFlags] $InheritanceFlags = [System.Security.AccessControl.InheritanceFlags]::None
    )

    $acl = Get-Acl -LiteralPath $Path
    $rule = [System.Security.AccessControl.FileSystemAccessRule]::new(
        $Identity,
        $Rights,
        $InheritanceFlags,
        [System.Security.AccessControl.PropagationFlags]::None,
        [System.Security.AccessControl.AccessControlType]::Allow
    )
    $acl.AddAccessRule($rule)
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Set-SecureDirectoryAcl {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][System.Security.Principal.SecurityIdentifier] $CurrentUserSid,
        [Parameter(Mandatory = $true)][bool] $LocalServiceCanWrite
    )

    $acl = [System.Security.AccessControl.DirectorySecurity]::new()
    $acl.SetAccessRuleProtection($true, $false)
    $rules = @(
        @{ Sid = [System.Security.Principal.SecurityIdentifier]::new('S-1-5-18'); Rights = [System.Security.AccessControl.FileSystemRights]::FullControl },
        @{ Sid = [System.Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'); Rights = [System.Security.AccessControl.FileSystemRights]::FullControl },
        @{ Sid = [System.Security.Principal.SecurityIdentifier]::new('S-1-5-19'); Rights = if ($LocalServiceCanWrite) { [System.Security.AccessControl.FileSystemRights]::FullControl } else { [System.Security.AccessControl.FileSystemRights]::ReadAndExecute } },
        @{ Sid = $CurrentUserSid; Rights = [System.Security.AccessControl.FileSystemRights]::FullControl }
    )

    foreach ($entry in $rules) {
        $rule = [System.Security.AccessControl.FileSystemAccessRule]::new(
            $entry.Sid,
            $entry.Rights,
            [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit,
            [System.Security.AccessControl.PropagationFlags]::None,
            [System.Security.AccessControl.AccessControlType]::Allow
        )
        $null = $acl.AddAccessRule($rule)
    }

    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Restore-ProjectAcls {
    param([Parameter(Mandatory = $true)][object[]] $Snapshot)

    foreach ($entry in $Snapshot) {
        $acl = [System.Security.AccessControl.DirectorySecurity]::new()
        $acl.SetSecurityDescriptorSddlForm(
            [string] $entry.sddl,
            [System.Security.AccessControl.AccessControlSections]::All
        )
        Set-Acl -LiteralPath ([string] $entry.path) -AclObject $acl
    }
}

function Invoke-ServiceControl {
    param([Parameter(Mandatory = $true)][string[]] $Arguments)

    & $serviceController @Arguments | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Windows service operation failed: $($Arguments[0])"
    }
}

try {
    if (-not (Test-Path -LiteralPath $serviceSource) -or -not (Test-Path -LiteralPath $monitorSource)) {
        throw 'The Windows agent source files are incomplete.'
    }
    if (-not (Test-Path -LiteralPath $projectRoot -PathType Container)) {
        throw 'The Laravel project directory was not found.'
    }
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        throw 'The configured PHP 8.3 executable was not found.'
    }
    if (-not (Test-Path -LiteralPath $SourceConfigPath -PathType Leaf)) {
        throw 'The existing DPAPI-protected agent configuration was not found.'
    }
    if (-not $compilerPath) {
        throw 'The .NET Framework C# compiler was not found; no service changes were made.'
    }
    foreach ($path in @($InstallDirectory, $DataDirectory, $LogDirectory)) {
        if (-not $path.StartsWith('D:\', [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Service installation, agent data, and service logs must remain on D:.'
        }
        if (Test-Path -LiteralPath $path) {
            throw "Refusing to overwrite an existing service directory: $path"
        }
    }
    foreach ($serviceName in $serviceNames) {
        if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
            throw "The service already exists; refusing to overwrite it: $serviceName"
        }
    }
    if (-not (Get-Service -Name 'DreamHostingMySQL' -ErrorAction SilentlyContinue)) {
        throw 'The configured DreamHostingMySQL dependency service was not found.'
    }

    $configWrapper = Get-Content -LiteralPath $SourceConfigPath -Raw | ConvertFrom-Json
    $monitorUri = $null
    if (-not $configWrapper.protected_data -or -not [Uri]::TryCreate([string] $configWrapper.base_url, [UriKind]::Absolute, [ref] $monitorUri)) {
        throw 'The saved agent configuration is not in the expected DPAPI-protected format.'
    }
    $localHosts = @('localhost', '127.0.0.1', '::1')
    if ($monitorUri.Scheme -ne 'https' -and -not ($monitorUri.Scheme -eq 'http' -and $monitorUri.Host -in $localHosts)) {
        throw 'The agent monitor URL must use HTTPS outside localhost testing.'
    }

    $drive = [System.IO.DriveInfo]::new('D:\')
    if (-not $drive.IsReady -or $drive.AvailableFreeSpace -lt 50MB) {
        throw 'D: is not ready or does not have enough free space for the service files.'
    }

    & $compilerPath /nologo /target:exe "/out:$temporaryExecutable" /reference:System.ServiceProcess.dll /reference:System.Web.Extensions.dll $serviceSource
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $temporaryExecutable)) {
        throw 'The Windows service host could not be compiled.'
    }
    & $temporaryExecutable --self-check | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'The Windows service host self-check failed.'
    }

    $action = 'Install three automatic services on D:, copy the existing encrypted agent state, and grant LocalService only the required access.'
    if (-not $PSCmdlet.ShouldProcess('Dream Hosting Monitor Windows services', $action)) {
        return
    }

    $serviceConfigDirectory = Join-Path $InstallDirectory 'services'
    $null = New-Item -ItemType Directory -Path $serviceConfigDirectory -Force
    $null = New-Item -ItemType Directory -Path $DataDirectory -Force
    $null = New-Item -ItemType Directory -Path $LogDirectory -Force
    $null = New-Item -ItemType Directory -Path (Join-Path $LogDirectory 'acl-backups') -Force

    $currentSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
    Set-SecureDirectoryAcl -Path $InstallDirectory -CurrentUserSid $currentSid -LocalServiceCanWrite $false
    Set-SecureDirectoryAcl -Path $serviceConfigDirectory -CurrentUserSid $currentSid -LocalServiceCanWrite $false
    Set-SecureDirectoryAcl -Path $DataDirectory -CurrentUserSid $currentSid -LocalServiceCanWrite $true
    Set-SecureDirectoryAcl -Path $LogDirectory -CurrentUserSid $currentSid -LocalServiceCanWrite $true
    Set-SecureDirectoryAcl -Path (Join-Path $LogDirectory 'acl-backups') -CurrentUserSid $currentSid -LocalServiceCanWrite $true

    $installedMonitorScript = Join-Path $InstallDirectory 'monitor.ps1'
    $installedServiceHost = Join-Path $InstallDirectory 'DreamHostingMonitorService.exe'
    Copy-Item -LiteralPath $monitorSource -Destination $installedMonitorScript -ErrorAction Stop
    Copy-Item -LiteralPath $temporaryExecutable -Destination $installedServiceHost -ErrorAction Stop

    $installedConfigPath = Join-Path $DataDirectory 'agent.json'
    Copy-Item -LiteralPath $SourceConfigPath -Destination $installedConfigPath -ErrorAction Stop
    if ((Get-FileHash -LiteralPath $SourceConfigPath -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $installedConfigPath -Algorithm SHA256).Hash) {
        throw 'The existing encrypted agent configuration did not copy intact.'
    }
    $sourcePendingPath = "$SourceConfigPath.pending"
    if (Test-Path -LiteralPath $sourcePendingPath -PathType Leaf) {
        Copy-Item -LiteralPath $sourcePendingPath -Destination "$installedConfigPath.pending" -ErrorAction Stop
        if ((Get-FileHash -LiteralPath $sourcePendingPath -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath "$installedConfigPath.pending" -Algorithm SHA256).Hash) {
            throw 'The encrypted pending queue did not copy intact.'
        }
    }

    $userProfile = $env:USERPROFILE.TrimEnd('\')
    $ancestorDirectories = [System.Collections.Generic.List[string]]::new()
    $currentDirectory = $projectRoot
    $pathRoot = [System.IO.Path]::GetPathRoot($currentDirectory)
    while (-not [string]::Equals($currentDirectory, $pathRoot, [StringComparison]::OrdinalIgnoreCase)) {
        $currentDirectory = Split-Path -Parent $currentDirectory
        if ($currentDirectory -and -not [string]::Equals($currentDirectory, $pathRoot, [StringComparison]::OrdinalIgnoreCase)) {
            $ancestorDirectories.Add($currentDirectory)
        }
    }
    $ancestorDirectories = @($ancestorDirectories | Select-Object -Unique)
    $projectWritableDirectories = @(
        (Join-Path $projectRoot 'storage'),
        (Join-Path $projectRoot 'bootstrap\cache')
    )
    $aclPaths = @($ancestorDirectories) + @($projectRoot) + $projectWritableDirectories
    foreach ($path in $aclPaths) {
        if (-not (Test-Path -LiteralPath $path -PathType Container)) {
            throw "Required Laravel directory was not found: $path"
        }
    }

    $projectAclSnapshot = foreach ($path in $aclPaths) {
        $acl = Get-Acl -LiteralPath $path
        [PSCustomObject]@{
            path = $path
            sddl = $acl.GetSecurityDescriptorSddlForm([System.Security.AccessControl.AccessControlSections]::All)
        }
    }
    $aclBackupPath = Join-Path (Join-Path $LogDirectory 'acl-backups') 'project-directory-acls.json'
    $aclSnapshotJson = ConvertTo-Json -InputObject @($projectAclSnapshot) -Depth 4 -Compress
    [IO.File]::WriteAllText($aclBackupPath, $aclSnapshotJson, [Text.UTF8Encoding]::new($false))

    $localServiceSid = [System.Security.Principal.SecurityIdentifier]::new('S-1-5-19')
    $projectAclsChanged = $true
    foreach ($path in $ancestorDirectories) {
        Add-DirectoryAccessRule -Path $path -Identity $localServiceSid -Rights ([System.Security.AccessControl.FileSystemRights]::ExecuteFile)
    }
    $projectInheritance = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
    Add-DirectoryAccessRule -Path $projectRoot -Identity $localServiceSid -Rights ([System.Security.AccessControl.FileSystemRights]::ReadAndExecute) -InheritanceFlags $projectInheritance
    foreach ($path in $projectWritableDirectories) {
        Add-DirectoryAccessRule -Path $path -Identity $localServiceSid -Rights ([System.Security.AccessControl.FileSystemRights]::Modify) -InheritanceFlags $projectInheritance
    }

    $agentPowerShell = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $agentArguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}" -ConfigPath "{1}"' -f $installedMonitorScript, $installedConfigPath
    $serviceDefinitions = @(
        [PSCustomObject]@{
            Name = $webServiceName
            DisplayName = 'Dream Hosting Monitor Local Web'
            Executable = $PhpPath
            Arguments = 'artisan serve --host=127.0.0.1 --port=8000 --no-interaction'
            WorkingDirectory = $projectRoot
            LogPath = Join-Path $LogDirectory 'web.log'
            Dependencies = 'DreamHostingMySQL'
            Description = 'Local development server bound to loopback for Dream Hosting Monitor.'
        },
        [PSCustomObject]@{
            Name = $schedulerServiceName
            DisplayName = 'Dream Hosting Monitor Scheduler'
            Executable = $PhpPath
            Arguments = 'artisan schedule:work --no-interaction'
            WorkingDirectory = $projectRoot
            LogPath = Join-Path $LogDirectory 'scheduler.log'
            Dependencies = 'DreamHostingMySQL'
            Description = 'Runs Laravel scheduled monitoring tasks for Dream Hosting Monitor.'
        },
        [PSCustomObject]@{
            Name = $agentServiceName
            DisplayName = 'Dream Hosting Monitor Windows Agent'
            Executable = $agentPowerShell
            Arguments = $agentArguments
            WorkingDirectory = $InstallDirectory
            LogPath = Join-Path $LogDirectory 'agent.log'
            Dependencies = 'DreamHostingMonitorWeb/DreamHostingMySQL'
            Description = 'Collects local Windows CPU, memory, and disk metrics and sends them to Dream Hosting Monitor.'
        }
    )

    foreach ($service in $serviceDefinitions) {
        $configurationPath = Join-Path $serviceConfigDirectory ($service.Name + '.json')
        $configuration = [ordered]@{
            service_name = $service.Name
            executable = $service.Executable
            arguments = $service.Arguments
            working_directory = $service.WorkingDirectory
            log_path = $service.LogPath
            restart_delay_seconds = 15
        }
        $configurationJson = ConvertTo-Json -InputObject $configuration -Compress
        [IO.File]::WriteAllText($configurationPath, $configurationJson, [Text.UTF8Encoding]::new($false))
        & $installedServiceHost --validate-config $configurationPath | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "The $($service.Name) service configuration did not validate."
        }
    }

    foreach ($service in $serviceDefinitions) {
        $configurationPath = Join-Path $serviceConfigDirectory ($service.Name + '.json')
        $binaryPath = '"{0}" --config "{1}"' -f $installedServiceHost, $configurationPath
        Invoke-ServiceControl -Arguments @('create', $service.Name, "binPath= $binaryPath", 'start= auto', 'obj= NT AUTHORITY\LocalService', "DisplayName= $($service.DisplayName)")
        $createdServices.Add($service.Name)
        Invoke-ServiceControl -Arguments @('description', $service.Name, $service.Description)
        Invoke-ServiceControl -Arguments @('failure', $service.Name, 'reset= 86400', 'actions= restart/60000/restart/60000/restart/60000')
        Invoke-ServiceControl -Arguments @('failureflag', $service.Name, '1')
        Invoke-ServiceControl -Arguments @('config', $service.Name, "depend= $($service.Dependencies)")
    }

    if (-not $NoStart) {
        foreach ($serviceName in $serviceNames) {
            Start-Service -Name $serviceName
        }
    }

    if ($NoStart) {
        Write-Output 'Three automatic LocalService entries were registered but remain stopped for a controlled handover.'
    } else {
        Write-Output 'The web, scheduler, and agent services are registered for automatic startup and running.'
    }
    Write-Output "Service files: $InstallDirectory"
    Write-Output "Agent data and logs: $DataDirectory ; $LogDirectory"
    Write-Output "Project ACL backup: $aclBackupPath"
} catch {
    foreach ($serviceName in $createdServices) {
        try {
            Stop-Service -Name $serviceName -Force -ErrorAction SilentlyContinue
            Invoke-ServiceControl -Arguments @('delete', $serviceName)
        } catch {
            # Preserve the original installer error and leave the ACL snapshot available for recovery.
        }
    }
    if ($projectAclsChanged -and $projectAclSnapshot) {
        try {
            Restore-ProjectAcls -Snapshot @($projectAclSnapshot)
        } catch {
            throw 'Service installation failed, and automatic project ACL rollback also failed. The ACL snapshot remains on D: for recovery.'
        }
    }
    throw
} finally {
    [IO.File]::Delete($temporaryExecutable)
}
