[CmdletBinding()]
param(
    [string] $MonitorUrl,
    [string] $ConfigPath = (Join-Path $env:ProgramData 'DreamHostingMonitor\agent.json'),
    [switch] $EnrollOnly,
    [switch] $Once,
    [switch] $FunctionsOnly
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$script:AgentVersion = '0.2.0'
$script:IntervalSeconds = 60
$script:MaximumQueuedBatches = 120
$script:MaximumBatchesPerCycle = 3
$script:Entropy = [Text.Encoding]::UTF8.GetBytes('DreamHostingMonitor.Agent.v2')

try {
    Add-Type -AssemblyName System.Security
} catch {
    throw 'Windows data protection support is unavailable.'
}

function Normalize-MonitorUrl {
    param([Parameter(Mandatory = $true)][string] $Value)

    $parsedUrl = $null
    if (-not [Uri]::TryCreate($Value, [UriKind]::Absolute, [ref] $parsedUrl)) {
        throw 'The monitor URL is invalid.'
    }

    $localHosts = @('localhost', '127.0.0.1', '::1')
    if ($parsedUrl.Scheme -ne 'https' -and -not ($parsedUrl.Scheme -eq 'http' -and $parsedUrl.Host -in $localHosts)) {
        throw 'The monitor URL must use HTTPS; HTTP is allowed only for localhost testing.'
    }
    if ($parsedUrl.UserInfo -or $parsedUrl.Query -or $parsedUrl.Fragment) {
        throw 'The monitor URL cannot contain credentials, a query, or a fragment.'
    }

    return $Value.TrimEnd('/')
}

function Get-AllowedSids {
    $sids = @(
        [System.Security.Principal.SecurityIdentifier]::new('S-1-5-18'),
        [System.Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'),
        [System.Security.Principal.SecurityIdentifier]::new('S-1-5-19'),
        [System.Security.Principal.WindowsIdentity]::GetCurrent().User
    )

    return $sids | Sort-Object -Property Value -Unique
}

function Enter-AgentMutex {
    param([Parameter(Mandatory = $true)][string] $Name)

    $mutexSecurity = [System.Security.AccessControl.MutexSecurity]::new()
    foreach ($sid in (Get-AllowedSids)) {
        $accessRule = [System.Security.AccessControl.MutexAccessRule]::new(
            $sid,
            [System.Security.AccessControl.MutexRights]::FullControl,
            [System.Security.AccessControl.AccessControlType]::Allow
        )
        $null = $mutexSecurity.AddAccessRule($accessRule)
    }

    $createdNew = $false
    $mutex = [System.Threading.Mutex]::new($false, $Name, [ref] $createdNew, $mutexSecurity)
    try {
        $ownsMutex = $mutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $ownsMutex = $true
    }

    if (-not $ownsMutex) {
        $mutex.Dispose()
        return $null
    }

    return $mutex
}

function Set-AgentDirectoryAcl {
    param([Parameter(Mandatory = $true)][string] $Path)

    $null = New-Item -ItemType Directory -Path $Path -Force
    $acl = [System.Security.AccessControl.DirectorySecurity]::new()
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($sid in (Get-AllowedSids)) {
        $rule = [System.Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [System.Security.AccessControl.FileSystemRights]::FullControl,
            [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit,
            [System.Security.AccessControl.PropagationFlags]::None,
            [System.Security.AccessControl.AccessControlType]::Allow
        )
        $null = $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Protect-AgentJson {
    param([Parameter(Mandatory = $true)][string] $Json)

    $bytes = [Text.Encoding]::UTF8.GetBytes($Json)
    try {
        $protectedBytes = [Security.Cryptography.ProtectedData]::Protect(
            $bytes,
            $script:Entropy,
            [Security.Cryptography.DataProtectionScope]::LocalMachine
        )
        return [Convert]::ToBase64String($protectedBytes)
    } finally {
        [Array]::Clear($bytes, 0, $bytes.Length)
    }
}

function Unprotect-AgentJson {
    param([Parameter(Mandatory = $true)][string] $ProtectedValue)

    $protectedBytes = [Convert]::FromBase64String($ProtectedValue)
    $clearBytes = $null
    try {
        $clearBytes = [Security.Cryptography.ProtectedData]::Unprotect(
            $protectedBytes,
            $script:Entropy,
            [Security.Cryptography.DataProtectionScope]::LocalMachine
        )
        return [Text.Encoding]::UTF8.GetString($clearBytes)
    } finally {
        [Array]::Clear($protectedBytes, 0, $protectedBytes.Length)
        if ($null -ne $clearBytes) {
            [Array]::Clear($clearBytes, 0, $clearBytes.Length)
        }
    }
}

function Save-ProtectedJson {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][AllowEmptyCollection()][object] $Value
    )

    $directory = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        Set-AgentDirectoryAcl -Path $directory
    }
    $json = ConvertTo-Json -InputObject $Value -Depth 12 -Compress
    $protectedValue = Protect-AgentJson -Json $json
    $wrapperValue = @{ protected_data = $protectedValue }
    if ($null -ne $Value.PSObject.Properties['base_url']) {
        $wrapperValue.base_url = [string] $Value.base_url
    }
    $wrapper = ConvertTo-Json -InputObject $wrapperValue -Compress
    $fileId = [Guid]::NewGuid().ToString('N')
    $temporaryPath = "$Path.$fileId.tmp"
    $backupPath = "$Path.$fileId.bak"
    try {
        [IO.File]::WriteAllText($temporaryPath, $wrapper, [Text.UTF8Encoding]::new($false))

        if (Test-Path -LiteralPath $Path) {
            try {
                [IO.File]::Replace($temporaryPath, $Path, $backupPath)
            } catch {
                [IO.File]::Move($Path, $backupPath)
                try {
                    [IO.File]::Move($temporaryPath, $Path)
                } catch {
                    [IO.File]::Move($backupPath, $Path)
                    throw
                }
            }
            if (Test-Path -LiteralPath $backupPath) {
                [IO.File]::Delete($backupPath)
            }
        } else {
            [IO.File]::Move($temporaryPath, $Path)
        }
    } finally {
        if (Test-Path -LiteralPath $temporaryPath) {
            [IO.File]::Delete($temporaryPath)
        }
    }
}

function Read-AgentJson {
    param([Parameter(Mandatory = $true)][string] $Path)

    try {
        $storedValue = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
        if ($storedValue.protected_data) {
            $json = Unprotect-AgentJson -ProtectedValue $storedValue.protected_data
            return ConvertFrom-Json -InputObject $json
        }

        # Convert the earlier JSON credential format to machine-protected storage.
        if ($storedValue.credential -is [string] -and $storedValue.credential -match '^[a-fA-F0-9]{64}$') {
            return $storedValue
        }
    } catch {
        throw 'The saved agent state could not be read securely.'
    }

    throw 'The saved agent state is invalid.'
}

function Read-EnrollmentToken {
    $secureToken = Read-Host 'Paste the one-time enrollment token' -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
        $secureToken.Dispose()
    }
}

function Invoke-MonitorPost {
    param(
        [Parameter(Mandatory = $true)][string] $BaseUrl,
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][object] $Body,
        [string] $Credential
    )

    $headers = @{ Accept = 'application/json' }
    if (-not [string]::IsNullOrWhiteSpace($Credential)) {
        $headers.Authorization = "Bearer $Credential"
    }
    $json = ConvertTo-Json -InputObject $Body -Depth 12 -Compress

    try {
        return Invoke-RestMethod -Method Post -Uri "$BaseUrl$Path" -Headers $headers -ContentType 'application/json' -Body $json -TimeoutSec 15 -ErrorAction Stop
    } catch {
        throw 'The monitoring API request failed.'
    }
}

