param(
    [string]$Version = "0.6.0",
    [string]$Build = "0020",
    [string]$Python = "python"
)

$ErrorActionPreference = "Stop"
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$dist = Join-Path $repoRoot "dist"
$work = Join-Path $dist ("build-" + [guid]::NewGuid().ToString("N"))
$updateStage = Join-Path $work "update"
$spkStage = Join-Path $work "spk"
$payload = Join-Path $work "payload"

New-Item -ItemType Directory -Force -Path $dist,$updateStage,$spkStage,$payload | Out-Null
try {
    $packageMeta = Get-Content (Join-Path $repoRoot "plugins\aiDrive\package.json") -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($packageMeta.version -ne $Version) { throw "Plugin version $($packageMeta.version) does not match release $Version" }

    New-Item -ItemType Directory -Force -Path (Join-Path $updateStage "plugins") | Out-Null
    Copy-Item -LiteralPath (Join-Path $repoRoot "plugins\aiDrive") -Destination (Join-Path $updateStage "plugins\aiDrive") -Recurse
    $updateZip = Join-Path $dist "ai-drive-update-v$Version.zip"
    if (Test-Path $updateZip) { Remove-Item -LiteralPath $updateZip -Force }
    Compress-Archive -Path (Join-Path $updateStage "plugins") -DestinationPath $updateZip -CompressionLevel Optimal
    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $updateZip).Hash.ToLowerInvariant()
    Set-Content -LiteralPath "$updateZip.sha256" -Value "$hash  $(Split-Path $updateZip -Leaf)" -Encoding ascii -NoNewline

    $sourceTar = Join-Path $work "source.tar"
    & git -C $repoRoot archive --format=tar --output=$sourceTar HEAD
    if ($LASTEXITCODE -ne 0) { throw "git archive failed" }
    New-Item -ItemType Directory -Force -Path (Join-Path $payload "web") | Out-Null
    & tar -xf $sourceTar -C (Join-Path $payload "web")
    if ($LASTEXITCODE -ne 0) { throw "source archive extraction failed" }
    foreach ($excluded in @("synology","release","dist")) {
        $path = Join-Path (Join-Path $payload "web") $excluded
        if (Test-Path $path) { Remove-Item -LiteralPath $path -Recurse -Force }
    }
    $dataPath = Join-Path $payload "web\data"
    if (Test-Path $dataPath) { Remove-Item -LiteralPath $dataPath -Recurse -Force }

    # Fail the release instead of shipping a page that only renders HTML but
    # cannot start its JavaScript runtime. These directories contain generic
    # names such as dist/data and were previously caught by broad archive rules.
    $requiredPayloadFiles = @(
        "static\app\dist\vendor.js",
        "static\app\dist\api.js",
        "static\app\dist\main.js",
        "app\sdks\archiveLib\bin\data.bin",
        "plugins\aiDrive\lib\data\cacert.pem"
    )
    foreach ($requiredFile in $requiredPayloadFiles) {
        $requiredPath = Join-Path (Join-Path $payload "web") $requiredFile
        if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
            throw "Required release file is missing: $requiredFile"
        }
        if ((Get-Item -LiteralPath $requiredPath).Length -eq 0) {
            throw "Required release file is empty: $requiredFile"
        }
    }

    $info = Get-Content (Join-Path $repoRoot "synology\package\INFO") -Raw -Encoding UTF8
    $info = $info -replace 'version="[^"]+"', ('version="' + $Version + '-' + $Build + '"')
    [System.IO.File]::WriteAllText((Join-Path $spkStage "INFO"),$info,(New-Object System.Text.UTF8Encoding($false)))
    Copy-Item -LiteralPath (Join-Path $repoRoot "synology\package\conf") -Destination (Join-Path $spkStage "conf") -Recurse
    Copy-Item -LiteralPath (Join-Path $repoRoot "synology\package\scripts") -Destination (Join-Path $spkStage "scripts") -Recurse
    Copy-Item -LiteralPath (Join-Path $repoRoot "LICENSE") -Destination (Join-Path $spkStage "LICENSE")
    Copy-Item -LiteralPath (Join-Path $repoRoot "static\images\icon\fav.png") -Destination (Join-Path $spkStage "PACKAGE_ICON.PNG")

    Add-Type -AssemblyName System.Drawing
    $sourceIcon = [System.Drawing.Image]::FromFile((Join-Path $repoRoot "static\images\icon\icon_512.png"))
    try {
        $icon = New-Object System.Drawing.Bitmap 256,256
        $graphics = [System.Drawing.Graphics]::FromImage($icon)
        try { $graphics.DrawImage($sourceIcon,0,0,256,256); $icon.Save((Join-Path $spkStage "PACKAGE_ICON_256.PNG"),[System.Drawing.Imaging.ImageFormat]::Png) }
        finally { $graphics.Dispose(); $icon.Dispose() }
    } finally { $sourceIcon.Dispose() }

    $spk = Join-Path $dist "AI-Drive-$Version-$Build-noarch.spk"
    if (Test-Path $spk) { Remove-Item -LiteralPath $spk -Force }
    & $Python (Join-Path $PSScriptRoot "make-spk.py") --payload $payload --metadata $spkStage --output $spk
    if ($LASTEXITCODE -ne 0) { throw "SPK creation failed" }

    [pscustomobject]@{
        UpdateZip = $updateZip
        UpdateSha256 = "$updateZip.sha256"
        SynologyPackage = $spk
    }
} finally {
    if (Test-Path $work) { Remove-Item -LiteralPath $work -Recurse -Force }
}
