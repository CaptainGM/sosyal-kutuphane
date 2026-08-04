FROM php:8.3-cli
RUN docker-php-ext-install mysqli
WORKDIR /app
COPY . .
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000"]
