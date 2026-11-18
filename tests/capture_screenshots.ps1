<#
.SYNOPSIS
    Retake the screenshots in docs/screenshots (the README and the user guide
    use them) from a local BUD with demo data, using headless Microsoft Edge.
    Not part of CI. See "Retaking the screenshots" in TESTING.md.
.DESCRIPTION
    For Windows with BUD running in WSL:
      1. In WSL, from the repo root, make a demo database:
           BUD_DB_PATH=/tmp/bud-demo/bud.db php tests/seed_demo_data.php --force
      2. In WSL, serve it:
           cd bud_addon/files/general/www/public
           BUD_DB_PATH=/tmp/bud-demo/bud.db php -S 127.0.0.1:8420
      3. In PowerShell, from the repo root:
           powershell -ExecutionPolicy Bypass -File .\tests\capture_screenshots.ps1
         or just some of them:
           powershell -ExecutionPolicy Bypass -Command "& .\tests\capture_screenshots.ps1 -Only desktop-dashboard,mobile-dashboard"
    Desktop shots are 1280 px wide (the printed manifest 900 px), phone shots
    are a 390 x 844 screen at 2x. Each shot loads a page, can fill in a form
    or open a pop-up first, then saves a PNG. To add one, add a line to
    $shots and use the PNG in README.md or bud_addon/DOCS.md.
    Run it against demo data only. The screenshots are public.
#>
param(
    [string]$OutDir = (Join-Path $PSScriptRoot '..\docs\screenshots'),
    [string]$BaseUrl = 'http://127.0.0.1:8420',
    [string[]]$Only = @(),
    [string]$Browser = ''
)

# "-Only a,b" can arrive as one string: split it on the commas
$Only = @($Only | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })

$ErrorActionPreference = 'Stop'

if (-not $Browser) {
    $Browser = @(
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
    ) | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
}
if (-not $Browser) {
    throw "Microsoft Edge wasn't found. Pass -Browser with the path to msedge.exe or chrome.exe."
}

try {
    Invoke-WebRequest "$BaseUrl/index.php" -UseBasicParsing -TimeoutSec 5 | Out-Null
} catch {
    throw "No BUD answering at $BaseUrl. Seed and serve the demo data first (see the notes at the top of this script)."
}

# --- What each shot does before it's taken ---------------------------------------
# The courier transfer still in progress, from the page's own data
$courierId = @'
const transfers = Object.values(JSON.parse(document.getElementById('transferData').textContent));
const courier = transfers.find(t => t.delivery_method === 'courier' && t.status === 'In Progress');
const done = transfers.find(t => t.delivery_method === 'courier' && t.status === 'Completed');
'@
$newTransfer = @'
openNewTransfer();
const form = document.getElementById('transferForm');
form.querySelector('[name=transported_by]').value = 'Chris Driver';
const to = document.getElementById('receiver_select');
to.value = to.options[1].value;
to.dispatchEvent(new Event('change'));
const courierRadio = form.querySelector('input[name=delivery_method][value=courier]');
courierRadio.checked = true;
courierRadio.dispatchEvent(new Event('change'));
document.getElementById('courier_company').value = 'Example Couriers';
const row = document.querySelector('#items-container .repeat-row');
const item = row.querySelector('select');
item.value = item.options[1].value;
row.querySelector('[name="batch[]"]').value = 'B2611-01';
row.querySelector('[name="qty[]"]').value = 8;
'@
$completeCourier = $courierId + @'
document.querySelector('[data-transfer-action="complete"][data-id="' + courier.id + '"]').click();
'@
$recordCourier = $courierId + @'
document.querySelector('[data-transfer-action="view"][data-id="' + done.id + '"]').click();
'@
# The printed manifest: catch what the print button writes and show it in this tab
$manifest = $courierId + @'
window.open = () => ({ document: {
    write: (html) => { document.open(); document.write(html.replace('window.print();', '')); },
    close: () => document.close()
} });
document.querySelector('[data-transfer-action="manifest"][data-id="' + courier.id + '"]').click();
'@
$menuOpen = "document.getElementById('nav-toggle').checked = true;"
# Draw the charts again without their grow-in animation, so they're whole in the shot
$chartsStill = "Chart.defaults.animation = false; document.dispatchEvent(new CustomEvent('bud:themechange'));"

