@echo off
REM 

echo ======================================
echo   SOSYAL KÜTÜPHANE - PHP SERVER
echo ======================================
echo.

REM 
cd /d "%~dp0"

REM 
php -v >nul 2>&1
if %errorlevel% neq 0 (
    echo ❌ HATA: PHP kurulu degil!
    echo Kurulum linki: https://www.php.net/downloads
    pause
    exit /b 1
)

echo ✅ PHP bulundu
echo.
echo 🚀 Server başlatılıyor...
echo 📱 Tarayıcıda açmak için: http://localhost:8000/index.html
echo.
echo Çıkmak için Ctrl+C tuşlarına basın
echo ======================================
echo.

php -S localhost:8000

echo.
echo Server kapatıldı.
pause
