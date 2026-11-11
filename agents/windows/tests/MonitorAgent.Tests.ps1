$monitorScript = Join-Path $PSScriptRoot '..\monitor.ps1'
$unusedConfigPath = Join-Path $env:TEMP ("DreamHostingMonitor.Tests.{0}.json" -f [Guid]::NewGuid().ToString('N'))
. $monitorScript -ConfigPath $unusedConfigPath -FunctionsOnly

Describe 'Load-PendingBatches' {
    It 'returns an empty list that can accept the first metric batch' {
        $missingQueuePath = Join-Path $env:TEMP ("DreamHostingMonitor.EmptyQueue.{0}.pending" -f [Guid]::NewGuid().ToString('N'))
        $pendingBatches = Load-PendingBatches -Path $missingQueuePath

        ($pendingBatches -is [System.Collections.Generic.List[object]]) | Should Be $true
        $pendingBatches.Count | Should Be 0

        $pendingBatches.Add([PSCustomObject]@{ batch_uuid = [Guid]::NewGuid().ToString() })
        $pendingBatches.Count | Should Be 1
        Test-Path -LiteralPath $missingQueuePath | Should Be $false
    }
}

Describe 'Get-MemoryUsedBytes' {
    It 'handles memory sizes larger than Int32 without overflow or conversion errors' {
        $usedBytes = Get-MemoryUsedBytes -TotalBytes ([Int64] 16000000000) -FreeBytes ([Int64] 4000000000)

        $usedBytes | Should Be ([Int64] 12000000000)
    }

    It 'clamps invalid negative usage to zero' {
        $usedBytes = Get-MemoryUsedBytes -TotalBytes ([Int64] 4000000000) -FreeBytes ([Int64] 5000000000)

        $usedBytes | Should Be ([Int64] 0)
    }
}

Describe 'Enter-AgentMutex' {
    It 'prevents a second Windows agent process from acquiring the same global mutex' {
        $mutexName = 'Global\DreamHostingMonitor.Tests.{0}' -f [Guid]::NewGuid().ToString('N')
        $agentMutex = Enter-AgentMutex -Name $mutexName

        try {
            ($null -ne $agentMutex) | Should Be $true

            $probeCommand = '$mutex = [System.Threading.Mutex]::OpenExisting("' + $mutexName + '"); try { if ($mutex.WaitOne(0)) { $mutex.ReleaseMutex(); exit 1 }; exit 0 } finally { $mutex.Dispose() }'
            $encodedCommand = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($probeCommand))
            $powerShellPath = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
            $probeProcess = Start-Process -FilePath $powerShellPath -ArgumentList @(
                '-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encodedCommand
            ) -NoNewWindow -Wait -PassThru

            $probeProcess.ExitCode | Should Be 0
        } finally {
            if ($null -ne $agentMutex) {
                $agentMutex.ReleaseMutex()
                $agentMutex.Dispose()
            }
        }
    }
}

Describe 'Save-ProtectedJson' {
    It 'writes DPAPI-protected state without resetting an existing directory ACL' {
        $testPath = Join-Path $env:TEMP ("DreamHostingMonitor.StateTest.{0}.json" -f [Guid]::NewGuid().ToString('N'))

        try {
            Save-ProtectedJson -Path $testPath -Value ([PSCustomObject]@{
                base_url = 'http://127.0.0.1:8000'
                credential = 'a' * 64
            })

            $wrapper = Get-Content -LiteralPath $testPath -Raw | ConvertFrom-Json
            $savedValue = Unprotect-AgentJson -ProtectedValue $wrapper.protected_data | ConvertFrom-Json

            $wrapper.base_url | Should Be 'http://127.0.0.1:8000'
            $savedValue.credential.Length | Should Be 64
            ([string] $wrapper.protected_data -ne [string] $savedValue.credential) | Should Be $true
        } finally {
            if (Test-Path -LiteralPath $testPath) {
                Remove-Item -LiteralPath $testPath -Force
            }
        }
    }
}

Describe 'Save-PendingBatches' {
    It 'replaces a non-empty queue with an empty queue after a batch is accepted' {
        $testPath = Join-Path $env:TEMP ("DreamHostingMonitor.QueueStateTest.{0}.json" -f [Guid]::NewGuid().ToString('N'))
        $script:PendingPath = $testPath
        $pendingBatches = [System.Collections.Generic.List[object]]::new()
        $pendingBatches.Add([PSCustomObject]@{
            batch_uuid = [Guid]::NewGuid().ToString()
            agent_version = '0.2.0'
            samples = @([PSCustomObject]@{ cpu_percent = 12.5 })
        })

        try {
            Save-PendingBatches -Batches $pendingBatches
            $pendingBatches.Clear()
            Save-PendingBatches -Batches $pendingBatches

            $restoredBatches = Read-AgentJson -Path $testPath
            @($restoredBatches).Count | Should Be 0
        } finally {
            if (Test-Path -LiteralPath $testPath) {
                Remove-Item -LiteralPath $testPath -Force
            }
            Get-ChildItem -LiteralPath (Split-Path -Parent $testPath) -Filter "$(Split-Path -Leaf $testPath).*" -File -ErrorAction SilentlyContinue |
                Remove-Item -Force -ErrorAction SilentlyContinue
        }
    }
}
