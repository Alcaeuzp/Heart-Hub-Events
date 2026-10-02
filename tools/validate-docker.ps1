[CmdletBinding()]
param(
    [string[]]$PhpVersions = @('7.4', '8.3', '8.4'),
    [switch]$KeepContainers
)
$ErrorActionPreference = 'Stop'
$hhermRoot = Split-Path -Parent $PSScriptRoot
$hhermResults = Join-Path $hhermRoot 'validation/docker-latest'
$hhermCompose = Join-Path $PSScriptRoot 'docker/compose.yml'
$hhermProject = 'hherm-secondary-validation'
New-Item -ItemType Directory -Path $hhermResults -Force | Out-Null
$hhermRuns = [System.Collections.Generic.List[object]]::new()
function Invoke-DockerCheck {
    param([string]$Name, [string[]]$Arguments)
    $hhermOutput = & docker @Arguments 2>&1
    $hhermCode = $LASTEXITCODE
    $hhermOutput | Set-Content -LiteralPath (Join-Path $hhermResults ($Name + '.log')) -Encoding utf8
    $hhermRuns.Add([pscustomobject]@{ Name=$Name; ExitCode=$hhermCode; Status=$(if ($hhermCode -eq 0) {'PASS'} else {'FAIL'}) })
    Write-Output "$Name exit $hhermCode"
    if ($hhermCode -ne 0) { Write-Output $hhermOutput }
    return $hhermCode
}
$hhermMounts = @('--rm','--network','none','--cap-drop','ALL','--security-opt','no-new-privileges','--mount',"type=bind,source=$hhermRoot,target=/plugin,readonly",'--mount',"type=bind,source=$hhermResults,target=/results",'--entrypoint','sh')
foreach ($hhermVersion in $PhpVersions) {
    if ($hhermVersion -notmatch '^\d+\.\d+$') { throw 'PHP versions must be major.minor values.' }
    Invoke-DockerCheck "php-$hhermVersion" (@('run') + $hhermMounts + @("php:$hhermVersion-cli",'/plugin/tools/docker/fixture-entrypoint.sh','php','tools/docker/run-php-suite.php',"/results/php-$hhermVersion.json")) | Out-Host
}
Invoke-DockerCheck 'javascript' (@('run') + $hhermMounts + @('node:24-bookworm-slim','/plugin/tools/docker/fixture-entrypoint.sh','node','tools/docker/run-js-suite.js','/results/javascript.json')) | Out-Host
$hhermComposeArgs = @('compose','-p',$hhermProject,'-f',$hhermCompose)
try {
    & docker @hhermComposeArgs up -d --build
    if ($LASTEXITCODE -ne 0) { throw 'Docker integration setup failed.' }
    $hhermReady = $false
    for ($hhermAttempt = 0; $hhermAttempt -lt 60; $hhermAttempt++) {
        & docker @hhermComposeArgs exec -T wordpress test -f /tmp/hherm-wordpress-ready 2>$null
        if ($LASTEXITCODE -eq 0) { $hhermReady = $true; break }
        Start-Sleep -Seconds 2
    }
    if (!$hhermReady) { throw 'WordPress did not become ready.' }
    Invoke-DockerCheck 'wordpress-mariadb' ($hhermComposeArgs + @('exec','-T','wordpress','wp','eval-file','/plugin/tests/integration/wordpress-mariadb.php')) | Out-Host
    Invoke-DockerCheck 'capacity-concurrency' ($hhermComposeArgs + @('exec','-T','wordpress','wp','eval-file','/plugin/tests/integration/capacity-concurrency.php')) | Out-Host
} finally {
    & docker @hhermComposeArgs logs --no-color 2>&1 | Set-Content -LiteralPath (Join-Path $hhermResults 'integration-container.log') -Encoding utf8
    if (!$KeepContainers) { & docker @hhermComposeArgs down --volumes }
}
$hhermRuns | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $hhermResults 'matrix.json') -Encoding utf8
if (@($hhermRuns | Where-Object Status -NE 'PASS').Count) { exit 1 }
Write-Output 'Docker runtime matrix, JavaScript and WordPress/MariaDB integration passed.'