function Get-AgentConfig {
    param([Parameter(Mandatory = $true)][string] $BaseUrl)

    $enrollmentToken = $null
    try {
        $enrollmentToken = Read-EnrollmentToken
        $response = Invoke-MonitorPost -BaseUrl $BaseUrl -Path '/api/v1/agent/enroll' -Body @{
            enrollment_token = $enrollmentToken
            hostname = $env:COMPUTERNAME
            platform = 'windows'
            os_version = Get-OperatingSystemVersion
            agent_version = $script:AgentVersion
        }

        if ($response.credential -notmatch '^[a-fA-F0-9]{64}$' -or [string]::IsNullOrWhiteSpace($response.server_id)) {
            throw 'The enrollment response was invalid.'
        }

        $config = [PSCustomObject]@{
            base_url = $BaseUrl
            server_id = [string] $response.server_id
            credential_id = [string] $response.credential_id
            credential = [string] $response.credential
        }
        Save-ProtectedJson -Path $ConfigPath -Value $config
        Write-Host 'Agent enrollment completed. The credential is protected with Windows DPAPI and restricted file permissions.'
        return $config
    } catch {
        throw 'Enrollment failed. Check the monitor URL, one-time token status, and local file permissions; no credentials were printed.'
    } finally {
        $enrollmentToken = $null
    }
}

