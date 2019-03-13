Установка на сервер
===================

1. Скопируйте каталог modulbank-shortcode в каталог `wp-content/plugins` вашего сайта или загрузите [архив](https://github.com/modulbank-pay/modulbank-wordpress-shorcode/releases/download/v2.2/modulbank-shortcode_2.2.zip) с плагином через UI загрузки расширений.
![Загрузка платежного модуля Модульбанка](https://modulbank-pay.github.io/screenshots/wordpress-shortcode/1.png)
![Загрузка платежного модуля Модульбанка](https://modulbank-pay.github.io/screenshots/wordpress-shortcode/2.png)
2. В администраторской панели вашего сайта откройте раздел «Плагины», найдите «Modulbank Shortcode» и нажмите «Активировать»:


Настройки на вашем сайте
========================

В администраторской панели вашего сайта откройте раздел

    Настройки → Modulbank Shortcode

Заполните поля «Merchant ID» и «Secret key». Эти значения уникальны для каждого магазина, посмотреть их можно в личном
кабинете в разделе «Готовые модули».
![Загрузка платежного модуля Модульбанка](https://modulbank-pay.github.io/screenshots/wordpress-shortcode/3.png)

Сохраните изменения.

Использование
=============
Чтобы в тексте страницы или поста появилась кнопка для оплаты услуги на фиксированную сумму:
```
[modulbank_sc amount="10.99" currency="RUB" description="Описание платежа"]
```

То же самое, но запрашивается имя, email и телефон клиента:

```
[modulbank_sc amount="10.99" currency="RUB" description="Описание платежа" fields="client_name,client_email,client_phone"]
```
Для онлайн фискализации наличие одного из полей client_email, client_phone является обязательным.

Форма приёма произвольной суммы с запросом имени и телефона:
```
[modulbank_sc currency="RUB" description="Описание платежа" fields="client_name,client_phone"]
```

Своя надпись на кнопке:
```
[modulbank_sc amount="5" currency="RUB" description="Описание платежа" button_text="Оплатить 5 рублей"]
```

Список проведённых транзакций можно посмотреть как в личном кабинете, так и на вкладке «Заказы и платежи»:
