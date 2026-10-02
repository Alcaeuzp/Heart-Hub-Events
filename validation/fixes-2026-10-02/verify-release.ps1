[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$hhermRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$hhermSlug = 'heart-hub-event-registration-manager'
$hhermVersion = '1.34.2'
$hhermZip = Join-Path $hhermRoot ($hhermSlug + '-' + $hhermVersion + '.zip')
$hhermExpected = @(
    Get-Item -LiteralPath (Join-Path $hhermRoot ($hhermSlug + '.php'))
    Get-Item -LiteralPath (Join-Path $hhermRoot 'uninstall.php')
    Get-Item -LiteralPath (Join-Path $hhermRoot 'readme.txt')
    Get-ChildItem -LiteralPath (Join-Path $hhermRoot 'includes'),(Join-Path $hhermRoot 'assets') -File -Recurse
)
$hhermNames = @($hhermExpected | ForEach-Object { $hhermSlug + '/' + $_.FullName.Substring($hhermRoot.Length + 1).Replace('\', '/') })
Add-Type -AssemblyName System.IO.Compression.FileSystem
$hhermArchive = [System.IO.Compression.ZipFile]::OpenRead($hhermZip)
try {
    $hhermEntries = @($hhermArchive.Entries)
    if ($hhermEntries.Count -ne $hhermExpected.Count) { throw 'Release file count does not match the runtime manifest.' }
    if (@($hhermEntries.FullName | Sort-Object -Unique).Count -ne $hhermEntries.Count) { throw 'Duplicate ZIP entry.' }
    foreach ($hhermEntry in $hhermEntries) {
        if ($hhermEntry.FullName -notin $hhermNames) { throw ('Unexpected packaged path: ' + $hhermEntry.FullName) }
        $hhermRelative = $hhermEntry.FullName.Substring($hhermSlug.Length + 1)
        $hhermSource = Join-Path $hhermRoot $hhermRelative.Replace('/', '\')
        $hhermStream = $hhermEntry.Open()
        $hhermHasher = [System.Security.Cryptography.SHA256]::Create()
        try {
            $hhermDigest = [BitConverter]::ToString($hhermHasher.ComputeHash($hhermStream)).Replace('-', '')
        } finally {
            $hhermHasher.Dispose()
            $hhermStream.Dispose()
        }
        if ($hhermDigest -ne (Get-FileHash -LiteralPath $hhermSource -Algorithm SHA256).Hash) { throw ('Packaged bytes differ: ' + $hhermRelative) }
    }
} finally { $hhermArchive.Dispose() }

$hhermMain = [System.IO.File]::ReadAllText((Join-Path $hhermRoot ($hhermSlug + '.php')))
$hhermReadme = [System.IO.File]::ReadAllText((Join-Path $hhermRoot 'readme.txt'))
if ($hhermMain -notmatch '(?m)^ \* Version: 1\.34\.2\s*$' -or $hhermMain -notmatch "define\( 'HHERM_VERSION', '1\.34\.2' \)" -or $hhermReadme -notmatch '(?m)^Stable tag: 1\.34\.2\s*$') { throw 'Release versions are inconsistent.' }
$hhermChanged = @()
$hhermBaseline = Join-Path $PSScriptRoot ('baseline/' + $hhermSlug)
foreach ($hhermFile in $hhermExpected) {
    $hhermRelative = $hhermFile.FullName.Substring($hhermRoot.Length + 1).Replace('\', '/')
    $hhermOld = Join-Path $hhermBaseline $hhermRelative.Replace('/', '\')
    if (!(Test-Path -LiteralPath $hhermOld) -or (Get-FileHash -LiteralPath $hhermOld -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $hhermFile.FullName -Algorithm SHA256).Hash) { $hhermChanged += $hhermRelative }
}
$hhermResult = [pscustomobject]@{
    Status = 'PASS'; Version = $hhermVersion; Files = $hhermExpected.Count
    SourceIdentical = $true; RuntimeOnly = $true
    Zip = $hhermZip; Bytes = (Get-Item -LiteralPath $hhermZip).Length
    SHA256 = (Get-FileHash -LiteralPath $hhermZip -Algorithm SHA256).Hash
    ChangedRuntimeFiles = $hhermChanged
}
$hhermResult | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $PSScriptRoot 'package-results.json') -Encoding utf8
$hhermResult | ConvertTo-Json -Depth 5