function Get-OperatingSystemVersion {
    try {
        $operatingSystem = Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop
        $description = '{0} ({1}, build {2})' -f $operatingSystem.Caption, $operatingSystem.Version, $operatingSystem.BuildNumber
        return $description.Substring(0, [Math]::Min(255, $description.Length))
    } catch {
        throw 'Windows operating system details could not be collected.'
    }
}

function Get-MemoryUsedBytes {
    param(
        [Parameter(Mandatory = $true)][Int64] $TotalBytes,
        [Parameter(Mandatory = $true)][Int64] $FreeBytes
    )

    $usedBytes = [Int64] ($TotalBytes - $FreeBytes)
    if ($usedBytes -lt 0) {
        return [Int64] 0
    }

    return $usedBytes
}

function Get-MetricSample {
    $operatingSystem = Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop
    $processors = @(Get-CimInstance -ClassName Win32_Processor -ErrorAction Stop)
    $cpuValue = ($processors | Measure-Object -Property LoadPercentage -Average).Average
    if ($null -eq $cpuValue) {
        $cpuValue = 0
    }

    $memoryTotal = [Int64] $operatingSystem.TotalVisibleMemorySize * 1024
    $memoryFree = [Int64] $operatingSystem.FreePhysicalMemory * 1024
    $disks = @(Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType=3' -ErrorAction Stop |
        Where-Object { $_.Size -gt 0 } |
        ForEach-Object {
            @{
                filesystem = [string] $_.DeviceID
                total_bytes = [Int64] $_.Size
                used_bytes = [Int64] ($_.Size - $_.FreeSpace)
            }
        })

    return @{
        collected_at = [DateTime]::UtcNow.ToString('o')
        cpu_percent = [Math]::Round([Math]::Max(0, [Math]::Min(100, [double] $cpuValue)), 2)
        memory_total_bytes = $memoryTotal
        memory_used_bytes = (Get-MemoryUsedBytes -TotalBytes $memoryTotal -FreeBytes $memoryFree)
        disks = $disks
    }
}

function Load-PendingBatches {
    param([string] $Path = $script:PendingPath)

    if (-not (Test-Path -LiteralPath $Path)) {
        $batches = [System.Collections.Generic.List[object]]::new()
        Write-Output -NoEnumerate $batches
        return
    }

    $storedBatches = Read-AgentJson -Path $Path
    $batches = [System.Collections.Generic.List[object]]::new()
    foreach ($batch in @($storedBatches)) {
        $batches.Add($batch)
    }
    Write-Output -NoEnumerate $batches
}

function Save-PendingBatches {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][System.Collections.Generic.List[object]] $Batches)

    Save-ProtectedJson -Path $script:PendingPath -Value @($Batches.ToArray())
}

function Send-AgentHeartbeat {
    param([Parameter(Mandatory = $true)][object] $Config)

    $null = Invoke-MonitorPost -BaseUrl $Config.base_url -Path '/api/v1/agent/heartbeat' -Credential $Config.credential -Body @{
        agent_version = $script:AgentVersion
        os_version = Get-OperatingSystemVersion
    }
}

