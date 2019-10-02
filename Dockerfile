FROM php:7-cli

RUN apt-get update 
RUN apt-get install -y libpq-dev libsqlite3-dev
RUN docker-php-ext-install pgsql

ADD ./ /opt/pgqm/
WORKDIR /opt/pgqm/

ENTRYPOINT [ "php", "pgqm.php" ]

