param(
    [string]$Path = "."
)

$badFragments = @(
    [string][char]0x00C3, # Ã
    [string][char]0x00C2, # Â
    [string][char]0x00C4, # Ä
    [string][char]0xFFFD  # replacement char
)

$ext = @('*.php','*.js','*.css','*.md','*.sql')
$files = Get-ChildItem -Path $Path -Recurse -File -Include $ext | Where-Object {
    $_.FullName -notmatch '\\.git\\' -and
    $_.FullName -notmatch '\\.agent\\' -and
    $_.FullName -notmatch '\\database\\' -and
    $_.Name -ne 'database.sql'
}

$hits = @()
foreach ($f in $files) {
    $text = Get-Content -Raw -Encoding UTF8 $f.FullName
    foreach ($frag in $badFragments) {
        if ($text.Contains($frag)) {
            $hits += [PSCustomObject]@{
                File = $f.FullName
                Fragment = ('U+' + ([int][char]$frag).ToString('X4'))
            }
            break
        }
    }
}

if ($hits.Count -eq 0) {
    Write-Output "OK: no obvious mojibake fragments found."
    exit 0
}

Write-Output "FOUND potential mojibake fragments:"
$hits | Sort-Object File | Format-Table -AutoSize
exit 1
