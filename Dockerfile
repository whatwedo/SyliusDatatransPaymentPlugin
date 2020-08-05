# Base stage (used in development and production)
FROM whatwedo/nginx-php:v2.2 as base

# Set workdir
WORKDIR /var/www

########################################################################################################################

# Development stage (depencencies and configuration used in development only)
FROM base as dev

RUN apk update &&\
    apk upgrade &&\
    apk add --no-cache php-exif@php make

# Install dde development depencencies
# .dde/configure-image.sh will be created automatically
COPY .dde/configure-image.sh /tmp/dde-configure-image.sh
ARG DDE_UID
ARG DDE_GID
RUN /tmp/dde-configure-image.sh

########################################################################################################################

# Production stage (depencencies and configuration used in production only)
FROM base as prod

# Add files
ADD . /var/www
