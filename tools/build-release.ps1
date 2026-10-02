[CmdletBinding()]
param(
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$hhermRoot = Split-Path -Parent $PSScriptRoot
$hhermSlug = 'heart-hub-event-registration-manager'
$hhermMain = Join-Path $hhermRoot ($hhermSlug + '.php')
$hhermSource = [System.IO.File]::ReadAllText($hhermMain)
$hhermMatch = [regex]::Match($hhermSource, '(?m)^ \* Version: (\d+\.\d+\.\d+)\s*$')
if (!$hhermMatch.Success) { throw 'Plugin version header not found.' }
$hhermVersion = $hhermMatch.Groups[1].Value
$hhermZipPath = Join-Path $hhermRoot ($hhermSlug + '-' + $hhermVersion + '.zip')
if ((Test-Path -LiteralPath $hhermZipPath) -and !$Force) {
    throw 'This release ZIP already exists. Use -Force to rebuild that version.'
}

# Ship only the plugin runtime and its bundled asset licences. Development notes,
# tests, tooling, and prior releases remain available in the source workspace.
$hhermFiles = @(
    Get-Item -LiteralPath $hhermMain
    Get-Item -LiteralPath (Join-Path $hhermRoot 'uninstall.php')
    Get-Item -LiteralPath (Join-Path $hhermRoot 'readme.txt')
    Get-ChildItem -LiteralPath (Join-Path $hhermRoot 'includes') -File -Recurse
    Get-ChildItem -LiteralPath (Join-Path $hhermRoot 'assets') -File -Recurse
)

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$hhermMode = if ($Force) { [System.IO.FileMode]::Create } else { [System.IO.FileMode]::CreateNew }
$hhermStream = [System.IO.File]::Open($hhermZipPath, $hhermMode)
try {
    $hhermArchive = [System.IO.Compression.ZipArchive]::new($hhermStream, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($hhermFile in ($hhermFiles | Sort-Object FullName)) {
            $hhermRelative = $hhermFile.FullName.Substring($hhermRoot.Length + 1).Replace('\', '/')
            $hhermEntry = $hhermSlug + '/' + $hhermRelative
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($hhermArchive, $hhermFile.FullName, $hhermEntry) | Out-Null
        }
    } finally {
        $hhermArchive.Dispose()
    }
} finally {
    $hhermStream.Dispose()
}

Write-Output ('Built {0} ({1} files)' -f $hhermZipPath, $hhermFiles.Count)