# name, path, width, height, scale, phone?, clip ('page' = as tall as the page up to the height,
# 'screen' = the screen only, for pop-ups), theme, setup script
$shots = @(
    @('desktop-dashboard',               '/index.php',          1280, 1000, 1, $false, 'page',   'light', ''),
    @('desktop-dashboard-dark',          '/index.php',          1280, 1000, 1, $false, 'page',   'dark',  ''),
    @('desktop-tracking',                '/tracking.php',       1280, 900,  1, $false, 'page',   'light', ''),
    @('desktop-stock',                   '/stock.php',          1280, 1000, 1, $false, 'page',   'light', ''),
    @('desktop-chain-of-custody',        '/custody.php',        1280, 1150, 1, $false, 'page',   'light', ''),
    @('desktop-new-transfer-courier',    '/custody.php',        1280, 1050, 1, $false, 'screen', 'light', $newTransfer),
    @('desktop-complete-signed-manifest','/custody.php',        1280, 1000, 1, $false, 'screen', 'light', $completeCourier),
    @('desktop-transfer-record',         '/custody.php',        1280, 1000, 1, $false, 'screen', 'light', $recordCourier),
    @('desktop-shipping-manifest',       '/custody.php',        900,  1020, 1, $false, 'screen', 'light', $manifest),
    @('desktop-destruction-register',    '/destruction.php',    1280, 900,  1, $false, 'page',   'light', ''),
    @('desktop-reporting',               '/reporting.php',      1280, 900,  1, $false, 'page',   'light', ''),
    @('desktop-reports',                 '/reports.php',        1280, 1300, 1, $false, 'page',   'light', ''),
    @('desktop-section-29-register',     '/s29.php',            1280, 1200, 1, $false, 'page',   'light', ''),
    @('desktop-analytics',               '/analytics.php',      1280, 1580, 1, $false, 'page',   'light', $chartsStill),
    @('desktop-administration',          '/administration.php', 1280, 900,  1, $false, 'page',   'light', ''),
    @('desktop-your-company',            '/company.php',        1280, 900,  1, $false, 'page',   'light', ''),
    @('desktop-verified-receivers',      '/receivers.php',      1280, 900,  1, $false, 'page',   'light', ''),
    @('mobile-dashboard',                '/index.php',          390,  844,  2, $true,  'screen', 'light', ''),
    @('mobile-menu-open',                '/custody.php',        390,  844,  2, $true,  'screen', 'light', $menuOpen),
    @('mobile-chain-of-custody',         '/custody.php',        390,  844,  2, $true,  'screen', 'light', ''),
    @('mobile-complete-signed-manifest', '/custody.php',        390,  844,  2, $true,  'screen', 'light', $completeCourier),
    @('mobile-stock',                    '/stock.php',          390,  844,  2, $true,  'screen', 'dark',  '')
)

# --- Headless browser, driven over the DevTools protocol ----------------------------
$port = 9336
$browserProfile = Join-Path $env:TEMP 'bud-screenshot-profile'
Remove-Item -Recurse -Force $browserProfile -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force $OutDir | Out-Null

$proc = Start-Process -FilePath $Browser -PassThru -WindowStyle Hidden -ArgumentList @(
    '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check',
    "--user-data-dir=$browserProfile", "--remote-debugging-port=$port", '--window-size=1280,900', 'about:blank')

