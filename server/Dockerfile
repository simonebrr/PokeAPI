# Usa l'immagine base PHP con Apache
FROM php:8.2-apache

# Installa estensioni necessarie e cron
RUN apt-get update && apt-get install -y \
    cron \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

# Copia i file crontab nel contenitore
COPY crontab /etc/cron.d/custom-cron

# Imposta i permessi per il file crontab
RUN chmod 0644 /etc/cron.d/custom-cron

# Applica il crontab
RUN crontab /etc/cron.d/custom-cron

# Assicurati che il servizio cron sia avviato
CMD ["sh", "-c", "cron && apache2-foreground"]
