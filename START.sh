

echo "======================================"
echo "   SOSYAL KÜTÜPHANE - PHP SERVER"
echo "======================================"
echo ""


cd "$(dirname "$0")"


if ! command -v php &> /dev/null; then
    echo "❌ HATA: PHP kurulu değil!"
    echo "Kurulum: https://www.php.net/downloads"
    exit 1
fi

echo "✅ PHP bulundu"
echo ""
echo "🚀 Server başlatılıyor..."
echo "📱 Tarayıcıda açmak için: http://localhost:8000/index.html"
echo ""
echo "Çıkmak için Ctrl+C tuşlarına basın"
echo "======================================"
echo ""

php -S localhost:8000

echo ""
echo "Server kapatıldı."
