// Şifre kuralları: kayıt ve şifre sıfırlama formlarında canlı geri bildirim.
// Kurallar api/lib/password.php ile aynıdır (en az 8 karakter, büyük harf, küçük harf, özel karakter).
function wirePasswordRules(inputId, listId) {
    const input = document.getElementById(inputId);
    const list = document.getElementById(listId);
    if (!input || !list) return;

    const rules = {
        length: (value) => value.length >= 8,
        upper: (value) => /\p{Lu}/u.test(value),
        lower: (value) => /\p{Ll}/u.test(value),
        special: (value) => /[^\p{L}\p{N}\s]/u.test(value),
    };

    const update = () => {
        list.querySelectorAll('li[data-rule]').forEach((item) => {
            item.classList.toggle('is-met', rules[item.dataset.rule](input.value));
        });
    };

    input.addEventListener('input', update);
    update();
}