$ws = $null
try {
    $deadline = (Get-Date).AddSeconds(25)
    $targets = $null
    do {
        try { $targets = Invoke-RestMethod "http://127.0.0.1:$port/json" -TimeoutSec 2 } catch { Start-Sleep -Milliseconds 300 }
    } while (-not $targets -and (Get-Date) -lt $deadline)
    if (-not $targets) { throw "The browser's DevTools endpoint never came up." }
    $page = $targets | Where-Object { $_.type -eq 'page' } | Select-Object -First 1

    $ws = [System.Net.WebSockets.ClientWebSocket]::new()
    $ws.ConnectAsync([Uri]$page.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
    $script:msgId = 0
    $recvBuf = [byte[]]::new(2MB)

    function Send-Cdp([string]$method, [hashtable]$params = @{}) {
        $script:msgId++
        $id = $script:msgId
        $json = @{ id = $id; method = $method; params = $params } | ConvertTo-Json -Depth 8 -Compress
        $bytes = [Text.Encoding]::UTF8.GetBytes($json)
        $ws.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
        while ($true) {
            $sb = [Text.StringBuilder]::new()
            do {
                $res = $ws.ReceiveAsync([ArraySegment[byte]]::new($recvBuf), [Threading.CancellationToken]::None).GetAwaiter().GetResult()
                [void]$sb.Append([Text.Encoding]::UTF8.GetString($recvBuf, 0, $res.Count))
            } while (-not $res.EndOfMessage)
            $msg = $sb.ToString() | ConvertFrom-Json
            if ($msg.id -eq $id) {
                if ($msg.error) { throw "DevTools $method failed: $($msg.error.message)" }
                return $msg.result
            }
        }
    }
    function Invoke-PageJs([string]$js) {
        $r = Send-Cdp 'Runtime.evaluate' @{ expression = $js; returnByValue = $true; awaitPromise = $true }
        return $r.result.value
    }
    function Open-Page([string]$path) {
        Send-Cdp 'Page.navigate' @{ url = "$BaseUrl$path" } | Out-Null
        $pathOnly = ($path -split '\?')[0]
        $t = (Get-Date).AddSeconds(15)
        do {
            Start-Sleep -Milliseconds 200
            $ready = Invoke-PageJs "document.readyState==='complete' && location.pathname.endsWith('$pathOnly')"
        } while (-not $ready -and (Get-Date) -lt $t)
        Start-Sleep -Milliseconds 1200
    }

    # Focus emulation, so a pop-up opens the way it does for a person
    Send-Cdp 'Emulation.setFocusEmulationEnabled' @{ enabled = $true } | Out-Null

    $saved = 0
    foreach ($s in $shots) {
        $name, $path, $w, $h, $scale, $mobile, $clipMode, $theme, $setup = $s
        if ($Only.Count -gt 0 -and $Only -notcontains $name) { continue }

        # A page shot starts on a short screen, so it's measured at its own
        # height (up to $h). A pop-up or phone shot is the screen itself.
        $screenH = if ($clipMode -eq 'page') { 600 } else { $h }
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $w; height = $screenH; deviceScaleFactor = $scale; mobile = $mobile } | Out-Null
        # The theme is saved per browser, like a person's choice
        Open-Page $path
        Invoke-PageJs "localStorage.setItem('bud-theme', '$theme'); true" | Out-Null
        Open-Page $path
        if ($setup -ne '') {
            Invoke-PageJs "(()=>{ $setup return true; })()" | Out-Null
            Start-Sleep -Milliseconds 1500
        }

        if ($clipMode -eq 'page') {
            $m = Send-Cdp 'Page.getLayoutMetrics'
            $contentH = [math]::Ceiling($m.cssContentSize.height)
            $clipH = [math]::Min([math]::Max($contentH, 600), $h)
        } else {
            $clipH = $h
        }
        $clip = @{ x = 0; y = 0; width = $w; height = $clipH; scale = 1 }
        $shot = Send-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = $true; clip = $clip }
        $out = Join-Path $OutDir "$name.png"
        [IO.File]::WriteAllBytes($out, [Convert]::FromBase64String($shot.data))
        $saved++
        "OK   $name  ($([math]::Round((Get-Item $out).Length / 1KB)) KB, $($w)x$($clipH) at ${scale}x)"
    }
    # Leave the browser on the default light theme
    Invoke-PageJs "localStorage.removeItem('bud-theme'); true" | Out-Null
    "Saved $saved screenshot(s) to $((Resolve-Path $OutDir).Path)"
} finally {
    if ($ws) { $ws.Dispose() }
    Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
}
