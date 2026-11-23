# NGW Phase 3 final regression — disposable coin_platform_test only. No commit/push.
$ErrorActionPreference = 'Continue'
$root = Split-Path $PSScriptRoot -Parent
if (-not (Test-Path (Join-Path $root 'vendor\bin\phpunit'))) { $root = $PSScriptRoot }
Set-Location $root

$php = 'C:\xampp\php\php.exe'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$log = Join-Path $root 'writable\ngw-phase3-regression.log'
$summary = Join-Path $root 'writable\ngw-phase3-regression-summary.txt'

function Log([string]$msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Add-Content -Path $log -Value $line
    Write-Host $line
}

Remove-Item $log -ErrorAction SilentlyContinue
Remove-Item $summary -ErrorAction SilentlyContinue
Log "START root=$root"

# Guard: refuse if any phpunit already running
$busy = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match 'phpunit' }
if ($busy) {
    Log "ABORT overlapping phpunit PID=$($busy.ProcessId -join ',')"
    'ABORT overlapping phpunit' | Set-Content $summary
    exit 2
}

& $mysql -u root -e "CREATE DATABASE IF NOT EXISTS coin_platform_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" 2>&1 | ForEach-Object { Log $_ }

# Confirm tests DB from config
$dbCheck = Select-String -Path (Join-Path $root 'app\Config\Database.php') -Pattern "database'\s*=>\s*'([^']+)'" -Context 5,0 |
    Select-Object -First 20
Log "Database.php snippets captured for audit"

$suites = @(
    @{ Name = 'Phase3'; Filter = 'Phase3PrincipalDailyReleaseTest' },
    @{ Name = 'Phase2'; Filter = 'Phase2ReferralBusinessVolumeTest' },
    @{ Name = 'Phase11D'; Filter = 'Phase11DCommissionWithdrawalTest' },
    @{ Name = 'Phase1'; Filter = 'Phase1AdminWithdrawalSettingsAndKycTest' },
    @{ Name = 'CoinLock'; Filter = 'CoinLockingTest' },
    @{ Name = 'WalletLedger'; Filter = 'MemberWalletTest|Phase9AProfileWalletDashboardTest' },
    @{ Name = 'AuthDash'; Filter = 'RegistrationAuthTest|HttpAuthPagesTest|DashboardAnalyticsTest|Phase10AMemberProductionReadinessTest|Phase10BAdminProductionReadinessTest' }
)

$results = @()
foreach ($s in $suites) {
    Log "===== RUN $($s.Name) filter=$($s.Filter) ====="
    $outFile = Join-Path $root "writable\ngw-reg-$($s.Name).log"
    & $php 'vendor\bin\phpunit' '--filter' $s.Filter '--testdox' '--no-coverage' 2>&1 | Tee-Object -FilePath $outFile | Out-Null
    $code = $LASTEXITCODE
    $tail = Get-Content $outFile -Tail 25 -ErrorAction SilentlyContinue
    $tail | ForEach-Object { Log $_ }
    $results += [pscustomobject]@{ Suite = $s.Name; ExitCode = $code; Log = $outFile }
    Log "===== END $($s.Name) exit=$code ====="
}

# Full suite last (only if all targeted passed)
$allOk = ($results | Where-Object { $_.ExitCode -ne 0 }).Count -eq 0
if ($allOk) {
    Log '===== RUN FullSuite ====='
    $outFile = Join-Path $root 'writable\ngw-reg-FullSuite.log'
    & $php 'vendor\bin\phpunit' '--testdox' '--no-coverage' 2>&1 | Tee-Object -FilePath $outFile | Out-Null
    $code = $LASTEXITCODE
    $tail = Get-Content $outFile -Tail 40 -ErrorAction SilentlyContinue
    $tail | ForEach-Object { Log $_ }
    $results += [pscustomobject]@{ Suite = 'FullSuite'; ExitCode = $code; Log = $outFile }
    Log "===== END FullSuite exit=$code ====="
} else {
    Log 'SKIP FullSuite because a targeted suite failed'
}

$results | Format-Table -AutoSize | Out-String | Set-Content $summary
$results | ForEach-Object { "SUITE=$($_.Suite) EXIT=$($_.ExitCode)" } | Add-Content $summary
Log 'DONE'
$results | Format-Table -AutoSize
exit 0
