@echo off
REM Start PHP built-in server for this workspace on 127.0.0.1:8000
SET PHP_EXE=C:\xampp\php\php.exe
IF NOT EXIST "%PHP_EXE%" (
  echo PHP executable not found at %PHP_EXE%.
  echo Edit start_dev_server.bat to point to your php.exe location.
  pause
  exit /b 1
)

pushd "%~dp0"
start "Feedback PHP Server" "%PHP_EXE%" -S 127.0.0.1:8000 -t "%~dp0"
popd
echo PHP dev server started: http://127.0.0.1:8000
exit /b 0
