[CmdletBinding()]
param(
    [string]$OutputDirectory = 'validation/latest',
    # Backward-compatible flag; audit regressions now run by default.
    [switch]$IncludeAuditProbes
)

$ErrorActionPreference = 'Stop'
$hhermRoot = Split-Path -Parent $PSScriptRoot
$hhermOutputPath = if ([System.IO.Path]::IsPathRooted($OutputDirectory)) { $OutputDirectory } else { Join-Path $hhermRoot $OutputDirectory }
New-Item -ItemType Directory -Path $hhermOutputPath -Force | Out-Null
$hhermPhp = (Get-Command php -ErrorAction Stop).Source
$hhermNode = (Get-Command node -ErrorAction Stop).Source
$hhermExtensionDirectory = Join-Path (Split-Path -Parent $hhermPhp) 'ext'
$hhermPhpArgs = @('-d', 'error_reporting=E_ALL', '-d', 'display_errors=1')
if (Test-Path -LiteralPath (Join-Path $hhermExtensionDirectory 'php_pdo_sqlite.dll')) {
    $hhermPhpArgs += @('-d', "extension_dir=$hhermExtensionDirectory", '-d', 'extension=pdo_sqlite')
}
$hhermResults = [System.Collections.Generic.List[object]]::new()

function Invoke-HhermCheck {
    param([string]$Kind, [string]$File, [string]$Executable, [string[]]$Arguments)
    $hhermTimer = [System.Diagnostics.Stopwatch]::StartNew()
    $hhermOutput = & $Executable @Arguments 2>&1
    $hhermExit = $LASTEXITCODE
    $hhermTimer.Stop()
    $hhermText = $hhermOutput -join "`n"
    $hhermSkipped = $hhermText -match '(?m)^SKIP\b'
    $hhermDiagnostics = $hhermText -match '(?im)^(PHP )?(Warning|Notice|Deprecated|Fatal error|Parse error):'
    $hhermAssertionCount = @($hhermOutput | Where-Object { $_ -match '^PASS[: ]' }).Count
    $hhermEmptyFixture = $Kind.EndsWith('fixture') -and $hhermAssertionCount -eq 0
    $hhermStatus = if ($hhermSkipped) { 'SKIP' } elseif ($hhermExit -ne 0 -or $hhermDiagnostics -or $hhermEmptyFixture) { 'FAIL' } else { 'PASS' }
    $hhermResults.Add([pscustomobject]@{
        Kind = $Kind; File = $File; Status = $hhermStatus; ExitCode = $hhermExit
        Assertions = $hhermAssertionCount
        DurationMs = $hhermTimer.ElapsedMilliseconds; Output = $hhermText
    })
    Write-Output ("{0} {1}: {2}" -f $hhermStatus, $Kind, $File)
    if ($hhermStatus -ne 'PASS') { Write-Output $hhermText }
}

$hhermPhpFiles = @(Get-Item -LiteralPath (Join-Path $hhermRoot 'heart-hub-event-registration-manager.php'),(Join-Path $hhermRoot 'uninstall.php')) + @(Get-ChildItem -LiteralPath (Join-Path $hhermRoot 'includes'),(Join-Path $hhermRoot 'tests') -Filter '*.php' -File -Recurse)
foreach ($hhermFile in $hhermPhpFiles | Sort-Object FullName) {
    Invoke-HhermCheck 'PHP syntax' $hhermFile.Name $hhermPhp @('-l', $hhermFile.FullName)
}
foreach ($hhermFile in Get-ChildItem -LiteralPath (Join-Path $hhermRoot 'assets') -Filter '*.js' -File -Recurse | Sort-Object FullName) {
    Invoke-HhermCheck 'JavaScript syntax' $hhermFile.Name $hhermNode @('--check', $hhermFile.FullName)
}
foreach ($hhermFile in Get-ChildItem -LiteralPath (Join-Path $hhermRoot 'tests') -Filter '*.php' -File | Sort-Object Name) {
    Invoke-HhermCheck 'PHP fixture' $hhermFile.Name $hhermPhp ($hhermPhpArgs + @($hhermFile.FullName))
}
foreach ($hhermFile in Get-ChildItem -LiteralPath (Join-Path $hhermRoot 'tests') -Filter '*.js' -File | Sort-Object Name) {
    Invoke-HhermCheck 'JavaScript fixture' $hhermFile.Name $hhermNode @($hhermFile.FullName)
}
$hhermResults | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $hhermOutputPath 'results.json') -Encoding utf8
$hhermFailures = @($hhermResults | Where-Object Status -NE 'PASS').Count
Write-Output ("Completed {0} checks; {1} failed or skipped." -f $hhermResults.Count, $hhermFailures)
if ($hhermFailures) { exit 1 }
