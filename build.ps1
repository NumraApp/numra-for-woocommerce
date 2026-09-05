# Numra for WooCommerce — build the distributable zip.
#
#   powershell -ExecutionPolicy Bypass -File build.ps1
#
# The build lives in build.mjs now. It was ported from PowerShell for two
# reasons: this script wrote its output into apps/portal/sdks, a path outside
# this directory and the one thing stopping the plugin being its own
# repository; and a PHP plugin should not need a Windows runner to prove it
# builds, which the release pipeline does on Linux.
#
# Kept as a shim because `build.ps1` is in the docs and in muscle memory.
# --publish-to hands the zip to the portal exactly as this script used to,
# and is what disappears when the repo is split out.

$ErrorActionPreference = 'Stop'
& node (Join-Path $PSScriptRoot 'build.mjs') --publish-to '../../apps/portal/sdks' @args
exit $LASTEXITCODE
