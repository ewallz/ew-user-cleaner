<#
.SYNOPSIS
    Bumps the plugin version in every place it is declared, and stubs a
    changelog entry.

.DESCRIPTION
    The release workflow refuses to publish unless the git tag matches all
    three declared versions. Updating them by hand is easy to get wrong, so
    this script is the single place that does it.

    It updates:
      1. The Version header in ew-user-cleaner.php
      2. The EWUC_VERSION constant in ew-user-cleaner.php
      3. The Stable tag in readme.txt
      4. Inserts a "= <version> =" stub at the top of the readme.txt changelog

    It deliberately does NOT commit, tag or push. Review the diff and write
    the changelog entry first, then run the git commands it prints.

.PARAMETER Version
    The new version, for example 1.3.0. A leading "v" is accepted and stripped.

.PARAMETER DryRun
    Show what would change without writing anything.

.EXAMPLE
    .\bin\bump-version.ps1 1.3.0

.EXAMPLE
    .\bin\bump-version.ps1 1.3.0 -DryRun
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string] $Version,

    [switch] $DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Resolve paths relative to the repository root, so the script works from
# any working directory.
$repoRoot   = Split-Path -Parent $PSScriptRoot
$pluginFile = Join-Path $repoRoot 'ew-user-cleaner.php'
$readmeFile = Join-Path $repoRoot 'readme.txt'

function Fail {
    param([string] $Message)
    Write-Host "ERROR: $Message" -ForegroundColor Red
    exit 1
}

# --- Validate input -------------------------------------------------------

$newVersion = $Version.Trim().TrimStart('v', 'V')

if ($newVersion -notmatch '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$') {
    Fail "'$Version' is not a valid version. Expected <major>.<minor>.<patch>, for example 1.3.0."
}

foreach ($file in @($pluginFile, $readmeFile)) {
    if (-not (Test-Path -LiteralPath $file)) {
        Fail "Could not find $file. Run this from inside the plugin repository."
    }
}

# --- Read current state ---------------------------------------------------

$pluginText = Get-Content -LiteralPath $pluginFile -Raw
$readmeText = Get-Content -LiteralPath $readmeFile -Raw

# Patterns are anchored to the exact declaration format used in this plugin.
$headerPattern   = '(?m)^(\s*\*\s*Version:\s*)([0-9][^\r\n]*?)(\s*)$'
$constantPattern = "(?m)^(define\(\s*'EWUC_VERSION'\s*,\s*')([^']+)('\s*\)\s*;)"
$stablePattern   = '(?m)^(Stable tag:\s*)([0-9][^\r\n]*?)(\s*)$'

if ($pluginText -notmatch $headerPattern)   { Fail "Could not find the Version header in ew-user-cleaner.php." }
$currentHeader = $Matches[2]

if ($pluginText -notmatch $constantPattern) { Fail "Could not find the EWUC_VERSION constant in ew-user-cleaner.php." }
$currentConstant = $Matches[2]

if ($readmeText -notmatch $stablePattern)   { Fail "Could not find 'Stable tag:' in readme.txt." }
$currentStable = $Matches[2]

Write-Host ''
Write-Host 'Current versions' -ForegroundColor Cyan
Write-Host ('  plugin header Version:  {0}' -f $currentHeader)
Write-Host ('  EWUC_VERSION constant:  {0}' -f $currentConstant)
Write-Host ('  readme.txt Stable tag:  {0}' -f $currentStable)
Write-Host ''

if ($currentHeader -ne $currentConstant -or $currentHeader -ne $currentStable) {
    Write-Host 'WARNING: the current versions do not agree. This bump will bring them into sync.' -ForegroundColor Yellow
    Write-Host ''
}

if ($newVersion -eq $currentHeader -and $newVersion -eq $currentConstant -and $newVersion -eq $currentStable) {
    Fail "Everything already declares $newVersion. Nothing to do."
}

# Guard against going backwards, which would break WordPress update checks.
# Only compares plain numeric versions; prerelease suffixes are skipped.
if ($currentHeader -match '^[0-9]+\.[0-9]+\.[0-9]+$' -and $newVersion -match '^[0-9]+\.[0-9]+\.[0-9]+$') {
    if ([version] $newVersion -lt [version] $currentHeader) {
        Fail "$newVersion is lower than the current $currentHeader. Version numbers must increase."
    }
}

# --- Check the tag is not already used -----------------------------------

