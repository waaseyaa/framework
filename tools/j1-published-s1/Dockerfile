FROM ubuntu@sha256:534baea6a22c03a63003dbc8dbe78fe34bc0d7e595d9a9dc9834884ff530eb55
ENV DEBIAN_FRONTEND=noninteractive
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d && chmod +x /usr/sbin/policy-rc.d \
    && apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates software-properties-common curl unzip apache2 \
    && add-apt-repository -y ppa:ondrej/php && apt-get update \
    && apt-get install -y --no-install-recommends php8.5-cli php8.5-fpm php8.5-sqlite3 php8.5-mbstring php8.5-xml php8.5-zip php8.5-curl php8.5-intl php8.5-gd \
    && a2enmod proxy_fcgi setenvif rewrite headers && a2enconf php8.5-fpm \
    && a2dissite 000-default && mkdir -p /run/php \
    && dpkg-query -W > /opt/runtime-packages.txt
COPY composer.phar /usr/local/bin/composer
RUN chmod 0755 /usr/local/bin/composer
CMD ["sleep", "infinity"]
