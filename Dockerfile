# ใช้ PHP built-in server (ง่ายสุดสำหรับ deploy)
FROM php:8.2-cli

WORKDIR /app
COPY weather.php /app/weather.php

# Render จะกำหนด PORT มาให้ใน env
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app"]
