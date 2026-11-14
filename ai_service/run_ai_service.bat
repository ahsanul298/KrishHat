@echo off
REM KrishiHat AI service - double-click to start (first run installs packages, takes a few minutes)
cd /d %~dp0
if not exist venv (
    echo Creating Python environment...
    python -m venv venv
)
call venv\Scripts\activate
pip install -r requirements.txt
set AI_SERVICE_KEY=krishihat-local-key
echo.
echo AI service starting at http://127.0.0.1:8001  (close this window to stop)
uvicorn app.main:app --host 127.0.0.1 --port 8001
pause
