# antigravity: models of a Google Antigravity plan through the official Antigravity CLI (a module of the Mercurius agent).
# The CLI is pinned by version and by the sha512 of its archive: its stream-json events, its agents and its quota
# answer change between versions, so a new version is a change of these arguments and a run of the checks, never an
# update on its own. The binary is downloaded from Google at the build and is not redistributed in an image registry.
ARG AGY_VERSION=1.5.18
ARG AGY_BUILD=1.5.18-6386224290725888
ARG AGY_SHA512_AMD64=ef379ec869719112f696e8c7cfddf776618712e58fc38e53df80801d6528abb4677782c7c8778f9c09c9123fa44c2c32675d228c06f2a8f7a8f4214e75f5bb26
ARG AGY_SHA512_ARM64=6c59cbce5a7632083dd4ef594296e52ce0abdc12f8b4ecb55523e093748cf178f8f8383d7e51e9ed7859ea6e46c83a43a7b46364b0e033dd13afb24d6bfbe4c8

FROM php:8.2-cli-bookworm AS cli
ARG AGY_VERSION
ARG AGY_BUILD
ARG AGY_SHA512_AMD64
ARG AGY_SHA512_ARM64
RUN set -eu; \
    case "$(uname -m)" in \
        x86_64) path="linux-x64/cli_linux_x64.tar.gz"; sum="$AGY_SHA512_AMD64" ;; \
        aarch64) path="linux-arm/cli_linux_arm64.tar.gz"; sum="$AGY_SHA512_ARM64" ;; \
        *) echo "unsupported machine" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/agy.tar.gz "https://storage.googleapis.com/antigravity-public/antigravity-cli/${AGY_BUILD}/${path}"; \
    echo "${sum}  /tmp/agy.tar.gz" | sha512sum -c -; \
    tar -xzf /tmp/agy.tar.gz -C /tmp antigravity; \
    mv /tmp/antigravity /agy; chmod 755 /agy; \
    test "$(/agy --version)" = "${AGY_VERSION}"

FROM php:8.2-cli-bookworm AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
COPY src ./src
COPY bin ./bin
COPY resources ./resources

FROM php:8.2-cli-bookworm
# The CLI is one binary linked against glibc only.
COPY --from=cli /agy /usr/local/bin/agy
RUN docker-php-ext-install pcntl posix \
    && useradd --system --uid 10060 --home /data/antigravity --shell /usr/sbin/nologin antigravity \
    && mkdir -p /data/antigravity && chown antigravity:antigravity /data/antigravity && chmod 700 /data/antigravity \
    && echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/antigravity.ini
WORKDIR /app
COPY --from=build /app /app
ENV HOME=/data/antigravity ANTIGRAVITY_HOME=/data/antigravity
USER antigravity
EXPOSE 8095
# 200 only when the CLI is found and new enough: a check of the work, not of the port.
HEALTHCHECK --interval=60s --timeout=30s --start-period=20s --retries=3 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8095/health") === false ? 1 : 0);'
CMD ["php", "bin/antigravity"]
