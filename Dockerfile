# syntax=docker/dockerfile:1
#
# build:
# docker build . -t paolini/phpexam
#
# run:
# docker run -d -p8000:80 <image_id>
#
# push:
# docker login
# docker push paolini/phpexam
#
FROM php:7.4-apache
RUN apt-get update && apt-get install -y \
	libldap2-dev \
    && docker-php-ext-install ldap
COPY webroot/docker_index.php /var/www/html/index.php
COPY exam.js /var/www/html/exam.js
RUN mkdir /app
RUN mkdir /app/var
RUN mkdir /app/etc
RUN chown www-data.www-data /app/var
COPY exam.php /app
COPY exam.js /app
COPY etc/example.xml /app/etc
WORKDIR /app
