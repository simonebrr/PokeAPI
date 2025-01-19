# Usa l'immagine base PHP con Apache
FROM php:8.2-apache

# Installa l'estensione pdo_mysql
RUN docker-php-ext-install pdo pdo_mysql

# Abilita moduli Apache necessari
RUN a2enmod rewrite

# Riavvia Apache per applicare le modifiche
CMD ["apache2-foreground"]
