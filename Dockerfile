FROM dunglas/frankenphp:php8.4

WORKDIR /app

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    libzip-dev \
    libicu-dev \
    unzip \
    git \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    intl \
    zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Non-root user, UID matched to the host so bind-mounted files stay editable.
ARG USER=appuser
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} ${USER} && \
    useradd -m -u ${UID} -g ${GID} ${USER}; \
    setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp; \
    chown -R ${USER}:${USER} /config/caddy /data/caddy
USER ${USER}

RUN echo 'alias l="ls -lah --color=auto"' >> ~/.bashrc
