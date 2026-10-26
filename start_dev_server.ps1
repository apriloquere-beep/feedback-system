# Start PHP built-in server for this workspace on 127.0.0.1:8000
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path $php)) {
  Write-Host "PHP not found at $php" -ForegroundColor Red
  Write-Host "Edit this script to point to your php.exe location and try again." -ForegroundColor Yellow
  exit 1
}

$root = Split-Path -Path $PSScriptRoot -Parent
Write-Host "Starting PHP dev server at http://127.0.0.1:8000 (root: $PSScriptRoot)"
Start-Process -FilePath $php -ArgumentList '-S','127.0.0.1:8000','-t',$PSScriptRoot -NoNewWindow -WindowStyle Minimized
Write-Host "Server started. Open http://127.0.0.1:8000/admin/dashboard.php in your browser." -ForegroundColor Green
