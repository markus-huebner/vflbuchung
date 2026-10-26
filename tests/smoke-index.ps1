param(
    [string]$BaseUrl = "http://localhost:9000"
)

$ErrorActionPreference = "Stop"

$page = Invoke-WebRequest -Uri "$BaseUrl/" -UseBasicParsing
if ($page.StatusCode -ne 200 -or $page.Content -notmatch "Belegungsplan" -or $page.Content -match 'data-resource-type="cabin"' -or $page.Content -notmatch 'class="calendar-navigation"') {
    throw "The booking calendar page did not load successfully."
}
if ($page.Content -match 'mobile-calendar|Handy-Ansicht') {
    throw "The removed mobile calendar is still linked."
}
$mobileStatus = $null
try {
    $mobilePage = Invoke-WebRequest -Uri "$BaseUrl/?page=mobile-calendar" -UseBasicParsing
    $mobileStatus = [int]$mobilePage.StatusCode
} catch {
    if ($_.Exception.Response) {
        $mobileStatus = [int]$_.Exception.Response.StatusCode
    } else {
        throw
    }
}
if ($mobileStatus -ne 404) {
    throw "The removed mobile calendar route must return HTTP 404."
}

$resourceResponse = Invoke-RestMethod -Uri "$BaseUrl/?api=resources"
if ($resourceResponse.PSObject.Properties.Name -contains "cabins") {
    throw "The resources API still returns configurable pitch-to-cabin assignments."
}
$resourceNames = @($resourceResponse.resources | ForEach-Object { $_.name })
$categoryNames = @($resourceResponse.resources | ForEach-Object { $_.category_name } | Sort-Object -Unique)
foreach ($categoryName in @("Platz", "Fahrzeug", "Kabine")) {
    if ($categoryNames -notcontains $categoryName) {
        throw "The resources API is missing the '$categoryName' category name."
    }
}
$southName = "Platz 1 S" + [char]0x00FC + "d"
$mojibakeSouthName = "Platz 1 S" + [char]0x00C3 + [char]0x00BC + "d"
if ($resourceNames -contains $mojibakeSouthName) {
    throw "The resources API returned the south pitch name with corrupted umlauts."
}
$expectedResources = @(
    $southName, "Platz 1 Nord", "Platz 2", "Platz 3",
    "Platz 4 Ost", "Platz 4 West", "Platz 5", "Vereinsbus",
    "Kabine 1", "Kabine 2", "Kabine 3", "Kabine 4", "Kabine 5", "Kabine 6"
)
foreach ($name in $expectedResources) {
    if ($resourceNames -notcontains $name) {
        throw "The resources API is missing '$name'."
    }
}

$start = [DateTime]::UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ")
$end = [DateTime]::UtcNow.AddDays(7).ToString("yyyy-MM-ddTHH:mm:ssZ")
$eventsUri = "$BaseUrl/?api=events&type=pitch&start=$([uri]::EscapeDataString($start))&end=$([uri]::EscapeDataString($end))"
$null = Invoke-RestMethod -Uri $eventsUri
$cabinEventsUri = "$BaseUrl/?api=events&type=cabin&start=$([uri]::EscapeDataString($start))&end=$([uri]::EscapeDataString($end))"
$null = Invoke-RestMethod -Uri $cabinEventsUri

Write-Output "Booking system smoke test passed."