$existingTag = & git -C $repoRoot tag --list "v$newVersion" 2>$null

if ($existingTag) {
    Fail "Tag v$newVersion already exists. Pick a new version, or delete the tag first."
}

# --- Apply the changes ----------------------------------------------------

$pluginText = [regex]::Replace(
    $pluginText,
    $headerPattern,
    { param($m) $m.Groups[1].Value + $newVersion + $m.Groups[3].Value },
    1
)

$pluginText = [regex]::Replace(
    $pluginText,
    $constantPattern,
    { param($m) $m.Groups[1].Value + $newVersion + $m.Groups[3].Value },
    1
)

$readmeText = [regex]::Replace(
    $readmeText,
    $stablePattern,
    { param($m) $m.Groups[1].Value + $newVersion + $m.Groups[3].Value },
    1
)

# Insert a changelog stub directly beneath the "== Changelog ==" heading.
#
# This is done line by line rather than with a regex over the whole file.
# Anchored multiline patterns are awkward here because the file may use CRLF
# endings, where "$" matches after the "\r", so a trailing carriage return
# leaks into replacements and blank lines get miscounted.
$newline = if ($readmeText -match "\r\n") { "`r`n" } else { "`n" }

# -split on the literal newline preserves empty lines, unlike Get-Content.
$lines        = $readmeText -split "\r?\n"
$headingIndex = -1
$alreadyLogged = $false

for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i].TrimEnd()

    if ($headingIndex -lt 0 -and $line -match '^==[ \t]*Changelog[ \t]*==$') {
        $headingIndex = $i
        continue
    }

    if ($line -match ('^=[ \t]*' + [regex]::Escape($newVersion) + '[ \t]*=$')) {
        $alreadyLogged = $true
        break
    }
}

if ($headingIndex -lt 0) {
    Fail "Could not find the '== Changelog ==' heading in readme.txt."
}

if (-not $alreadyLogged) {
    # Skip any blank lines that already follow the heading, so the result is
    # always: heading, one blank, stub, one blank, previous entry.
    $insertAt = $headingIndex + 1

    while ($insertAt -lt $lines.Count -and $lines[$insertAt].Trim() -eq '') {
        $insertAt++
    }

    $stubLines = @(
        ''
        "= $newVersion ="
        '* TODO: describe the changes in this release.'
        ''
    )

    $rebuilt = New-Object System.Collections.Generic.List[string]
    $rebuilt.AddRange([string[]] $lines[0..$headingIndex])
    $rebuilt.AddRange([string[]] $stubLines)

    if ($insertAt -lt $lines.Count) {
        $rebuilt.AddRange([string[]] $lines[$insertAt..($lines.Count - 1)])
    }

    $readmeText = $rebuilt -join $newline
}

# --- Write or report ------------------------------------------------------

if ($DryRun) {
    Write-Host "Dry run: no files were changed." -ForegroundColor Yellow
    Write-Host ''
    Write-Host "Would set all three versions to $newVersion" -ForegroundColor Cyan
    if (-not $alreadyLogged) {
        Write-Host "Would insert this changelog stub:" -ForegroundColor Cyan
        Write-Host "  = $newVersion ="
        Write-Host "  * TODO: describe the changes in this release."
    } else {
        Write-Host "Changelog already has a '= $newVersion =' entry; it would be left alone." -ForegroundColor Cyan
    }
    exit 0
}

# -NoNewline because the captured text already ends with its own newline.
Set-Content -LiteralPath $pluginFile -Value $pluginText -NoNewline -Encoding utf8
Set-Content -LiteralPath $readmeFile -Value $readmeText -NoNewline -Encoding utf8

Write-Host "Bumped to $newVersion" -ForegroundColor Green
Write-Host ''

if (-not $alreadyLogged) {
    Write-Host 'A changelog stub was added to readme.txt. Edit it before committing.' -ForegroundColor Yellow
    Write-Host ''
}

Write-Host 'Next steps:' -ForegroundColor Cyan
Write-Host '  1. Edit the changelog entry in readme.txt'
Write-Host '  2. Review the diff:  git diff'
Write-Host '  3. Then run:'
Write-Host ''
Write-Host '       git add -A'
Write-Host ("       git commit -m ""Release {0}""" -f $newVersion)
Write-Host ("       git tag v{0}" -f $newVersion)
Write-Host '       git push --follow-tags'
Write-Host ''
