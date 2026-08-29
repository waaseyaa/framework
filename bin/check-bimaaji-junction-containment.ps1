#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Windows junction containment proof for `bimaaji:install` (#2656).

.DESCRIPTION
    `BimaajiInstallCommand::resolvePathInSandbox()` decides, for every path the
    command reads, writes, deletes or rewrites, whether that path is inside the
    consumer project. Two of its three guards lean on one platform fact:

        PHP's realpath() resolves a Windows directory junction to its target.

    That is the whole reason the guard resolves paths instead of trusting
    is_link(), which does NOT report a junction. The claim was written in the
    docblock and asserted in the spec — and never executed. Every other
    containment proof in this change is a POSIX symlink on Ubuntu
    (`InstallCommandSandboxContainmentTest`), and `ci/skeleton-create-project-windows`
    deliberately runs neither PHPUnit nor Bimaaji.

    This gate executes it. It is PowerShell and PHP, drives the real
    `bimaaji:install` entry point, and makes no serving claim, so it sits
    inside that lane's stated contract rather than widening it.

    Junctions, not symbolic links, deliberately: a Windows symbolic link
    normally needs SeCreateSymbolicLinkPrivilege or Developer Mode, while
    `New-Item -ItemType Junction` does not — and a junction is the exact
    reparse-point shape the guard has to catch. Junctions are directory-only
    on Windows, so this proof redirects a target's *directory*
    (`.waaseyaa`, `.claude/skills`), which is the reachable Windows analogue
    of the POSIX cases and exercises the same realpath() resolution.

    TWO POSITIVE CONTROLS run before each guarded case, in the spirit of
    `bin/check-skeleton-docker-secret-exclusion`. Without them a junction that
    silently failed to redirect — an unsupported filesystem, a
    differently-shaped runner image — would make the whole gate pass while
    proving nothing:

      1. PHP realpath() on the junction must resolve OUTSIDE the consumer
         root. This is the platform premise the guard depends on, executed.
      2. An unguarded write through the junction must actually land outside
         the consumer root. This is the escape the guard exists to prevent,
         demonstrated live.

    Only then does the guarded run happen, and it must satisfy ALL THREE of:

      a. non-zero exit;
      b. no manifest (or skill directory) at the external location;
      c. the external sentinel's bytes unchanged.

    (c) is the one that matters. Asserting only the non-zero exit would pass a
    command that refused loudly and wrote the file anyway — precisely the
    failure mode the Linux sentinels were built to catch.

.PARAMETER ConsumerRoot
    A skeleton consumer that has completed `install:init`, so the kernel can
    boot and `bimaaji:install` can run. `ci/skeleton-create-project-windows`
    hands over the project it just built. This script owns that directory's
    cleanup.

.NOTES
    Exit 0 — containment held.
    Exit 1 — a containment escape, or a positive control that did not observe
             the behaviour it exists to demonstrate.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $ConsumerRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandArgumentPassing = 'Standard'

function Write-Step {
    param([string] $Message)
    Write-Host "check-bimaaji-junction-containment: $Message"
}

function Fail {
    param([string] $Message)
    [Console]::Error.WriteLine("check-bimaaji-junction-containment: $Message")
    exit 1
}

# Resolve the three paths through PHP, so every comparison is made against the
# same canonical form the production guard sees.
function Resolve-WithPhp {
    param([string] $Path)
    $env:BIMAAJI_PROBE_PATH = $Path
    $resolved = (& php -r 'echo realpath(getenv("BIMAAJI_PROBE_PATH")) ?: "";') | Out-String
    return $resolved.Trim()
}

function Test-IsInside {
    param([string] $Candidate, [string] $Root)
    if ([string]::IsNullOrEmpty($Candidate) -or [string]::IsNullOrEmpty($Root)) { return $false }
    $normalisedRoot = $Root.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    $normalisedCandidate = $Candidate.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    return $normalisedCandidate.StartsWith($normalisedRoot, [StringComparison]::OrdinalIgnoreCase)
}

