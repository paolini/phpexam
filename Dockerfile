# syntax=docker/dockerfile:1
#
# build:
# docker build .
#
# run:
# docker run -d -p8000:80 <image_id>
#
# push:
# docker push paolini/phpexam:latest
#
FROM php:7.4-apache
COPY webroot/docker_index.php /var/www/html/index.php
COPY exam.js /var/www/html/exam.js
RUN mkdir /app
RUN mkdir /app/var
RUN chown www-data.www-data /app/var
COPY exam.php /app
COPY exam.js /app
COPY example.xml /app
WORKDIR /app
