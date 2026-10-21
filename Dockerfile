FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && { echo 'ServerTokens Prod'; echo 'ServerSignature Off'; } > /etc/apache2/conf-available/hardening.conf \
    && a2enconf hardening \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php-overrides.ini $PHP_INI_DIR/conf.d/zz-overrides.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY app/ /var/www/html/

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/onlineForm/images/Student_Images /var/www/html/uploads

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/index.php") === false ? 1 : 0);'

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