function New-JunctionOrFail {
    param([string] $LinkPath, [string] $TargetPath)

    # Never skip when the link cannot be made. A containment proof that
    # quietly does nothing is worse than no proof at all.
    try {
        New-Item -ItemType Junction -Path $LinkPath -Target $TargetPath -ErrorAction Stop | Out-Null
    }
    catch {
        Fail "could not create the junction $LinkPath -> $TargetPath. This proof requires one; it fails rather than passing vacuously. $($_.Exception.Message)"
    }

    $item = Get-Item -LiteralPath $LinkPath -Force
    if (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Fail "$LinkPath was created but is not a reparse point, so it cannot prove anything about junction handling."
    }
    Write-Step "created junction $LinkPath -> $TargetPath"
}

function Remove-JunctionOnly {
    param([string] $LinkPath)
    # Directory::Delete on a reparse point removes the link, never the target's
    # contents. Remove-Item -Recurse has historically not been safe here.
    #
    # The reparse-point test is load-bearing, not defensive: this runs from the
    # finally block, by which point the path may have been restored to the
    # consumer's REAL .waaseyaa directory. Deleting that would destroy the
    # site:init ownership metadata, and on a non-empty directory
    # Directory::Delete throws, which would mask the gate's real result.
    if (-not (Test-Path -LiteralPath $LinkPath)) { return }
    $item = Get-Item -LiteralPath $LinkPath -Force -ErrorAction SilentlyContinue
    if ($null -eq $item) { return }
    if (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { return }
    [System.IO.Directory]::Delete($LinkPath, $false)
}

function New-Sentinel {
    param([string] $Directory)
    $sentinel = Join-Path $Directory 'sentinel.txt'
    Set-Content -LiteralPath $sentinel -Value "Bytes outside the project root. bimaaji:install must never touch these.`n" -NoNewline -Encoding utf8
    return $sentinel
}

function Assert-SentinelUntouched {
    param([string] $Sentinel, [string] $ExpectedHash, [string] $Case)
    if (-not (Test-Path -LiteralPath $Sentinel)) {
        Fail "$Case — the sentinel outside the project root was DELETED."
    }
    $actual = (Get-FileHash -LiteralPath $Sentinel -Algorithm SHA256).Hash
    if ($actual -ne $ExpectedHash) {
        Fail "$Case — the sentinel outside the project root was MODIFIED (expected SHA256 $ExpectedHash, got $actual)."
    }
}

function Assert-PositiveControls {
    param([string] $JunctionPath, [string] $Outside, [string] $ResolvedRoot, [string] $ResolvedOutside, [string] $Case)

    # Control 1 — the platform premise the production guard depends on.
    $resolvedJunction = Resolve-WithPhp -Path $JunctionPath
    if ([string]::IsNullOrEmpty($resolvedJunction)) {
        Fail "$Case — PHP realpath() could not resolve the junction $JunctionPath."
    }
    if (Test-IsInside -Candidate $resolvedJunction -Root $ResolvedRoot) {
        Fail "$Case — PHP realpath() resolved the junction to '$resolvedJunction', which is INSIDE the consumer root. The junction is not redirecting, so this proof would be vacuous."
    }
    if (-not (Test-IsInside -Candidate $resolvedJunction -Root $ResolvedOutside)) {
        Fail "$Case — PHP realpath() resolved the junction to '$resolvedJunction', which is neither inside the consumer root nor the external directory '$ResolvedOutside'."
    }
    Write-Step "$Case — control 1 OK: PHP realpath() follows the junction to $resolvedJunction"

    # Control 2 — the escape itself, demonstrated live.
    $probeName = 'containment-probe.txt'
    Set-Content -LiteralPath (Join-Path $JunctionPath $probeName) -Value 'probe' -NoNewline
    $landedOutside = Join-Path $Outside $probeName
    if (-not (Test-Path -LiteralPath $landedOutside)) {
        Fail "$Case — an unguarded write through the junction did NOT land outside the consumer root. The junction is not redirecting, so this proof would be vacuous."
    }
    Remove-Item -LiteralPath $landedOutside -Force
    Write-Step "$Case — control 2 OK: an unguarded write through the junction escapes to $Outside"
}

function Invoke-BimaajiInstall {
    param([string] $Root, [string] $ClientId)
    Push-Location $Root
    try {
        $output = (& php vendor/bin/waaseyaa bimaaji:install --client=$ClientId --force 2>&1 | Out-String)
        return [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = $output }
    }
    finally {
        Pop-Location
    }
}

# --------------------------------------------------------------------------

if (-not (Test-Path -LiteralPath $ConsumerRoot)) {
    Fail "consumer root $ConsumerRoot does not exist."
}
$ConsumerRoot = (Resolve-Path -LiteralPath $ConsumerRoot).Path
if (-not (Test-Path -LiteralPath (Join-Path $ConsumerRoot 'vendor/bin/waaseyaa'))) {
    Fail "consumer root $ConsumerRoot has no vendor/bin/waaseyaa; bimaaji:install cannot run there."
}

$resolvedRoot = Resolve-WithPhp -Path $ConsumerRoot
if ([string]::IsNullOrEmpty($resolvedRoot)) {
    Fail "PHP realpath() could not resolve the consumer root $ConsumerRoot."
}

$outsideManifest = Join-Path ([IO.Path]::GetTempPath()) ('bimaaji-outside-manifest-' + [guid]::NewGuid().ToString('N'))
$outsideSkills = Join-Path ([IO.Path]::GetTempPath()) ('bimaaji-outside-skills-' + [guid]::NewGuid().ToString('N'))
$waaseyaaLink = Join-Path $ConsumerRoot '.waaseyaa'
$waaseyaaStash = Join-Path $ConsumerRoot '.waaseyaa-real'
$claudeDir = Join-Path $ConsumerRoot '.claude'
$skillsLink = Join-Path $claudeDir 'skills'

try {
    New-Item -ItemType Directory -Path $outsideManifest -Force | Out-Null
    New-Item -ItemType Directory -Path $outsideSkills -Force | Out-Null

    foreach ($external in @($outsideManifest, $outsideSkills)) {
        if (Test-IsInside -Candidate $external -Root $ConsumerRoot) {
            Fail "the external directory $external is inside the consumer root; it cannot serve as a sentinel location."
        }
    }

    $resolvedOutsideManifest = Resolve-WithPhp -Path $outsideManifest
    $resolvedOutsideSkills = Resolve-WithPhp -Path $outsideSkills

    # ---- Case A: a junctioned .waaseyaa must not redirect the manifest ----

    $sentinelA = New-Sentinel -Directory $outsideManifest
    $sentinelAHash = (Get-FileHash -LiteralPath $sentinelA -Algorithm SHA256).Hash

    # site:init has already published real ownership metadata here; keep it.
    if (Test-Path -LiteralPath $waaseyaaLink) {
        Move-Item -LiteralPath $waaseyaaLink -Destination $waaseyaaStash
    }
    New-JunctionOrFail -LinkPath $waaseyaaLink -TargetPath $outsideManifest
    Assert-PositiveControls -JunctionPath $waaseyaaLink -Outside $outsideManifest `
        -ResolvedRoot $resolvedRoot -ResolvedOutside $resolvedOutsideManifest -Case 'case A (.waaseyaa)'

    $runA = Invoke-BimaajiInstall -Root $ConsumerRoot -ClientId 'cursor'

    if ($runA.ExitCode -eq 0) {
        Fail "case A — bimaaji:install exited 0 with a redirected .waaseyaa. Losing the ownership manifest must be a non-zero exit. Output: $($runA.Output)"
    }
    $escapedManifest = Join-Path $outsideManifest 'bimaaji-install.json'
    if (Test-Path -LiteralPath $escapedManifest) {
        Fail "case A — the ownership manifest was written OUTSIDE the project root, at $escapedManifest."
    }
    Assert-SentinelUntouched -Sentinel $sentinelA -ExpectedHash $sentinelAHash -Case 'case A'

    $strayA = @(Get-ChildItem -LiteralPath $outsideManifest -Force -Recurse |
        Where-Object { $_.FullName -ne $sentinelA })
    if ($strayA.Count -ne 0) {
        Fail "case A — bimaaji:install created $($strayA.Count) unexpected entr(y|ies) outside the project root: $($strayA.FullName -join ', ')"
    }
    Write-Step 'case A PASSED: a junctioned .waaseyaa redirected nothing; exit was non-zero and the sentinel is byte-identical.'

    Remove-JunctionOnly -LinkPath $waaseyaaLink
    if (Test-Path -LiteralPath $waaseyaaStash) {
        Move-Item -LiteralPath $waaseyaaStash -Destination $waaseyaaLink
    }

    # ---- Case B: a junctioned .claude/skills must not redirect skill writes ----

    $sentinelB = New-Sentinel -Directory $outsideSkills
    $sentinelBHash = (Get-FileHash -LiteralPath $sentinelB -Algorithm SHA256).Hash

    if (-not (Test-Path -LiteralPath $claudeDir)) {
        New-Item -ItemType Directory -Path $claudeDir -Force | Out-Null
    }
    if (Test-Path -LiteralPath $skillsLink) {
        Remove-Item -LiteralPath $skillsLink -Recurse -Force
    }
    New-JunctionOrFail -LinkPath $skillsLink -TargetPath $outsideSkills
    Assert-PositiveControls -JunctionPath $skillsLink -Outside $outsideSkills `
        -ResolvedRoot $resolvedRoot -ResolvedOutside $resolvedOutsideSkills -Case 'case B (.claude/skills)'

    $runB = Invoke-BimaajiInstall -Root $ConsumerRoot -ClientId 'claude'

    if ($runB.ExitCode -eq 0) {
        Fail "case B — bimaaji:install exited 0 with a redirected .claude/skills. Output: $($runB.Output)"
    }
    $escapedSkills = @(Get-ChildItem -LiteralPath $outsideSkills -Force -Directory |
        Where-Object { $_.Name -like 'waaseyaa-*' })
    if ($escapedSkills.Count -ne 0) {
        Fail "case B — skill directories were written OUTSIDE the project root: $($escapedSkills.FullName -join ', ')"
    }
    Assert-SentinelUntouched -Sentinel $sentinelB -ExpectedHash $sentinelBHash -Case 'case B'

    $strayB = @(Get-ChildItem -LiteralPath $outsideSkills -Force -Recurse |
        Where-Object { $_.FullName -ne $sentinelB })
    if ($strayB.Count -ne 0) {
        Fail "case B — bimaaji:install created $($strayB.Count) unexpected entr(y|ies) outside the project root: $($strayB.FullName -join ', ')"
    }
    Write-Step 'case B PASSED: a junctioned .claude/skills redirected nothing; exit was non-zero and the sentinel is byte-identical.'

    Write-Step 'containment holds across Windows directory junctions.'
    exit 0
}
finally {
    Remove-JunctionOnly -LinkPath $skillsLink
    Remove-JunctionOnly -LinkPath $waaseyaaLink
    if ((Test-Path -LiteralPath $waaseyaaStash) -and -not (Test-Path -LiteralPath $waaseyaaLink)) {
        Move-Item -LiteralPath $waaseyaaStash -Destination $waaseyaaLink -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $outsideManifest -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $outsideSkills -Recurse -Force -ErrorAction SilentlyContinue
}
