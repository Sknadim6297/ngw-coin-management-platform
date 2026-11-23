# Continue NGW regression after Phase3 OK / Phase2 fix. Disposable DB only.
$ErrorActionPreference = 'Continue'
Set-Location 'C:\xampp\htdocs\coin-platform'
$php = 'C:\xampp\php\php.exe'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$log = 'writable\ngw-phase3-regression-continue.log'
Remove-Item $log -ErrorAction SilentlyContinue

function Log($m) {
  $line = "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m
  Add-Content $log $line
  Write-Output $line
}

& $mysql -u root -e "CREATE DATABASE IF NOT EXISTS coin_platform_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" | Out-Null

$suites = @(
  @{ N='Phase2'; F='Phase2ReferralBusinessVolumeTest' },
  @{ N='Phase11D'; F='Phase11DCommissionWithdrawalTest' },
  @{ N='Phase1'; F='Phase1AdminWithdrawalSettingsAndKycTest' },
  @{ N='CoinLock'; F='CoinLockingTest' },
  @{ N='WalletLedger'; F='MemberWalletTest|Phase9AProfileWalletDashboardTest' },
  @{ N='AuthDash'; F='RegistrationAuthTest|HttpAuthPagesTest|DashboardAnalyticsTest|Phase10AMemberProductionReadinessTest|Phase10BAdminProductionReadinessTest' }
)

$summary = @()
foreach ($s in $suites) {
  Log "===== $($s.N) ====="
  $out = "writable\ngw-reg-$($s.N).log"
  $proc = Start-Process -FilePath $php -ArgumentList @('vendor\bin\phpunit','--filter',$s.F,'--testdox','--no-coverage') -NoNewWindow -Wait -PassThru -RedirectStandardOutput $out -RedirectStandardError "writable\ngw-reg-$($s.N).err.log"
  $code = $proc.ExitCode
  Get-Content $out -Tail 30 -ErrorAction SilentlyContinue | ForEach-Object { Log $_ }
  Log "===== END $($s.N) exit=$code ====="
  $summary += "SUITE=$($s.N) EXIT=$code"
}

# Full suite only if all targeted passed
$failed = ($summary | Where-Object { $_ -notmatch 'EXIT=0$' }).Count
if ($failed -eq 0) {
  Log '===== FullSuite ====='
  $out = 'writable\ngw-reg-FullSuite.log'
  $proc = Start-Process -FilePath $php -ArgumentList @('vendor\bin\phpunit','--testdox','--no-coverage') -NoNewWindow -Wait -PassThru -RedirectStandardOutput $out -RedirectStandardError 'writable\ngw-reg-FullSuite.err.log'
  Get-Content $out -Tail 40 -ErrorAction SilentlyContinue | ForEach-Object { Log $_ }
  Log "===== END FullSuite exit=$($proc.ExitCode) ====="
  $summary += "SUITE=FullSuite EXIT=$($proc.ExitCode)"
} else {
  Log "SKIP FullSuite failed_count=$failed"
}

$summary | Set-Content 'writable\ngw-phase3-regression-summary.txt'
$summary | ForEach-Object { Log $_ }
Log 'DONE'
