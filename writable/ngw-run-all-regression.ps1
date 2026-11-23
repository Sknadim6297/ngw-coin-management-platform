# NGW final regression — sequential, disposable coin_platform_test only.
$ErrorActionPreference = 'Continue'
Set-Location 'C:\xampp\htdocs\coin-platform'
$php = 'C:\xampp\php\php.exe'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$master = 'writable\ngw-final-regression.log'
$summary = 'writable\ngw-final-summary.txt'

function Log([string]$m) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m
    Add-Content -Path $master -Value $line -Encoding utf8
    Write-Output $line
}

# Wait for any existing phpunit (max ~15 min)
$deadline = (Get-Date).AddMinutes(15)
while ((Get-Date) -lt $deadline) {
    $busy = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match 'phpunit' }
    if (-not $busy) { break }
    Log ("WAITING_EXISTING_PHPUNIT pid=" + ($busy.ProcessId -join ','))
    Start-Sleep -Seconds 15
}

Remove-Item $master -ErrorAction SilentlyContinue
Remove-Item $summary -ErrorAction SilentlyContinue
Log 'START'

& $mysql -u root -e "CREATE DATABASE IF NOT EXISTS coin_platform_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" 2>&1 | ForEach-Object { Log ("mysql: $_") }

$suites = @(
    @{ N = 'Phase3'; F = 'Phase3PrincipalDailyReleaseTest' },
    @{ N = 'Phase2'; F = 'Phase2ReferralBusinessVolumeTest' },
    @{ N = 'Phase11D'; F = 'Phase11DCommissionWithdrawalTest' },
    @{ N = 'Phase1'; F = 'Phase1AdminWithdrawalSettingsAndKycTest' },
    @{ N = 'CoinLock'; F = 'CoinLockingTest' },
    @{ N = 'WalletLedger'; F = 'MemberWalletTest|Phase9AProfileWalletDashboardTest' },
    @{ N = 'AuthDash'; F = 'RegistrationAuthTest|HttpAuthPagesTest|DashboardAnalyticsTest|Phase10AMemberProductionReadinessTest|Phase10BAdminProductionReadinessTest' }
)

$lines = @()
foreach ($s in $suites) {
    Log ("===== RUN $($s.N) =====")
    $out = "writable\ngw-final-$($s.N).log"
    $err = "writable\ngw-final-$($s.N).err.log"
    Remove-Item $out, $err -ErrorAction SilentlyContinue
    $p = Start-Process -FilePath $php -ArgumentList @('vendor\bin\phpunit', '--filter', $s.F, '--testdox', '--no-coverage') `
        -WorkingDirectory (Get-Location).Path -NoNewWindow -Wait -PassThru `
        -RedirectStandardOutput $out -RedirectStandardError $err
    $code = [int]$p.ExitCode
    if (Test-Path $out) {
        Get-Content $out -Tail 35 -ErrorAction SilentlyContinue | ForEach-Object { Log $_ }
    }
    if ((Test-Path $err) -and ((Get-Item $err).Length -gt 0)) {
        Get-Content $err -Tail 20 -ErrorAction SilentlyContinue | ForEach-Object { Log ("ERR: $_") }
    }
    Log ("===== END $($s.N) EXIT=$code =====")
    $lines += "SUITE=$($s.N) EXIT=$code"
}

$failed = @($lines | Where-Object { $_ -notmatch 'EXIT=0$' }).Count
if ($failed -eq 0) {
    Log '===== RUN FullSuite ====='
    $out = 'writable\ngw-final-FullSuite.log'
    $err = 'writable\ngw-final-FullSuite.err.log'
    Remove-Item $out, $err -ErrorAction SilentlyContinue
    $p = Start-Process -FilePath $php -ArgumentList @('vendor\bin\phpunit', '--testdox', '--no-coverage') `
        -WorkingDirectory (Get-Location).Path -NoNewWindow -Wait -PassThru `
        -RedirectStandardOutput $out -RedirectStandardError $err
    if (Test-Path $out) { Get-Content $out -Tail 50 -ErrorAction SilentlyContinue | ForEach-Object { Log $_ } }
    Log ("===== END FullSuite EXIT=$([int]$p.ExitCode) =====")
    $lines += "SUITE=FullSuite EXIT=$([int]$p.ExitCode)"
} else {
    Log ("SKIP FullSuite failed_suites=$failed")
}

$lines | Set-Content $summary -Encoding utf8
$lines | ForEach-Object { Log $_ }
Log 'DONE'
exit 0