function Send-PendingMetricBatches {
    param([Parameter(Mandatory = $true)][object] $Config)

    $pendingBatches = Load-PendingBatches
    if ($pendingBatches.Count -eq 0) {
        if ($pendingBatches.Count -lt $script:MaximumQueuedBatches) {
            $pendingBatches.Add([PSCustomObject]@{
                batch_uuid = [Guid]::NewGuid().ToString()
                agent_version = $script:AgentVersion
                samples = @((Get-MetricSample))
            })
            Save-PendingBatches -Batches $pendingBatches
        }
    } else {
        if ($pendingBatches.Count -lt $script:MaximumQueuedBatches) {
            $pendingBatches.Add([PSCustomObject]@{
                batch_uuid = [Guid]::NewGuid().ToString()
                agent_version = $script:AgentVersion
                samples = @((Get-MetricSample))
            })
            Save-PendingBatches -Batches $pendingBatches
        } else {
            Write-Warning 'The encrypted retry queue is full; this cycle will not add another metric sample.'
        }
    }

    $sentCount = 0
    while ($pendingBatches.Count -gt 0 -and $sentCount -lt $script:MaximumBatchesPerCycle) {
        $batch = $pendingBatches[0]
        $response = Invoke-MonitorPost -BaseUrl $Config.base_url -Path '/api/v1/agent/metrics' -Credential $Config.credential -Body @{
            batch_uuid = $batch.batch_uuid
            agent_version = $batch.agent_version
            samples = @($batch.samples)
        }
        if ($response.status -notin @('stored', 'duplicate')) {
            throw 'The metric API returned an unexpected status.'
        }

        $pendingBatches.RemoveAt(0)
        Save-PendingBatches -Batches $pendingBatches
        $sentCount++
    }
}

if ($FunctionsOnly) {
    return
}

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    if ([string]::IsNullOrWhiteSpace($MonitorUrl)) {
        throw 'Provide the monitor URL for first enrollment.'
    }
    $BaseUrl = Normalize-MonitorUrl -Value $MonitorUrl
    $config = Get-AgentConfig -BaseUrl $BaseUrl
} else {
    try {
        $config = Read-AgentJson -Path $ConfigPath
        if ($config.credential -notmatch '^[a-fA-F0-9]{64}$' -or [string]::IsNullOrWhiteSpace($config.base_url)) {
            throw 'The saved agent state is invalid.'
        }
        $BaseUrl = Normalize-MonitorUrl -Value ([string] $config.base_url)
        if (-not [string]::IsNullOrWhiteSpace($MonitorUrl) -and (Normalize-MonitorUrl -Value $MonitorUrl) -ne $BaseUrl) {
            throw 'The saved credential belongs to a different monitor URL.'
        }
        if ($config.base_url -ne $BaseUrl) {
            $config.base_url = $BaseUrl
            Save-ProtectedJson -Path $ConfigPath -Value $config
        }
        if ($config.credential_id -and -not $config.server_id) {
            throw 'The saved agent state is invalid.'
        }
        if (Test-Path -LiteralPath $ConfigPath) {
            $savedValue = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
            if (-not $savedValue.protected_data) {
                Save-ProtectedJson -Path $ConfigPath -Value $config
            }
        }
    } catch {
        throw 'The saved agent state is invalid or cannot be decrypted by this computer.'
    }
}

$script:PendingPath = "$ConfigPath.pending"
if ($EnrollOnly) {
    if (Test-Path -LiteralPath $script:PendingPath) {
        Write-Output 'Agent is enrolled. Pending metrics remain safely queued.'
    } else {
        Write-Output 'Agent is enrolled and ready for service installation.'
    }
    exit 0
}

$agentMutex = Enter-AgentMutex -Name 'Global\DreamHostingMonitor.Agent'
if ($null -eq $agentMutex) {
    Write-Output 'A monitoring instance is already active; this duplicate instance will exit.'
    exit 0
}

try {
    Write-Output "Monitoring this Windows host every $script:IntervalSeconds seconds. Credentials and pending samples are DPAPI-protected."
    do {
        $cycleStarted = [DateTime]::UtcNow
        try {
            Send-AgentHeartbeat -Config $config
        } catch {
            Write-Warning 'Heartbeat failed; it will be retried on the next cycle.'
        }
        try {
            Send-PendingMetricBatches -Config $config
            Write-Output 'Monitoring cycle completed.'
        } catch {
            Write-Warning 'Metric collection or submission failed; the encrypted retry queue will be retained.'
        }

        if (-not $Once) {
            $elapsedSeconds = ([DateTime]::UtcNow - $cycleStarted).TotalSeconds
            $sleepSeconds = [Math]::Max(1, $script:IntervalSeconds - [Math]::Floor($elapsedSeconds))
            Start-Sleep -Seconds $sleepSeconds
        }
    } while (-not $Once)
} finally {
    $agentMutex.ReleaseMutex()
    $agentMutex.Dispose()
}
